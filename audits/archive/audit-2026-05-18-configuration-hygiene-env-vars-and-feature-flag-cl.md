# Configuration Hygiene, Env Vars & Feature Flag Cleanup Audit — 2026-05-18

**Branch:** development
**Lens:** configuration hygiene env vars and feature flag cleanup
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- config/partna.php
- app/Services/FeatureFlags/FeatureFlagService.php
- app/Services/FeatureFlags/OverrideScope.php
- app/Http/Middleware/FeatureGate.php
- app/helpers.php
- composer.json

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 2 complete

---

`★ Insight ─────────────────────────────────────`
**Two of DeepSeek's four draft findings were dropped after verification:**
- **CFG-1 (square_sync/fresha_sync stale):** Grep confirms `feature:square_sync` and `feature:fresha_sync` middleware is still live on eight routes in `routes/api/professional.php` and `routes/api/staff.php`, and `FreshaServiceSyncService` / `SquareServiceSyncService` still reference these gates. The config entries are not stale.
- **CFG-2 (grace_period_days zero callers):** `VoidExpiredPayoutsJob:79` and `CommissionPayoutService:67` both call `config('partna.store.grace_period_days', 60)` as an intentional fallback. A test file (`GracePeriodConfigSplitTest.php`) specifically exercises this fallback path. Removing the key would break both callers.
`─────────────────────────────────────────────────`

## P3 — Nice to have

- [ ] **#CFG-1** · P3 — `composer.json` still identifies as `laravel/laravel` skeleton
    - **Where:** composer.json:3–4
    - **Affects:** Dependency auditing tools (Dependabot, private Packagist, CI release pipelines) that key off the `name` field. No runtime impact.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Change `"name": "laravel/laravel"` to `"partna/partna"` or `"sidest/partna-api"`.
        - Update `"description"` from `"The skeleton application for the Laravel framework."` to a project-accurate summary.
    - **Technical:** Composer's `name` field is the package identifier used by tooling. It doesn't affect autoloading or runtime but would collide with the canonical `laravel/laravel` package if this project were ever published or indexed by a private registry. Dependabot's dependency graph also uses it to label the root package in security advisories.
    - **Plain English:** The nameplate on the office door still says "Model Home — Construction Company" after you've been running your real business there for months. It doesn't stop customers getting in, but delivery drivers get confused and it looks unfinished to anyone who checks.
    - **Evidence:**
        ```json
        {
            "$schema": "https://getcomposer.org/schema.json",
            "name": "laravel/laravel",
            "type": "project",
            "description": "The skeleton application for the Laravel framework.",
        ```

- [ ] **#CFG-2** · P3 — Unprefixed env vars create a silent misconfiguration surface for ops
    - **Where:** config/partna.php (multiple locations — `form_timing`, `notifications`, `gdpr`, root-level retention keys, all `cache.ttls` entries)
    - **Affects:** Operators configuring the app who expect all Partna env vars to follow the `PARTNA_*` pattern. Setting `PARTNA_FORM_TIMING_MIN_MS` is silently ignored; the code reads bare `FORM_TIMING_MIN_MS`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Standardise the outliers to use the `PARTNA_X / SIDEST_X / default` triple-fallback pattern already established for the majority of keys.
        - Keys to migrate: `FORM_TIMING_MIN_MS`, `FORM_TIMING_MAX_MS`, `NOTIFICATIONS_EMAIL_ENABLED`, `GDPR_QUEUE`, `SOFT_DELETE_RETENTION_DAYS`, `ANALYTICS_RAW_EVENT_RETENTION_DAYS`, `COMMERCE_PROJECTIONS_TTL_SECONDS`, and all `CACHE_TTL_*` vars.
        - Keep the bare-name read as a middle fallback (between the `PARTNA_` read and the hardcoded default) during any migration window where existing deployments may still set the old name.
        - Update `.env.example` to use the prefixed names.
    - **Technical:** The project uses a consistent `env('PARTNA_X', env('SIDEST_X', $default))` triple-fallback pattern for the rebrand migration. Roughly ten keys break this pattern and use bare env names. An operator who applies the pattern mentally will set `PARTNA_CACHE_TTL_PUBLIC_PAYLOAD=600` and see no effect — the code reads `CACHE_TTL_PUBLIC_PAYLOAD`. This "shadow config" antipattern is benign at zero customers but becomes an incident trap as ops muscle-memory builds around the `PARTNA_*` namespace.
    - **Plain English:** Most dials in the control room have a "PARTNA" label. About ten don't — they're just bare numbers. A new ops person who learns "everything starts with PARTNA" will label those unlabelled dials themselves, but the labels won't be connected to anything. The fix is to wire them up properly so the labels actually work.
    - **Evidence:**
        ```php
        // Majority pattern — prefixed:
        'streaming' => [
            'max_live_check_per_site' => (int) env('PARTNA_STREAMING_MAX_LIVE_CHECK_PER_SITE', env('SIDEST_STREAMING_MAX_LIVE_CHECK_PER_SITE', 5)),
        ],

        // Outliers — no PARTNA_ prefix:
        'form_timing' => [
            'min_ms' => (int) env('FORM_TIMING_MIN_MS', 2500),
            'max_ms' => (int) env('FORM_TIMING_MAX_MS', 43200000),
        ],
        'soft_delete_retention_days' => (int) env('SOFT_DELETE_RETENTION_DAYS', 30),
        'analytics_raw_event_retention_days' => (int) env('ANALYTICS_RAW_EVENT_RETENTION_DAYS', 90),
        'notifications' => [
            'email_enabled' => (bool) env('NOTIFICATIONS_EMAIL_ENABLED', false),
        ],
        'gdpr' => [
            'queue' => env('GDPR_QUEUE', 'gdpr'),
        ],
        'cache' => ['ttls' => [
            'public_payload' => (int) env('CACHE_TTL_PUBLIC_PAYLOAD', 900),
            'analytics_short' => (int) env('CACHE_TTL_ANALYTICS_SHORT', 300),
            // ... all CACHE_TTL_* keys similarly unprefixed
        ]],
        ```
