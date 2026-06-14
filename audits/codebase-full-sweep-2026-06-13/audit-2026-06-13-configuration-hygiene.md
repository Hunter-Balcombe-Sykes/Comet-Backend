# Configuration Hygiene Audit — 2026-06-13

**Branch:** development
**Lens:** Configuration Hygiene — `env()` outside config files, missing `.env.example` keys, feature flags without safe defaults, hardcoded values
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- `config/app.php`
- `config/services.php`
- `config/queue.php`
- `config/partna.php`
- `.env.example`
- `app/Services/Diagnostics/EnvCheckService.php`
- `app/Services/BotProtection/CircuitBreaker.php`
- `app/Services/Media/MediaDiskResolver.php`
- `app/Services/Streaming/LiveStatusPoller.php`
- `app/Services/Streaming/KickApiClient.php`
- `app/Services/Streaming/TwitchApiClient.php`
- `app/Http/Middleware/Auth/VerifySupabaseAuthHookSignature.php`
- `app/Http/Middleware/Auth/VerifySupabaseEmailHookSignature.php`
- `app/Http/Middleware/VerifyBotToken.php`
- `app/Jobs/**/*.php` (queue name grep sweep)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 7 complete

---

## P2 — Should fix

- [ ] **#CFG-1** · P2 — `FRONTEND_URL` consumed by `config/app.php` and `EnvCheckService::REQUIRED` but absent from `.env.example`
    - **Where:** `config/app.php:18`, `app/Services/Diagnostics/EnvCheckService.php:29`
    - **Affects:** Developers setting up a new environment — the key is invisible until `php artisan env:check` fires, which requires knowing to run it. The backend uses `config('app.frontend_url')` to construct cross-origin email links and CORS headers; a wrong value silently breaks those.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `FRONTEND_URL=https://app.partna.au` (with a comment) to `.env.example` directly below the `APP_URL` line.
        - Add a dev example value (`http://localhost:5173`) as a comment so developers don't need to guess the local default.
        - Document the distinction: `APP_URL` = the API backend; `FRONTEND_URL` = the dashboard SPA.
    - **Technical:** `config/app.php:18` resolves `env('FRONTEND_URL', 'https://partna.au')`. The key appears in `EnvCheckService::REQUIRED` as `'app.frontend_url' => 'FRONTEND_URL'`, so `env:check` will catch a blank value at runtime — but `.env.example` is the first-look surface a developer reads when provisioning a new environment. `APP_URL` is present in `.env.example`; `FRONTEND_URL` is not, creating an invisible gap between the two config axes.
    - **Plain English:** When a new developer sets up the backend, they copy `.env.example` as their starting point. The file tells them about `APP_URL` (the backend address) but says nothing about `FRONTEND_URL` (the dashboard address). The app works because there's a hard-coded fallback, but cross-origin features and emails will use the wrong domain until someone realises the switch exists and flips it.
    - **Evidence:**
        ```php
        // config/app.php:18
        'frontend_url' => env('FRONTEND_URL', 'https://partna.au'),

        // app/Services/Diagnostics/EnvCheckService.php:29
        'app.frontend_url' => 'FRONTEND_URL',
        // (FRONTEND_URL does NOT appear anywhere in .env.example)
        ```

---

- [ ] **#CFG-2** · P2 — Five `config/services.php` keys consumed by active features are absent from `.env.example`
    - **Where:** `config/services.php:18, 59–61, 81, 106`
    - **Affects:** Slack long-wait horizon notifications, Postmark mail transport fallback, Cloudflare for-SaaS custom domain provisioning (commit `028a5d09`), and the Apify Instagram scraper pilot — all silently get `null` in a fresh environment with no hint in `.env.example`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add commented stubs for `POSTMARK_API_KEY`, `SLACK_BOT_USER_OAUTH_TOKEN`, `SLACK_BOT_USER_DEFAULT_CHANNEL` next to the existing `MAIL_MAILER` block.
        - Add `CLOUDFLARE_SAAS_API_TOKEN=` and `CLOUDFLARE_SAAS_CNAME_TARGET=cname.partna.au` in the Cloudflare block, below `CLOUDFLARE_CACHE_PURGE_TOKEN`. Both were introduced in `028a5d09` (`feat(site): custom domains via Cloudflare for SaaS`) but not backfilled into `.env.example`. Note the required scopes: Zone:SSL, Certificates:Edit, Zone:Read.
        - Add `# APIFY_TOKEN=` with a note that it is a legacy remnant kept as a stub — `null` is safe but the key should be visible so it's not silently consumed by accident.
    - **Technical:** All five keys have no second-argument default in `config/services.php` (they resolve to `null` when absent), which is correct — `null` means the feature is unconfigured. The problem is discoverability: `.env.example` is the canonical reference for every env-tunable surface. `CLOUDFLARE_SAAS_API_TOKEN` is particularly acute because commit `028a5d09` ships active custom-domain provisioning code that calls `services.cloudflare.saas_api_token` — a fresh staging deploy will silently fail custom-domain operations until the key is set.
    - **Plain English:** Imagine ordering a new appliance that comes with five accessories in separate boxes — but the setup guide only mentions three of them. The other two work fine once installed, but no one knows they came with the package. `CLOUDFLARE_SAAS_API_TOKEN` is the most important missing label: it was added in the most recent infrastructure feature (custom domains), so it's the freshest gap a new environment would hit.
    - **Evidence:**
        ```php
        // config/services.php — all five absent from .env.example
        'postmark' => ['key' => env('POSTMARK_API_KEY')],
        'slack' => [
            'notifications' => [
                'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
                'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
            ],
        ],
        'cloudflare' => [
            // ... existing keys documented in .env.example ...
            'saas_api_token'   => env('CLOUDFLARE_SAAS_API_TOKEN'),      // missing — added in 028a5d09
            'saas_cname_target' => env('CLOUDFLARE_SAAS_CNAME_TARGET', 'cname.partna.au'), // missing
        ],
        'apify' => ['token' => env('APIFY_TOKEN')],
        ```

---

- [ ] **#CFG-3** · P2 — Fourteen operational-tuning env keys in `config/partna.php` / `config/queue.php` are absent from `.env.example`
    - **Where:** `config/partna.php` (multiple lines); `config/queue.php:101`
    - **Affects:** On-call engineers tuning queue routing, media GC, rate limits, GDPR windows, and handle audit retention during incidents. All keys have safe defaults so the app won't break — but the keys are undiscoverable without grepping config source.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add the following commented stubs to the "Operational tuning knobs" section of `.env.example` (alongside the cache TTLs already documented there): `PARTNA_VISITOR_CONFIRMATION_PER_HOUR`, `PARTNA_MEDIA_LEDGER_WINDOW_DAYS`, `PARTNA_MEDIA_GC_MIN_AGE_HOURS`, `PARTNA_EXPORT_MAX_ROWS`, `PARTNA_ANALYTICS_QUEUE`, `PARTNA_MODERATION_HIGH_LANE`, `PARTNA_DESIGN_KIT_COLUMNS_VERSION`, `SIDEST_HANDLE_AUDIT_RETENTION_YEARS`, `SIDEST_RATE_LIMIT_PUBLIC_PROFILE_PER_MINUTE`, `SIDEST_PUBLIC_PROFILE_CACHE_TTL`, `GDPR_EXPORT_SIGNED_URL_TTL_DAYS`, `GDPR_EXPORT_DEDUP_WINDOW_MINUTES`, `PARTNA_GDPR_QUEUE_RETRY_AFTER`, `PARTNA_PUBLIC_ANALYTICS_ENDPOINT`.
        - Use the current config-file defaults as the commented-out values (e.g. `# PARTNA_EXPORT_MAX_ROWS=50000`).
        - Note: `PARTNA_CACHE_TTL_EMAIL_BRAND` is already documented at line 271 — do not duplicate it.
    - **Technical:** `config/partna.php` carries an "Operational tuning knobs" comment block and `.env.example` has a corresponding section listing cache TTL keys — but the section is incomplete. Fourteen keys that affect incident response (export row caps, media GC timing, GDPR dedup windows, queue lane names) are only discoverable by reading PHP source. `PARTNA_ANALYTICS_QUEUE` is particularly relevant: if the analytics queue needs to be rerouted to a different worker during a backlog incident, an operator must grep `config/partna.php` to find the key rather than having it in the ops runbook's reference file.
    - **Plain English:** The incident-response playbook for this system starts with "check the labeled knobs in `.env.example`." Right now, 14 important knobs are unlabeled. During a real incident — say the GDPR export queue is backing up — an engineer has to open PHP source files to find the right dial. Labeling them (adding commented entries with their default values) turns a 10-minute search into a 10-second copy-paste.
    - **Evidence:**
        ```php
        // config/partna.php — sample of keys absent from .env.example
        'visitor_confirmation_per_hour' => (int) env('PARTNA_VISITOR_CONFIRMATION_PER_HOUR', 5),
        'media_orphan_sweep' => [
            'ledger_window_days' => (int) env('PARTNA_MEDIA_LEDGER_WINDOW_DAYS', 30),
            'gc_min_age_hours'   => (int) env('PARTNA_MEDIA_GC_MIN_AGE_HOURS', 24),
        ],
        'export' => ['max_rows' => (int) env('PARTNA_EXPORT_MAX_ROWS', 50_000)],
        'analytics_queue' => ['name' => env('PARTNA_ANALYTICS_QUEUE', 'analytics')],
        'design_kit_columns_version' => (int) env('PARTNA_DESIGN_KIT_COLUMNS_VERSION', 1),
        'handle' => ['audit_retention_years' => (int) env('SIDEST_HANDLE_AUDIT_RETENTION_YEARS', 7)],
        'gdpr' => [
            'signed_url_ttl_days'  => (int) env('GDPR_EXPORT_SIGNED_URL_TTL_DAYS', 7),
            'dedup_window_minutes' => (int) env('GDPR_EXPORT_DEDUP_WINDOW_MINUTES', 30),
        ],
        'moderation' => ['queue' => ['high_priority_lane' => env('PARTNA_MODERATION_HIGH_LANE', 'moderation_high')]],

        // config/queue.php:101
        'retry_after' => (int) env('PARTNA_GDPR_QUEUE_RETRY_AFTER', env('GDPR_QUEUE_RETRY_AFTER', 660)),
        ```

---

- [ ] **#CFG-4** · P2 — Supabase webhook HMAC secrets absent from `EnvCheckService`; a non-prod deploy with missing secrets gets a false `status: ok` while all hook deliveries return 503
    - **Where:** `app/Services/Diagnostics/EnvCheckService.php:23–95`
    - **Affects:** Staging and local-dev environments — missing `SUPABASE_AUTH_HOOK_SECRET` or `SUPABASE_EMAIL_HOOK_SECRET` causes every Supabase auth/email hook delivery to 503, silently breaking MFA enrolment events and transactional auth emails, with no signal from `php artisan env:check` or the `/api/internal/env-check` endpoint.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `'Supabase Webhooks'` group to `EnvCheckService::REQUIRED` with:
            - `'supabase.auth_hook_secret' => 'SUPABASE_AUTH_HOOK_SECRET'`
            - `'services.supabase.email_hook_secret' => 'SUPABASE_EMAIL_HOOK_SECRET'`
        - Both keys are already in `.env.example` (lines 147, 185) and `AppServiceProvider` already throws a `RuntimeException` at boot in production when either is empty — so `prod` is protected. The gap is specifically `env:check` not surfacing them in staging/dev where the boot guard is disabled.
    - **Technical:** Both `VerifySupabaseAuthHookSignature` and `VerifySupabaseEmailHookSignature` fail-closed (return 503) when their secrets are empty — the correct security posture. `AppServiceProvider::boot()` (lines 171, 178) throws at startup in production if either secret is blank, which is a strong prod guard. However, in staging/dev environments, the boot guard does not fire. `EnvCheckService::REQUIRED` currently covers Cloudflare tokens and all DB/Redis credentials but has no `'Supabase Webhooks'` group. Running `php artisan env:check` against a staging environment where both secrets are absent will report `status: 'ok'` while every Supabase-initiated webhook delivery (user creation, deletion, MFA enrolment) returns 503. Adding them to `REQUIRED` closes the non-prod discovery gap.
    - **Plain English:** The pre-launch safety checklist (the env-check tool) verifies that all the important plugs are plugged in before you flip the switch. It correctly checks the database plug, the cache plug, and the Cloudflare plug. But it misses two plugs specifically controlling how user signup and login emails flow through the system. In production, the app refuses to start without them — so that environment is protected. But in the staging environment where developers test new features, those two plugs can go missing and the checklist still says "all good" while every login email silently fails.
    - **Evidence:**
        ```php
        // EnvCheckService.php — REQUIRED has no Supabase Webhooks group
        public const REQUIRED = [
            'Supabase Auth' => [
                'supabase.url'              => 'SUPABASE_URL',
                'supabase.jwt_issuer'       => 'SUPABASE_JWT_ISSUER',
                // ... 'supabase.auth_hook_secret' is NOT here
            ],
            // no 'Supabase Webhooks' group at all
        ];

        // VerifySupabaseAuthHookSignature.php:26-33
        $secret = (string) config('supabase.auth_hook_secret', '');
        if ($secret === '') {
            return response()->json(['error' => true, 'message' => 'Auth hook is not configured.'], 503);
        }

        // VerifySupabaseEmailHookSignature.php:27-35
        $secret = (string) config('services.supabase.email_hook_secret', '');
        if ($secret === '') {
            return response()->json(['error' => true, 'message' => 'Email hook is not configured.'], 503);
        }

        // AppServiceProvider.php:171,178 — prod-only boot guards (exist; env:check does not)
        if (app()->isProduction() && empty(config('services.supabase.email_hook_secret'))) {
            throw new \RuntimeException('...');
        }
        if (app()->isProduction() && empty(config('supabase.auth_hook_secret'))) {
            throw new \RuntimeException('...');
        }
        ```

---

- [ ] **#CFG-5** · P2 — `MediaDiskResolver` superglobal probe (`$_ENV`/`$_SERVER`) is invisible to `EnvCheckService` and creates an undetectable config-split between cached and runtime disk names
    - **Where:** `app/Services/Media/MediaDiskResolver.php:33–34`, `app/Services/Diagnostics/EnvCheckService.php`
    - **Affects:** All media storage operations (image and video upload, retrieval, deletion) — callers that read `config('partna.media_disk')` directly instead of calling `MediaDiskResolver::resolve()` will use the deploy-time cached value, potentially routing storage to a different bucket than the live runtime disk.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `PARTNA_MEDIA_DISK` to `EnvCheckService::RECOMMENDED` (it is already in `.env.example` at line 164, so just map `'partna.media_disk' => 'PARTNA_MEDIA_DISK'`). This makes the env-check report surface the value in use.
        - Add a Nightwatch breadcrumb (`Log::info`) inside `MediaDiskResolver::resolve()` when the runtime probe returns a different value than `config('partna.media_disk')` — this turns a silent split into a visible event on the next deploy.
        - Add a note in the class docblock warning that callers must call `resolve()` rather than reading `config('partna.media_disk')` directly.
    - **Technical:** `MediaDiskResolver` intentionally probes `$_ENV`/`$_SERVER` directly because Laravel Cloud caches config at deploy time but injects platform env vars into the process at runtime — the class docblock explains this correctly. `PARTNA_MEDIA_DISK` IS in `.env.example` (line 164), so discovery is fine. The gap is observability: `config('partna.media_disk')` and `MediaDiskResolver::resolve()` can silently return different disk names, `EnvCheckService` cannot detect the split, and there is no Nightwatch signal when the two diverge. The legacy `SIDEST_MEDIA_DISK` fallback also perpetuates the old naming without surfacing it anywhere.
    - **Plain English:** The system for deciding which storage bucket to use has two sources of truth: a frozen instruction sheet (the cached config, set at deploy time) and a live sticky note (the runtime environment variable, injected by the cloud platform). The live sticky note wins by design. But there's no alarm if the two disagree, and the pre-flight checklist doesn't even check the sticky note's value. Adding both an alarm and a checklist entry makes the split visible the moment it happens.
    - **Evidence:**
        ```php
        // MediaDiskResolver.php:33–34 — intentional superglobal probe (documented)
        $explicit = $_ENV['PARTNA_MEDIA_DISK'] ?? $_SERVER['PARTNA_MEDIA_DISK']
            ?? $_ENV['SIDEST_MEDIA_DISK'] ?? $_SERVER['SIDEST_MEDIA_DISK'] ?? null;
        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        // EnvCheckService — neither PARTNA_MEDIA_DISK nor SIDEST_MEDIA_DISK in REQUIRED or RECOMMENDED
        // (PARTNA_MEDIA_DISK is in .env.example:164 but does not appear in EnvCheckService at all)
        ```

---

## P3 — Nice to have

- [ ] **#CFG-6** · P3 — `PARTNA_THROTTLE_ENABLED` defaults to `true`; inconsistent with the `false` baseline every other feature flag uses
    - **Where:** `config/partna.php:797`
    - **Affects:** Consistency — a developer scanning the config file who assumes all flags default to safe-off would miss that this one doesn't.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either add a comment on the line explaining why throttling defaults `true` (it is safety infrastructure, not an unfinished feature, so `true` is the correct safe state), OR change the default to `false` and set `PARTNA_THROTTLE_ENABLED=true` explicitly in every non-test environment's `.env`.
        - The comment approach is lower risk and more honest — the `.env.example` already has `PARTNA_THROTTLE_ENABLED=true` at line 196, so behaviour in all real environments is correct.
    - **Technical:** Every other `SIDEST_*_ENABLED` / `PARTNA_*_ENABLED` flag (`video_uploads_enabled`, `smart_booking`, `square_sync`, `fresha_sync`, `notifications.email_enabled`, `individual_waitlist_enabled`) defaults to `false`. `PARTNA_THROTTLE_ENABLED` defaults to `true` via `env('PARTNA_THROTTLE_ENABLED', env('SIDEST_THROTTLE_ENABLED', true))`. This is actually correct for throttling — rate limiting is protective and should be on by default — but the inconsistency creates a cognitive trap where a future developer might treat all feature flags as opt-in and miss the impact of changing this one.
    - **Plain English:** Every light switch in the house defaults to "off" when you move in — except one, which starts "on" because it controls the security floodlight. The house is safer that way, but the labeling is confusing. Adding a note ("this one is intentionally on — it's a security control") prevents someone from accidentally turning it off thinking it's a feature flag.
    - **Evidence:**
        ```php
        // config/partna.php:797
        'throttle' => [
            'enabled' => (bool) env('PARTNA_THROTTLE_ENABLED', env('SIDEST_THROTTLE_ENABLED', true)),
        ],
        // Compare with every other flag — all default false:
        'video_uploads_enabled' => (bool) env('PARTNA_VIDEO_UPLOADS_ENABLED', env('SIDEST_VIDEO_UPLOADS_ENABLED', false)),
        'features' => [
            'smart_booking' => (bool) env('PARTNA_SMART_BOOKING_ENABLED', env('SIDEST_SMART_BOOKING_ENABLED', false)),
        ],
        ```

---

- [ ] **#CFG-7** · P3 — `.env.example` contains `GDPR_REDACT_PLACEHOLDER_DOMAIN=gdpr.sidest.io`; the config default is `gdpr.partna.au`
    - **Where:** `.env.example:292`, `config/partna.php:1104`
    - **Affects:** New developers and automated environment checks — the example file contradicts the code default, creating confusion about which domain is canonical.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `.env.example:292` from `GDPR_REDACT_PLACEHOLDER_DOMAIN=gdpr.sidest.io` to `GDPR_REDACT_PLACEHOLDER_DOMAIN=gdpr.partna.au` to match the config-file default.
        - If `sidest.io` is still a live, actively-owned domain pointed at Partna infrastructure, add a comment to that effect; otherwise this is purely a stale-brand remnant from the standalone strip-down (2026-05-22).
    - **Technical:** `config/partna.php:1104` sets `'redact_placeholder_domain' => env('GDPR_REDACT_PLACEHOLDER_DOMAIN', 'gdpr.partna.au')`. `.env.example` explicitly overrides this with `gdpr.sidest.io` — so any environment that copies `.env.example` verbatim will use the old-brand domain as the anonymisation placeholder in GDPR redaction emails. This is a data-display issue (wrong domain appears in anonymised customer records) rather than a security issue, but it undermines trust if a customer sees `gdpr.sidest.io` in a redacted record.
    - **Plain English:** The legal anonymisation system replaces real customer email addresses with fake ones at a placeholder domain. The code says the fake domain is `gdpr.partna.au`, but the setup file says `gdpr.sidest.io` — the company's old name. Any new environment set up from the template will use the old name. It's not dangerous, but if a customer ever sees one of these redacted records they'll wonder who `sidest.io` is.
    - **Evidence:**
        ```
        # .env.example:292
        GDPR_REDACT_PLACEHOLDER_DOMAIN=gdpr.sidest.io
        ```
        ```php
        // config/partna.php:1104
        'redact_placeholder_domain' => env('GDPR_REDACT_PLACEHOLDER_DOMAIN', 'gdpr.partna.au'),
        ```

---

- [ ] **#CFG-8** · P3 — Four queue-connection tuning vars in `config/queue.php` absent from `.env.example`
    - **Where:** `config/queue.php:72–73, 77, 154`
    - **Affects:** Operators who need to tune the default Redis queue connection or failed-job driver during an incident — the knobs exist but are invisible without reading PHP source.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add commented stubs for `REDIS_QUEUE_CONNECTION`, `REDIS_QUEUE`, `REDIS_QUEUE_RETRY_AFTER`, and `QUEUE_FAILED_DRIVER` to the Redis/queue section of `.env.example`.
        - Add a comment linking `REDIS_QUEUE_RETRY_AFTER` to the longest job `$timeout` (currently 300s; the config default of 360 provides a 60-second safety margin — document this relationship).
    - **Technical:** `config/queue.php:72–77` exposes four tunable keys for the default Redis connection. All have safe defaults (`'default'`, `'default'`, `360`, `'database-uuids'`). `QUEUE_CONNECTION=redis` is documented in `.env.example` but the more granular queue-routing knobs are not. These are the vars an operator reaches for when rerouting traffic to a different Redis connection under load or extending `retry_after` when a long-running job is re-queuing mid-execution.
    - **Plain English:** The `.env.example` tells you which queue engine to use (`QUEUE_CONNECTION=redis`) but not how to tune that engine. It's like a car manual that says "the engine runs on petrol" but doesn't mention you can also adjust the rev limiter and the idle speed. Adding the four tuning entries means the mechanic doesn't have to read circuit diagrams to find the dials.
    - **Evidence:**
        ```php
        // config/queue.php:70–80 — four vars absent from .env.example
        'redis' => [
            'connection'  => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue'       => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 360),
        ],
        // config/queue.php:153–154
        'failed' => [
            'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        ],
        ```

---

- [ ] **#CFG-9** · P3 — Queue names hardcoded as string literals in 13 jobs while 4 already use `config()`; an env-driven rename strands the hardcoded jobs silently
    - **Where:** `app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php:70`, `app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:60,94`, `app/Jobs/Notifications/SendFeedbackEmailJob.php:39`, `app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php:44`, `app/Jobs/Account/SendAccountDeletionRequestMailJob.php:52`, `app/Jobs/Cache/WarmPublicSiteCacheJob.php:54`, `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php:55`, `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:63`, `app/Jobs/Platforms/InstagramConnectJob.php:64`, `app/Jobs/Streaming/CheckStreamingLiveStatusJob.php:30`, `app/Jobs/ProcessImageVariantsJob.php:51`
    - **Affects:** Queue routing operability — if `PARTNA_QUEUE_NOTIFICATIONS` is used to reroute the notifications lane, jobs using `config()` move but the hardcoded ones stay on the old queue with no Horizon worker consuming them.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extend `config/partna.php`'s existing `queues` array (already has `notifications`) with: `cloudflare`, `cache_warm`, `scraping`, `streaming`, `images`, `mail`.
        - Add corresponding `PARTNA_QUEUE_*` env vars to `.env.example` alongside the existing `PARTNA_QUEUE_NOTIFICATIONS`.
        - Replace each hardcoded `->onQueue('...')` with the matching `config('partna.queues.*')` call. The `notifications` jobs that already use `config('partna.queues.notifications', 'notifications')` (e.g. `SendSubscriptionConfirmationJob`, `SendEnquiryConfirmationJob`) show the correct pattern.
    - **Technical:** `config/partna.php:985–989` already establishes the `queues` config array pattern: `'queues' => ['notifications' => env('PARTNA_QUEUE_NOTIFICATIONS', 'notifications')]`. This was introduced precisely to allow env-driven queue routing. But 13 of the project's 21 `ShouldQueue` jobs ignore this pattern and hardcode the queue name as a string literal. `RecordAnalyticsEventJob` (`config('partna.analytics_queue.name')`) and `DeleteMediaArtifactsJob` (`config('partna.video_queue.name')`) demonstrate the correct pattern for non-notifications queues. The inconsistency means `PARTNA_QUEUE_NOTIFICATIONS` only reroutes 4 of the ~8 jobs that should use that lane, and `cloudflare`/`cache-warm`/`streaming`/`scraping`/`images` queues have no env-tunable routing at all.
    - **Plain English:** The post office uses a routing table to direct mail — change the table and all mail goes to the new address. But 13 of the mail trucks have the old address painted directly on the side. If you update the routing table (the config), 8 trucks follow the new route but 13 paint the old one on themselves and drive there anyway. Jobs that should go to the same place end up at different queues, and the jobs in the hardcoded trucks pile up silently when their queue has no worker.
    - **Evidence:**
        ```php
        // Hardcoded (13 jobs — sample):
        $this->onQueue('notifications');    // SendAccountDeletionRequestMailJob, SendEnquiryNotificationJob, etc.
        $this->onQueue('cache-warm');       // WarmPublicSiteCacheJob
        $this->onQueue('cloudflare');       // CloudflareCachePurgeJob, SyncSubdomainToKvJob
        $this->onQueue('scraping');         // InstagramConnectJob
        $this->onQueue('streaming');        // CheckStreamingLiveStatusJob

        // Config-driven (correct pattern — 4 jobs):
        $this->onQueue((string) config('partna.analytics_queue.name', 'analytics')); // RecordAnalyticsEventJob
        $this->onQueue(config('partna.queues.notifications', 'notifications'));       // SendSubscriptionConfirmationJob
        ```

---

- [ ] **#CFG-10** · P3 — `CircuitBreaker` constructor defaults are hardcoded and ignore `config/partna.php` circuit-breaker values; `VerifyBotToken` reads those config keys for log dedup, creating a silent drift between the two
    - **Where:** `app/Services/BotProtection/CircuitBreaker.php:10–14`, `app/Http/Middleware/VerifyBotToken.php:186,210`
    - **Affects:** Bot protection circuit-breaker behaviour — tuning `partna.bot_protection.circuit_breaker.*` in config has no effect on when the breaker trips or resets, only on the log-dedup TTL in `VerifyBotToken`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `CircuitBreaker`'s constructor to read from `config('partna.bot_protection.circuit_breaker.failure_threshold', 5)`, `config('...window_seconds', 60)`, and `config('...cooldown_seconds', 300)` as its defaults.
        - Since these three config values are currently hardcoded literals in `config/partna.php` (not env-driven), they'll stay at 5/60/300 unless someone adds env vars — but at least the breaker and the log-dedup TTL will read from the same source.
    - **Technical:** `config/partna.php:1207–1211` defines `'circuit_breaker' => ['failure_threshold' => 5, 'window_seconds' => 60, 'cooldown_seconds' => 300]`. `VerifyBotToken` reads `config('partna.bot_protection.circuit_breaker.cooldown_seconds', 300)` (line 186, 210) for its log-dedup TTL. But `CircuitBreaker` itself (lines 10–14) uses constructor-injected defaults `$cooldownSeconds = 300` and never calls `config()`. Both values are currently 300, so no observable bug exists today — but they can silently drift if someone changes the config values expecting the breaker to respond. The values in `config/partna.php` are dead config for the circuit breaker itself.
    - **Plain English:** The bot-protection circuit breaker has a timer that controls how long it stays tripped. The timer value appears on a central settings page (the config file), but the actual circuit breaker doesn't check that page — it uses a value written on a Post-it note stuck inside itself. Right now both values say "300 seconds," so everything works. But if you ever change the settings page, the circuit breaker ignores the update. The fix is making the circuit breaker read from the settings page like everything else does.
    - **Evidence:**
        ```php
        // CircuitBreaker.php:10–14 — constructor defaults; no config() calls anywhere in class
        public function __construct(
            private int $failureThreshold = 5,
            private int $windowSeconds    = 60,
            private int $cooldownSeconds  = 300,
        ) {}

        // VerifyBotToken.php:186 — reads config for log dedup TTL (same logical value, different source)
        Redis::expire($key, (int) config('partna.bot_protection.circuit_breaker.cooldown_seconds', 300));

        // config/partna.php:1207–1210 — these values are ignored by CircuitBreaker
        'circuit_breaker' => [
            'failure_threshold' => 5,
            'window_seconds'    => 60,
            'cooldown_seconds'  => 300,
        ],
        ```

---

- [ ] **#CFG-11** · P3 — `LiveStatusPoller` cold-demotion TTL constants are hardcoded private constants with no config path; tuning API budget vs. polling frequency requires a code change
    - **Where:** `app/Services/Streaming/LiveStatusPoller.php:26–37`
    - **Affects:** Streaming live-status polling efficiency — the cold-handle demotion tiers (the primary lever for controlling Twitch/Kick API budget consumption) cannot be adjusted via config during a rate-limit incident or a high-traffic launch period.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `KICK_RATE_LIMITED_TTL`, `LIVE_TTL_SECONDS`, `WARM_OFFLINE_TTL`, `COOL_OFFLINE_TTL`, `COLD_OFFLINE_TTL`, and `TTL_SKIP_THRESHOLD` into `config/partna.php` under `streaming.ttls.*` with the current values as defaults.
        - Update `LiveStatusPoller` to read from `config('partna.streaming.ttls.*', <current-value>)` instead of `self::CONSTANT`.
        - Add the six `PARTNA_STREAMING_TTL_*` keys as commented stubs to `.env.example` alongside the existing `# PARTNA_STREAMING_MAX_LIVE_CHECK_PER_SITE=5`.
    - **Technical:** The existing `streaming.max_live_check_per_site` key (`config/partna.php:237`) demonstrates the pattern — it was moved to config precisely so it could be tuned via env without a deploy. The six TTL constants in `LiveStatusPoller` control the same logical domain (polling budget) but are not config-driven. At scale, the cold-demotion tiers are the primary cost lever: raising `COLD_OFFLINE_TTL` from 1800s to 3600s halves the API calls for long-dormant handles. Currently, doing so requires a code change and deploy rather than a one-liner env update.
    - **Plain English:** The streaming poller has a clever system to avoid pestering offline streamers constantly: check them less and less often the longer they've been offline. The timing rules for "how much less often" are written in permanent marker inside the code. The similar rule about "how many handles to check per site" was already moved to a config file so it can be tuned without a deployment. Moving the timing rules there too means adjusting the polling budget during a launch week is a five-second config change, not a full software deploy.
    - **Evidence:**
        ```php
        // LiveStatusPoller.php:26–37 — all TTLs are hardcoded private constants
        private const KICK_RATE_LIMITED_TTL = 300;
        private const LIVE_TTL_SECONDS      = 180;
        private const WARM_OFFLINE_TTL      = 180;
        private const COOL_OFFLINE_TTL      = 600;
        private const COLD_OFFLINE_TTL      = 1800;
        private const TTL_SKIP_THRESHOLD    = 60;
        // No config() call anywhere in the class.

        // Compare — existing config-driven pattern (config/partna.php:237):
        'streaming' => [
            'max_live_check_per_site' => (int) env('PARTNA_STREAMING_MAX_LIVE_CHECK_PER_SITE', 5),
        ],
        ```

---

- [ ] **#CFG-12** · P3 — Twitch and Kick API base URLs hardcoded as private class constants; token-URL management via `config()` in `StreamingTokenManager` demonstrates the available pattern
    - **Where:** `app/Services/Streaming/KickApiClient.php:20`, `app/Services/Streaming/TwitchApiClient.php:14`
    - **Affects:** Environment parity — staging and local dev cannot redirect streaming API calls to a mock server or integration-test double without editing source.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `partna.streaming.kick_api_url` and `partna.streaming.twitch_api_url` to `config/partna.php` under the existing `streaming` block, using the current URLs as defaults.
        - Replace `private const CHANNELS_URL` and `private const STREAMS_URL` with `config('partna.streaming.kick_api_url', 'https://api.kick.com/public/v1/channels')` and `config('partna.streaming.twitch_api_url', 'https://api.twitch.tv/helix/streams')`.
        - Add commented stubs to `.env.example` alongside the Twitch/Kick credential keys.
    - **Technical:** `StreamingTokenManager` already reads `config('services.twitch.client_id')` and `config('services.kick.client_id')`, making the OAuth token flow config-driven. The API endpoint URLs are the only remaining hardcoded surface in the streaming clients. There are no official Twitch/Kick sandbox environments, so this primarily benefits integration testing with `Http::fake()` or a local mock server, and eliminates a class of "update URL in two files" maintenance events if either platform changes their API path (as Kick has done historically with version bumps).
    - **Plain English:** The streaming check service knows which website addresses to contact — they're stencilled directly onto the service's internals. The login credentials for those services are already managed through a settings file, so if they change, you update one place. Moving the website addresses to the same settings file means a future Kick API version bump (`/v1/` → `/v2/`) is a one-line config change rather than a source code edit and deploy.
    - **Evidence:**
        ```php
        // KickApiClient.php:20
        private const CHANNELS_URL = 'https://api.kick.com/public/v1/channels';

        // TwitchApiClient.php:14
        private const STREAMS_URL = 'https://api.twitch.tv/helix/streams';

        // StreamingTokenManager — config-driven credentials (the correct pattern)
        config('services.twitch.client_id')
        config('services.kick.client_id')
        ```
