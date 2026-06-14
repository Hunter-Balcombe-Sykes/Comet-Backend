# Transaction boundary correctness

Hunt every `DB::transaction` / `DB::beginTransaction` / model-save-inside-a-transaction site in the codebase and measure it against the **gold-standard transaction discipline** required for a system where the database transaction is the unit of atomic state change, nothing else. External I/O, queue dispatches, cache writes, log shipping, and notification side-effects belong **outside** the transaction or **after commit**, never inside.

The bug shape this lens catches is the one that does not surface in tests and surfaces catastrophically in production: a transaction commits the DB row, the queue dispatch inside the transaction never fires because of a Redis blip, the user sees a confirmation, the downstream job never runs. Or: the transaction rolls back on a constraint violation, but the cache was written before commit, so every reader for the next TTL serves a value that does not exist in the database. Or: an outbound HTTP call inside the transaction holds an open Postgres connection for 10–30 seconds, exhausting the connection pool under load — the hottest path (public sitepage resolution) sees cascading 500s.

There is no single "reference file" for this pattern — the gold standard is a **discipline**, codified below.

## The gold standard (what "correct" looks like here)

Every `DB::transaction(...)` / `DB::beginTransaction()` block must satisfy **all** of:

1. **No external network I/O inside the transaction.** No `Http::` facade calls to arbitrary hosts, no `CloudflareKvService` writes, no `TwitchApiClient` / `KickApiClient` calls, no platform-scraper fetches, no Resend/Postmark mail sends, no SmartLink URL fetches. These hold a Postgres connection open for the duration of the I/O — under load, this exhausts the connection pool.
2. **No queue dispatches inside the transaction.** `dispatch(...)` writes to Redis. If the transaction rolls back, the job still runs against state that no longer exists. Use `DB::afterCommit(fn() => dispatch(...))` or move the dispatch after the `DB::transaction` block returns.
3. **No cache writes inside the transaction.** `Cache::put`, `Cache::remember` (the write path), `Cache::forget`, `Cache::flush`, version-token bumps. On rollback, the cache shows state that doesn't exist in the database. Use `DB::afterCommit(fn() => $cache->forget($key))`.
4. **No event dispatches with side-effecting listeners inside the transaction.** Laravel events fire synchronously unless `ShouldQueue`. A listener that itself writes to cache, dispatches a job, or hits an external service inherits all of the above failure modes. `Event::dispatch` / `event(...)` inside a transaction is a flag.
5. **No observers with cross-resource side effects firing on intra-transaction saves.** Observers on `saving` / `updating` run inside the parent transaction. An observer that dispatches a job, invalidates a cache, or calls a service inherits the same failure modes — `created` / `updated` / `saved` observers must use `DB::afterCommit` for any non-DB side effect.
6. **No `Log::` to external sinks inside hot transactions.** Local file logging is fine; structured log shipping (Nightwatch, Sentry) over the network is an external I/O call masquerading as a log statement.
7. **Bounded transaction scope.** Transactions wrap the smallest atomic unit. A `DB::transaction(fn() => { /* 200 lines */ })` that includes setup, fetch, mutation, and dispatch logic is too coarse. Fetch first, dispatch after, transact the mutation only.
8. **Explicit deadlock-retry only where idempotent.** `DB::transaction(fn() => ..., $attempts)` with `$attempts > 1` retries on deadlock. The closure MUST be idempotent — re-running it must produce the same result. Flag every `$attempts > 1` and confirm the closure is safe to re-run.
9. **No nested transactions without intent.** Laravel auto-converts nested `DB::transaction` calls into SAVEPOINTs. This is sometimes correct (composable services) and sometimes a bug (the inner caller didn't realise it was already in a transaction and an outer rollback wipes its work). Flag nested cases and confirm intent.
10. **Connection consistency.** All writes in a transaction must use the same connection. `Model::on('other_db')->...` inside a `DB::connection('default')->transaction(...)` is not part of the transaction and will not roll back. With Supabase and the `pgsql` connection enforced by `BaseModel`, this is unlikely but worth a sweep.
11. **Observer side effects gated by `DB::afterCommit`.** Any observer hook (`created`, `updated`, `deleted`, `restored`) that dispatches a job, writes to cache, sends a notification, or hits an external service must wrap the side effect in `DB::afterCommit(fn() => ...)`. Bare `dispatch(...)` in an observer is a bug.
12. **Cache invalidation on `*ed` events, not `*ing` events.** Observers using `creating` / `updating` to invalidate caches run BEFORE the DB commits — readers between the cache forget and the commit see the OLD row, re-warm the cache with the OLD value, and the system stays stale until the next TTL or explicit bust.

## Use the lens prefix `TXN` for findings

Number them `TXN-1`, `TXN-2`, … sequentially across the whole audit, regardless of category.

## Findings categories

### (1) External I/O inside transactions

- `Http::post(...)`, `Http::get(...)`, any `Http::` facade call inside a `DB::transaction` closure or between `DB::beginTransaction` / `DB::commit`.
- `CloudflareKvService` write calls inside transactions — KV writes go over the Cloudflare API; any latency or error holds the Postgres connection open.
- `TwitchApiClient` / `KickApiClient` calls inside transactions — streaming API latency is unpredictable.
- `SafeUrlFetcher` calls (SmartLinks, platform connectors) inside transactions.
- Resend/Postmark mail sends (`Mail::send`, `Mail::queue`, Notification sends) inside transactions — even queued mail sends write to Redis, inheriting the dispatch problem.
- Direct `curl_exec` / `file_get_contents('http://...')` inside transactions.
- Canonical fix: fetch external data BEFORE the transaction, pass the result into the closure; perform external state changes AFTER the transaction commits, with compensating logic if they fail.

### (2) Queue dispatches inside transactions

- `dispatch(new SomeJob(...))` inside `DB::transaction(...)`.
- `SomeJob::dispatch(...)` inside `DB::transaction(...)`.
- `Bus::dispatch(...)`, `Bus::dispatchSync(...)`, `Bus::dispatchAfterResponse(...)` inside transactions.
- Job dispatch in observers without `DB::afterCommit` wrapping.
- Canonical fix: `DB::afterCommit(fn() => SomeJob::dispatch(...))` inside the transaction, OR move the dispatch outside the `DB::transaction` block.
- Note: `after_commit` is set to `false` on every queue connection in `config/queue.php` (lines 46, 56, 67, 79, 91, 103 — confirmed). There is NO blanket `after_commit => true` safety net. Every dispatch inside a transaction must be explicitly protected with `DB::afterCommit(fn() => ...)` in code.

### (3) Cache writes inside transactions

- `Cache::put(...)`, `Cache::remember(...)` (the write path of remember), `Cache::forget(...)`, `Cache::flush(...)` inside `DB::transaction(...)`.
- `CacheLockService::rememberLocked(...)` calls inside transactions — the closure's write path commits to cache regardless of outer transaction outcome.
- Version-token bumps (`Cache::increment(...)`) inside transactions.
- Cache invalidation in observers without `DB::afterCommit` wrapping.
- Canonical fix: `DB::afterCommit(fn() => $cache->forget($key))`. For reads, route through the cache outside the transaction; transactions read from the DB directly.

### (4) Observers running side effects on intra-transaction saves

- Observers on `creating` / `updating` / `saving` events that do anything other than mutate the model's attributes.
- Observers on `created` / `updated` / `saved` events (`BlockObserver`, `CustomerObserver`, `IntegrationConnectionObserver`, `ServiceCategoryObserver`, `ServiceObserver`, `SiteMediaObserver`, `SiteObserver`, `SmartLinkObserver`, `UserObserver`) that dispatch jobs, write cache, or send notifications WITHOUT `DB::afterCommit`.
- Observers that call services with their own transactions (nested transaction risk — see category 8).
- Observers that hit external APIs (any `CloudflareKvService`, `Http::` call from an observer) — these execute synchronously inside whatever transaction the saver is in.
- Canonical fix: move side effects to `*ed` hooks AND wrap in `DB::afterCommit`. If the side effect must happen before save (validation, uniqueness guard), reframe as a Form Request rule or service-layer guard, not an observer.

### (5) Cache invalidation timing (the `*ing` vs `*ed` bug)

- Observer `updating(Model $m)` that calls `$cacheService->invalidate(...)` or `$siteCacheService->bust(...)` — runs before commit, reader can re-warm with old value.
- Observer `creating(Model $m)` that bumps a version token — readers between bump and commit see new version pointing at non-existent row.
- Service-layer code that calls `Cache::forget(...)` followed by `$model->save()` — invalidation before mutation has the same race.
- Canonical fix: invalidate AFTER the DB commit, via `created` / `updated` observer + `DB::afterCommit`, or service-layer ordering with explicit `afterCommit`.

### (6) Transaction scope too coarse

- `DB::transaction(fn() => { /* fetch + compute + mutate + dispatch + log */ })` blocks where the fetch and compute can happen outside.
- Transactions wrapping loops that include an external I/O call per iteration — any per-item latency multiplies the lock-and-connection cost.
- Transactions wrapping entire HTTP request handling rather than the specific mutation.
- Long-running closures (visible by line count or by named external calls) — flag for splitting and confirm what actually needs atomicity.
- Account deletion / GDPR flows that wrap multi-step teardown (media deletion, KV purge, subdomain alias creation) in a single transaction — the external steps cannot roll back and should be orchestrated as a job chain, not a transaction.

### (7) Retry attempts on non-idempotent closures

- `DB::transaction(fn() => ..., $attempts)` with `$attempts > 1` where the closure increments a counter, appends to an event log, dispatches a job, or has any non-idempotent side effect.
- Default deadlock retry behaviour assumed without confirming the closure is safe.
- Canonical fix: either make the closure idempotent (upserts, key-based skip), or use `$attempts = 1` and handle deadlocks at a higher level with explicit retry logic.

### (8) Nested transactions / unintended SAVEPOINT semantics

- Service method A calls service method B; both wrap their work in `DB::transaction`. B becomes a SAVEPOINT inside A's transaction — A's rollback also rolls back B, which may or may not be intended.
- `SiteProvisioningService` calling `AccountDeletionService` or vice versa — confirm the nested transaction intent.
- Tests using `DatabaseTransactions` trait combined with code that uses `DB::transaction` — confirm production semantics are not different from test semantics.
- Canonical fix: explicit `DB::transactionLevel()` check, or pass an `$inTransaction` flag, or restructure so only the outermost caller wraps in a transaction.

### (9) Lock ordering inside transactions

- `lockForUpdate()` / `sharedLock()` taken in inconsistent order across services for the same row pair — deadlock risk under concurrent requests.
- Pessimistic locks on a wide query (`User::lockForUpdate()->get()` without a narrow `where`) — locks many rows simultaneously.
- `SELECT FOR UPDATE` followed by external I/O — the lock is held during the network call.
- Handle/subdomain rename — confirm `lockForUpdate` is taken on both the `site.sites` row and the alias table in a consistent order to prevent deadlocks when two concurrent renames race.
- Canonical fix: lock in canonical order (always by ID ascending), narrow the lock scope, never hold a lock across external I/O.

### (10) Event dispatch with side-effecting listeners inside transactions

- `Event::dispatch(new SomethingHappened(...))` inside `DB::transaction` where a registered listener calls a service, dispatches a job, writes cache, or hits an external API.
- `event(...)` helper calls inside transactions with non-queued listeners.
- Canonical fix: queue the listener (`ShouldQueue`), OR dispatch the event after commit, OR move the side effect to an explicit service call gated by `DB::afterCommit`.

## Per-finding requirements

For every finding:
- Cite the category number (1–10).
- Default tier: **P0** for external I/O inside transactions on the highest-stakes current paths (account deletion/GDPR, moderation decisions + audit writes, handle rename + alias + KV sync ordering, site publish + cache purge ordering) — connection-pool exhaustion or phantom audit state is a hard outage or compliance failure. **P1** for queue dispatch / cache write inside transactions (silent data drift on rollback or Redis failure), and for observer side effects without `afterCommit`. **P2** for coarse transaction scope, retry-on-non-idempotent, nested-transaction surprises. **P3** for lock-ordering hygiene unless a deadlock is observed.
- Quote verbatim evidence: the `DB::transaction(...)` opening line, the offending call inside, and the closing brace or `return` line.
- Name the canonical fix: `DB::afterCommit(fn() => ...)`, move the external call before/after the transaction, switch observer hook from `*ing` to `*ed`, narrow transaction scope, queue the listener.
- Identify whether the bug surfaces in tests: if the only test is happy-path with no rollback / no Redis-down simulation, note that — these bugs hide in green CI by design.

## Out of scope — do NOT re-flag

- `DB::afterCommit` calls that ARE present and correctly placed (this is the target pattern, not a finding).
- Test-only transaction wrappers (`DatabaseTransactions`, `RefreshDatabase` traits).
- Migration files (`supabase/migrations/`) — transaction semantics there are intentional and a different concern.
- Read-only `DB::transaction` blocks used to enforce REPEATABLE READ isolation for a multi-statement read (confirm they are truly read-only and skip).
- Shopify, Stripe, Square, Fresha, commerce schema, billing schema — all removed in the 2026-05-22 standalone strip.

## Suggested per-domain scope groups

### Group A — Core user + site mutation services (highest-stakes paths)
```
--scope app/Services/User
--scope app/Services/Site
--scope app/Services/Accounts
--scope app/Services/Auth
```

### Group B — Outbound I/O services (external call temptation high)
```
--scope app/Services/Cloudflare
--scope app/Services/Streaming
--scope app/Services/SmartLinks
--scope app/Services/Platforms
--scope app/Jobs/Cloudflare
--scope app/Jobs/Streaming
--scope app/Jobs/Platforms
```

### Group C — Observers (the `afterCommit` blind spot)
```
--scope app/Observers
```

### Group D — Account lifecycle, GDPR, and moderation jobs
```
--scope app/Jobs/Account
--scope app/Jobs/Gdpr
--scope app/Jobs/Moderation
```

### Group E — Internal controllers + webhook handlers
```
--scope app/Http/Controllers/Api/Internal
```

## Exhaustiveness directive

`rg -n "DB::transaction|DB::beginTransaction"` returns every candidate site; inspect each one and emit a finding for every gold-standard property the block fails to satisfy. Three services each dispatching a job inside a transaction = three findings (`TXN-1`, `TXN-2`, `TXN-3`), not one consolidated finding. A single transaction with both an `Http::` call AND a `Cache::put` inside is two findings. Walk every observer file; bare `dispatch(...)` without `DB::afterCommit` is a finding regardless of whether you can prove a current rollback path triggers it — the rollback path will exist eventually, and the bug surfaces silently. The adjudicator dedupes and re-tiers; **under-reporting is the failure mode**. The cost of these bugs in production is hours of state reconciliation; the cost of an over-flagged finding is one line in an audit.
