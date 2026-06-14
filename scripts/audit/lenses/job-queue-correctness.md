# Job/Queue Correctness: idempotency, retry safety, ShouldBeUnique, missing $this->fail(), retry storms

Hunt **non-idempotent jobs that are retried**, **missing `$this->fail()` calls**, **retry storms** (infinite retries on non-transient errors), **missing `ShouldBeUnique` / `WithoutOverlapping`** on jobs that must not run concurrently, and **job dispatch mistakes** (wrong queue, missing delay, dispatched on request thread). This is distinct from the scaling lens — the concern here is correctness under failure, not throughput.

Partna jobs run via **Laravel Horizon** on Redis (DB 2). Queues: `default`, `moderation_high`, `notifications`, `mail`, `streaming`, `analytics`, `cloudflare`, `cache-warm`, `images`, `gdpr`. Video work uses the separate `redis_video` connection. The highest-stakes jobs now are: **GDPR/account-deletion jobs** (irreversible side effects), **`SyncSubdomainToKvJob`** (the ONLY writer to `SUBDOMAIN_KV` — routing correctness for every user's public sitepage), **`CloudflareCachePurgeJob`** (edge cache coherence), **moderation jobs** (site/user suspension with real-world consequences), **notification dispatch** (dedup to avoid duplicate emails), and **media pipeline jobs** (storage artifact lifecycle).

**CI coverage note:** `tests/Feature/Queue/JobHygienePolicyTest.php` CI-enforces that every `ShouldQueue` job defines `$backoff`. Do NOT re-flag a missing `$backoff` as a finding — that is CI-covered. DO flag: wrong or flat `$backoff` (e.g. `[5, 5, 5]` on a path that should have exponential backoff), missing `$tries` / `$timeout` / `$maxExceptions` where the job's consequences make the defaults dangerous, and any backoff that doesn't match the failure characteristic of the work.

## Use the lens prefix `JOB` for findings

Number them `JOB-1`, `JOB-2`, … sequentially. **P0 for non-idempotent irreversible jobs (GDPR deletion, KV write) that retry on failure without an idempotency guard. P1 for missing `$this->fail()` on any consequential job, retry storm risk. P2 for missing `ShouldBeUnique`/`WithoutOverlapping` on concurrency-sensitive jobs, wrong queue assignment. P3 for hygiene (flat backoff on a path that warrants exponential, missing `$timeout` on vendor I/O).**

## Findings categories

### (1) Non-idempotent jobs that are retried

- Jobs that write records without checking whether the record already exists — retry after partial success creates duplicates.
- Jobs that call external APIs (Cloudflare KV write, streaming API) without checking whether the operation already completed.
- `$tries > 1` on a job whose `handle()` has no idempotency guard — every retry repeats the side-effect.
- **`SyncSubdomainToKvJob`** specifically: it is the sole writer to `SUBDOMAIN_KV`. A retry after a partial KV write must produce the same final KV state as a first run. Flag any path inside `handle()` that is not safe to run twice with the same input (double-insert into `site_subdomain_aliases`, double-expiration update, etc.).
- **GDPR/account-deletion jobs**: irreversible effects (data export, deletion confirmation emails, hard deletes) must be idempotent or gated by a status check that short-circuits retries after the first success.
- **Moderation jobs** (`SuspendSiteJob`, `SuspendUserJob`, `QuarantineMediaJob`): suspension applied twice must be a no-op, not an error or a duplicate audit-log entry.
- **Notification jobs** (`DispatchEnquiryNotificationsJob`, `SendTransactionalNotificationEmailJob`, etc.): duplicate dispatch for the same event must be prevented. Flag any path that re-dispatches without a dedup key.

### (2) Missing `$this->fail()` — silent non-failure

- `catch (\Throwable $e)` blocks that only `Log::error(...)` and then return — Horizon marks the job succeeded; failed-jobs counter doesn't increment; no Nightwatch alert fires (Nightwatch alerts on exceptions and auto-detected slow jobs/routes, NOT on log queries — `Log::error` alone is invisible to alerting).
- `catch` blocks that re-throw a different exception type but lose the original stack trace — makes root-cause analysis impossible.
- Jobs that validate their payload in `handle()` and return early on invalid input without calling `$this->fail()` — the job is silently discarded.
- Jobs with a `failed(Throwable $e)` method that performs cleanup but does not propagate the failure back to Horizon correctly — the cleanup runs but the job may not be marked failed.
- Moderation jobs with `HasActionLogLifecycle` concern: confirm the concern calls `$this->fail($e)` on exception and doesn't swallow it to produce a false "action completed" log entry.
- Missing `$this->fail()` after a vendor API call that returns a non-200 but doesn't throw (e.g. Cloudflare API returning a 4xx with `http_errors => false`).

### (3) Retry storms on non-transient errors

- `$tries` set high (10+) for jobs that can encounter permanent failures (user not found, Cloudflare API key revoked, KV namespace missing).
- Missing `$maxExceptions` — a job that throws on every attempt will exhaust `$tries` over a long window; `$maxExceptions` short-circuits faster and is appropriate for jobs whose errors are always exceptions.
- No distinction between transient errors (network timeout → retry with backoff) and permanent errors (missing resource → `$this->fail()` immediately) — everything retried uniformly.
- Wrong or flat `$backoff` value where exponential backoff is warranted (Cloudflare API rate-limit errors, Streaming API quota; flat `[5, 5, 5]` hammers the vendor).
- Jobs that catch a rate-limit response and `$this->release($seconds)` with too short a delay — creates a tight retry loop.
- **`CheckStreamingLiveStatusJob`**: polling job for Twitch/Kick live status. Confirm it has a sensible `$tries` and `$backoff` so a platform outage doesn't storm the queue.

### (4) Missing `ShouldBeUnique` / `WithoutOverlapping` on concurrency-sensitive jobs

Jobs where two concurrent instances produce incorrect state:

- **`SyncSubdomainToKvJob`**: two concurrent syncs for the same user write conflicting KV entries. Should use `ShouldBeUnique` with a `uniqueId()` scoped to user/site.
- **`CloudflareCachePurgeJob`**: concurrent purges for the same URL are wasteful but not correctness-breaking — P3. Concurrent purges that include a stale-rebuild race are P2.
- **`WarmPublicSiteCacheJob`**: concurrent warm runs for the same site could produce a race on the cache store. Flag if `ShouldBeUnique` or an atomic lock is missing.
- **`AggregateCacheMetricsJob`**: aggregate writes without a uniqueness guard produce double-aggregation.
- **`CheckStreamingLiveStatusJob`**: if dispatched by a scheduler and a previous run is still in flight, two live-status writes for the same streamer race. Flag if `WithoutOverlapping` is absent.
- **`SyncCustomerMarketingOptInJob`**: per-user marketing opt-in sync — two concurrent syncs for the same customer could submit conflicting opt-in state to the mail provider.
- `ShouldBeUnique` present but `uniqueId()` returns a constant — effectively serialises ALL jobs of that class globally, which is usually wrong (flag: should be scoped to user/site id).

### (5) Jobs dispatched on the request thread or to the wrong queue

- Vendor I/O called directly in a controller instead of dispatching a job — blocks the request thread and times out under load.
- `dispatchSync()` used outside of tests or one-off artisan commands — only valid in those contexts.
- Jobs dispatched with `onQueue('default')` that belong on a dedicated queue: GDPR work on `gdpr`, moderation on `moderation_high`, notifications on `notifications`, analytics on `analytics`, Cloudflare KV/purge on `cloudflare`, image/video variants on `images`, cache warm on `cache-warm`.
- **`gdpr` queue**: irreversible deletion jobs running on `default` are starved by bulk analytics ingest — a GDPR request timing out is a regulatory concern.
- **`moderation_high` queue**: suspension jobs that land on `default` are deprioritised behind bulk work during a traffic spike — a moderation action delayed by queue depth is a safety concern.
- Missing `->delay(...)` on jobs where the upstream record may not yet be committed to the DB when the job starts (dispatch-before-commit patterns, especially in Observer `creating`/`created` hooks).

### (6) Job payload and serialization correctness (PII in Redis)

- Large Eloquent models passed as job constructor arguments — the full model is serialized to Redis; use model ID + re-fetch in `handle()`.
- Jobs that accept or serialize PII fields (email addresses, IP addresses, user agents, personal metadata) in the constructor — Redis payload is readable; GDPR scope requires that PII is not persisted unnecessarily to the queue store.
- **GDPR jobs specifically**: `ExportUserDataJob` and any account-deletion job must not serialize raw PII into the Redis payload. Use the user's UUID only; fetch what's needed from the DB in `handle()`.
- **`SendEnquiryNotificationJob` / `SendEnquiryConfirmationJob`**: enquiry objects may contain customer personal data (name, email, message) — confirm the job serializes only IDs, not the full enquiry payload.
- Closures dispatched as jobs via `dispatch(function() {...})` — closures don't survive worker restarts.
- Missing `SerializesModels` trait on jobs that accept Eloquent models — model is serialized by value, not by reference; stale data on retry if the model changes between dispatch and handle.

## Per-finding requirements

For every finding:
- Cite the category number (1–6).
- For idempotency findings (cat 1): quote the `handle()` method's write path and confirm there is no existence check or upsert guard.
- For retry findings (cat 3): quote the `$tries` and `$backoff` properties (or their absence). Do NOT re-flag a bare missing `$backoff` — CI covers it. Flag wrong/flat backoff or dangerous `$tries` only.
- For `ShouldBeUnique` findings (cat 4): quote the class declaration and confirm the interface is absent.
- For PII findings (cat 6): quote the constructor parameters and identify the PII field.
- Name the canonical fix: `ShouldBeUnique` + `uniqueId()` scoped by user/site id, `$this->fail($e)`, `$this->release($seconds)`, `$maxExceptions`, idempotency status check, `onQueue('gdpr')` / `onQueue('moderation_high')`, serialize ID not model.

## Suggested per-domain scope groups

### Group A — Irreversible / highest-stakes jobs
```
--scope app/Jobs/Gdpr
--scope app/Jobs/Cloudflare
--scope app/Jobs/Moderation
```

### Group B — Notification and media jobs
```
--scope app/Jobs/Notifications
--scope app/Jobs/ProcessImageVariantsJob.php
--scope app/Jobs/ProcessVideoVariantsJob.php
--scope app/Jobs/DeleteMediaArtifactsJob.php
```

### Group C — Analytics, cache, and streaming jobs
```
--scope app/Jobs/Analytics
--scope app/Jobs/Cache
--scope app/Jobs/Streaming
```

### Group D — Platform and account jobs
```
--scope app/Jobs/Platforms
--scope app/Jobs/Account
```

## Exhaustiveness directive

Walk every job file. Every `catch` block in `handle()` is a candidate for a missing `$this->fail()` finding. Every job with `$tries > 1` that writes to the DB, calls a vendor API, or has irreversible side effects is a candidate for an idempotency finding. Every job that operates on a per-user or per-site resource is a candidate for a `ShouldBeUnique` gap. Do not assume correctness — prove it by reading the code. Three jobs each missing an idempotency guard = three findings (`JOB-1`, `JOB-2`, `JOB-3`), not one consolidated finding. The adjudicator dedupes and re-tiers — **under-reporting is the failure mode**.
