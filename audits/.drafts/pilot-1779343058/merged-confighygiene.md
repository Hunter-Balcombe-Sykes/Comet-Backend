
<!-- ═══ CHUNK: config ═══ -->

- [ ] **#CFG-1** · P1 — NIGHTWATCH_ENABLED defaults to `true`, shipping observability data without explicit opt-in
    - **Where:** config/nightwatch.php:20
    - **Affects:** Every new environment (staging, CI, ephemeral previews) — ships request/query/exception data to Nightwatch before anyone consciously enables it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change default to `env('NIGHTWATCH_ENABLED', false)` so environments must explicitly opt in.
        - Update `.env.example` to un-comment `NIGHTWATCH_ENABLED=false` as the documented default.
    - **Technical:** Category 3 — observability feature flags should be fail-closed. With `env('NIGHTWATCH_ENABLED', true)`, any environment that omits the variable (fresh deploy, CI branch) silently begins shipping payloads including request paths, query strings, exception details, and potentially PII-adjacent metadata to the Nightwatch ingest agent. The `.env.example` already shows `NIGHTWATCH_ENABLED` commented with value `true`, but the `true` default in config means commenting it out does nothing — it's already on. This is the opposite of the `captcha`, `smart_booking`, and every other feature flag in `config/partna.php`, all of which correctly default to `false`.
    - **Plain English:** Think of Nightwatch as a security camera. Right now every new copy of the app starts with the camera recording and shipping footage to a third party, even in testing environments. The other feature toggles (booking, captcha, Square sync) all start "off" until you explicitly flip them on. Nightwatch should work the same way — off by default, turned on only when you're ready.
    - **Evidence:**
        ```php
        'enabled' => env('NIGHTWATCH_ENABLED', true),
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#CFG-2** · P2 — STRIPE_PLATFORM_WEBHOOK_SECRET and STRIPE_PLATFORM_THIN_WEBHOOK_SECRET referenced in config but absent from .env.example
    - **Where:** config/services.php (lines referencing STRIPE_PLATFORM_WEBHOOK_SECRET + STRIPE_PLATFORM_THIN_WEBHOOK_SECRET); .env.example (keys absent)
    - **Affects:** New Stripe platform webhook endpoints (`/webhooks/stripe-platform` and `/webhooks/stripe-platform-thin`) — silently receive `null` as the signing secret, so signature verification either fails at runtime or uses an empty-string comparison path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `STRIPE_PLATFORM_WEBHOOK_SECRET=` and `STRIPE_PLATFORM_THIN_WEBHOOK_SECRET=` to `.env.example` with inline comments explaining they're for the destination-charge platform webhook endpoints.
        - Remove the dead `STRIPE_WEBHOOK_SECRET=` from `.env.example` — it is not referenced anywhere in `config/services.php` (the config uses the three scoped secrets: `connect_webhook_secret`, `platform_webhook_secret`, `platform_thin_webhook_secret`).
    - **Technical:** Category 2 — `config/services.php` defines three scoped Stripe webhook secrets for the new Event Destinations system (`connect_webhook_secret`, `platform_webhook_secret`, `platform_thin_webhook_secret`), but `.env.example` only has the legacy `STRIPE_WEBHOOK_SECRET` (which maps to no config key) and `STRIPE_CONNECT_WEBHOOK_SECRET`. A new operator setting up the platform webhook endpoints will find no `.env.example` entry for the two platform-scope secrets, leave them unset, and hit a 500 or silent verification bypass depending on how the controller handles `null`.
    - **Plain English:** Imagine three locked doors, each with a different key slot. The instruction card only tells you about one key. The other two doors get a blank key by default, which either jams the lock or opens it for anyone. The fix is adding the missing two key slots to the instruction card and removing an old key slot that no door uses.
    - **Evidence:**
        ```php
        // config/services.php — these two keys exist
        'platform_webhook_secret' => env('STRIPE_PLATFORM_WEBHOOK_SECRET'),
        'platform_thin_webhook_secret' => env('STRIPE_PLATFORM_THIN_WEBHOOK_SECRET'),
        ```
        ```
        // .env.example — only the legacy STRIPE_WEBHOOK_SECRET is present (dead key)
        STRIPE_WEBHOOK_SECRET=
        STRIPE_CONNECT_WEBHOOK_SECRET=
        // STRIPE_PLATFORM_WEBHOOK_SECRET and STRIPE_PLATFORM_THIN_WEBHOOK_SECRET are absent
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#CFG-3** · P2 — FRONTEND_URL referenced in config/app.php but missing from .env.example
    - **Where:** config/app.php:16; .env.example
    - **Affects:** Any code or service that reads `config('app.frontend_url')` — resolves to the hardcoded default `'https://partna.au'` in every environment unless manually added.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `FRONTEND_URL=https://partna.au` (commented or active) to `.env.example` with a comment explaining its purpose (used for CORS, email links, redirect targets).
    - **Technical:** Category 2 — `config/app.php` defines `'frontend_url' => env('FRONTEND_URL', 'https://partna.au')`, but `FRONTEND_URL` does not appear in `.env.example`. This means it's invisible to new developers; local/staging environments silently use the production URL `https://partna.au`, which could cause confusing redirects, broken CORS checks, or email links pointing to production from staging environments. The key is discoverable and defaults to a production value — both undesirable properties.
    - **Plain English:** A config value controls where "magic links" in emails point. That value defaults to the real production website. There's no entry for it in the setup instructions, so local and staging environments all point emails to the live site instead of their own. Adding it to the setup file makes it visible and lets each environment set its own target.
    - **Evidence:**
        ```php
        // config/app.php
        'frontend_url' => env('FRONTEND_URL', 'https://partna.au'),
        ```
        ```
        // .env.example — FRONTEND_URL is absent
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CFG-4** · P2 — LOG_STACK in .env.example references non-existent 'nightwatch' channel
    - **Where:** .env.example line `LOG_STACK=single,nightwatch`; config/logging.php channels list
    - **Affects:** Log delivery in any environment using the .env.example default — 'nightwatch' resolves to an undefined channel, likely causing log entries routed to that stack to be silently dropped or throw channel-not-found errors.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either remove `nightwatch` from the `LOG_STACK` value in `.env.example`, or add a `'nightwatch'` channel definition to `config/logging.php`.
        - Confirm whether Nightwatch has its own log driver that should be registered; if so, add it to `config/logging.php`.
    - **Technical:** Category 5 — `LOG_STACK=single,nightwatch` tells Laravel to build a stack channel from the `single` and `nightwatch` channels. `config/logging.php` defines `single` but has no `nightwatch` channel. Laravel will throw an exception when resolving the stack's channel list. The `.env.example` default makes this the default logging path for every new environment that copies `.env.example` verbatim, which could mean all log output is lost at startup rather than just the Nightwatch portion.
    - **Plain English:** The setup file tells the app to send logs through two pipes: one named "single" (which exists) and one named "nightwatch" (which doesn't). When the app tries to open both pipes, the missing one causes the whole logging system to jam. Every log message — errors, warnings, everything — is lost until someone fixes the pipe name.
    - **Evidence:**
        ```
        // .env.example
        LOG_STACK=single,nightwatch
        ```
        ```php
        // config/logging.php — no 'nightwatch' channel exists
        'channels' => [
            'stack' => [...],
            'single' => [...],
            'daily' => [...],
            'slack' => [...],
            'papertrail' => [...],
            'stderr' => [...],
            'syslog' => [...],
            'errorlog' => [...],
            'null' => [...],
            'emergency' => [...],
        ],
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#CFG-5** · P2 — DB_URL missing from .env.example despite being referenced in config/database.php pgsql connection
    - **Where:** config/database.php (pgsql connection — `'url' => env('DB_URL')`); .env.example
    - **Affects:** Supabase connection string deployments — `DB_URL` is the canonical way to pass a full connection string (common in cloud platforms like Heroku, Render, Laravel Cloud) and overrides individual DB_HOST/DB_PORT/etc. when set.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `# DB_URL=` (commented) to `.env.example` with a comment: "Full PostgreSQL connection string — overrides DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD when set."
    - **Technical:** Category 2 — `config/database.php` reads `env('DB_URL')` for the pgsql connection URL field. When set, Laravel's database connector uses it as the DSN, overriding all individual host/port/db/user/password values. This is the standard deployment pattern for cloud platforms injecting `DATABASE_URL`. Without it in `.env.example`, operators on platforms that use connection strings may not know the key exists and will manually decompose the string into individual vars, creating an extra step and potential for errors.
    - **Plain English:** Most cloud platforms give you a single database connection string — one long URL with everything in it. The app supports pasting that URL directly into one config variable, which then overrides the individual host/port/username fields. That variable isn't listed in the setup file, so operators on those platforms end up manually splitting the URL into five separate pieces instead of one paste.
    - **Evidence:**
        ```php
        // config/database.php — pgsql connection
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            // ...
        ],
        ```
        ```
        // .env.example — DB_URL is absent
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CFG-6** · P3 — STRIPE_API_VERSION defaults diverge between config/services.php and config/partna.php exports section
    - **Where:** config/services.php (stripe.api_version → `'2026-02-25.clover'`) vs config/partna.php (exports.commission.stripe_api_version → `'2025-02-24.acacia'`)
    - **Affects:** Stripe SDK binding — two different API version pins exist in the same codebase; the SDK client only uses one.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Audit which binding the Stripe SDK actually reads. If both paths read the same SDK binding, consolidate to a single env var or have one config key reference the other.
        - Update `.env.example` `STRIPE_API_VERSION` to match whichever is the intended canonical version.
    - **Technical:** Category 5 — `config/services.php` sets `stripe.api_version` to `'2026-02-25.clover'` while `config/partna.php` sets `exports.commission.stripe_api_version` to `'2025-02-24.acacia'` (a pre-Basil version). Both read from `env('STRIPE_API_VERSION', ...)` but supply different defaults. If the Stripe SDK client reads `config('services.stripe.api_version')`, the export config's `stripe_api_version` is dead weight; if the export code reads `config('partna.exports.commission.stripe_api_version')` instead, those exports run against an older API version than the rest of the app. The `.env.example` shows `2025-02-24.acacia` which further muddies which version is canonical.
    - **Plain English:** Two config entries both claim to control which version of Stripe's API the app uses, but they disagree on the default. One says "use the February 2026 version," the other says "use February 2025." The setup file says February 2025 too. This is like having two thermostats in one room set to different temperatures — only one actually controls the heater, but nobody knows which without tracing wires.
    - **Evidence:**
        ```php
        // config/services.php
        'stripe' => [
            'api_version' => env('STRIPE_API_VERSION', '2026-02-25.clover'),
        ],
        ```
        ```php
        // config/partna.php
        'exports' => [
            'commission' => [
                'stripe_api_version' => env('STRIPE_API_VERSION', '2025-02-24.acacia'),
            ],
        ],
        ```
        ```
        // .env.example
        STRIPE_API_VERSION=2025-02-24.acacia
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#CFG-7** · P3 — SHOPIFY_APP_HANDLE default mismatch between config/services.php and .env.example
    - **Where:** config/services.php (`'side-st-hydrogen'`) vs .env.example (`SHOPIFY_APP_HANDLE=side-st`)
    - **Affects:** New developers setting up the Shopify embedded app — the .env.example value would 404 the post-install redirect in the Shopify admin.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Align `.env.example` `SHOPIFY_APP_HANDLE` with the config default (`side-st-hydrogen`), or set it to an unambiguously placeholder value (e.g., `your-app-handle`) with a comment explaining where to find it.
    - **Technical:** Category 5 — `config/services.php` sets `shopify.app_handle` default to `'side-st-hydrogen'`, which must match the `handle` field in `shopify.app.toml`. `.env.example` shows `SHOPIFY_APP_HANDLE=side-st` — a different value. A new developer who copies `.env.example` verbatim will get `side-st` as the app handle, Shopify will try to route the app under `/store/<shop>/apps/side-st`, and the post-install redirect will 404 because the TOML file declares a different handle. The config inline comment explicitly warns about this: "Must match `handle` in Sidest-Embedded/shopify.app.toml."
    - **Plain English:** The app has a name that Shopify uses to build its admin URL. The code's fallback name is "side-st-hydrogen", but the setup file lists "side-st". If someone copies the setup file as-is, Shopify will look for the app at the wrong address and the installation will fail with a "page not found" error.
    - **Evidence:**
        ```php
        // config/services.php
        'app_handle' => env('SHOPIFY_APP_HANDLE', 'side-st-hydrogen'),
        ```
        ```
        // .env.example
        SHOPIFY_APP_HANDLE=side-st
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CFG-8** · P3 — PARTNA_GALLERY_IMAGE_MAX and PARTNA_CONTENT_IMAGE_MAX defaults in .env.example (5) don't match config defaults (6)
    - **Where:** config/partna.php (image_pools.gallery.max → 6, image_pools.content.max → 6); .env.example (PARTNA_GALLERY_IMAGE_MAX=5, PARTNA_CONTENT_IMAGE_MAX=5)
    - **Affects:** New environments that copy .env.example verbatim get 5-image limits instead of the 6-image limit the config intends as default.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Update `.env.example` to `PARTNA_GALLERY_IMAGE_MAX=6` and `PARTNA_CONTENT_IMAGE_MAX=6`, or comment them out entirely since the config default of 6 is documented in the config inline comments ("the dashboard exposes 6 slots").
    - **Technical:** Category 5 — The `.env.example` file actively overrides the config defaults to a lower value (5 vs 6). The config inline comment explains the 6-slot reasoning: "Affiliate sitepage gallery + content panels both expose 6 slots in the dashboard." Setting the `.env.example` to 5 means anyone who copies it verbatim ships a 5-slot dashboard, which contradicts the inline architectural intent and would cause frontend mismatches if the dashboard is coded to expect 6.
    - **Plain English:** The dashboard is designed to show 6 image slots for gallery and content panels. The code falls back to 6 if nothing else is specified. But the setup file explicitly sets it to 5, so anyone who copies the setup file gets 5 slots instead of 6. This is like having a 6-seat car but instructions that tell you to only use 5.
    - **Evidence:**
        ```php
        // config/partna.php
        'gallery' => ['max' => (int) env('PARTNA_GALLERY_IMAGE_MAX', env('SIDEST_GALLERY_IMAGE_MAX', 6))],
        'content' => ['max' => (int) env('PARTNA_CONTENT_IMAGE_MAX', env('SIDEST_CONTENT_IMAGE_MAX', 6))],
        ```
        ```
        // .env.example
        PARTNA_GALLERY_IMAGE_MAX=5
        PARTNA_CONTENT_IMAGE_MAX=5
        ```
    - `[DRAFT, confidence: 0.85]`

<!-- ═══ CHUNK: infra ═══ -->

- [ ] **CFG-1** · P3 — Hardcoded TTL/multiplier constants in cache‑layer services prevent operator tuning without code changes
    - **Where:** `app/Services/Cache/SiteCacheService.php:34-38` (and similar in `CacheLockService`, `FeatureFlagService`, `Listeners`)
    - **Affects:** Platform operations — adjusting cache behaviour (stale‑window duration, lock timeouts, TTL jitter) requires a code deploy and can cause brief unavailability while the fleet restarts.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract `PAYLOAD_STALE_TTL_MULTIPLIER`, `MISS_PRIMARY_TTL_SECONDS`, lock seconds, and jitter constants into `config/partna.cache` (e.g. `cache.stale_multiplier`, `cache.miss_ttl_seconds`, `cache.lock_seconds`).
        - Do the same for hardcoded TTLs in listeners (`SetTransitionBannerOnTransition`, `ToggleStripeRequirementBannerOnTransition`) and in `FeatureFlagService` (`BASE_TTL_SECONDS`, `TTL_JITTER_SECONDS`).
        - Add the new keys to `.env.example` with sensible defaults so new environments inherit the same behaviour.
    - **Technical:** These values directly control how long a stale‑while‑revalidate window lasts, how fast a cold‑miss falls back, and the jitter window that spreads cache expiry across nodes. When they’re hardcoded as class constants, an operator responding to an incident (e.g. a locked key causing payouts to appear stuck) cannot adjust them without pushing a code change. Moving them to `config/sidest.php` (backed by `env()` with defaults) lets a production engineer change the value via an environment variable and redeploy without touching application logic.
    - **Plain English:** Imagine a car whose engine timing is set by a welded‑in screw. To advance or retard it you’d have to replace the whole engine block. That’s what’s happening here — the numbers that control how long cached data stays “fresh enough” are baked into the code. If one of these numbers causes a problem (e.g. payouts look like they’re delayed because the cache won’t refresh), the only fix is for an engineer to change the code and roll out a new version of the app. Moving the numbers into a central settings file makes them adjustable from a dashboard without touching the code.
    - **Evidence:**
        ```php
        // app/Services/Cache/SiteCacheService.php:34–38
        private const PAYLOAD_STALE_TTL_MULTIPLIER = 10;
        private const MISS_PRIMARY_TTL_SECONDS = 30;

        // app/Services/Cache/CacheLockService.php:58–60
        private const STALE_TTL_MULTIPLIER = 10;

        // app/Services/FeatureFlags/FeatureFlagService.php:74–77
        private const BASE_TTL_SECONDS = 300;
        private const TTL_JITTER_SECONDS = 60;

        // app/Listeners/Accounts/SetTransitionBannerOnTransition.php:28
        private const TTL_SECONDS = 604800; // 7 days

        // app/Listeners/Accounts/ToggleStripeRequirementBannerOnTransition.php:28
        private const TTL_SECONDS = 3600;
        ```
    - `[DRAFT, confidence: 1.0]`

<!-- ═══ CHUNK: svc-prof-stripe ═══ -->

- [ ] **#CFG-1** · P2 — Hardcoded storefront reachability cache TTL and HTTP timeouts in BrandStatusService
    - **Where:** app/Services/Professional/Brand/BrandStatusService.php:279-280 and 291-294
    - **Affects:** Brand status determination (storefront_live → ready_for_affiliates). Slow or transient Shopify stores may cause false negatives, stalling brand onboarding.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the 60s / 15s cache TTLs into `config/sidest.php` under a `brand_status` key (e.g., `brand_status.storefront_reachable_cache_ttl_success`, `...failure`).
        - Move the 5s timeout and 3s connect_timeout into the same config block (`brand_status.storefront_check_timeout`, `...connect_timeout`).
        - Update the `Cache::put` and `Http::withOptions` calls to use `config('sidest.brand_status.…')`.
    - **Technical:** The `isStorefrontReachable` method hardcodes `Cache::put($cacheKey, $reachable, $reachable ? 60 : 15)` and passes `'timeout' => 5, 'connect_timeout' => 3` to `Http::withOptions`. These numbers control how long a failing storefront keeps the brand in `shopify_configured` and how long we wait for a response. In production, transient network issues or a slow Shopify proxy may need more lenient defaults; without config, tuning requires a code deployment. Moving them into `config/sidest.php` lets ops adjust them via env without touching code.
    - **Plain English:** When Partna checks if a brand’s storefront is live, it remembers a “no” answer for 15 seconds before rechecking. If the storefront is slow to respond, we give up after 3 seconds. Currently those numbers are written directly in the code, so if we ever need to be more patient (e.g., during a Shopify outage), we’d have to push new code. Putting them in a config file lets us tweak them with an environment variable change and a restart — much faster and safer.
    - **Evidence:**
        ```php
        Cache::put($cacheKey, $reachable, $reachable ? 60 : 15);
        ```
        ```php
        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'timeout' => 5,
                'connect_timeout' => 3,
            ])->get($url);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CFG-2** · P3 — Hardcoded pending payout sweep limit (500) in CommissionPayoutService
    - **Where:** app/Services/Stripe/CommissionPayoutService.php:197
    - **Affects:** Daily cron sweep that re-queues existing pending/processing payouts. With many stuck payouts, only 500 are processed per run.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the `500` limit into a config key like `partna.store.payout_pending_sweep_limit`.
        - Replace the `->limit(500)` with `->limit(config('partna.store.payout_pending_sweep_limit', 500))`.
    - **Technical:** The `processEligiblePayouts` method fetches existing pending/processing payouts with `->limit(500)->get()`. If the number of in‑flight payouts grows beyond 500, the cron only touches the first 500 each run. While each payout is dispatched as a job, the sweep itself is a single DB query; the limit caps the number of jobs created per invocation. Moving the limit to config allows scaling without a deploy.
    - **Plain English:** Every night Partna picks up any payouts that haven’t finished and retries them. Right now it only looks at the first 500 — if more than 500 are stuck, the rest have to wait until the next night. This number is baked into the code, so if we ever need to handle more at once, we’d have to change code and redeploy. Making it a config value lets us adjust it on the fly.
    - **Evidence:**
        ```php
        $existingPending = CommissionPayout::query()
            ->whereIn('status', ['pending', 'processing'])
            ...
            ->limit(500)
            ->get();
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CFG-3** · P3 — Hardcoded void chunk size (500) in CommissionVoidService
    - **Where:** app/Services/Stripe/CommissionVoidService.php:67
    - **Affects:** Nightly void of expired commissions; each chunk processes at most 500 orders.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->chunkById(500, ...)` with a configurable value from `partna.store.commission_void_chunk_size`.
    - **Technical:** `processVoidableCommissions` uses `Order::query()->...->chunkById(500, ...)`. Hardcoding 500 means every cron tick processes at most 500 orders per 30‑day window. If a tenant has 5,000 voidable orders, the cron will run 10 times before completing them. Making the chunk size configurable allows operators to increase throughput without a deploy.
    - **Plain English:** Partna’s nightly cleanup of expired commissions processes the work in batches of 500 orders at a time. If a large brand has thousands of commissions that need voiding, it takes many nights to finish. That batch size is fixed in the code, so we can’t speed it up without pushing a code change. Making it a config setting would let us increase the batch size when needed.
    - **Evidence:**
        ```php
        Order::query()
            ->where('status', 'approved')
            ...
            ->chunkById(500, function ($orders) use (&$stats) {
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CFG-4** · P3 — Hardcoded bulk invite row limit (500) in BrandAffiliateInviteService
    - **Where:** app/Services/Professional/Brand/BrandAffiliateInviteService.php:23
    - **Affects:** Brands that want to upload more than 500 affiliate invites in one CSV.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the `BULK_MAX_ROWS = 500` constant into `config/sidest.php` (e.g., `invites.bulk_max_rows`).
        - Replace the constant usage with `config('sidest.invites.bulk_max_rows', 500)`.
    - **Technical:** The service defines `private const BULK_MAX_ROWS = 500` and enforces it in `processBulkInvites`. The limit is arbitrary and may need to be adjusted per‑brand or platform‑wide. Hardcoding it means any change requires a code deployment and a PR. Storing the limit in config allows ops to raise it for a specific tenant or during a migration without touching code.
    - **Plain English:** When a brand uploads a CSV of affiliate invitations, they can include at most 500 rows. If a large brand wants to upload more, they’re stuck — the limit is written directly in the code. Putting that number in a config file means we can raise it for specific partners without changing the code or deploying.
    - **Evidence:**
        ```php
        private const BULK_MAX_ROWS = 500;
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#CFG-5** · P3 — Hardcoded Stripe Connect status cache TTL (60 seconds)
    - **Where:** app/Services/Stripe/StripeConnectService.php:17
    - **Affects:** How quickly the platform picks up Stripe account status changes (onboarding / capability activation).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `private const STATUS_CACHE_TTL = 60` with a config value like `services.stripe.connect_status_cache_ttl`.
        - Use `config('services.stripe.connect_status_cache_ttl', 60)` where the constant is referenced.
    - **Technical:** The `syncAccountStatus` method wraps the Stripe v2 account retrieve in a 60‑second cache. The TTL is hardcoded in a class constant, so operators cannot shorten it to resolve status propagation issues without a deploy. Moving it to `config/services.php` lets ops reduce the TTL via env to respond to incidents quickly.
    - **Plain English:** After a brand finishes their Stripe onboarding, Partna remembers the result for 60 seconds before checking again. That 60‑second timer is baked into the code, so if we ever need to speed it up (e.g., to fix a stuck “onboarding” status), we’d have to push new code. Making it a config value would let us change it with an environment variable.
    - **Evidence:**
        ```php
        private const STATUS_CACHE_TTL = 60;
        ```
    - `[DRAFT, confidence: 0.8]`

<!-- ═══ CHUNK: svc-commerce ═══ -->

- [ ] **#CFG-8** · P3 — BrandDesignImporter::THEME_HINTS hardcoded as private constant instead of config-driven
    - **Where:** app/Services/Shopify/BrandDesignImporter.php (THEME_HINTS private const)
    - **Affects:** Developers adding support for new Shopify themes — requires a code change and deploy instead of a config update.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the THEME_HINTS array into `config/partna.php` under a key like `shopify.theme_hints`.
        - Replace the `self::THEME_HINTS` reference with `config('partna.shopify.theme_hints')`.
    - **Technical:** The THEME_HINTS array is a data mapping (theme display name → settings_data.json key paths) that changes when Shopify releases new themes or renames keys. Hardcoding it as a PHP constant means adding a theme like "Sense" or "Refresh" requires editing a service class, running tests, and deploying — the same operational cost as a logic change. Placing it in `config/partna.php` allows a config-only deploy or even an env-driven override in a pinch. The existing `matchThemeHints()` method already provides the runtime resolution layer; only the data table needs relocation.
    - **Plain English:** Imagine a restaurant menu printed directly inside the kitchen's wiring diagram. Every time the chef adds a new dish, an electrician has to come out and rewire. The theme-hint mapping is the menu — it belongs on a chalkboard (the config file), not hardwired into the kitchen circuits.
    - **Evidence:**
        ```php
        private const THEME_HINTS = [
            'horizon' => [
                'radius' => ['buttons_radius', 'inputs_radius', 'variant_pills_radius', 'radius'],
                'thickness' => ['buttons_border_thickness', 'inputs_border_thickness', 'border_thickness'],
                'spacing' => ['spacing_sections', 'sections_spacing', 'section_spacing'],
            ],
            'dawn' => [
                'radius' => ['buttons_radius', 'inputs_radius', 'variant_pills_radius'],
                'thickness' => ['buttons_border_thickness', 'inputs_border_thickness'],
                'spacing' => ['spacing_sections', 'page_width'],
            ],
            // ... prestige, impact, impulse, generic ...
        ];
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CFG-9** · P3 — BrandDesignImporter design-enum bucket thresholds hardcoded as private constants
    - **Where:** app/Services/Shopify/BrandDesignImporter.php (RADIUS_ROUNDED_MIN, RADIUS_PILL_MIN, THICKNESS_STANDARD_MIN, THICKNESS_BOLD_MIN, SPACING_DEFAULT_MIN, SPACING_SPACIOUS_MIN)
    - **Affects:** Product/design teams wanting to tune the visual mapping of Shopify pixel values to Sidest design enums (square/rounded/pill, hairline/standard/bold, tight/default/spacious).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `design_thresholds` key under `config/partna.php` (e.g. `partna.shopify.design_thresholds`) with the six threshold values.
        - Replace `self::RADIUS_ROUNDED_MIN` etc. with `config('partna.shopify.design_thresholds.radius_rounded_min', 5)`.
    - **Technical:** The comment above these constants reads "These thresholds intentionally match the design brief — change them here and the whole app follows," acknowledging they are a tuning surface, not an implementation detail. The constants are consumed by three `bucket*()` methods (`bucketRadius`, `bucketThickness`, `bucketSpacing`). Moving them to config lets the design team adjust the pixel-to-enum mapping without a code deploy, and keeps the tuning surface discoverable in one file alongside other design-related config.
    - **Plain English:** The design team decides what counts as a "rounded" vs "pill" button by picking pixel ranges. Those numbers are currently written inside the engine. Moving them to the settings panel means the designer can tweak them without asking an engineer to open the hood.
    - **Evidence:**
        ```php
        // Radius:    0-4 = square,    5-16 = rounded,    17+ = pill
        // Thickness: 0-1 = hairline,  2-3  = standard,   4+  = bold
        // Spacing:   0-32 = tight,    33-64 = default,   65+ = spacious
        private const RADIUS_ROUNDED_MIN = 5;
        private const RADIUS_PILL_MIN = 17;
        private const THICKNESS_STANDARD_MIN = 2;
        private const THICKNESS_BOLD_MIN = 4;
        private const SPACING_DEFAULT_MIN = 33;
        private const SPACING_SPACIOUS_MIN = 65;
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CFG-10** · P3 — ShopifyCostTracker tuning constants hardcoded as private constants
    - **Where:** app/Services/Shopify/Client/ShopifyCostTracker.php (MIN_ESTIMATE, WINDOW_SIZE, EXPIRY_SECONDS)
    - **Affects:** Shopify Admin/Storefront API cost estimation accuracy. Incorrect estimates cause unnecessary local-bucket waits or premature THROTTLED exceptions.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add keys under `config/partna.php` at `partna.shopify.cost_tracker.min_estimate`, `.window_size`, `.expiry_seconds`.
        - Replace `self::MIN_ESTIMATE` with `config('partna.shopify.cost_tracker.min_estimate', 10)` and likewise for the other two.
    - **Technical:** The `MIN_ESTIMATE` (10 points) is the floor for any GraphQL query's pre-acquisition cost estimate — too high wastes budget headroom, too low risks THROTTLED responses. `WINDOW_SIZE` (20 samples) controls how many historical actual/requested cost ratios feed the sliding-window arithmetic mean. `EXPIRY_SECONDS` (86400 = 24h) governs how long stale cost samples survive in Redis. All three are operational tuning parameters, not algorithmic constants. Moving them to config lets operators adjust estimation behaviour per environment (e.g. a staging environment could use a smaller window for faster feedback) and keeps them discoverable alongside the throttle config they interact with.
    - **Plain English:** The cost tracker learns how expensive each Shopify query really is by remembering the last 20 calls. The number 20, the minimum budget it reserves, and how long it remembers — these are tuning knobs, not fixed laws of physics. Right now they're bolted to the wall. Putting them in the config file lets the team adjust them with a settings change instead of a code change.
    - **Evidence:**
        ```php
        class ShopifyCostTracker
        {
            private const MIN_ESTIMATE = 10;
            private const WINDOW_SIZE = 20;
            private const EXPIRY_SECONDS = 86400;
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#CFG-11** · P3 — AffiliateProductCatalogService::ADMIN_PRODUCTS_PER_PAGE hardcoded as private constant
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php (ADMIN_PRODUCTS_PER_PAGE = 50)
    - **Affects:** Affiliate catalog query performance and Shopify GraphQL cost budget consumption. A page size too large hits the 1000-point cost ceiling; too small causes excessive round-trips for large catalogs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a key `partna.shopify.catalog_page_size` (or `partna.store.catalog_page_size`) to `config/partna.php`.
        - Replace `self::ADMIN_PRODUCTS_PER_PAGE` with `(int) config('partna.shopify.catalog_page_size', 50)`.
    - **Technical:** The page size of 50 is a tuning compromise between Shopify's 1000-point GraphQL cost budget and HTTP round-trip overhead. It's used in both `COLLECTION_PRODUCTS_QUERY` and `ALL_PRODUCTS_QUERY` paths via the `$first` parameter. BrandCatalogService has the identical `PRODUCTS_PER_PAGE = 50` as its own private constant — if an operator changes one but not the other, the two catalog views (brand admin vs affiliate storefront) diverge in pagination behaviour. A shared config key keeps them in lockstep and makes the trade-off explicit.
    - **Plain English:** When loading a brand's product catalog, we grab 50 products at a time. That number balances speed against Shopify's rate limits. It's currently typed in two different places inside the code. Moving it to a shared settings file makes it one knob — turn it once, both places follow.
    - **Evidence:**
        ```php
        class AffiliateProductCatalogService
        {
            // ...
            private const ADMIN_PRODUCTS_PER_PAGE = 50;
        ```
        ```php
        // Same constant in BrandCatalogService:
        class BrandCatalogService
        {
            // ...
            private const PRODUCTS_PER_PAGE = 50;
        ```
    - `[DRAFT, confidence: 0.80]`

<!-- ═══ CHUNK: svc-rest-models ═══ -->

- [ ] **#CFG-1** · P3 — Hardcoded Square API version string  
    - **Where:** app/Services/Square/SquareApiClient.php (makeRequest method)  
    - **Affects:** Square integration — API version upgrades require a code change instead of a one-line config update; risk of shipping stale version in a deploy.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Add a config key like `services.square.api_version` to `config/services.php`, sourced from `SQUARE_API_VERSION` in `.env.example`.  
        - Replace the hardcoded string `'2025-10-16'` with `config('services.square.api_version')`.  
    - **Technical:** The `makeRequest` method sets the `Square-Version` header to a literal string. When Square releases a new API version, every service class that builds requests must be touched. Extracting to config centralises the version and allows environment-specific pinning (e.g., testing against a pre-release version in sandbox without a full deploy).  
    - **Plain English:** Imagine every office door has the opening hours painted on it instead of on a sign outside. When the hours change, you need to repaint every single door. Moving the version to a configuration file is like updating one sign — everyone reads from the same place, and changes happen once.  
    - **Evidence:**  
        ```php
        // SquareApiClient::makeRequest
        ->withHeaders([
            'Square-Version' => '2025-10-16',
        ])
        ```  
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CFG-2** · P3 — Hardcoded retry count in Square and Fresha API clients  
    - **Where:** app/Services/Square/SquareApiClient.php (request method, `$maxRetries = 3`) and app/Services/Fresha/FreshaApiClient.php (request method, `$maxRetries = 3`)  
    - **Affects:** System resilience — unable to tune retry behaviour per environment (e.g., fewer retries in dev for faster feedback).  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Add a config key like `services.square.max_retries` (and `services.fresha.max_retries`) to `config/services.php`.  
        - Use `config('services.square.max_retries', 3)` in the respective `request()` methods, keeping the current value as the default.  
    - **Technical:** The retry limit for 429 responses is buried inside a service class. In staging, a lower retry count may be desirable to surface rate-limit issues sooner; in production, a higher limit may be warranted. Config extraction makes this tunable per environment via `php artisan config:cache` without modifying the class.  
    - **Plain English:** Think of it like the number of times you let a vending machine retry a stuck coin before walking away. Right now that number is written on a sticky note inside the machine — only a mechanic can change it. Putting it in a configuration file lets the owner adjust it without opening the machine.  
    - **Evidence:**  
        ```php
        // SquareApiClient::request
        $maxRetries = 3;

        // FreshaApiClient::request
        $maxRetries = 3;
        ```  
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CFG-3** · P3 — Hardcoded HTTP timeouts in Square/Fresha API clients and token services  
    - **Where:** app/Services/Square/SquareApiClient.php (`->timeout(30)`), app/Services/Fresha/FreshaApiClient.php (`->timeout(30)`), app/Services/Square/SquareTokenService.php (`->timeout(20)`), app/Services/Fresha/FreshaTokenService.php (`->timeout(20)`)  
    - **Affects:** Reliability under network degradation — timeouts cannot be adjusted per environment or service without a code change.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Add config keys `services.square.http_timeout`, `services.fresha.http_timeout`, and similar for token services, defaulting to the current values.  
        - Replace all hardcoded `->timeout(N)` with `config('services.square.http_timeout', 30)` etc.  
    - **Technical:** Four service classes embed timeout seconds as integer literals inside `makeRequest()` and token refresh calls. If Square’s latency spikes, operators cannot raise the timeout quickly without a code push. Config-ifying allows runtime tuning via env variable, and keeps the pattern consistent with other configurable limits (rate limits, retry counts).  
    - **Plain English:** These numbers are like dials on a factory machine that control how long to wait before giving up. Right now the dials are glued in place — only an engineer with a screwdriver (a code deploy) can turn them. Moving them to a control panel (config) lets the operator adjust on the fly.  
    - **Evidence:**  
        ```php
        // SquareApiClient::makeRequest
        ->timeout(30)

        // FreshaApiClient::makeRequest
        ->timeout(30)

        // SquareTokenService::refreshAccessToken
        ->timeout(20)

        // FreshaTokenService::refreshAccessToken
        ->timeout(20)
        ```  
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CFG-4** · P2 — CloudflarePurgeService hardcodes the public domain `partna.au`  
    - **Where:** app/Services/Cloudflare/CloudflarePurgeService.php (purgeHandle method)  
    - **Affects:** Cache invalidation — if the public domain changes (e.g., another TLD), all handle-based purges would target the wrong URLs and edge cache would remain stale.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Read the domain from an existing config key (`partna.public_domain`) rather than the string literal `partna.au`.  
        - Update the URL construction to `"https://{$h}." . config('partna.public_domain')`.  
    - **Technical:** The `purgeHandle()` method constructs purge URLs with a hardcoded `.partna.au` suffix. The app already has a `PARTNA_PUBLIC_DOMAIN` env var and its corresponding `config('partna.public_domain')` value — AppServiceProvider even refuses to boot in production if it’s empty. Using that value here would automatically keep purge URLs in sync with any future domain migration.  
    - **Plain English:** This is like having a forwarding address written on a single whiteboard in the mailroom. If the company moves, the mailroom keeps sending packages to the wrong building until someone remembers to update that one whiteboard. Using the official company-wide address book (the config value) would fix it automatically.  
    - **Evidence:**  
        ```php
        // CloudflarePurgeService::purgeHandle
        $this->purgeUrls([
            "https://{$h}.partna.au/",
            "https://{$h}.partna.au",
        ]);
        ```  
    - `[DRAFT, confidence: 0.8]`

- [ ] **#CFG-5** · P3 — Hardcoded batch sizes and TTL tiers in LiveStatusPoller  
    - **Where:** app/Services/Streaming/LiveStatusPoller.php (constants `TWITCH_BATCH_SIZE`, `KICK_BATCH_SIZE`, `LIVE_TTL_SECONDS`, `WARM_OFFLINE_TTL`, etc.)  
    - **Affects:** Streaming live-status polling — tuning poll frequency or batch size requires a code deploy.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Move the operational constants to `config/partna.php` under a `streaming` key (e.g., `partna.streaming.poll_batch_sizes.twitch`, `partna.streaming.ttl_tiers.live`).  
        - Replace constant references with `config()` calls, keeping the current values as defaults.  
    - **Technical:** The poller uses class constants to drive tiered TTLs and API batch sizes — directly impacting API call rate and Redis key freshness. In production, you may want to raise `KICK_BATCH_SIZE` when Kick relaxes limits, or shorten `LIVE_TTL_SECONDS` for a high-priority event. Hardcoding these numbers makes experimentation risky and slow. Moving them to config allows hot-restart tuning via `php artisan config:cache`.  
    - **Plain English:** Think of it like the thermostat schedule in a building — it decides how often to check the temperature. Right now that schedule is etched into metal. Moving it to a programmable thermostat (config) lets facilities adjust it for special events without a construction crew.  
    - **Evidence:**  
        ```php
        private const TWITCH_BATCH_SIZE = 100;
        private const KICK_BATCH_SIZE = 50;
        private const LIVE_TTL_SECONDS = 180;
        private const WARM_OFFLINE_TTL = 180;
        private const COOL_OFFLINE_TTL = 600;
        private const COLD_OFFLINE_TTL = 1800;
        ```  
    - `[DRAFT, confidence: 0.9]`

<!-- ═══ CHUNK: jobs ═══ -->

- [ ] **#CFG-1** · P2 — Inconsistent Shopify API version fallback defaults across jobs
    - **Where:** Multiple files — `ReconcileStuckShopifyIntegrationsJob.php:176` vs 9 other Shopify jobs
    - **Affects:** Shopify API integration for all brands when `services.shopify.api_version` is missing from config. Different jobs would use different API versions, producing mismatched GraphQL schema expectations.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Audit all `config('services.shopify.api_version', …)` calls and align the fallback to a single value — the canonical default is `'2026-04'` (the version the reconciler already targets).
        - Add `SHOPIFY_API_VERSION=2026-04` to `.env.example` so the fallback never activates in configured environments.
    - **Technical:** Category 4. `ReconcileStuckShopifyIntegrationsJob` defaults to `'2026-04'` while `CreateShopifyCollectionsJob`, `CreateShopifyMetafieldsJob`, `CreateShopifySalesChannelJob`, `CreateStorefrontAccessTokenJob`, `CreateShopifyAffiliateDiscountJob`, `RegisterShopifyWebhooksJob`, `SetShopifySetupCompleteJob`, `SyncShopifyBrandDesignJob`, and `ProcessShopifyShopUpdateJob` all default to `'2025-01'`. If the config key is absent, the reconciler queries a different API surface than the install-chain jobs — meaning a brand re-installing during a config outage would have metafield definitions created on `2025-01` while the reconciler validates tokens against `2026-04` schema, potentially flagging healthy integrations as broken due to response shape differences.
    - **Plain English:** Think of it like a restaurant where the lunch menu and the dinner menu have the same item names but different prices. Nine chefs are cooking from the lunch menu, but the health inspector is checking against the dinner menu. Most of the time the manager provides the right menu (the config key), but if that page goes missing, chaos ensues — some dishes look wrong to the inspector even though they're perfectly fine.
    - **Evidence:**
        ```php
        // ReconcileStuckShopifyIntegrationsJob.php:176
        $apiVersion = (string) config('services.shopify.api_version', '2026-04');
        ```
        ```php
        // CreateShopifyCollectionsJob.php (and 8 other jobs)
        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CFG-2** · P3 — Shopify Hydrogen CNAME target `shops.myshopify.com` hardcoded without config indirection
    - **Where:** `app/Jobs/Cloudflare/ProvisionBrandDnsJob.php:52`
    - **Affects:** Brand DNS provisioning during OAuth install. If Shopify ever changes the Oxygen/Hydrogen hosting domain, every brand install breaks until a code deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the hardcoded `'shops.myshopify.com'` string with `config('services.shopify.hydrogen_cname', 'shops.myshopify.com')`.
        - Add `SHOPIFY_HYDROGEN_CNAME=shops.myshopify.com` to `.env.example` so ops can override it without a code change.
    - **Technical:** Category 4. `upsertCname($subdomain, 'shops.myshopify.com', false)` bakes a vendor-specific DNS target into the job body. Unlike API version strings (which have scattered fallback defaults elsewhere), this value has no config path at all — it cannot be changed without editing and deploying the job file. Every other Shopify vendor constant (API version, domain validation patterns) already routes through `config('services.shopify.*')`; this is the lone hardcoded exception in the DNS provisioning path.
    - **Plain English:** This is like having a shipping address printed directly onto the packaging machine instead of on a configurable label. If the warehouse moves, you have to rebuild the machine rather than just printing a new label. Shopify's hosting domain is stable, but if it ever changes — or if a brand is on a Shopify Plus plan with a custom domain contract — the only fix is a code deploy.
    - **Evidence:**
        ```php
        // ProvisionBrandDnsJob.php:52
        $dns->upsertCname($subdomain, 'shops.myshopify.com', false);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CFG-3** · P3 — Queue connection `redis_gdpr` hardcoded while queue name uses config
    - **Where:** `app/Jobs/Shopify/Gdpr/RedactShopJob.php:39`
    - **Affects:** GDPR shop-redact job routing. Inconsistent config pattern — connection is hardcoded, queue name is config-driven. A staging environment using a different Redis instance for GDPR jobs cannot redirect without a code change.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$this->onConnection('redis_gdpr')` with `$this->onConnection(config('partna.gdpr.connection', 'redis_gdpr'))`.
        - Add `GDPR_QUEUE_CONNECTION=redis_gdpr` to `.env.example` if not already present, mapping to a `partna.gdpr.connection` config key.
    - **Technical:** Category 4. The constructor reads `config('partna.gdpr.queue', 'gdpr')` for the queue name but hardcodes `'redis_gdpr'` for the connection. Every other GDPR job (`ExportProfessionalDataJob`, `ExportCustomerDataJob`, `RedactCustomerJob`) uses the same `config('partna.gdpr.queue')` pattern for the name but leaves the connection to the job's default — making `RedactShopJob` the outlier in both directions. The author likely copied the pattern from `DeleteMediaArtifactsJob` (which does use config for both connection and queue), but only applied it to the queue half.
    - **Plain English:** Imagine a shipping department where the destination address is on a configurable label but the shipping carrier is painted on the wall. If you need to switch carriers for a test run, you can change the label but the truck still shows up at the old dock. This job is like that — the queue name moves with the config, but the Redis connection is bolted to the wall.
    - **Evidence:**
        ```php
        // RedactShopJob.php:39
        $this->onConnection('redis_gdpr')->onQueue(config('partna.gdpr.queue', 'gdpr'));
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CFG-4** · P3 — Staff broadcast batch dispatch hardcodes `'mail'` queue name
    - **Where:** `app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:86`
    - **Affects:** Staff broadcast email delivery routing. A typo in the hardcoded string (`'mail'` vs `'email'`) would silently route sends to a queue with no workers.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->onQueue('mail')` with `->onQueue(config('partna.notifications.mail_queue', 'mail'))`.
        - Mirror the pattern already used by `FanOutBrandStatusNotificationJob` which sources its batch chunk size from `config('partna.notifications.batch_chunk_size')`.
    - **Technical:** Category 4. `SendStaffBroadcastEmailsJob` dispatches leaf batches to a hardcoded `'mail'` queue. The parent job itself uses `$this->onQueue('notifications')` (also hardcoded, but conventional for the notifications domain). The `'mail'` string appears nowhere else in config — if a deployment configures a different queue name for email workers (`'emails'`, `'transactional'`), this batch silently dispatches to an unconsumed queue and no subscriber receives the broadcast. The sibling `FanOutBrandStatusNotificationJob` already demonstrates the config-driven pattern for batch dispatch parameters.
    - **Plain English:** This is like a mailroom clerk who always writes "Mail Room" on the inter-office envelope regardless of what the company directory says. If the mailroom gets renamed to "Postal Services," the clerk's envelopes pile up in a dead drop while everyone wonders why the newsletter never arrived.
    - **Evidence:**
        ```php
        // SendStaffBroadcastEmailsJob.php:86
        $batch = Bus::batch($chunk)
            ->onQueue('mail')
            ->name('staff-broadcast:'.$notification->id)
            ->allowFailures()
            ->dispatch();
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CFG-5** · P3 — Image variant job hardcodes `'images'` queue while video counterpart uses config
    - **Where:** `app/Jobs/ProcessImageVariantsJob.php:40`
    - **Affects:** Image processing queue routing. Operator cannot redirect image variant work to a different queue without a code change, unlike video processing.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$this->onQueue('images')` with `$this->onQueue(config('partna.image_queue.name', 'images'))`.
        - Mirror the connection+queue config pattern from `ProcessVideoVariantsJob` (`config('partna.video_queue.connection')` / `config('partna.video_queue.name')`).
    - **Technical:** Category 4. `ProcessImageVariantsJob` hardcodes `$this->onQueue('images')` while its sibling `ProcessVideoVariantsJob` reads both connection and queue from `config('partna.video_queue.*')`. Image and video processing have the same architectural needs — dedicated workers, separate scaling, independent timeout configuration — but the image job entirely lacks a config-driven escape hatch. A staging environment that routes variant generation to a lower-priority queue for cost savings can do so for video but not for images.
    - **Plain English:** The video processing machine has a dial to choose which conveyor belt it feeds into. The image processing machine has the belt name stamped into the metal. They do the same kind of work (turn uploads into web-ready files), but only one of them can be reconfigured without a factory shutdown.
    - **Evidence:**
        ```php
        // ProcessImageVariantsJob.php:40 — hardcoded
        $this->onQueue('images');
        ```
        ```php
        // ProcessVideoVariantsJob.php:54-55 — config-driven
        $this->onConnection((string) config('partna.video_queue.connection', 'redis_video'));
        $this->onQueue((string) config('partna.video_queue.name', 'videos'));
        ```
    - `[DRAFT, confidence: 0.9]`
