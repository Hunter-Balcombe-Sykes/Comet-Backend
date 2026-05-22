# Transaction boundary correctness

Hunt every `DB::transaction` / `DB::beginTransaction` / model-save-inside-a-transaction site in the codebase and measure it against the **gold-standard transaction discipline** required for a multi-vendor financial system: the database transaction is the unit of *atomic state change*, nothing else. External I/O, queue dispatches, cache writes, log shipping, and notification side-effects belong **outside** the transaction or **after commit**, never inside.

The bug shape this lens catches is the one that does not surface in tests and surfaces catastrophically in production: a transaction commits the DB row, the queue dispatch inside the transaction never fires because of a Redis blip, the user sees a confirmation, the downstream job never runs. Or: the transaction rolls back on a constraint violation, but the cache was written before commit, so every reader for the next TTL serves a value that does not exist in the database. Or: a Stripe API call inside the transaction holds an open DB connection for 12 seconds, exhausting Postgres connections under load.

There is no single "reference file" for this pattern the way there is for caching — the gold standard is a **discipline**, codified below.

## The gold standard (what "correct" looks like here)

Every `DB::transaction(...)` / `DB::beginTransaction()` block must satisfy **all** of:

1. **No external network I/O inside the transaction.** No Shopify Admin API calls, no Stripe API calls, no Square / Fresha calls, no `Http::` facade calls to arbitrary hosts, no Slack / email sends. These hold a Postgres connection open for the duration of the I/O — under load, this exhausts the connection pool.
2. **No queue dispatches inside the transaction.** `dispatch(...)` writes to Redis (or the configured queue driver). If the transaction rolls back, the job still runs against state that no longer exists. Use `DB::afterCommit(fn() => dispatch(...))` or move the dispatch after the `DB::transaction` block returns.
3. **No cache writes inside the transaction.** `Cache::put`, `Cache::forget`, `Cache::flush`, version-token bumps. On rollback, the cache shows state that doesn't exist in the database. Use `DB::afterCommit(fn() => $cache->forget($key))`.
4. **No event dispatches with side-effecting listeners inside the transaction.** Laravel events fire synchronously unless `ShouldQueue`. A listener that itself writes to the cache, dispatches a job, or hits an external API inherits all of the above failure modes through the back door. `Event::dispatch` inside a transaction is a flag.
5. **No observers with cross-resource side effects firing on intra-transaction saves.** Observers on `saving` / `updating` run inside the parent transaction. An observer that dispatches a job, invalidates a cache, or calls a service inherits the same failure modes — `created` / `updated` / `saved` observers must use `DB::afterCommit` for any non-DB side effect.
6. **No `Log::` to external sinks inside hot transactions.** Local file logging is fine; structured log shipping (Nightwatch, Sentry, etc.) over the network is an external I/O call masquerading as a log statement.
7. **Bounded transaction scope.** Transactions wrap the smallest atomic unit. A `DB::transaction(fn() => { /* 200 lines */ })` that includes setup, fetch, mutation, and dispatch logic is too coarse. Fetch first, dispatch after, transact the mutation only.
8. **Explicit deadlock-retry only where idempotent.** `DB::transaction(fn() => ..., $attempts)` with `$attempts > 1` retries on deadlock. The closure MUST be idempotent — re-running it must produce the same result. Flag every `$attempts > 1` and confirm the closure is safe to re-run.
9. **No nested transactions without intent.** Laravel auto-converts nested `DB::transaction` calls into SAVEPOINTs. This is sometimes correct (composable services) and sometimes a bug (the inner caller didn't realise it was already in a transaction and an outer rollback wipes its work). Flag nested cases and confirm intent.
10. **Connection consistency.** All writes in a transaction must use the same connection. `Model::on('other_db')->...` inside a `DB::connection('default')->transaction(...)` is not part of the transaction and won't roll back. With Supabase + the `pgsql` connection enforced by `BaseModel`, this is unlikely but worth a sweep.
11. **Observer side effects gated by `DB::afterCommit`.** Specifically: any observer hook (`created`, `updated`, `deleted`, `restored`) that dispatches a job, writes to cache, sends a notification, or hits an external service must wrap the side effect in `DB::afterCommit(fn() => ...)`. Bare `dispatch(...)` in an observer is a bug.
12. **Cache invalidation on `*ed` events, not `*ing` events.** Observers using `creating` / `updating` to invalidate caches run BEFORE the DB commits — readers between the cache forget and the commit see the OLD row, re-warm the cache with the OLD value, and the system stays stale until the next TTL or explicit bust.

## Use the lens prefix `TXN` for findings

Number them `TXN-1`, `TXN-2`, … sequentially across the whole audit, regardless of category.

## Findings categories

### (1) External I/O inside transactions

- `Http::post(...)`, `Http::get(...)`, any `Http::` facade call inside a `DB::transaction` closure or between `DB::beginTransaction` / `DB::commit`.
- Shopify SDK calls (`$shopify->graphql(...)`, `$shopify->rest->Order::all(...)`) inside transactions.
- Stripe SDK calls (`\Stripe\PaymentIntent::create(...)`, `\Stripe\Transfer::create(...)`) inside transactions — especially severe because Stripe calls can take 5–30s under degraded conditions.
- Square / Fresha SDK calls inside transactions.
- Direct `curl_exec` / `file_get_contents('http://...')` inside transactions.
- Canonical fix: fetch external data BEFORE the transaction, pass the result into the closure; perform external state changes AFTER the transaction commits, with compensating logic if they fail.

### (2) Queue dispatches inside transactions

- `dispatch(new SomeJob(...))` inside `DB::transaction(...)`.
- `SomeJob::dispatch(...)` inside `DB::transaction(...)`.
- `Bus::dispatch(...)`, `Bus::dispatchSync(...)`, `Bus::dispatchAfterResponse(...)` inside transactions.
- Job dispatch in observers without `DB::afterCommit` wrapping.
- Canonical fix: `DB::afterCommit(fn() => SomeJob::dispatch(...))` inside the transaction, OR move the dispatch outside the `DB::transaction` block.
- For Laravel 8+: confirm `after_commit => true` is NOT set at the queue config level as a blanket workaround — the per-call discipline is preferable because it's auditable in the code.

### (3) Cache writes inside transactions

- `Cache::put(...)`, `Cache::remember(...)` (the write path of remember), `Cache::forget(...)`, `Cache::flush(...)` inside `DB::transaction(...)`.
- `CacheLockService::rememberLocked(...)` calls inside transactions — the closure's write path commits to cache regardless of outer transaction outcome.
- Version-token bumps (`Cache::increment('analyticsSummaryVersion')`) inside transactions.
- Cache invalidation in observers without `DB::afterCommit` wrapping.
- Canonical fix: `DB::afterCommit(fn() => $cache->forget($key))`. For reads, route through the cache outside the transaction; transactions read from the DB directly.

### (4) Observers running side effects on intra-transaction saves

- Observers on `creating` / `updating` / `saving` events that do anything other than mutate the model's attributes.
- Observers on `created` / `updated` / `saved` events that dispatch jobs / write cache / send notifications WITHOUT `DB::afterCommit`.
- Observers that call services with their own transactions (nested transaction risk — see category 9).
- Observers that hit external APIs (any `Shopify*Service`, `Stripe*Service` call from an observer) — these execute synchronously inside whatever transaction the saver is in.
- Canonical fix: move side effects to `*ed` hooks AND wrap in `DB::afterCommit`. If the side effect must happen before save (e.g. validation), reframe as a Form Request rule or service-layer guard, not an observer.

### (5) Cache invalidation timing (the `*ing` vs `*ed` bug)

- Observer `updating(Model $m)` that calls `$cacheService->invalidate(...)` — runs before commit, reader can re-warm with old value.
- Observer `creating(Model $m)` that bumps a version token — readers between bump and commit see new version pointing at non-existent row.
- Service-layer code that calls `Cache::forget(...)` followed by `$model->save()` — invalidation before mutation has the same race.
- Canonical fix: invalidate AFTER the DB commit, via `created` / `updated` observer + `DB::afterCommit`, or service-layer ordering with explicit `afterCommit`.

### (6) Transaction scope too coarse

- `DB::transaction(fn() => { /* fetch + compute + mutate + dispatch + log */ })` blocks where the fetch and compute can happen outside.
- Transactions wrapping loops that include external I/O per iteration (a 100-item loop with a Shopify call each iteration = 100× the lock-and-connection cost).
- Transactions wrapping entire HTTP request handling rather than the specific mutation.
- Long-running closures (visible by line count or by named external calls) — flag for splitting and confirming what actually needs atomicity.

### (7) Retry attempts on non-idempotent closures

- `DB::transaction(fn() => ..., $attempts)` with `$attempts > 1` where the closure increments a counter, appends to a log, dispatches a job, or has any non-idempotent side effect.
- Default deadlock retry behaviour assumed without confirming the closure is safe.
- Canonical fix: either make the closure idempotent (upserts, key-based skip), or use `$attempts = 1` and handle deadlocks at a higher level with explicit retry logic.

### (8) Nested transactions / unintended SAVEPOINT semantics

- Service method A calls service method B; both wrap their work in `DB::transaction`. B becomes a SAVEPOINT inside A's transaction — A's rollback also rolls back B, which may or may not be intended.
- Tests using `DatabaseTransactions` trait combined with code that uses `DB::transaction` — confirm the production semantics aren't different from test semantics.
- Calls into third-party packages from inside transactions where the package internally opens its own transaction.
- Canonical fix: explicit `DB::transactionLevel()` check, or pass an `inTransaction` flag, or restructure so only the outermost caller wraps in a transaction.

### (9) Lock ordering inside transactions

- `lockForUpdate()` / `sharedLock()` taken in inconsistent order across services for the same row pair — deadlock risk under concurrent webhooks.
- Pessimistic locks on a wide query (`Order::lockForUpdate()->get()` without `where`) — locks the world.
- `SELECT FOR UPDATE` followed by external I/O — the lock is held during the network call.
- Canonical fix: lock in canonical order (e.g. always by ID ascending), narrow the lock scope, never hold a lock across external I/O.

### (10) Event dispatch with side-effecting listeners inside transactions

- `Event::dispatch(new SomethingHappened(...))` inside `DB::transaction` where a registered listener calls a service / dispatches a job / writes cache / hits external API.
- `event(...)` helper calls inside transactions with non-queued listeners.
- Listener registered on a model event that has its own transaction — see also category 8.
- Canonical fix: queue the listener (`ShouldQueue`), OR dispatch the event after commit, OR move the side effect to an explicit service call gated by `DB::afterCommit`.

## Per-finding requirements

For every finding:
- Cite the category number (1–10).
- Default tier: **P0** for external I/O inside transactions on financial paths (Stripe / payout / commission code) — connection-pool exhaustion is a hard outage. **P1** for queue dispatch / cache write inside transactions (silent data drift on rollback or Redis failure), and for observer side effects without `afterCommit`. **P2** for coarse transaction scope, retry-on-non-idempotent, nested-transaction surprises. **P3** for lock-ordering hygiene unless deadlock is observed.
- Quote verbatim evidence: the `DB::transaction(...)` opening line, the offending call inside, and the closing brace or `return` line.
- Name the canonical fix: `DB::afterCommit(fn() => ...)`, move the external call before/after the transaction, switch observer hook from `*ing` to `*ed`, narrow transaction scope, queue the listener.
- Identify whether the bug surfaces in tests: if the only test is happy-path with no rollback / no Redis-down simulation, note that — these bugs hide in green CI by design.

## Out of scope — do NOT re-flag

- `DB::afterCommit` calls that ARE present and correctly placed (this is the target pattern, not a finding).
- Test-only transaction wrappers (`DatabaseTransactions`, `RefreshDatabase` traits).
- Migration files (`supabase/migrations/`) — transaction semantics there are intentional and a different concern.
- Read-only `DB::transaction` blocks used to enforce REPEATABLE READ isolation for a multi-statement read (these are legitimate; confirm they're truly read-only and skip).

## Suggested per-domain scope groups

### Group A — Financial paths (highest stakes)
```
--scope app/Services/Stripe
--scope app/Services/Billing
--scope app/Jobs/Stripe
--scope app/Models/Commerce
```

### Group B — Vendor integration services (external I/O temptation high)
```
--scope app/Services/Shopify
--scope app/Services/Square
--scope app/Services/Fresha
--scope app/Jobs/Shopify
```

### Group C — Observers (the `afterCommit` blind spot)
```
--scope app/Observers
```

### Group D — Webhook job processing
```
--scope app/Jobs/Shopify
--scope app/Jobs/Stripe
--scope app/Jobs/Square
--scope app/Jobs/Fresha
```

### Group E — Account lifecycle and identity
```
--scope app/Services/Accounts
--scope app/Services/Auth
--scope app/Http/Controllers/Api/Internal
```

## Exhaustiveness directive

`rg -n "DB::transaction|DB::beginTransaction"` returns every candidate site; inspect each one and emit a finding for every gold-standard property the block fails to satisfy. Three services each dispatching a job inside a transaction = three findings (`TXN-1`, `TXN-2`, `TXN-3`), not one consolidated finding. A single transaction with both a Stripe call AND a `Cache::put` inside is two findings. Walk every observer file; bare `dispatch(...)` without `DB::afterCommit` is a finding regardless of whether you can prove a current rollback path triggers it — the rollback path will exist eventually, and the bug surfaces silently. The adjudicator dedupes and re-tiers; **under-reporting is the failure mode**. The cost of these bugs in production is hours of state reconciliation; the cost of an over-flagged finding is one line in an audit.
