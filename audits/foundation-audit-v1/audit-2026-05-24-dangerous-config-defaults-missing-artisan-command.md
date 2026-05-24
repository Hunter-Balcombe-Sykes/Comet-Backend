`★ Insight ─────────────────────────────────────`
CONFIG-1 is a **false positive**: `config/queue.php` shows `'connection' => env('REDIS_QUEUE_CONNECTION', 'default')` — the queue driver also defaults to the `'default'` Redis connection (DB 0), so Horizon's `'use' => 'default'` is already aligned. The `'queue'` Redis entry (DB 3) exists in `database.php` but is not used by default.

FFLAG-1 is dropped: confidence 0.6 + no real user impact (sub-second race window, existing SWR already documents the same TTL ceiling).
`─────────────────────────────────────────────────`

# Config, Listener & Feature Flag Audit — 2026-05-24

**Branch:** development
**Lens:** dangerous config defaults, missing artisan command authz, listener cascade bugs, feature-flag determinism, diagnostic info leakage
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- config/horizon.php
- config/database.php
- config/queue.php
- config/logging.php
- config/nightwatch.php
- app/Observers/Core/BlockObserver.php
- app/Services/FeatureFlags/FeatureFlagService.php
- app/Services/Diagnostics/EnvCheckService.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **CONFIG-1** · P2 — `queue.batching` and `queue.failed` fall back to `sqlite` while everything else falls back to `pgsql`
    - **Where:** config/queue.php — `batching.database` and `failed.database` both use `env('DB_CONNECTION', 'sqlite')`
    - **Affects:** Any code path that uses `Bus::batch()` and the failed-jobs table. A deploy that omits `DB_CONNECTION` from `.env` writes batch state and failed-job records to a non-existent SQLite file while Eloquent connects to PostgreSQL.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change both `env('DB_CONNECTION', 'sqlite')` fallbacks to `'pgsql'` to match the `config/database.php` primary default.
        - Add `queue.batching.database` and `queue.failed.database` to `EnvCheckService::REQUIRED` so a missing `DB_CONNECTION` is caught before deploy.
    - **Technical:** `config/database.php` reads `env('DB_CONNECTION', 'pgsql')` while `config/queue.php` reads the identical env var but with a different hardcoded fallback: `'sqlite'`. Laravel resolves each `env()` call independently — there is no shared default. A production deploy missing `DB_CONNECTION` gives you PostgreSQL for Eloquent, Redis for queue transport, but SQLite for batch bookkeeping and failed-job storage. Failures are silent: the SQLite file is never created (no writable path configured), so `Bus::batch()` callbacks never fire and failed jobs disappear without trace.
    - **Plain English:** Two back-office filing drawers — the one for "failed jobs" and the one for "batch job progress" — are labelled to use a different filing system (SQLite) than every other drawer in the office (PostgreSQL). If someone forgets to explicitly label the drawers during setup, they stay locked and work piles up invisibly behind them.
    - **Evidence:**
        ```php
        // config/queue.php
        'batching' => [
            'database' => env('DB_CONNECTION', 'sqlite'),
            'table' => 'job_batches',
        ],
        'failed' => [
            'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
            'database' => env('DB_CONNECTION', 'sqlite'),
            'table' => 'failed_jobs',
        ],
        ```
        ```php
        // config/database.php — different fallback for the same env var
        'default' => env('DB_CONNECTION', 'pgsql'),
        ```

- [ ] **CONFIG-2** · P2 — Default log level is `debug` across all channels, risking PII capture if `LOG_LEVEL` is omitted from production `.env`
    - **Where:** config/logging.php — channels `single`, `daily`, `papertrail`, `stderr`, `syslog`, `errorlog` all use `env('LOG_LEVEL', 'debug')`; config/nightwatch.php `filtering.log_level` chains the same default
    - **Affects:** Any production deploy missing `LOG_LEVEL`. `Log::debug()` calls with request context, cache keys, and query parameters write to disk and Nightwatch at debug verbosity. The `slack` channel already defaults to `'critical'` — only the file/stream channels are affected.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the fallback in every affected channel from `'debug'` to `'warning'`.
        - Add `logging.channels.single.level` (or the active `LOG_CHANNEL`) to `EnvCheckService::RECOMMENDED` so a missing `LOG_LEVEL` surfaces in the env-check report.
    - **Technical:** Laravel ships `debug` as the default log level in new installations. This codebase inherits that default unchanged. A production deploy without `LOG_LEVEL` set produces debug-level output in every configured channel — including `papertrail` and `stderr`, which ship to external log aggregators. `config/nightwatch.php` compounds this: `'log_level' => env('NIGHTWATCH_LOG_LEVEL', env('LOG_LEVEL', 'debug'))` means Nightwatch also ingests debug entries, increasing ingestion volume and payload size. The risk is configuration-error-triggered, not always-on — but the consequence (PII or secret material in external log stores) justifies hardening the default.
    - **Plain English:** The app's security camera is set to record every whisper in the room by default, rather than only recording when the alarm trips. That's fine when you're testing, but if someone forgets to turn down the sensitivity before going live, every customer request detail gets written to the long-term tape — including things you'd rather not store.
    - **Evidence:**
        ```php
        // config/logging.php — pattern repeated across 6 channel definitions
        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
        ],
        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
        ],
        ```
        ```php
        // config/nightwatch.php
        'log_level' => env('NIGHTWATCH_LOG_LEVEL', env('LOG_LEVEL', 'debug')),
        ```

- [ ] **CONFIG-3** · P2 — PostgreSQL `search_path` includes `brand`, `commerce`, and `billing` schemas removed during the standalone strip
    - **Where:** config/database.php — `'search_path' => env('DB_SEARCH_PATH', 'public,core,site,brand,commerce,notifications,analytics,billing')`
    - **Affects:** Every PostgreSQL query on a project that dropped `brand`, `commerce`, and `billing` schemas. PostgreSQL logs a notice for each missing schema in the path; if any stale objects remain, an unqualified table name could resolve against them unexpectedly.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Update the default to the post-strip schema inventory: `'public,core,site,notifications,analytics'`. (`billing` is not listed in CLAUDE.md as a current schema; `notifications` and `analytics` remain active per the architecture docs.)
        - Set `DB_SEARCH_PATH` explicitly in production `.env` so the hardcoded default is never relied on, and add it to `EnvCheckService::RECOMMENDED`.
    - **Technical:** Commits `d85af452`, `281b1676`, and `5e92aac6` deleted the brand and commerce domain code. If the corresponding PostgreSQL schemas were also dropped (or were never created on the dev Supabase project), PostgreSQL generates a `WARNING: schema "brand" does not exist, skipping` notice on every new connection. Beyond the noise, if a stale table exists in one of these schemas it could shadow the intended `core.*` or `public.*` resolution. The `billing` schema is not listed in CLAUDE.md's schema inventory and appears only as a search_path remnant. `notifications` is confirmed active.
    - **Plain English:** The app's database map still lists three demolished neighbourhoods (`brand`, `commerce`, `billing`) from before the renovation. Every new database connection briefly drives through these empty lots looking for streets that no longer exist — harmless noise today, but a latent hazard if any debris was left behind.
    - **Evidence:**
        ```php
        // config/database.php
        'search_path' => env('DB_SEARCH_PATH', 'public,core,site,brand,commerce,notifications,analytics,billing'),
        ```

---

## P3 — Nice to have

- [ ] **LISTENER-1** · P3 — BlockObserver `onBlockMutated` has independent try-catches: Redis bust success + `site->touch()` failure leaves Cloudflare edge cache stale
    - **Where:** app/Observers/Core/BlockObserver.php — `onBlockMutated()`, two sequential try-catch blocks
    - **Affects:** Public site visitors in the narrow window where `invalidateSite()` succeeds (Redis/network) but `site->touch()` fails (DB timeout/deadlock). Edge cache holds pre-mutation HTML for up to the Cloudflare `s-maxage` window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log both failures together with a combined context entry so the partial-success state is explicitly traceable in Nightwatch.
        - Optionally: call `site->touch()` first (before `invalidateSite()`), so a DB failure prevents both side-effects rather than leaving a partial state. The existing docblock says these must both run — a combined try-catch expresses that intent more explicitly than two independent ones.
    - **Technical:** `$afterCommit = true` means the Block write has already committed when this method runs. If `invalidateSite()` succeeds (busts Redis) and `site->touch()` then fails (e.g. DB lock timeout), the next request will hit origin and get fresh data from Redis — but Cloudflare's edge cache still holds the pre-mutation HTML. The `touch()` failure is logged as a standalone warning without context that the Redis bust already succeeded, making incident diagnosis harder. The failure scenario is narrow (requires a DB-layer error after a Redis-layer success on the same request) but the resulting stale state is user-visible.
    - **Plain English:** When a user updates their profile, the system does two things: clears the local fast-cache, then pings the global CDN to also refresh. These two tasks are independent — if the local clear works but the CDN ping fails, the local cache is fresh but global visitors keep seeing the old version for a few minutes. Logging both steps together (even on failure) makes it much easier to diagnose when this happens.
    - **Evidence:**
        ```php
        // app/Observers/Core/BlockObserver.php
        try {
            $this->siteCache->invalidateSite($block->site);
        } catch (\Throwable $e) {
            Log::warning('Site cache invalidation failed on block '.$action, ...);
        }

        try {
            $block->site->touch();
        } catch (\Throwable $e) {
            Log::warning('Parent site touch() failed on block '.$action, ...);
        }
        ```

- [ ] **CONFIG-4** · P3 — Nightwatch ships surrounding source code on every exception by default
    - **Where:** config/nightwatch.php — `'capture_exception_source_code' => env('NIGHTWATCH_CAPTURE_EXCEPTION_SOURCE_CODE', true)`
    - **Affects:** Every uncaught exception: surrounding PHP source lines are included in the Nightwatch ingest payload.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the default to `false`. Enable `NIGHTWATCH_CAPTURE_EXCEPTION_SOURCE_CODE=true` when actively debugging a known exception class, then revert.
    - **Technical:** With `capture_exception_source_code = true` and `NIGHTWATCH_ENABLED = true` (both defaults), every unhandled exception ships the surrounding source lines to Nightwatch. Nightwatch is trusted infrastructure, but source-code capture is a debugging convenience that increases payload size on every exception and widens the blast radius of a Nightwatch token compromise. The pre-beta rationale for leaving everything on makes sense during development; default-off with operator opt-in is the production posture.
    - **Plain English:** Every time the app crashes, it automatically photocopies a page of its own source code and mails it to the monitoring service. That service is trustworthy, but photocopying source code should be something you intentionally switch on when debugging a specific problem, not the permanent factory setting.
    - **Evidence:**
        ```php
        // config/nightwatch.php
        'capture_exception_source_code' => env('NIGHTWATCH_CAPTURE_EXCEPTION_SOURCE_CODE', true),
        ```
