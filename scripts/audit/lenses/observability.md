# Observability: logging gaps, silent failures, missing Nightwatch instrumentation

Hunt **jobs that swallow exceptions silently**, **inbound callbacks that return 200 but don't process**, **missing Nightwatch coverage**, and **log calls that obscure rather than illuminate**. Silent failures are the hardest production bugs to diagnose — a green dashboard hiding a broken pipeline is worse than an obvious crash.

## Nightwatch alert model (canonical — deviations are findings)

**Nightwatch alerts fire on EXCEPTIONS and auto-detected slow jobs/routes/commands.** They do NOT fire on log queries. `Log::warning(...)` and `Log::error(...)` are breadcrumbs visible only when someone actively reads Cloud logs (`cloud env:logs partna development`). A soft failure that needs an operator alert — a job that couldn't write to Cloudflare KV, a GDPR export that silently stopped halfway — **must throw or call `$this->fail($e)`**, not just log. Any code that logs an error condition without throwing or failing is instrumenting for debugging but not for alerting. Flag every such path at P1 when the failure is consequential (irreversible work, routing correctness, user-visible errors) and P2 for lesser-impact paths.

Partna uses **Laravel Horizon** on Redis (DB 2). Queues: `default`, `moderation_high`, `notifications`, `mail`, `streaming`, `analytics`, `cloudflare`, `cache-warm`, `images`, `gdpr`. Failed jobs land in `failed_jobs` on the pgsql connection (`database-uuids` driver per `config/queue.php`). Real logs live in Laravel Cloud (`cloud env:logs partna development`); `storage/logs/laravel.log` is test-suite output only.

## Use the lens prefix `OBS` for findings

Number them `OBS-1`, `OBS-2`, … sequentially. **P1 is the default for confirmed silent failures (job swallows exception, callback 200s on processing failure). P2 for instrumentation gaps, missing audit trail on critical state transitions. P3 for hygiene (log-level mismatch, unstructured log context).**

## Findings categories

### (1) Jobs that swallow exceptions

- `try/catch` blocks in `handle()` that catch `\Throwable` or `\Exception` without calling `$this->fail($e)` — the job completes as "succeeded" in Horizon; failed-jobs counter doesn't increment; no Nightwatch alert fires.
- `catch` blocks that only `Log::error(...)` and return — logging is a breadcrumb, not an alert. A broken GDPR export or a failed KV write that logs silently is invisible to on-call monitoring.
- Jobs that return early on an error condition (invalid payload, missing record) without calling `$this->fail()` — the job is silently discarded; Horizon shows a completion, not a failure.
- `finally` blocks that suppress exceptions propagating from the `try` block.
- **Moderation jobs with `HasActionLogLifecycle` concern**: confirm this concern calls `$this->fail($e)` on exception and does not produce a false "action completed" audit-log entry for a job that actually crashed.
- **`SyncSubdomainToKvJob`**: this is the sole writer to `SUBDOMAIN_KV`. A failed KV write that logs but returns normally leaves the edge routing stale. Confirm the job throws (or calls `$this->fail($e)`) on any Cloudflare API failure rather than logging and returning.
- **`CloudflareCachePurgeJob`**: a swallowed exception means the edge cache is never invalidated. Users see stale sitepage content indefinitely with no alert. Confirm exception propagation.
- **`ExportUserDataJob` (GDPR)**: a partial export that catches and swallows an exception marks the job done but leaves the user's GDPR request in limbo. Flag any swallowed exception in GDPR jobs.
- Jobs implementing `ShouldQueue` with a `failed(Throwable $e)` cleanup method: confirm the cleanup doesn't itself throw and silence the original failure.
- Missing `$this->fail()` after a vendor API call that returns non-200 but doesn't throw (e.g. Cloudflare API called with `http_errors => false` — Guzzle returns a `Response` object instead of throwing; a check on the status code must be followed by `$this->fail()`, not just `Log::error`).

### (2) Inbound callbacks that 200-but-don't-process

- Hook controllers that catch exceptions from `Mail::queue()` or `dispatch()` and return 200 regardless — Supabase's retry mechanism is bypassed; the work never happened.
- **`SupabaseEmailHookController`**: the exception catch block correctly reverts the dedup marker (`Cache::forget`) and returns 500. Confirm this pattern is intact. If a refactor removes the `Cache::forget` before the 500 return, the retry will see the dedup key, return 200, and permanently drop the email.
- **`SupabaseAuthHookController`**: `$this->repo->record()` runs synchronously in the controller. An exception from the repository propagates — confirm no outer try/catch in the controller or middleware stack silently converts this to 200.
- Webhook/hook routes under the `throttle:webhooks` group: confirm that a 500 response from a hook controller is not swallowed and re-written to 200 by a global exception handler or middleware.
- Missing `try/catch` around `dispatch()` calls where a Redis failure would result in an uncaught exception that Laravel converts to a 500 — this is CORRECT behaviour for hooks. Flag any catch that converts it to 200.

### (3) Missing or wrong Nightwatch instrumentation

- Long-running artisan commands without a `$timeout` property or execution time annotation — slow commands are invisible to Nightwatch auto-detection.
- Jobs that do not define `$timeout` and perform vendor I/O (`TwitchApiClient`, `KickApiClient`, `CloudflareKvService`, `CloudflarePurgeService`) — Nightwatch cannot flag them as "slow" without a baseline; a hung HTTP call stalls the worker.
- **`CheckStreamingLiveStatusJob`**: polling job for Twitch/Kick live status. Confirm `$timeout` is set; a hung streaming API call will hold a worker thread with no visibility.
- **`ProcessVideoVariantsJob`**: video processing is inherently long-running (separate `redis_video` connection). Confirm `$timeout` reflects the actual processing budget; a hung ffmpeg call with no `$timeout` is invisible to Nightwatch.
- Jobs that fan out heavy work without breaking into smaller dispatchable chunks — Nightwatch sees one very long job rather than many trackable short jobs.
- Routes under `app/Http/Controllers/Api/Platforms` that call `PlatformRefresher` or individual scrapers synchronously on the request thread — slow scrape calls are invisible as route-level slowness unless the work is dispatched to a job.
- `SafeUrlFetcher` calls in synchronous paths (not inside jobs) that can block for seconds — these inflate route response times with no Nightwatch attribution.

### (4) Log calls that obscure rather than illuminate

- `Log::info(...)` on error paths — severity mismatch misleads triage.
- `Log::error(...)` without structured context (`['job' => ..., 'user_id' => ..., 'error' => $e->getMessage(), 'trace' => ...]`) — unstructured strings are unsearchable in Cloud logs.
- `Log::warning` on a failure path that actually needs operator attention (e.g. `bot_protection.fail_open` when the captcha backend is down, `Idempotency middleware failing open` on Redis failure) — these log as breadcrumbs but never trigger an alert. They are appropriate for transient/expected degradations, but flag any case where a sustained failure of this kind would be invisible to on-call.
- Duplicate log calls for the same event in both a controller AND a service — log inflation without clarity.
- `Log::debug(...)` statements left in production paths without a `config('app.debug')` / `app()->isLocal()` guard — these pollute Cloud logs in production.
- `PiiLogHygieneSweepTest` enforces that PII fields (email, IP, name) don't appear in log calls — reference this as CI-covered; do not re-flag PII log hygiene as a new finding.

### (5) Queue configuration and failure visibility

- Jobs dispatched to `default` when they should be on a dedicated queue (`gdpr`, `moderation_high`, `notifications`, `analytics`, `cloudflare`, `cache-warm`, `images`, `streaming`). A GDPR deletion job on `default` is starved behind analytics ingest — this is both a correctness risk (SLA on GDPR) and an observability gap (failure is attributed to a generic queue).
- `failed_jobs` table: `config/queue.php` uses `database-uuids` driver on `pgsql` connection — correct for a multi-server PostgreSQL environment. Do NOT flag this as the SQLite default speculation; the config is verified. Flag only if you find evidence of the `file` driver or the table missing from the baseline migration.
- Missing `Queue::failing()` / `Queue::after()` hooks for critical queues (`gdpr`, `moderation_high`) — no alert on job failure for irreversible or safety-critical work. Horizon dashboards are opt-in; automated alerts on queue failure require explicit instrumentation.
- Jobs with no `$tries` set: Laravel default is 1 (no retry). For a GDPR export or KV sync that can transiently fail, 1 try with no retry is likely wrong. Flag where the default is silent rather than intentional.
- `$tries = 0` (unlimited) on non-idempotent paths — repeat-forever amplifies a bug and makes it invisible in standard dashboards.

### (6) Health-check and bootstrap failures

- `GET /internal/env-check` (`EnvCheckController`): confirm it returns a meaningful degraded status when Redis is unreachable rather than a bare 200. A health endpoint that passes even when the queue backend is down gives false confidence.
- Service constructors that catch connection failures and fall back silently — the app boots "healthy" but is partially broken (e.g. `CloudflareKvService` or a streaming client that catches a config-missing exception in the constructor and sets a `$healthy = false` flag, then silently no-ops all writes).
- Missing readiness check for Horizon — if the worker is down the app accepts requests, enqueues jobs, and the queue depth grows silently.

### (7) Critical state transitions — audit trail and log coverage

The `audit` schema is append-only and `app_backend` has SELECT/INSERT only. `ModerationAuditService` (`app/Services/Moderation/ModerationAuditService.php`) is the write path for moderation events. `StaffAuditService` + `RecordStaffAuditEntry` middleware cover staff actions. The following transitions must have both a Nightwatch-visible failure path (throw/fail) AND an audit trail:

- **Account deletion requested / cancelled**: `AccountDeletionService` (`app/Services/User/AccountDeletionService.php`) + `StaffAccountDeletionController`. Confirm: (a) a row is written to an audit table on request, (b) a failure to record the audit row throws rather than is silently skipped, (c) the confirmation email job (`SendAccountDeletionRequestMailJob`) propagates failure correctly.
- **Handle renamed**: renames write `core.user_handle_aliases` and `site.site_subdomain_aliases`. Confirm: the rename service writes an audit-trail entry and that a failure to write the alias rows throws (not just logs) — a silent failure leaves the old handle unprotected and the KV sync stale.
- **Site published / unpublished**: `SyncSubdomainToKvJob` is the downstream effect. Confirm: a failure to sync KV throws and surfaces as a Horizon failure, not a `Log::warning`.
- **Moderation decisions** (suspension, quarantine, case resolution): `ModerationAuditService::recordStaffAction()` / `recordSystemAction()` must be called for every decision. Confirm: `SuspendSiteJob`, `SuspendUserJob`, `QuarantineMediaJob` all write to the audit trail before or after the effect, and that a failure to write the audit trail is not silently swallowed.
- Missing audit trail for system-initiated events (e.g. auto-suspension triggered by a job) where only staff-initiated events are currently recorded — flag the gap.

## Per-finding requirements

For every finding:
- Cite the category number (1–7).
- Default tier: **P1 for confirmed silent failure** (categories 1–2), **P2 for instrumentation and audit-trail gaps** (categories 3, 5–7), **P3 for hygiene** (category 4 log-level mismatch, unstructured context).
- Quote verbatim evidence (the `catch` block, the dispatch/queue call, the missing `$this->fail()`, the log call without context).
- Name the canonical fix: `$this->fail($e)`, structured `Log::error(..., ['context' => ...])`, `->onQueue('gdpr')` / `->onQueue('moderation_high')`, `$timeout`, `ModerationAuditService::recordSystemAction()`, `Queue::failing()` hook.

## Suggested per-domain scope groups

### Group A — Jobs (highest priority)
```
--scope app/Jobs/Gdpr
--scope app/Jobs/Cloudflare
--scope app/Jobs/Moderation
--scope app/Jobs/Notifications
--scope app/Jobs/Streaming
--scope app/Jobs/Account
--scope app/Jobs/ProcessImageVariantsJob.php
--scope app/Jobs/ProcessVideoVariantsJob.php
--scope app/Jobs/DeleteMediaArtifactsJob.php
```

### Group B — Inbound callback controllers
```
--scope app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php
--scope app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php
--scope app/Http/Controllers/Api/Internal
```

### Group C — Vendor I/O services
```
--scope app/Services/Cloudflare
--scope app/Services/Streaming
--scope app/Services/Platforms
--scope app/Services/Media
```

### Group D — Audit services
```
--scope app/Services/Audit
--scope app/Services/Moderation/ModerationAuditService.php
--scope app/Http/Middleware/Logging/RecordStaffAuditEntry.php
```

### Group E — Queue and health configuration
```
--scope config/queue.php
--scope config/horizon.php
```

### Group F — Ingest runtime + routing probes
```
--scope app/Ingest/Runtime
--scope app/Ingest/Projection
--scope app/Ingest/Support
--scope app/Routing
--scope app/Content
--scope app/Site
```
## Exhaustiveness directive

Walk every job file. Every `try/catch` in a `handle()` method is a candidate finding. Every inbound callback controller that returns 200 must be traced through to confirm the processing path cannot silently fail. Every critical state transition (account deletion, handle rename, moderation decision) must have a verifiable audit trail write and a verifiable failure path that surfaces to Nightwatch. Under-reporting here means production incidents with no paper trail — a moderation suspension that silently failed is a safety gap, a KV sync that silently failed is a routing outage.
