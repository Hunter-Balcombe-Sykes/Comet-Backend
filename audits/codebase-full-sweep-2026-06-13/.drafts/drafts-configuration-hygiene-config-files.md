- [ ] **#CFG-1** · P2 — `FRONTEND_URL` env var read by config/app.php but missing from .env.example
    - **Where:** config/app.php:18
    - **Affects:** Any code path reading `config('app.frontend_url')` — new developers or fresh environments won't know this key exists to override it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `FRONTEND_URL=` to `.env.example` with a comment explaining it points to the frontend SPA (e.g. `https://app.partna.au`).
        - Document the relationship to `APP_URL` (which is the API/backend URL) so the two aren't confused.
    - **Technical:** config/app.php reads `env('FRONTEND_URL', 'https://partna.au')`. The key has a safe default, so the app won't break — but it's invisible to anyone setting up a new environment. Laravel's convention is that every `env()` key used in config files has a corresponding stub in `.env.example`, even if commented out. Missing keys hide configuration surface area.
    - **Plain English:** Imagine buying a house and finding an unlabeled light switch that does something useful. You want it labeled so the next person doesn't have to guess. `FRONTEND_URL` is that unlabeled switch — the house works fine without touching it, but no one knows it's there to adjust.
    - **Evidence:**
        ```php
        'frontend_url' => env('FRONTEND_URL', 'https://partna.au'),
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CFG-2** · P2 — Five vendor-service env keys consumed by config/services.php are absent from .env.example
    - **Where:** config/services.php:15,60,61,76,95,97
    - **Affects:** Slack notifications, Postmark mail transport, Cloudflare for SaaS custom domains, Apify Instagram scraper — silent null when keys are needed but undiscoverable.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add commented-out stubs to `.env.example` for: `POSTMARK_API_KEY`, `SLACK_BOT_USER_OAUTH_TOKEN`, `SLACK_BOT_USER_DEFAULT_CHANNEL`, `CLOUDFLARE_SAAS_API_TOKEN`, `APIFY_TOKEN`.
        - For `APIFY_TOKEN`: note that it's a legacy remnant (Instagram scraper pilot) and can remain commented — its presence is a discoverability aid if new code accidentally reads it.
        - For `CLOUDFLARE_SAAS_API_TOKEN`: document that it falls back to `CLOUDFLARE_API_TOKEN` when unset, and note the required Cloudflare permissions (Zone:SSL, Certificates:Edit, Zone:Read).
    - **Technical:** Laravel's config cache (`php artisan config:cache`) flattens all `env()` calls at cache-build time. If a key isn't in `.env`, it silently resolves to `null` (or its second-argument default). The problem isn't missing defaults — `services.php` handles `null` gracefully in most paths — it's that operators can't discover which knobs exist without reading the config source. `.env.example` is the canonical map of all tunable configuration.
    - **Plain English:** Your car's dashboard has blank buttons where optional features go. The features are wired up and work if you install the right module, but the blank buttons don't tell you what they do. Adding labels (commented env stubs) tells the mechanic which optional modules are available without making them trace every wire.
    - **Evidence:**
        ```php
        // config/services.php
        'postmark' => ['key' => env('POSTMARK_API_KEY')],
        'slack' => [
            'notifications' => [
                'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
                'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
            ],
        ],
        'cloudflare' => ['saas_api_token' => env('CLOUDFLARE_SAAS_API_TOKEN')],
        'apify' => ['token' => env('APIFY_TOKEN')],
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#CFG-3** · P2 — Eleven operational-tuning env keys read by config/partna.php are absent from .env.example
    - **Where:** config/partna.php (various lines — see evidence)
    - **Affects:** Ops engineers who need to tune queue routing, media GC, rate limits, GDPR windows, analytics endpoints, and handle audit retention during incidents. Keys are invisible without reading config source.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add commented-out stubs with safe-default values to `.env.example` for: `PARTNA_VISITOR_CONFIRMATION_PER_HOUR`, `PARTNA_MEDIA_LEDGER_WINDOW_DAYS`, `PARTNA_MEDIA_GC_MIN_AGE_HOURS`, `PARTNA_EXPORT_MAX_ROWS`, `PARTNA_ANALYTICS_QUEUE`, `PARTNA_MODERATION_HIGH_LANE`, `PARTNA_DESIGN_KIT_COLUMNS_VERSION`, `PARTNA_PUBLIC_ANALYTICS_ENDPOINT`, `PARTNA_GDPR_QUEUE_RETRY_AFTER`, `GDPR_EXPORT_SIGNED_URL_TTL_DAYS`, `GDPR_EXPORT_DEDUP_WINDOW_MINUTES`, `SIDEST_HANDLE_AUDIT_RETENTION_YEARS`, `SIDEST_RATE_LIMIT_PUBLIC_PROFILE_PER_MINUTE`, `SIDEST_PUBLIC_PROFILE_CACHE_TTL`, `PARTNA_CACHE_TTL_EMAIL_BRAND`.
        - Group them under the existing "Operational tuning knobs" section with the same comment style.
    - **Technical:** These keys all have safe defaults in config/partna.php (either as literals or via second-argument fallbacks), so a missing env var won't crash the app. The gap is operational: during an incident (e.g. analytics queue backing up, need to raise export row cap, GDPR sweep missing artifacts), the on-call engineer must grep config source to find the right env var name. `.env.example` is meant to be the single reference for all tunable knobs — when keys are missing, incident response is slower and error-prone.
    - **Plain English:** Your apartment has a circuit breaker panel, but half the switches are blank — no labels, no hints. When the living room lights go out, you have to flip every unlabeled switch until you find the right one. Adding labels (the missing .env.example stubs) means the next person can fix it in seconds instead of minutes.
    - **Evidence:**
        ```php
        // config/partna.php - a sample of unlisted keys
        'visitor_confirmation_per_hour' => (int) env('PARTNA_VISITOR_CONFIRMATION_PER_HOUR', 5),
        'media_orphan_sweep' => [
            'ledger_window_days' => (int) env('PARTNA_MEDIA_LEDGER_WINDOW_DAYS', 30),
            'gc_min_age_hours' => (int) env('PARTNA_MEDIA_GC_MIN_AGE_HOURS', 24),
        ],
        'analytics_queue' => ['name' => env('PARTNA_ANALYTICS_QUEUE', 'analytics')],
        'design_kit_columns_version' => (int) env('PARTNA_DESIGN_KIT_COLUMNS_VERSION', 1),
        'handle' => ['audit_retention_years' => (int) env('SIDEST_HANDLE_AUDIT_RETENTION_YEARS', 7)],
        'gdpr' => ['signed_url_ttl_days' => (int) env('GDPR_EXPORT_SIGNED_URL_TTL_DAYS', 7)],
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CFG-4** · P3 — `PARTNA_THROTTLE_ENABLED` feature flag defaults to `true` (enabled) instead of safe-off `false`
    - **Where:** config/partna.php (throttle.enabled line)
    - **Affects:** New environments — throttling is active by default. Low risk (throttling is a safety mechanism, not an unfinished feature), but inconsistent with the fail-closed pattern every other feature flag follows.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either change default to `false` and explicitly enable in production `.env`, OR add a comment justifying why this specific flag defaults to `true` (throttling is safety infrastructure, not a gated feature).
        - If changing to `false`, ensure the production `.env` on Laravel Cloud has `PARTNA_THROTTLE_ENABLED=true` before deploying.
    - **Technical:** Every other `SIDEST_*_ENABLED` / `PARTNA_*_ENABLED` flag in config/partna.php defaults to `false` (smart_booking, square_sync, fresha_sync, video_uploads, waitlist, individual_waitlist, notifications_email). This is the correct pattern: new environments should opt IN to features, not find them accidentally on. `PARTNA_THROTTLE_ENABLED` breaks this pattern by defaulting to `true`. In practice, throttling is a safety net — having it on by default is defensible — but the inconsistency means a developer scanning the config file might assume all flags are safe-off and miss that this one isn't.
    - **Plain English:** Every light switch in your house is "off" by default when you move in — except one, which starts "on." It happens to control a safety floodlight, so it's not dangerous, but it's confusing. A new resident might assume all switches start off and never check this one. Label it clearly or flip it to match the others.
    - **Evidence:**
        ```php
        'throttle' => [
            'enabled' => (bool) env('PARTNA_THROTTLE_ENABLED', env('SIDEST_THROTTLE_ENABLED', true)),
        ],
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#CFG-5** · P3 — `.env.example` contains `GDPR_REDACT_PLACEHOLDER_DOMAIN=gdpr.sidest.io` — legacy `sidest.io` domain, not `partna.au`
    - **Where:** .env.example (GDPR section)
    - **Affects:** New developers setting up environments — they'll see a legacy domain name and may wonder if the config is stale or misconfigured.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the placeholder to `gdpr.partna.au` (which matches `config/partna.php`'s default).
        - If `sidest.io` is still a valid-owned domain used in production, add a comment explaining its relationship to `partna.au`.
    - **Technical:** The config/partna.php default for `GDPR_REDACT_PLACEHOLDER_DOMAIN` is `'gdpr.partna.au'`. The `.env.example` file overrides this with `gdpr.sidest.io` — a domain from the platform's previous branding. This isn't a runtime bug (the env file is just a template), but it creates confusion: is `sidest.io` the real production domain? Is this a stale remnant? The `.env.example` should mirror the config defaults unless there's a deliberate reason to diverge.
    - **Plain English:** The instruction manual for your appliance lists a support website from the company's old name. It still works (they own both domains), but a new customer will pause and wonder if they have an outdated manual. Update it to the current name so there's no doubt.
    - **Evidence:**
        ```
        # .env.example
        GDPR_REDACT_PLACEHOLDER_DOMAIN=gdpr.sidest.io
        ```
        ```php
        // config/partna.php
        'redact_placeholder_domain' => env('GDPR_REDACT_PLACEHOLDER_DOMAIN', 'gdpr.partna.au'),
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#CFG-6** · P3 — Queue connection name env vars (`REDIS_QUEUE_CONNECTION`, `REDIS_QUEUE`, `REDIS_QUEUE_RETRY_AFTER`, `QUEUE_FAILED_DRIVER`) consumed by config/queue.php but absent from .env.example
    - **Where:** config/queue.php:42,43,46,78
    - **Affects:** Operators who need to reroute queue traffic to a different Redis connection or adjust retry_after during an incident. Keys are undiscoverable.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add commented-out stubs to `.env.example` for `REDIS_QUEUE_CONNECTION`, `REDIS_QUEUE`, `REDIS_QUEUE_RETRY_AFTER`, and `QUEUE_FAILED_DRIVER`.
        - Document the relationship between `REDIS_QUEUE_RETRY_AFTER` and the longest job `$timeout` (must exceed it by ≥60s).
    - **Technical:** These four env vars control the default Redis queue connection's behavior. They all have safe defaults in config/queue.php (`'default'`, `'default'`, `360`, `'database-uuids'`), so they won't break anything when absent. But queue configuration is the kind of thing an on-call engineer needs to tune under load — hunting through config source to find the right env var name wastes precious minutes during an incident.
    - **Plain English:** Your office thermostat has advanced settings behind a hidden menu. The temperature is fine by default, but when the building overheats on a summer day, the facilities person has to Google the manual to find the secret button combo. Put the advanced settings in the visible manual so they're one flip away.
    - **Evidence:**
        ```php
        // config/queue.php
        'redis' => [
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 360),
        ],
        'failed' => [
            'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        ],
        ```
    - `[DRAFT, confidence: 0.75]`
