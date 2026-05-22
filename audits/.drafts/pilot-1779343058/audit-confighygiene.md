`★ Insight ─────────────────────────────────────`
**Key adjudication decisions:**
1. CFG-4 draft (LOG_STACK nightwatch channel) is a **false positive** — `NightwatchServiceProvider` auto-registers `logging.channels.nightwatch` at lines 203–207 of the service provider, so no missing-channel failure occurs.
2. All Square and Fresha config findings are **dropped** — per project memory, Square/Fresha integrations were abandoned 2026-05-11.
3. CFG-1 draft (NIGHTWATCH_ENABLED=true) is **re-tiered P1→P2** — without a valid `NIGHTWATCH_TOKEN`, the ingest agent silently fails rather than leaking PII, so it's hardening not a data-exposure risk today.
`─────────────────────────────────────────────────`

# Config Hygiene Audit — 2026-05-21

**Branch:** development
**Lens:** Whole-backend PILOT audit — 'confighygiene' lens
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/`
- `config/`
- `.env.example`
- `supabase/migrations/`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 10 complete

---

## P2 — Should fix

- [ ] **#CFG-1** · P2 — NIGHTWATCH_ENABLED defaults to `true`, silently enabling observability in every unset environment
    - **Where:** `config/nightwatch.php:15`
    - **Affects:** Any environment that copies `.env.example` without explicitly setting `NIGHTWATCH_ENABLED=false` — CI, ephemeral previews, contractor dev boxes — will attempt to ship request paths, exception details, and metadata to the Nightwatch ingest agent on `127.0.0.1:2407`. Without a valid token or running daemon the ingest will fail noisily, not silently skip.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the default in `config/nightwatch.php` to `env('NIGHTWATCH_ENABLED', false)`.
        - Update `.env.example` line `# NIGHTWATCH_ENABLED=true` to `NIGHTWATCH_ENABLED=true` (uncommented, explicit) so production environments consciously opt in.
    - **Technical:** Every other feature flag in this codebase (`PARTNA_SMART_BOOKING_ENABLED`, `PARTNA_CAPTCHA_ENABLED`, `PARTNA_FRESHA_SYNC_ENABLED`) defaults to `false` — require explicit opt-in. Nightwatch does the opposite: it ships observability payloads (including exception source lines and request metadata) until you explicitly disable it. The `.env.example` currently has `# NIGHTWATCH_ENABLED=true` commented out, which looks like "not set" but the config falls back to `true`, so commenting it out has no effect. In CI or ephemeral preview environments without the Nightwatch daemon running, this generates connection-refused noise on every request.
    - **Plain English:** Every new copy of the app starts with the monitoring camera running and trying to upload footage, even in test environments. All the other on/off switches in the app start in the "off" position until you flip them. This one starts "on." The fix is making it consistent — start off, require a deliberate flip to enable it.
    - **Evidence:**
        ```php
        // config/nightwatch.php:15
        'enabled' => env('NIGHTWATCH_ENABLED', true),
        ```

- [ ] **#CFG-2** · P2 — `STRIPE_PLATFORM_WEBHOOK_SECRET` and `STRIPE_PLATFORM_THIN_WEBHOOK_SECRET` absent from `.env.example`; dead legacy key present
    - **Where:** `config/services.php:91-92`; `.env.example:308`
    - **Affects:** Anyone setting up a new environment for the `/webhooks/stripe-platform` and `/webhooks/stripe-platform-thin` endpoints — both signing secrets resolve to `null`, causing signature verification to fail at runtime. The legacy `STRIPE_WEBHOOK_SECRET=` in `.env.example` maps to no config key and misleads operators.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `STRIPE_PLATFORM_WEBHOOK_SECRET=` and `STRIPE_PLATFORM_THIN_WEBHOOK_SECRET=` to `.env.example` below `STRIPE_CONNECT_WEBHOOK_SECRET=`, with inline comments matching the explanations already in `config/services.php`.
        - Remove the dead `STRIPE_WEBHOOK_SECRET=` line from `.env.example` (no config key reads it).
    - **Technical:** `config/services.php` defines three scoped Stripe webhook secrets under the new Event Destinations system: `connect_webhook_secret` → `STRIPE_CONNECT_WEBHOOK_SECRET`, `platform_webhook_secret` → `STRIPE_PLATFORM_WEBHOOK_SECRET`, `platform_thin_webhook_secret` → `STRIPE_PLATFORM_THIN_WEBHOOK_SECRET`. `.env.example` only exposes the first and retains the legacy `STRIPE_WEBHOOK_SECRET` which no config key reads. A new operator following `.env.example` will configure Connect webhooks correctly but leave the platform endpoints with a `null` signing secret, making them reject all inbound Stripe events.
    - **Plain English:** Three different Stripe webhook endpoints each need their own password to verify incoming messages. The setup file only lists one of the three passwords and has a fourth entry for a password that no door uses anymore. Anyone following the setup file will have two webhook endpoints that silently reject all messages, making Stripe payouts and refund events invisible to the platform.
    - **Evidence:**
        ```php
        // config/services.php:90-92
        'connect_webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
        'platform_webhook_secret' => env('STRIPE_PLATFORM_WEBHOOK_SECRET'),
        'platform_thin_webhook_secret' => env('STRIPE_PLATFORM_THIN_WEBHOOK_SECRET'),
        ```
        ```
        // .env.example:308-309 — only legacy key present; both platform keys absent
        STRIPE_WEBHOOK_SECRET=
        STRIPE_CONNECT_WEBHOOK_SECRET=
        ```

- [ ] **#CFG-3** · P2 — `FRONTEND_URL` referenced in `config/app.php` but absent from `.env.example`, defaulting all environments to production URL
    - **Where:** `config/app.php:18`; `.env.example`
    - **Affects:** Any code reading `config('app.frontend_url')` — email magic links, CORS headers, redirect targets — will point to `https://partna.au` from local dev and staging environments unless `FRONTEND_URL` is manually added.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `FRONTEND_URL=https://partna.au` (commented out or active) to `.env.example` with a note: "used for email links, CORS headers, and redirect targets — override in staging/dev."
    - **Technical:** `config/app.php` defines `'frontend_url' => env('FRONTEND_URL', 'https://partna.au')`. The production URL is the default, meaning a local developer who fires a password-reset or invite email will receive a link pointing to the live production site, not their local app. `FRONTEND_URL` does not appear anywhere in `.env.example`, making it invisible to new developers and invisible to automated environment setup scripts.
    - **Plain English:** When the app sends out email invitations or verification links, it builds the link URL from a config value. That value defaults to the live production website. A developer testing invites locally would get an email whose link opens the real website instead of their local copy. The fix is to add the setting to the setup file so each environment can specify its own address.
    - **Evidence:**
        ```php
        // config/app.php:18
        'frontend_url' => env('FRONTEND_URL', 'https://partna.au'),
        ```
        ```
        // .env.example — FRONTEND_URL is absent
        ```

- [ ] **#CFG-4** · P2 — `CloudflarePurgeService::purgeHandle()` hardcodes `partna.au` when `config('partna.public_domain')` already exists
    - **Where:** `app/Services/Cloudflare/CloudflarePurgeService.php:71-72`
    - **Affects:** Handle-based cache invalidation. If the public domain ever changes (TLD migration, multi-region custom domain), edge cache for all individual-account pages will remain stale indefinitely because purge URLs still target the old domain.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the literal `.partna.au` suffix with `config('partna.public_domain')`.
        - Construct the URLs as `"https://{$h}." . config('partna.public_domain') . "/"` and without trailing slash.
    - **Technical:** `AppServiceProvider` already boots against `config('partna.public_domain')` and refuses production startup if the key is empty — it is the single canonical source of the public domain. `CloudflarePurgeService::purgeHandle()` bypasses that source entirely with a hardcoded string. Every other domain-aware path in the app (Cloudflare KV, DNS provisioning, subdomain validation) reads from `config('partna.public_domain')`; this is the lone exception. Purge misses manifest as stale pages at the edge that don't self-heal until the CDN TTL expires.
    - **Plain English:** When the app tells Cloudflare "please clear the cache for this page," it builds the page's web address by writing the domain name directly into the code. The codebase already has one official place where the domain is stored — the config — and everything else reads from there. This one spot doesn't, so if the domain ever changes it will silently keep sending cache-clear requests to the old address, leaving stale pages visible to users.
    - **Evidence:**
        ```php
        // app/Services/Cloudflare/CloudflarePurgeService.php:71-72
        $this->purgeUrls([
            "https://{$h}.partna.au/",
            "https://{$h}.partna.au",
        ]);
        ```

---

## P3 — Nice to have

- [ ] **#CFG-5** · P3 — Stripe API version default diverges between `config/services.php` (`2026-02-25.clover`) and `config/partna.php` (`2025-02-24.acacia`)
    - **Where:** `config/services.php:95`; `config/partna.php` (`exports.commission.stripe_api_version`); `.env.example:316`
    - **Affects:** The comment in `.env.example` claims the config default is `2025-02-24.acacia`, but `config/services.php` defaults to `2026-02-25.clover`. Both read `STRIPE_API_VERSION` from env, so in configured environments it doesn't matter — but the stale comment misleads operators deciding whether to bump the pin.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Update the `.env.example` comment ("Config default (set in config/services.php): 2025-02-24.acacia") to reflect the actual default `2026-02-25.clover`.
        - Align `config/partna.php exports.commission.stripe_api_version` fallback to `2026-02-25.clover` to match.
    - **Technical:** Two config defaults diverge for the same env var. Since `.env.example` actively sets `STRIPE_API_VERSION=2025-02-24.acacia`, the `config/services.php` default of `2026-02-25.clover` is unreachable in any configured environment — but the stale comment ("last pre-Basil") describes a version two majors behind the one `config/services.php` intends as canonical.
    - **Evidence:**
        ```php
        // config/services.php:95
        'api_version' => env('STRIPE_API_VERSION', '2026-02-25.clover'),
        ```
        ```
        // .env.example:311-316 — comment and value both cite older version
        # Config default (set in config/services.php): 2025-02-24.acacia
        STRIPE_API_VERSION=2025-02-24.acacia
        ```

- [ ] **#CFG-6** · P3 — `SHOPIFY_APP_HANDLE` default in `config/services.php` (`side-st-hydrogen`) differs from `.env.example` value (`side-st`)
    - **Where:** `config/services.php` (`shopify.app_handle`); `.env.example:325`
    - **Affects:** A developer who deletes `SHOPIFY_APP_HANDLE` from their `.env` would get the `config/services.php` fallback `side-st-hydrogen`; one who copies `.env.example` verbatim gets `side-st`. Shopify routes the post-install redirect under the app handle — a wrong value produces a 404 in the Shopify Admin.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Align `.env.example` to `SHOPIFY_APP_HANDLE=side-st-hydrogen` (or a placeholder) with a comment pointing to `shopify.app.toml`.
    - **Technical:** The config inline comment explicitly warns "Must match `handle` in Sidest-Embedded/shopify.app.toml." Having `.env.example` supply a different value creates a discoverability trap — a new developer following the example file will hit a Shopify install 404 before ever touching `app.toml`.
    - **Evidence:**
        ```php
        // config/services.php
        'app_handle' => env('SHOPIFY_APP_HANDLE', 'side-st-hydrogen'),
        ```
        ```
        // .env.example:325
        SHOPIFY_APP_HANDLE=side-st
        ```

- [ ] **#CFG-7** · P3 — `PARTNA_GALLERY_IMAGE_MAX` and `PARTNA_CONTENT_IMAGE_MAX` in `.env.example` set to `5`, conflicting with config default of `6`
    - **Where:** `config/partna.php` (`image_pools.gallery.max` / `image_pools.content.max`); `.env.example:194-195`
    - **Affects:** New environments that copy `.env.example` verbatim get a 5-slot gallery/content cap instead of the 6-slot cap the dashboard is designed around.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Update `.env.example` to `PARTNA_GALLERY_IMAGE_MAX=6` and `PARTNA_CONTENT_IMAGE_MAX=6`, or comment them out so the config default of 6 takes effect.
    - **Technical:** The config comment reads "Affiliate sitepage gallery + content panels both expose 6 slots in the dashboard." The `.env.example` actively overrides this to 5, meaning the dashboard and the config are misaligned for any environment that copies the example file. A frontend coded to render 6 slots against a backend capped at 5 would either show a disabled 6th slot or throw a validation error on upload attempt.
    - **Evidence:**
        ```php
        // config/partna.php
        'gallery' => ['max' => (int) env('PARTNA_GALLERY_IMAGE_MAX', env('SIDEST_GALLERY_IMAGE_MAX', 6))],
        'content' => ['max' => (int) env('PARTNA_CONTENT_IMAGE_MAX', env('SIDEST_CONTENT_IMAGE_MAX', 6))],
        ```
        ```
        // .env.example:194-195
        PARTNA_GALLERY_IMAGE_MAX=5
        PARTNA_CONTENT_IMAGE_MAX=5
        ```

- [ ] **#CFG-8** · P3 — Shopify API version fallback defaults inconsistent across jobs (`2026-04` vs `2025-01`)
    - **Where:** `app/Jobs/Shopify/ReconcileStuckShopifyIntegrationsJob.php:163`; `app/Jobs/Shopify/CreateShopifyCollectionsJob.php:183` (and 7 other jobs)
    - **Affects:** In a misconfigured environment where `SHOPIFY_API_VERSION` is unset, the reconciler queries a different Shopify API surface (`2026-04`) than the 8 install-chain jobs (`2025-01`), potentially misinterpreting healthy integration states as broken.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Align all 9 fallback strings to `'2026-04'` (the version the reconciler targets).
        - Ensure `SHOPIFY_API_VERSION=2026-04` is set in `.env.example` (currently `2025-01`), which makes the fallback path unreachable in practice.
    - **Technical:** `ReconcileStuckShopifyIntegrationsJob` falls back to `'2026-04'` while the 8 install-chain jobs (`CreateShopifyCollectionsJob`, `CreateShopifyMetafieldsJob`, `CreateShopifySalesChannelJob`, `CreateStorefrontAccessTokenJob`, `CreateShopifyAffiliateDiscountJob`, `RegisterShopifyWebhooksJob`, `SetShopifySetupCompleteJob`, `SyncShopifyBrandDesignJob`) fall back to `'2025-01'`. When the config key is present the divergence is dormant, but a misconfigured env or a config cache miss exposes it. The `.env.example` also shows `2025-01` not `2026-04`, meaning the documented default and the reconciler's expectation already diverge.
    - **Evidence:**
        ```php
        // ReconcileStuckShopifyIntegrationsJob.php:163
        $apiVersion = (string) config('services.shopify.api_version', '2026-04');
        ```
        ```php
        // CreateShopifyCollectionsJob.php:183 (and 7 others)
        $apiVersion = trim((string) config('services.shopify.api_version', '2025-01'));
        ```

- [ ] **#CFG-9** · P3 — `ProvisionBrandDnsJob` hardcodes Shopify Hydrogen CNAME target `shops.myshopify.com`
    - **Where:** `app/Jobs/Cloudflare/ProvisionBrandDnsJob.php:52`
    - **Affects:** Brand DNS provisioning during OAuth install. If Shopify changes the Oxygen/Hydrogen hosting domain, every brand install will silently write a broken CNAME until a code deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'shops.myshopify.com'` with `config('services.shopify.hydrogen_cname', 'shops.myshopify.com')`.
        - Add `# SHOPIFY_HYDROGEN_CNAME=shops.myshopify.com` to `.env.example`.
    - **Technical:** Every other Shopify vendor constant routes through `config('services.shopify.*')`; this is the sole hardcoded exception in the DNS provisioning path. There is no env-override escape hatch whatsoever for this value — if Shopify's hosting target changes (or a Shopify Plus brand has a custom contract), the only fix is a code deploy.
    - **Evidence:**
        ```php
        // ProvisionBrandDnsJob.php:52
        $dns->upsertCname($subdomain, 'shops.myshopify.com', false);
        ```

- [ ] **#CFG-10** · P3 — `ProcessImageVariantsJob` hardcodes queue name `images` while the video counterpart uses config-driven values
    - **Where:** `app/Jobs/ProcessImageVariantsJob.php:40`
    - **Affects:** Operators cannot reroute image variant processing to a different queue (e.g., lower-priority during cost-saving periods) without a code deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `PARTNA_IMAGE_QUEUE_CONNECTION` / `PARTNA_IMAGE_QUEUE_NAME` to `config/partna.php` alongside the existing `video_queue` block.
        - Replace `$this->onQueue('images')` with `$this->onConnection(config('partna.image_queue.connection', 'redis'))->onQueue(config('partna.image_queue.name', 'images'))`.
    - **Technical:** `ProcessVideoVariantsJob` reads both connection and queue from `config('partna.video_queue.connection')` / `config('partna.video_queue.name')`. Image and video variant processing have identical architectural requirements — dedicated workers, independent timeout configuration, separate scaling — but image processing has no config escape hatch. The asymmetry has no documented reason; it appears to be an omission.
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

- [ ] **#CFG-11** · P3 — `SendStaffBroadcastEmailsJob` hardcodes `'mail'` queue name for batch leaf dispatch
    - **Where:** `app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:86`
    - **Affects:** Staff broadcast email delivery — a typo or infrastructure rename (`'mail'` → `'emails'`) would silently route sends to a queue with no workers, dropping all broadcast emails.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->onQueue('mail')` with `->onQueue(config('partna.notifications.mail_queue', 'mail'))`.
        - Mirror the config-driven pattern already used by sibling `FanOutBrandStatusNotificationJob` for batch chunk size.
    - **Technical:** The parent job dispatches batches to a hardcoded `'mail'` queue. No other part of `config/partna.php` defines a canonical mail queue name — `'mail'` appears only as a string literal in this file. If the queue infrastructure is renamed (common when separating transactional vs broadcast mail), this batch silently routes to an unconsumed queue and no subscriber receives the staff broadcast.
    - **Evidence:**
        ```php
        // SendStaffBroadcastEmailsJob.php:86
        $batch = Bus::batch($chunk)
            ->onQueue('mail')
            ->name('staff-broadcast:'.$notification->id)
            ->allowFailures()
            ->dispatch();
        ```

- [ ] **#CFG-12** · P3 — `RedactShopJob` hardcodes Redis connection `redis_gdpr` while reading queue name from config
    - **Where:** `app/Jobs/Shopify/Gdpr/RedactShopJob.php:39`
    - **Affects:** GDPR shop-redact job routing — a staging environment using a different Redis connection for GDPR isolation cannot redirect without a code change, despite being able to change the queue name via config.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$this->onConnection('redis_gdpr')` with `$this->onConnection(config('partna.gdpr.connection', 'redis_gdpr'))`.
        - Add `GDPR_QUEUE_CONNECTION=redis_gdpr` to `.env.example` if not already present, backed by a `partna.gdpr.connection` config key.
    - **Technical:** The constructor reads `config('partna.gdpr.queue', 'gdpr')` for the queue name but hardcodes `'redis_gdpr'` for the connection. The sibling GDPR jobs (`ExportProfessionalDataJob`, `ExportCustomerDataJob`, `RedactCustomerJob`) use the queue config pattern consistently; `RedactShopJob` is the outlier. Half-config, half-hardcode makes the pattern confusing and means a staging environment that wants GDPR jobs on a shared Redis connection must patch the class.
    - **Evidence:**
        ```php
        // RedactShopJob.php:39
        $this->onConnection('redis_gdpr')->onQueue(config('partna.gdpr.queue', 'gdpr'));
        ```

- [ ] **#CFG-13** · P3 — Operational tuning constants hardcoded in cache-layer services and payout processing
    - **Where:** `app/Services/Cache/SiteCacheService.php` (`PAYLOAD_STALE_TTL_MULTIPLIER=10`, `MISS_PRIMARY_TTL_SECONDS=30`); `app/Services/Cache/CacheLockService.php` (`STALE_TTL_MULTIPLIER=10`); `app/Services/FeatureFlags/FeatureFlagService.php` (`BASE_TTL_SECONDS=300`, `TTL_JITTER_SECONDS=60`); `app/Services/Stripe/CommissionPayoutService.php` (pending sweep `->limit(500)`); `app/Services/Stripe/StripeConnectService.php` (`STATUS_CACHE_TTL=60`)
    - **Affects:** Incident response — an operator cannot tune stale-while-revalidate windows, feature-flag cache TTLs, or the payout sweep batch size without a code deploy.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `cache` block to `config/partna.php` for `stale_multiplier`, `miss_ttl_seconds`, `lock_seconds`, and `feature_flag_ttl_seconds`/`feature_flag_jitter_seconds`.
        - Add `partna.store.payout_pending_sweep_limit` (default 500) and `services.stripe.connect_status_cache_ttl` (default 60).
        - Add all new keys as commented entries in the "Operational tuning knobs" section of `.env.example` (which already has a block for this purpose at line 237).
    - **Technical:** These values directly control stale-while-revalidate window durations, cold-miss fallback speed, and the number of pending payouts touched per cron sweep. They are operational tuning surfaces, not algorithmic constants. The `.env.example` already has a commented "Operational tuning knobs" section (lines 237–285) documenting exactly this pattern for cache TTLs — these constants belong there but were missed. Moving them follows the existing project pattern, not a new one.
    - **Evidence:**
        ```php
        // SiteCacheService.php
        private const PAYLOAD_STALE_TTL_MULTIPLIER = 10;
        private const MISS_PRIMARY_TTL_SECONDS = 30;

        // FeatureFlagService.php
        private const BASE_TTL_SECONDS = 300;
        private const TTL_JITTER_SECONDS = 60;

        // CommissionPayoutService.php
        ->limit(500)->get(); // pending sweep cap
        ```
