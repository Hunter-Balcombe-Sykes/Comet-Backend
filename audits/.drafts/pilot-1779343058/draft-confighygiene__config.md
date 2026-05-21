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
