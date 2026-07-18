# Configuration Hygiene Audit — 2026-07-05

**Branch:** development
**Lens:** Configuration Hygiene — `env()` outside config, missing `.env.example` keys, feature flags without safe defaults, hardcoded values that should be config-driven
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet`
**Source files audited:**
- config/partna.php
- config/services.php
- .env.example
- app/Jobs/Moderation/NotifyOnCallStaffJob.php
- app/Jobs/Moderation/NotifyReportedUserJob.php
- app/Jobs/Moderation/QuarantineMediaJob.php
- app/Jobs/Moderation/NotifyReporterJob.php
- app/Jobs/Moderation/NotifyStaffOfCaseUpdateJob.php
- app/Jobs/Moderation/PurgeModerationCacheJob.php
- app/Jobs/Moderation/SuspendSiteJob.php
- app/Jobs/Moderation/SuspendUserJob.php
- app/Jobs/Notifications/SendEnquiryNotificationJob.php
- app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php
- app/Services/Streaming/KickApiClient.php
- app/Services/Streaming/LiveStatusPoller.php
- app/Jobs/Platforms/InstagramConnectJob.php
- app/Http/Middleware/IdempotencyKey.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 6 complete

---

## P2 — Should fix

- [ ] **CFG-1** · P2 — ~19 operational-tuning env keys with safe hardcoded defaults are undocumented in `.env.example`
    - **Where:** config/partna.php (streaming TTL block; signup/login throttles; moderation report throttles; connect/refresh tuning; retention windows); config/services.php (`twitch.streams_url`, `kick.channels_url`)
    - **Affects:** On-call/ops engineers responding to a live incident (Twitch/Kick rate-limiting, a report-abuse wave, a Twitch/Kick API URL migration, a privacy-retention change) — the env override already works, but the key name is only discoverable by grepping source instead of reading the master `.env.example` reference.
    - **Effort:** M (~2–4h to enumerate + add ~19 commented entries across the existing "Operational tuning knobs" section)
    - **What to do:**
        - Add commented-out entries (with current defaults as example values) to the existing `# ── Operational tuning knobs ─────` block in `.env.example` for: `PARTNA_STREAMING_LIVE_TTL`, `PARTNA_STREAMING_WARM_OFFLINE_TTL`, `PARTNA_STREAMING_COOL_OFFLINE_TTL`, `PARTNA_STREAMING_COLD_OFFLINE_TTL`, `PARTNA_STREAMING_TTL_SKIP_THRESHOLD`, `PARTNA_STREAMING_KICK_RATE_LIMITED_TTL`.
        - Add `# TWITCH_STREAMS_URL=https://api.twitch.tv/helix/streams` and `# KICK_CHANNELS_URL=https://api.kick.com/public/v1/channels` alongside the existing `TWITCH_CLIENT_ID`/`KICK_CLIENT_ID` entries.
        - Add `PARTNA_SIGNUP_AVAILABILITY_PER_MINUTE`, `PARTNA_SIGNUP_AVAILABILITY_PER_HOUR`, `PARTNA_LOGIN_IDENTIFIER_PER_MINUTE` under the rate-limits section.
        - Add `PARTNA_REPORT_PUBLIC_THROTTLE_REQUESTS`, `PARTNA_REPORT_PUBLIC_THROTTLE_MINUTES`, `PARTNA_REPORT_TARGET_THROTTLE_REQUESTS`, `PARTNA_REPORT_TARGET_THROTTLE_WINDOW_MIN` under a `# Moderation` subheading.
        - Add `PARTNA_CONNECT_RATE_DEFAULT` and `PARTNA_REFRESH_YTIMG_HQ_RECHECK_TTL` next to the already-documented `PARTNA_REFRESH_YTIMG_POOL` / `PARTNA_REFRESH_FETCH_MANY_POOL`.
        - Add `PARTNA_UNSUBSCRIBED_RETENTION_DAYS` and `PARTNA_MODERATION_SIGNAL_PII_RETENTION_DAYS` under retention windows.
    - **Technical:** All of these read via `env('KEY', <safe default>)` and none change behaviour today — this is a discoverability gap, not a runtime bug. `.env.example` is the canonical incident-response reference (it already documents sibling keys in each of these blocks — `PARTNA_STREAMING_MAX_LIVE_CHECK_PER_SITE`, `PARTNA_ENQUIRY_NOTIFY_PER_HOUR`, `PARTNA_REFRESH_YTIMG_POOL`, `PARTNA_ANALYTICS_RAW_EVENT_RETENTION_DAYS`), which makes the omission of these ~19 keys a visible inconsistency during incident-response skimming rather than a one-off oversight.
    - **Plain English:** Partna has a lot of adjustable safety knobs — how often to check if someone is live-streaming, how many signup attempts to allow per minute, how long to keep certain personal data. All of these already have sensible starting values, so nothing is broken today. But the master settings list (the file a new environment or an engineer under pressure reads first) is missing about 19 of these knob names. It's like a fuse box where most fuses are labelled but a chunk aren't — the electrician can still find the right one, just slower, and slower is exactly what you don't want during an incident.
    - **Evidence:**
        ```php
        // config/partna.php — streaming
        'live_ttl_seconds' => (int) env('PARTNA_STREAMING_LIVE_TTL', 180),
        'warm_offline_ttl' => (int) env('PARTNA_STREAMING_WARM_OFFLINE_TTL', 180),
        'cool_offline_ttl' => (int) env('PARTNA_STREAMING_COOL_OFFLINE_TTL', 600),
        'cold_offline_ttl' => (int) env('PARTNA_STREAMING_COLD_OFFLINE_TTL', 1800),
        'ttl_skip_threshold' => (int) env('PARTNA_STREAMING_TTL_SKIP_THRESHOLD', 60),
        'kick_rate_limited_ttl' => (int) env('PARTNA_STREAMING_KICK_RATE_LIMITED_TTL', 300),
        ```
        ```php
        // config/services.php
        'streams_url' => env('TWITCH_STREAMS_URL', 'https://api.twitch.tv/helix/streams'),
        'channels_url' => env('KICK_CHANNELS_URL', 'https://api.kick.com/public/v1/channels'),
        ```
        ```php
        // config/partna.php — auth throttles
        'signup_availability_per_minute' => (int) env('PARTNA_SIGNUP_AVAILABILITY_PER_MINUTE', 10),
        'signup_availability_per_hour' => (int) env('PARTNA_SIGNUP_AVAILABILITY_PER_HOUR', 60),
        'login_identifier_per_minute' => (int) env('PARTNA_LOGIN_IDENTIFIER_PER_MINUTE', 20),
        ```
        ```php
        // config/partna.php — moderation reporting
        'public_throttle' => [
            'requests' => (int) env('PARTNA_REPORT_PUBLIC_THROTTLE_REQUESTS', 5),
            'minutes' => (int) env('PARTNA_REPORT_PUBLIC_THROTTLE_MINUTES', 1),
        ],
        'per_target_throttle' => [
            'requests' => (int) env('PARTNA_REPORT_TARGET_THROTTLE_REQUESTS', 3),
            'window_minutes' => (int) env('PARTNA_REPORT_TARGET_THROTTLE_WINDOW_MIN', 60),
        ],
        ```
        ```php
        // config/partna.php — connect/refresh + retention
        'default' => (int) env('PARTNA_CONNECT_RATE_DEFAULT', 20),
        'hq_recheck_ttl_seconds' => (int) env('PARTNA_REFRESH_YTIMG_HQ_RECHECK_TTL', 21600),
        'unsubscribed_retention_days' => (int) env('PARTNA_UNSUBSCRIBED_RETENTION_DAYS', 365),
        'signal_pii_retention_days' => (int) env('PARTNA_MODERATION_SIGNAL_PII_RETENTION_DAYS', 90),
        ```

## P3 — Nice to have

- [ ] **CFG-2** · P3 — ~23 legacy `SIDEST_*` fallback env keys consumed by config/partna.php are undocumented in `.env.example`
    - **Where:** config/partna.php (throughout — `env('PARTNA_*', env('SIDEST_*', <default>))` chains, e.g. gallery/content image caps, video upload limits, image quality/pixel caps, ffmpeg paths, video queue settings, feature flags)
    - **Affects:** Developers migrating an old `.env` file, or anyone who copies a `SIDEST_*` var from another Partna-derived project and wonders whether it's actually read.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a single commented block at the bottom of `.env.example`: "Legacy (deprecated) — read only as fallbacks when the matching `PARTNA_*` key is unset. Prefer the `PARTNA_*` equivalents above," listing the ~23 keys (or inline `# (legacy fallback)` comments next to the corresponding `PARTNA_*` entries).
    - **Technical:** This is a deliberate, working migration pattern (new `PARTNA_*` key takes priority, old `SIDEST_*` key still honoured for existing deployments) — `.env.example` already calls out one instance of this pattern (`PARTNA_PUBLIC_DOMAIN` / legacy `SIDEST_PUBLIC_DOMAIN`, line 13) but not the ~23 others across the image, video, and feature-flag blocks. Risk is low since behaviour is correct either way — this is pure documentation debt.
    - **Plain English:** Partna renamed a lot of settings from an old naming scheme to a new one, but kept the old names working as a quiet backup. The master settings list only shows the new names, so someone reusing an old config file can't tell from that list alone whether their old-named settings are still being honoured.
    - **Evidence:**
        ```php
        'gallery' => ['max' => (int) env('PARTNA_GALLERY_IMAGE_MAX', env('SIDEST_GALLERY_IMAGE_MAX', 6))],
        'content' => ['max' => (int) env('PARTNA_CONTENT_IMAGE_MAX', env('SIDEST_CONTENT_IMAGE_MAX', 6))],
        'image_max_upload_size' => (int) env('PARTNA_IMAGE_MAX_UPLOAD_KB', env('SIDEST_IMAGE_MAX_UPLOAD_KB', 10240)),
        'video_uploads_enabled' => (bool) env('PARTNA_VIDEO_UPLOADS_ENABLED', env('SIDEST_VIDEO_UPLOADS_ENABLED', false)),
        ```

- [ ] **CFG-3** · P3 — `config('app.url')` used as a fallback default inside config/partna.php creates a config-cache staleness edge case
    - **Where:** config/partna.php (`public_profile.analytics_endpoint`, ~line 927)
    - **Affects:** Deployments where `APP_URL` changes without a `config:cache` rebuild — the analytics beacon endpoint returned to the sitepage would silently point at the old URL.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either drop the `config('app.url')` fallback in favour of a static/env-only default, or add a comment documenting that changing `APP_URL` requires a `config:cache` rebuild for this key to pick it up.
    - **Technical:** `config/partna.php` calls `config('app.url')` at config-load time to build the fallback for `PARTNA_PUBLIC_ANALYTICS_ENDPOINT`. Because `config/app.php` loads first alphabetically, this resolves correctly on a live load, but `php artisan config:cache` (standard in production) bakes the resolved string into the cache. If `APP_URL` later changes without a fresh `config:cache`, the analytics endpoint silently keeps pointing at the old host — and the payload builder has no way to detect the drift. `APP_URL` changes are rare and normally accompanied by a full deploy (which does rebuild the cache), so real-world exposure is low.
    - **Plain English:** The analytics tracking address is built by combining the main site address with a fixed path, calculated once and then cached. If the main site address ever changes without also refreshing that cache, the tracking address quietly keeps pointing at the old one — like a forwarding sticker on a mailbox that still shows the previous street name until someone remembers to peel it off.
    - **Evidence:**
        ```php
        'analytics_endpoint' => env(
            'PARTNA_PUBLIC_ANALYTICS_ENDPOINT',
            rtrim(config('app.url'), '/').'/api/analytics'
        ),
        ```

- [ ] **CFG-4** · P3 — `FRESHA_BOOKING_INIT_HASH` / `FRESHA_CLIENT_VERSION` are listed empty (not commented out) in `.env.example`, defeating their config-level fallback defaults
    - **Where:** .env.example:437-438; config/services.php:103-104
    - **Affects:** Any fresh environment provisioned by copying `.env.example` → `.env` (a standard Laravel bootstrap step) — Fresha's `fetchEmployeeServices` call silently loses its pinned persisted-query hash/client version.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Comment out both lines in `.env.example` (`# FRESHA_BOOKING_INIT_HASH=` / `# FRESHA_CLIENT_VERSION=`) so a fresh `.env` leaves the keys genuinely unset instead of explicitly empty.
    - **Technical:** `config/services.php` defines `'booking_init_hash' => env('FRESHA_BOOKING_INIT_HASH', '4ea9d1b3...')` — a real fallback value, not a null-safe placeholder. Laravel's `env()` only falls back to the default when the key is absent from the environment; if `.env` contains the key with an explicit empty string (which is exactly what happens when `.env.example`'s uncommented `FRESHA_BOOKING_INIT_HASH=` line is copied verbatim), `env()` returns `''`, not the default hash. The lens's own guidance for legacy/pinned-value keys is to ship them as commented-out stubs for exactly this reason — every other "safe when unset" key in this file (`RESEND_API_KEY=`, `GOOGLE_MAPS_API_KEY=`, etc.) has no meaningful default to lose, so the same uncommented-empty pattern is harmless there but not here. Impact is graceful-degrade rather than a crash (the code comment states `fetchEmployeeServices` falls back to the whole-location menu when the hash is stale/wrong), which keeps this at P3.
    - **Plain English:** Two settings hold a special code that lets Partna talk to Fresha's booking system correctly. There's a good backup value built into the code for when these aren't set — but the master settings file currently has them present-but-blank instead of fully absent, and blank-but-present quietly overrides the backup value with nothing. It's a subtle trap: it looks like "not configured, use the safe backup" but actually behaves like "configured to be broken." The saving grace is the code already has a soft fallback behaviour for this exact case, so nothing crashes — a feature just quietly gets worse.
    - **Evidence:**
        ```
        FRESHA_BOOKING_INIT_HASH=
        FRESHA_CLIENT_VERSION=
        ```
        ```php
        'fresha' => [
            'booking_init_hash' => env('FRESHA_BOOKING_INIT_HASH', '4ea9d1b31075d62f789fcec884c45d76aaeb42e56ffb1b78cc1b7f7c557ad7cb'),
            'client_version' => env('FRESHA_CLIENT_VERSION', 'd135e4b3a3be51f9dd24f5cc2af6dd6a647f85dd'),
        ],
        ```

- [ ] **CFG-5** · P3 — Hardcoded idempotency cache TTL, lock duration, and body cap in middleware
    - **Where:** app/Http/Middleware/IdempotencyKey.php:16-20
    - **Affects:** Operational agility — tuning the response-cache lifetime, lock window, or cacheable body size requires a code deploy instead of an env change.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `TTL_SEC`, `LOCK_SEC`, and `MAX_BODY_BYTES` into a new `config('partna.idempotency', [...])` block, each backed by `env('PARTNA_IDEMPOTENCY_*', <current value>)`.
        - Read the values from config in the middleware, keeping the current constants as the config defaults.
    - **Technical:** The middleware hardcodes the response-cache lifetime (24h), the distributed-lock TTL (120s), and the cacheable body-size cap (256 KiB) as private class constants with no config or env override. These are legitimate operational levers (e.g. shortening the lock window during a spike, or raising the body cap for a richer API response) that currently require a full deploy to change.
    - **Plain English:** This middleware keeps a copy of certain API responses for 24 hours so a repeated request doesn't redo the work — like a receipt kept in a locked drawer. The "keep for 24 hours" and "drawer key valid for 2 minutes" rules are hardwired into the code. If ops ever needed to shorten or lengthen either, they'd need a full software release instead of just changing a setting.
    - **Evidence:**
        ```php
        private const TTL_SEC = 86_400;        // 24h response cache

        private const LOCK_SEC = 120;          // distributed lock TTL — sized for slow synchronous handlers (mail dispatch, R2 upload). Raise further only if a handler legitimately exceeds 2 min.

        private const MAX_BODY_BYTES = 262_144; // 256 KB cache body cap (bigger payloads bypass cache)
        ```

- [ ] **CFG-6** · P3 — Hardcoded API batch sizes and Instagram fetch timeouts/size caps with no config override
    - **Where:** app/Services/Streaming/KickApiClient.php:23; app/Services/Streaming/LiveStatusPoller.php:41,43; app/Jobs/Platforms/InstagramConnectJob.php:70-84
    - **Affects:** Operational agility when an upstream (Twitch/Kick/Instagram CDN) changes its rate limits or response characteristics — adjusting a batch size or transfer cap requires a full deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract `KickApiClient::KICK_BATCH_SIZE` and `LiveStatusPoller::TWITCH_BATCH_SIZE` / `KICK_BATCH_SIZE` into `config('partna.streaming.batch_sizes', [...])`, backed by env with the current values as defaults.
        - Move `InstagramConnectJob`'s `IMAGE_TIMEOUT_SECONDS`, `VIDEO_TIMEOUT_SECONDS`, `MAX_VIDEO_BYTES`, `MAX_IMAGE_BYTES` into a `config('partna.limits.instagram', [...])` block.
    - **Technical:** Note that `LiveStatusPoller`'s cold-handle demotion TTLs are already correctly config-driven (`config('partna.streaming.live_ttl_seconds', self::LIVE_TTL_DEFAULT)` etc.) — only the two batch-size constants and the Instagram job's timeout/byte-cap constants lack any config path. These are genuine operational tunables (matching upstream API limits and CDN latency characteristics) that the surrounding code already treats as config-worthy elsewhere in the same files, making the omission an inconsistency rather than a one-off.
    - **Plain English:** A few numbers that control how much data Partna asks for in one go from Twitch, Kick, and Instagram are locked into the code rather than being adjustable settings. If one of those services changes its rules (e.g. a stricter batch limit), fixing it means shipping new code instead of just changing a number.
    - **Evidence:**
        ```php
        // app/Services/Streaming/KickApiClient.php
        public const KICK_BATCH_SIZE = 50;
        ```
        ```php
        // app/Services/Streaming/LiveStatusPoller.php
        private const TWITCH_BATCH_SIZE = 100;

        private const KICK_BATCH_SIZE = 50;           // Matches KickApiClient::KICK_BATCH_SIZE
        ```
        ```php
        // app/Jobs/Platforms/InstagramConnectJob.php
        private const IMAGE_TIMEOUT_SECONDS = 10;
        private const VIDEO_TIMEOUT_SECONDS = 30;
        private const MAX_VIDEO_BYTES = 52428800;
        private const MAX_IMAGE_BYTES = 15728640;
        ```

- [ ] **CFG-7** · P3 — Queue names hardcoded as string literals across moderation/notification jobs instead of reading `config('partna.queues.*')`
    - **Where:** app/Jobs/Moderation/NotifyOnCallStaffJob.php:32; app/Jobs/Moderation/QuarantineMediaJob.php:32; app/Jobs/Moderation/PurgeModerationCacheJob.php:35; app/Jobs/Moderation/SuspendSiteJob.php:29; app/Jobs/Moderation/SuspendUserJob.php:28; app/Jobs/Moderation/NotifyReportedUserJob.php:35; app/Jobs/Moderation/NotifyReporterJob.php:33; app/Jobs/Moderation/NotifyStaffOfCaseUpdateJob.php:45; app/Jobs/Notifications/SendEnquiryNotificationJob.php:44; app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php:35
    - **Affects:** Queue-routing consistency — re-routing a lane per environment, or recovering from a typo, needs a code change instead of an env override, for this subset of jobs.
    - **Effort:** M (~2–4h across ~10 files)
    - **What to do:**
        - Replace each hardcoded `$this->queue = 'moderation_high'` / `'notifications'` / `->onQueue('notifications')` with `config('partna.moderation.queue.high_priority_lane', 'moderation_high')` or `config('partna.queues.notifications', 'notifications')` respectively — matching the config keys that already exist for this exact purpose.
        - Audit remaining moderation/notification jobs for the same pattern while in the file.
    - **Technical:** `config/partna.php` already defines both target config paths — `queues.notifications` (env `PARTNA_QUEUE_NOTIFICATIONS`) and `moderation.queue.high_priority_lane` (env `PARTNA_MODERATION_HIGH_LANE`) — and `InstagramConnectJob` already correctly reads `config('partna.queues.scraping', 'scraping')` in its constructor, proving the pattern works fine in this exact context (assigning `$this->queue` in the constructor to dodge the PHP 8.4 typed-property/trait conflict noted in every one of these jobs' comments). The ~10 moderation/notification jobs above just hardcode the literal string instead of resolving it from config, so an environment-specific reroute or an incident-driven lane change would need a deploy for these jobs but not for `InstagramConnectJob`.
    - **Plain English:** Picture a group of couriers: some check a central signboard to know which loading dock to use, others just memorised "dock 3" months ago. If the signboard is updated — say, to fix a jam — only the couriers who actually check it will follow the change; the ones running on memory keep going to the old dock. Most of Partna's background jobs already check the signboard (a shared settings file); about ten moderation/notification jobs still run on memory.
    - **Evidence:**
        ```php
        // app/Jobs/Moderation/NotifyOnCallStaffJob.php
        public function __construct(public readonly string $actionLogId, public readonly string $caseId)
        {
            // Queueable::$queue is untyped; assign in constructor to avoid PHP 8.4 trait conflict.
            $this->queue = 'moderation_high';
        }
        ```
        ```php
        // app/Jobs/Notifications/SendEnquiryNotificationJob.php
        public function __construct(
            public readonly string $enquiryId,
            public readonly string $blockId,
        ) {
            $this->onQueue('notifications');
        }
        ```
        ```php
        // app/Jobs/Platforms/InstagramConnectJob.php — the pattern already done correctly elsewhere
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
        ```

## Suggested Bundled Sessions

- **Bundle 1 — `.env.example` documentation & fallback-default correctness:** CFG-1, CFG-2, CFG-3, CFG-4
    - **Why grouped:** All four are edits to `.env.example` / a config default expression, no code-path changes — one file, one low-risk PR.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Extract hardcoded operational constants into config:** CFG-5, CFG-6, CFG-7
    - **Why grouped:** Same root-cause pattern (magic numbers / queue names as hardcoded class constants instead of `config('partna.*')`); same fix shape (add config keys with current values as defaults, update each consumer to read them).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

None.
