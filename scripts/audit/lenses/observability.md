# Observability: logging gaps, silent failures, missing Nightwatch instrumentation

Hunt **jobs that swallow exceptions silently**, **webhooks that return 200 but don't process**, **missing Nightwatch coverage**, and **log calls that obscure rather than illuminate**. Silent failures are the hardest production bugs to diagnose — a green dashboard hiding a broken pipeline is worse than an obvious crash.

Partna uses **Laravel Nightwatch** for exception tracking and slow-route/job/command/task detection. Jobs run via **Laravel Horizon** on Redis. Webhooks from Shopify, Stripe, and Square must be both verified and processed — a 200 on a failed job dispatch is a silent failure.

## Use the lens prefix `OBS` for findings

Number them `OBS-1`, `OBS-2`, … sequentially. **P1 is the default for confirmed silent failures (job swallows exception, webhook 200s on dispatch failure). P2 for missing instrumentation gaps.**

## Findings categories

### (1) Jobs that swallow exceptions

- `try/catch` blocks in `handle()` that catch `\Throwable` or `\Exception` without calling `$this->fail($e)` — the job completes as "succeeded" and Horizon shows no failure.
- `catch` blocks that only `Log::error(...)` — Nightwatch won't surface it as a job failure; it won't trigger alerts; it won't increment the failed-jobs counter.
- Jobs that return early on error conditions without `$this->fail()` — silent no-op.
- `finally` blocks that suppress exceptions from the `try` block.
- Jobs implementing `ShouldQueue` that have no `failed(Throwable $e)` method when one is needed for cleanup (e.g. releasing locks, rolling back side-effects).

### (2) Webhooks that 200-but-don't-process

- Webhook controllers that catch exceptions from `dispatch()` or service calls and return 200 regardless — the vendor retries stop but the work never happened.
- Webhook controllers that dispatch a job synchronously in tests but the job constructor throws — verify the dispatch path under failure.
- Webhooks that validate HMAC, return 200, then fail silently in the processing path — the vendor's retry mechanism is bypassed.
- Missing `try/catch` around `dispatch()` calls where a Redis failure would result in a 500 returned to the vendor — vendors often stop retrying on 5xx after N attempts.

### (3) Missing or wrong Nightwatch instrumentation

- Long-running commands (`artisan`) without `#[ListensTo(CommandExecuted::class)]` or equivalent — slow commands invisible to Nightwatch.
- Slow jobs that don't have a `$timeout` property set — Nightwatch can't flag them as "slow" without a baseline.
- Routes that should be monitored (payment callbacks, OAuth flows) without any performance baseline — consider `->withoutExceptionHandling()` in tests as a proxy check.
- Jobs that fan out heavy work (catalog sync, bulk notifications) without breaking into smaller dispatchable chunks — Nightwatch sees one 10-minute job instead of 100 trackable 6-second jobs.

### (4) Log calls that obscure rather than illuminate

- `Log::info(...)` on error paths — severity mismatch misleads triage.
- `Log::error(...)` without structured context (`['job' => ..., 'professional_id' => ..., 'error' => $e->getMessage()]`) — unstructured strings are unsearchable in Nightwatch.
- Duplicate log calls for the same event in the controller AND the service — log inflation without clarity.
- `Log::debug(...)` statements left in production paths (not behind a feature-flag or `app()->isLocal()` guard).
- Missing log calls on critical state transitions: payout settled, affiliate commission reversed, brand disconnected — no audit trail.

### (5) Queue configuration and failure visibility

- Jobs dispatched to the `default` queue when they should be on a dedicated queue (`commerce`, `notifications`, `video`) — priority inversion under load.
- Jobs with no `$tries` or `$backoff` set — Laravel defaults (3 tries, no backoff) may be wrong for idempotent vs non-idempotent work.
- Missing `onQueue(...)` call on time-sensitive jobs dispatched alongside bulk jobs — starvation risk.
- `Queue::failing()` / `Queue::after()` hooks missing for queues that process financial work — no alert on job failure for payouts, commission movements.
- `failed_jobs` table: confirm it exists and is not the SQLite default in a multi-server environment.

### (6) Health-check and bootstrap failures

- `php artisan about` / health endpoints that return 200 even when Redis is unreachable — false-positive health.
- Service containers that catch connection failures in their constructors and fall back to a degraded mode without surfacing it — app boots "healthy" but is partially broken.
- Missing readiness checks for Horizon — if the worker is down, the app still accepts requests and queues jobs that will never run.

## Per-finding requirements

For every finding:
- Cite the category number (1–6).
- Default tier: **P1 for confirmed silent failure** (categories 1–2), **P2 for instrumentation gaps** (categories 3–5), **P3 for hygiene** (category 4 log-level mismatch).
- Quote verbatim evidence (the `catch` block, the dispatch call, the missing `$this->fail()`).
- Name the canonical fix: `$this->fail($e)`, `Log::error(..., ['context'])`, `->onQueue('commerce')`, `$tries`, `$backoff`, Nightwatch `$timeout`.

## Suggested per-domain scope groups

### Group A — Jobs (highest priority)
```
--scope app/Jobs
```

### Group B — Webhook controllers
```
--scope app/Http/Controllers/Api/Webhooks
--scope app/Http/Controllers/Api/Shopify
```

### Group C — Services with vendor I/O
```
--scope app/Services/Shopify
--scope app/Services/Stripe
--scope app/Services/Billing
```

### Group D — Queue configuration
```
--scope config/queue.php
--scope config/horizon.php
```

## Exhaustiveness directive

Walk every job file. Every `try/catch` in a `handle()` method is a candidate finding. Every webhook controller that returns 200 must be traced through to confirm the processing path can't silently fail. Under-reporting here means production incidents with no paper trail.
