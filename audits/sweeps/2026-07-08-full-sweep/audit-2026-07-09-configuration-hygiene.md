# Configuration Hygiene Audit — 2026-07-09

**Branch:** development
**Lens:** Configuration Hygiene: `env()` outside config, missing `.env.example` keys, feature flags without safe defaults
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- config/partna.php, config/supabase.php, config/horizon.php, config/services.php
- .env.example
- app/Services/Media/MediaDiskResolver.php, ImageVariantService.php, VideoVariantService.php
- app/Jobs/Gdpr/ExportUserDataJob.php, app/Console/Commands/PruneCompletedExportsCommand.php
- app/Jobs/Platforms/InstagramConnectJob.php, EnrichLinkCardJob.php, RefreshConnectionJob.php
- app/Jobs/Moderation/*.php, app/Jobs/Notifications/*.php, app/Jobs/Cloudflare/*.php
- app/Services/Streaming/TwitchApiClient.php, StreamingTokenManager.php
- app/Services/Cloudflare/CloudflarePurgeService.php
- app/, routes/, bootstrap/, database/ (full `env()`-outside-config sweep)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#CFG-1** · P2 — ~30+ env vars read in `config/partna.php`, `config/supabase.php`, and `config/services.php` have no corresponding entry in `.env.example`
    - **Where:** config/partna.php (streaming, idempotency, throttle blocks), config/supabase.php, config/services.php
    - **Affects:** Operations — new environments get working defaults, but operators can't discover which knobs are tunable without reading config source under pressure.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a commented entry (with default value + one-line purpose comment) to `.env.example` for each key below, following the existing convention used in the "Operational tuning knobs" section.
        - Prioritise the ones that gate cost/safety boundaries: streaming poll TTLs, idempotency lock/TTL windows, signup/login rate limits, JWT leeway.
    - **Technical:** Config files use `env('KEY', $default)` so the app runs fine without the var being set, but `.env.example` is the onboarding/incident-response contract. Verified missing: the full streaming TTL block (`PARTNA_STREAMING_LIVE_TTL`, `_WARM_OFFLINE_TTL`, `_COOL_OFFLINE_TTL`, `_COLD_OFFLINE_TTL`, `_TTL_SKIP_THRESHOLD`, `_KICK_RATE_LIMITED_TTL`), the full idempotency block (`PARTNA_IDEMPOTENCY_TTL_SECONDS`, `_LOCK_SECONDS`, `_MAX_BODY_BYTES`), `PARTNA_SIGNUP_AVAILABILITY_PER_MINUTE`/`_PER_HOUR`, `PARTNA_LOGIN_IDENTIFIER_PER_MINUTE`, `SUPABASE_JWT_LEEWAY_SECONDS`, `SUPABASE_HTTP_TIMEOUT_SECONDS`, and `TWITCH_STREAMS_URL`/`KICK_CHANNELS_URL` in `config/services.php`. Note: DeepSeek's draft also flagged `HORIZON_NOTIFICATION_EMAIL`/`_SLACK_WEBHOOK`/`_SLACK_CHANNEL` as missing with no `.env.example` entry — verified false, they are already documented (commented) at `.env.example` lines 74–76; that claim is dropped from this finding.
    - **Plain English:** Think of `.env.example` as the labeled control panel for the app. Every knob that actually exists in the code should have a labeled (even if switched-off) slot on that panel. Right now a real set of knobs — streaming poll windows, idempotency lock timing, signup/login rate limits, JWT clock-skew tolerance — exist in the machine but aren't on the panel, so an operator under incident pressure has to go read source code instead of scanning one file.
    - **Evidence:**
        ```php
        // config/partna.php
        'live_ttl_seconds' => (int) env('PARTNA_STREAMING_LIVE_TTL', 180),
        'warm_offline_ttl' => (int) env('PARTNA_STREAMING_WARM_OFFLINE_TTL', 180),
        'cool_offline_ttl' => (int) env('PARTNA_STREAMING_COOL_OFFLINE_TTL', 600),
        'cold_offline_ttl' => (int) env('PARTNA_STREAMING_COLD_OFFLINE_TTL', 1800),
        ...
        'lock_seconds' => (int) env('PARTNA_IDEMPOTENCY_LOCK_SECONDS', 120),
        ...
        'signup_availability_per_minute' => (int) env('PARTNA_SIGNUP_AVAILABILITY_PER_MINUTE', 10),
        'login_identifier_per_minute' => (int) env('PARTNA_LOGIN_IDENTIFIER_PER_MINUTE', 20),
        ```
        ```php
        // config/supabase.php
        'jwt_leeway_seconds' => (int) env('SUPABASE_JWT_LEEWAY_SECONDS', 60),
        'http_timeout_seconds' => (int) env('SUPABASE_HTTP_TIMEOUT_SECONDS', 5),
        ```
        ```php
        // config/services.php
        'streams_url' => env('TWITCH_STREAMS_URL', 'https://api.twitch.tv/helix/streams'),
        'channels_url' => env('KICK_CHANNELS_URL', 'https://api.kick.com/public/v1/channels'),
        ```

- [ ] **#CFG-2** · P2 — `PARTNA_BRAND_SCAN_ENABLED` defaults to `true`, the only `*_ENABLED` flag in `config/partna.php` that doesn't default safe-off
    - **Where:** config/partna.php (brand_scan block, ~line 1134)
    - **Affects:** Any fresh environment where `PARTNA_BRAND_SCAN_URL`/`_TOKEN` later get populated (e.g. staging copies prod env for a config test) — the scanner activates without a corresponding opt-in on the `_ENABLED` flag.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `(bool) env('PARTNA_BRAND_SCAN_ENABLED', true)` to default `false`, matching every sibling flag (`logo_removal.enabled`, `video_uploads_enabled`, `features.smart_booking`, etc.).
        - Update the `.env.example` entry from `PARTNA_BRAND_SCAN_ENABLED=true` to `false`.
    - **Technical:** The flag currently relies on a *second*, independent safety mechanism (empty `PARTNA_BRAND_SCAN_URL`/`_TOKEN` → client fails closed, `ok:false`) rather than the `_ENABLED` flag itself being off. This is inconsistent with the codebase's own sibling pattern: `logo_removal` — architecturally identical (self-hosted Cloudflare Worker + Container, URL + token gate) — defaults `enabled` to `false` and documents "Ships DISABLED. Turn on only once the container is deployed." Two safety mechanisms should fail independently; today, populating `PARTNA_BRAND_SCAN_URL`/`_TOKEN` in an environment (e.g. copying a partial prod `.env` to staging for another test) silently activates brand scanning with no explicit `_ENABLED=true` step, because the flag was never off to begin with.
    - **Plain English:** Every feature switch in this file starts in the "off" position except one — the brand scanner — which starts "on" but is currently harmless only because a second setting (the scanner's address) is usually left blank. It's like a power switch that's always flipped to "on," relying entirely on the extension cord being unplugged to stay safe. If someone plugs the cord in without also flipping the switch off first, the appliance runs — which isn't what "off by default" is supposed to mean.
    - **Evidence:**
        ```php
        // config/partna.php — the only *_ENABLED flag defaulting to true
        'brand_scan' => [
            'enabled' => (bool) env('PARTNA_BRAND_SCAN_ENABLED', true),

        // compare:
        'logo_removal' => [
            'enabled' => (bool) env('PARTNA_LOGO_REMOVAL_ENABLED', false),
        ```

- [ ] **#CFG-3** · P2 — `InstagramConnectJob` hardcodes `Storage::disk('media')` in three places instead of `MediaDiskResolver::resolve()`
    - **Where:** app/Jobs/Platforms/InstagramConnectJob.php (mirrorOne, mirrorVideo, handle's stale-file cleanup)
    - **Affects:** Mirrored Instagram media (profile pics, post covers, reels) — lands on whatever disk `'media'` points to at config-cache time, ignoring a runtime disk override.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Resolve the disk once via `MediaDiskResolver::resolve()` at the top of `handle()` and reuse it across `mirrorOne`, `mirrorVideo`, and the stale-file cleanup (pass it through as a parameter or a private property).
        - Add a test proving a `PARTNA_MEDIA_DISK` runtime override redirects Instagram mirror writes.
    - **Technical:** Every other media write path — `ImageVariantService`, `VideoVariantService`, `ProcessImageVariantsJob`, `ProcessLogoVariantsJob`, `ProcessVideoVariantsJob` (via `$service->resolvedDiskName()`) — routes through `MediaDiskResolver::resolve()`, which explicitly exists because "Laravel Cloud caches config at deploy time but injects platform env vars into the process environment at runtime" (per the resolver's own docblock). `InstagramConnectJob::mirrorOne()`/`mirrorVideo()`/`handle()` instead call the bare string `Storage::disk('media')` three times, resolving `config/filesystems.php`'s `disks.media` at config-cache time only. A runtime `PARTNA_MEDIA_DISK` override (the documented Laravel Cloud pattern) silently leaves mirrored Instagram content on the old disk while every other media artifact moves.
    - **Plain English:** The company's delivery vans all check a dispatch board each morning for which warehouse to use. The Instagram van's driver has "Warehouse B" painted on the dashboard instead and never checks the board. If the board switches everyone to Warehouse C, the Instagram van still delivers to B — and nobody notices until customers report broken photo links.
    - **Evidence:**
        ```php
        // mirrorOne()
        Storage::disk('media')->put($path, $body);
        return Storage::disk('media')->url($path);

        // mirrorVideo()
        Storage::disk('media')->put($path, $stream);
        return Storage::disk('media')->url($path);

        // handle() — stale file cleanup
        Storage::disk('media')->delete($stale);
        ```
        ```php
        // Contrast — app/Jobs/ProcessImageVariantsJob.php:131
        $diskName = $service->resolvedDiskName();
        ```

- [ ] **#CFG-4** · P2 — `ExportUserDataJob` and `PruneCompletedExportsCommand` read `config('partna.media_disk')` directly instead of `MediaDiskResolver::resolve()`
    - **Where:** app/Jobs/Gdpr/ExportUserDataJob.php:90,151 and app/Console/Commands/PruneCompletedExportsCommand.php:42
    - **Affects:** GDPR export ZIP uploads (right-of-access deliveries) and completed-export artifact pruning — both may write to or prune the wrong storage backend after a runtime `PARTNA_MEDIA_DISK` change.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace both `Storage::disk(config('partna.media_disk'))` / `Storage::disk((string) config('partna.media_disk'))` call sites with `Storage::disk(MediaDiskResolver::resolve())`.
        - Add a test proving the runtime `$_ENV`/`$_SERVER` probe overrides the cached config value for both call sites.
    - **Technical:** `MediaDiskResolver::resolve()` exists precisely to close this gap — its docblock states "IMPORTANT: Always call resolve() rather than reading config('partna.media_disk') directly — the superglobal probe may override config at runtime." `ExportUserDataJob::handle()` (both the upload and the orphan-cleanup delete) and `PruneCompletedExportsCommand::handle()` both bypass it. Because a GDPR export ZIP is written and later read back within the same job run using one `$disk` handle, a stale-config disk stays internally consistent for that one run — but if a disk migration happens between export requests, exported personal-data archives silently accumulate on a decommissioned bucket rather than the current one, and the prune command may fail to find (and thus fail to delete) artifacts it's supposed to be enforcing retention on.
    - **Plain English:** Imagine an office with a mail-sorting robot that checks a whiteboard each morning for the correct delivery room. Most departments trust the robot. Two clerks — the ones handling legally-required customer-data exports — instead walk straight to the room number written in an old printed handbook. If someone updates the whiteboard mid-week (a routine storage migration), those two clerks keep delivering sensitive customer data exports to the old room.
    - **Evidence:**
        ```php
        // app/Jobs/Gdpr/ExportUserDataJob.php — handle()
        $disk = Storage::disk(config('partna.media_disk'));
        ```
        ```php
        // app/Console/Commands/PruneCompletedExportsCommand.php — handle()
        $disk = Storage::disk((string) config('partna.media_disk'));
        ```
        ```php
        // app/Services/Media/MediaDiskResolver.php — the canonical path they bypass
        // IMPORTANT: Always call resolve() rather than reading config('partna.media_disk')
        // directly — the superglobal probe may override config at runtime.
        public static function resolve(): string
        ```

## P3 — Nice to have

- [ ] **#CFG-5** · P3 — Three job constructors read queue-routing config with no fallback default
    - **Where:** app/Jobs/Platforms/EnrichLinkCardJob.php:40, app/Jobs/Platforms/RefreshConnectionJob.php:53, app/Jobs/Gdpr/ExportUserDataJob.php:33
    - **Affects:** Queue routing — jobs intended for isolated Horizon supervisors silently join `default` if the config key is ever absent, causing resource contention.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a fallback second argument matching the config file's own default: `config('partna.queues.scraping', 'scraping')` (×2) and `config('partna.gdpr.queue', 'gdpr')`.
    - **Technical:** All three keys already resolve reliably today (`config/partna.php` defines `'scraping' => env('PARTNA_QUEUE_SCRAPING', 'scraping')` and `'gdpr' => [... 'queue' => env('PARTNA_GDPR_QUEUE', env('GDPR_QUEUE', 'gdpr'))]`), so this is not currently causing misrouting. It's a consistency gap: sibling jobs (`app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`, `CloudflareCachePurgeJob.php`) call `config('partna.queues.cloudflare', 'cloudflare')` with a fallback, so if the `queues.scraping`/`gdpr.queue` config key is ever removed or renamed without every call site being updated in lockstep, these three jobs fall onto `default` instead of failing loudly.
    - **Plain English:** Three conveyor belts each have their own speed and handling rules, and today every package is correctly labeled. But three workers were never told what to do "if the label goes missing" — so if that ever happens, they'd default to dumping everything on the main belt instead of raising a flag. Writing "if unlabeled, use belt X" on their instructions closes that gap before it matters.
    - **Evidence:**
        ```php
        // app/Jobs/Platforms/EnrichLinkCardJob.php:40 — no fallback
        $this->onQueue(config('partna.queues.scraping'));

        // app/Jobs/Platforms/RefreshConnectionJob.php:53 — no fallback
        $this->onQueue(config('partna.queues.platform_refresh'));

        // app/Jobs/Gdpr/ExportUserDataJob.php:33 — no fallback
        $this->onQueue(config('partna.gdpr.queue'));
        ```
        ```php
        // Contrast — app/Jobs/Cloudflare/CloudflareCachePurgeJob.php:60
        $this->onQueue(config('partna.queues.cloudflare', 'cloudflare'));
        ```

- [ ] **#CFG-6** · P3 — Ten moderation and notification jobs hardcode queue-name literals instead of reading `config('partna.*')`
    - **Where:** app/Jobs/Moderation/{NotifyOnCallStaffJob,NotifyReportedUserJob,NotifyReporterJob,NotifyStaffOfCaseUpdateJob,PurgeModerationCacheJob,QuarantineMediaJob,SuspendSiteJob,SuspendUserJob}.php, app/Jobs/Notifications/{DispatchEnquiryNotificationsJob,SendEnquiryNotificationJob}.php
    - **Affects:** Queue routing for moderation enforcement and enquiry-notification delivery — a typo during a refactor silently routes a job to an unmonitored queue.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - The 8 moderation jobs: change `$this->queue = 'moderation_high';` to `$this->queue = config('partna.moderation.queue.high_priority_lane', 'moderation_high');` — this config key **already exists** (`config/partna.php`'s `moderation.queue.high_priority_lane`, env `PARTNA_MODERATION_HIGH_LANE`, already documented commented-out in `.env.example`). No new config key needed.
        - `DispatchEnquiryNotificationsJob`: change `$this->queue = 'notifications';` to `$this->queue = config('partna.queues.notifications', 'notifications');` — also an existing key, matching the pattern every other notification job in the same directory already uses.
        - `SendEnquiryNotificationJob`: change `$this->onQueue('notifications');` to `$this->onQueue(config('partna.queues.notifications', 'notifications'));`.
    - **Technical:** DeepSeek's draft proposed "add missing keys to config/partna.php under queues" — verified against current source and that's unnecessary: `config('partna.moderation.queue.high_priority_lane')` (default `'moderation_high'`) already exists (`config/partna.php` moderation block) with its `.env.example` entry (`# PARTNA_MODERATION_HIGH_LANE=moderation_high`), and `config('partna.queues.notifications')` already exists and is the pattern used by every sibling job in `app/Jobs/Notifications/` (`SendSubscriptionConfirmationJob`, `SendFeedbackEmailJob`, `SendEnquiryConfirmationJob`, `SendStaffBroadcastEmailsJob`, etc. all call `config('partna.queues.notifications', 'notifications')`). The fix is wiring these 10 outlier constructors to the config keys that already exist, not adding new ones. The "PHP 8.4 trait conflict" comment in each moderation job (`// Queueable::$queue is untyped; assign in constructor to avoid PHP 8.4 trait conflict.`) explains why `$queue` is set in the constructor body rather than as a typed property — it does not explain why the constructor body assigns a literal instead of a `config()` read; the two concerns are orthogonal.
    - **Plain English:** Every package in the warehouse has its destination floor hand-written on the box instead of using the central routing system. Most of the time this is fine — everyone writes "Floor 3" correctly. But if you ever need to redirect moderation packages to a different floor, you have to find and relabel ten separate box templates by hand, and a typo on any one of them sends that box to a corner nobody's watching.
    - **Evidence:**
        ```php
        // app/Jobs/Moderation/NotifyOnCallStaffJob.php:32
        $this->queue = 'moderation_high';

        // app/Jobs/Moderation/SuspendUserJob.php:28
        $this->queue = 'moderation_high';

        // app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php:35
        $this->queue = 'notifications';

        // app/Jobs/Notifications/SendEnquiryNotificationJob.php:44
        $this->onQueue('notifications');
        ```
        ```php
        // config/partna.php — the key the moderation jobs should read (already exists)
        'queue' => [
            'high_priority_lane' => env('PARTNA_MODERATION_HIGH_LANE', 'moderation_high'),
        ],
        ```
        ```php
        // Contrast — app/Jobs/Notifications/SendEnquiryConfirmationJob.php:37
        $this->onQueue(config('partna.queues.notifications', 'notifications'));
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Media-disk resolver bypass:** #CFG-3, #CFG-4
    - **Why grouped:** identical root cause (bypasses `MediaDiskResolver::resolve()`, the canonical runtime-safe disk lookup) and identical mechanical fix across all 5 call sites.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Queue-routing config-drivenness:** #CFG-5, #CFG-6
    - **Why grouped:** same root cause (queue name not fully sourced from config), same file family (`app/Jobs/`), fixes are mechanical constructor edits pointing at config keys that already exist.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — `.env.example` & default-flag hygiene:** #CFG-1, #CFG-2
    - **Why grouped:** both touch `config/partna.php` + `.env.example` only — no application-code behavior change, pure documentation/default-value correctness.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.
