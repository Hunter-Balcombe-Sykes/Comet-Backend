# Job/Queue Correctness: idempotency, retry safety, ShouldBeUnique, missing $this->fail(), retry storms

Hunt **non-idempotent jobs that are retried**, **missing `$this->fail()` calls**, **retry storms** (infinite retries on non-transient errors), **missing `ShouldBeUnique`** on jobs that must not run concurrently, and **job dispatch mistakes** (wrong queue, missing delay, dispatched on request thread). This is distinct from the scaling lens — the concern here is correctness under failure, not throughput.

Partna jobs run via **Laravel Horizon** on Redis. Jobs span vendor I/O (Shopify, Stripe, Square, Fresha), cache operations, notifications, and analytics. Financial jobs (commission movements, payout settlements) require idempotency and failure visibility — silent retries on financial operations can cause double-processing.

## Use the lens prefix `JOB` for findings

Number them `JOB-1`, `JOB-2`, … sequentially. **P0 for non-idempotent financial jobs that retry on failure. P1 for missing `$this->fail()` on any job, retry storm risk. P2 for missing `ShouldBeUnique`, wrong queue. P3 for hygiene (missing timeout, no backoff).**

## Findings categories

### (1) Non-idempotent jobs that are retried

- Jobs that insert records without checking if the record already exists — retry after partial success creates duplicates.
- Jobs that call vendor APIs (Shopify metafield write, Stripe charge) without checking if the operation already completed — double-charge, double-write risk.
- Jobs that move money or record commission without a dedup key (vendor event ID, idempotency key) — financial double-processing.
- `$tries > 1` on a job whose `handle()` has no idempotency guard — every retry repeats the side-effect.
- Jobs that create `commission_movements` or update `brand_affiliate_rollup` without confirming the source event hasn't already been processed.

### (2) Missing `$this->fail()` — silent non-failure

- `catch (\Throwable $e)` blocks that only log and then return — Horizon marks the job succeeded; failed-jobs counter doesn't increment; no alert fires.
- `catch` blocks that re-throw a different exception type but lose the original stack trace — makes root-cause analysis impossible.
- Jobs that validate their payload in `handle()` and return early on invalid input without calling `$this->fail()` — the job is gone, the problem is invisible.
- Jobs with a `failed(Throwable $e)` method that doesn't call `$this->fail($e)` — the cleanup runs but the job isn't marked failed.
- Missing `$this->fail()` after a vendor API call that returns a non-200 but doesn't throw — Guzzle with `http_errors => false` pattern is particularly prone to this.

### (3) Retry storms on non-transient errors

- `$tries` set to a high value (10+) for jobs that can encounter permanent failures (record not found, vendor account suspended) — retries are wasted and delay detection.
- Missing `$maxExceptions` property — a job that throws on every attempt will exhaust `$tries` over a long window; `$maxExceptions` short-circuits faster.
- No distinction between transient errors (network timeout → retry) and permanent errors (invalid Stripe account → fail immediately) — everything is retried uniformly.
- `$backoff` missing or set to a flat value — exponential backoff (`[5, 30, 60]`) is correct for vendor rate-limit errors; flat backoff hammers the vendor.
- Jobs that catch a rate-limit response and re-dispatch immediately instead of using `$this->release($seconds)` — creates a tight retry loop.

### (4) Missing `ShouldBeUnique` on jobs that must not run concurrently

- Catalog sync jobs (`SyncBrandCatalogJob`, `SyncAffiliateCatalogJob`) without `ShouldBeUnique` — two concurrent syncs produce race conditions on metafield writes.
- Payout settlement jobs without uniqueness guard — two concurrent payouts for the same brand result in double-payout.
- Rollup rebuild jobs without `ShouldBeUnique` — concurrent rebuilds write conflicting aggregates.
- Webhook processing jobs without per-event uniqueness — if the vendor retries before the first job completes, a second job starts on the same event.
- `ShouldBeUnique` present but `uniqueId()` returns a constant — effectively serialises all jobs of that type globally, which is usually wrong.

### (5) Jobs dispatched on the request thread (wrong dispatch path)

- Vendor I/O called directly in a controller action instead of dispatching a job — blocks the request thread and times out under load.
- `->dispatchSync()` used on jobs that should be async — only valid in tests or one-off scripts.
- `Bus::chain([...])` where one job in the chain does heavy vendor I/O without a queue — chain blocks on that step.
- Jobs dispatched with `onQueue('default')` that should be on a priority queue (`commerce`, `notifications`) — starved under bulk-queue load.
- Missing `->delay(now()->addSeconds(5))` on webhook-triggered jobs where the vendor record isn't ready yet (Shopify order create race).

### (6) Job payload and serialization correctness

- Large Eloquent models passed as job constructor arguments — the full model is serialized to Redis; use model ID + re-fetch in `handle()`.
- Closures dispatched as jobs via `dispatch(function() {...})` — closures don't survive worker restarts.
- Jobs that serialize sensitive data (tokens, secrets, PII) in the constructor — Redis payload is readable; use encrypted values or fetch from the DB in `handle()`.
- Missing `SerializesModels` trait on jobs that accept Eloquent models — model is serialized by value, not by reference; stale data on retry.
- Jobs without a `handle()` return type annotation — minor, but signals the job was not carefully designed.

## Per-finding requirements

For every finding:
- Cite the category number (1–6).
- For idempotency findings (category 1): quote the `handle()` method's write path and confirm there is no existence check.
- For retry findings (category 3): quote the `$tries` and `$backoff` properties (or their absence).
- For `ShouldBeUnique` findings (category 4): quote the class declaration and confirm the interface is absent.
- Name the canonical fix: `ShouldBeUnique` + `uniqueId()`, `$this->fail($e)`, `$this->release($seconds)`, `$maxExceptions`, idempotency key check, `onQueue('commerce')`.

## Suggested per-domain scope groups

### Group A — Financial jobs (highest priority)
```
--scope app/Jobs/Stripe
--scope app/Jobs/Analytics
```

### Group B — Shopify jobs (high retry risk)
```
--scope app/Jobs/Shopify
```

### Group C — Notification and cache jobs
```
--scope app/Jobs/Notifications
--scope app/Jobs/Cache
```

### Group D — All remaining jobs
```
--scope app/Jobs
```

## Exhaustiveness directive

Walk every job file. Every `catch` block in `handle()` is a candidate for a missing `$this->fail()` finding. Every job with `$tries > 1` that writes to the DB or calls a vendor API is a candidate for an idempotency finding. Do not assume correctness — prove it by reading the code.
