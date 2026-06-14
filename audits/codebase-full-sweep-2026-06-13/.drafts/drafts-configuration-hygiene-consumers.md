- [ ] **#CFG-1** · P3 — Queue names hardcoded across 8+ jobs while others use config()
    - **Where:** Multiple files in app/Jobs/* (see Evidence)
    - **Affects:** Queue routing operability — a typo in a hardcoded queue name creates a silent routing failure with no Horizon worker consuming the job.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add queue name keys to `config/partna.php` for every queue used: `queues.notifications`, `queues.cloudflare`, `queues.cache_warm`, `queues.scraping`, `queues.streaming`, `queues.mail`, `queues.mail_batch`.
        - Replace hardcoded `$this->onQueue('...')` in every job with `$this->onQueue(config('partna.queues.*'))`.
        - Add a CI guard (extend the existing `JobHygienePolicyTest`) that fails any `ShouldQueue` job that calls `onQueue()` with a string literal not matching `config(`.
    - **Technical:** Laravel's queue system routes jobs to Horizon workers by queue name. When some jobs hardcode `'notifications'` and others use `config('partna.queues.notifications', 'notifications')`, there are two sources of truth. A rename in `.env` updates the config-driven jobs but leaves the hardcoded ones stranded on the old queue — they silently accumulate with no worker consuming them. The architecture already has the right pattern (`RecordAnalyticsEventJob` reads `config('partna.analytics_queue.name')`; `DeleteMediaArtifactsJob` reads `config('partna.video_queue.*')`), but it's applied inconsistently.
    - **Plain English:** Think of queue names like labeled mail-sorting bins in a post office. Most bins have their label pulled from a master list (the config file), so if you need to rename "Notifications" to "Notifications-V2," you change it in one place and all the sorting machines update. But a few bins have their labels written in permanent marker directly on the bin — those won't update, and mail will pile up in the old bin with nobody collecting it.
    - **Evidence:**
        ```php
        // SendAccountDeletionRequestMailJob.php (hardcoded)
        $this->onQueue('notifications');

        // SendEnquiryNotificationJob.php (hardcoded)
        $this->onQueue('notifications');
        
        // SendStaffBroadcastEmailsJob.php (hardcoded)
        $this->onQueue('notifications');
        
        // WarmPublicSiteCacheJob.php (hardcoded)
        $this->onQueue('cache-warm');
        
        // CloudflareCachePurgeJob.php (hardcoded)
        $this->onQueue('cloudflare');
        
        // InstagramConnectJob.php (hardcoded)
        $this->onQueue('scraping');
        
        // CheckStreamingLiveStatusJob.php (hardcoded)
        $this->onQueue('streaming');
        
        // SendStaffBroadcastEmailsJob.php (hardcoded in Bus::batch)
        ->onQueue('mail')
        
        // Contrast with — RecordAnalyticsEventJob.php (config-driven ✓)
        $this->onQueue((string) config('partna.analytics_queue.name', 'analytics'));
        
        // Contrast with — DeleteMediaArtifactsJob.php (config-driven ✓)
        $this->onQueue((string) config('partna.video_queue.name', 'videos'));
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CFG-2** · P3 — CircuitBreaker constructor defaults hardcoded instead of reading config values that already exist
    - **Where:** app/Services/BotProtection/CircuitBreaker.php:14-17
    - **Affects:** Bot protection circuit breaker behavior — changing `config('partna.bot_protection.circuit_breaker.*')` has no effect on the breaker's actual thresholds.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `CircuitBreaker`'s constructor to read `config('partna.bot_protection.circuit_breaker.failure_threshold', 5)`, `config('...window_seconds', 60)`, `config('...cooldown_seconds', 300)`.
        - Keep the defaults as fallback values so the class still works in a test container that hasn't loaded partna config.
    - **Technical:** `VerifyBotToken` already reads `config('partna.bot_protection.circuit_breaker.cooldown_seconds', 300)` for its log-dedup TTL, which means the config key exists and is expected to be honoured. But `CircuitBreaker` itself never reads config — it relies entirely on constructor-injected values. If the service container binding doesn't pass config values (and there's no evidence it does), the defaults 5/60/300 are the only values ever used. Worse, the log dedup TTL and the actual breaker cooldown can silently drift apart because they come from different sources for the same logical value.
    - **Plain English:** There's a dial on the dashboard labeled "Circuit Breaker Cooldown" that's wired to the warning light but not to the actual circuit breaker. Turning the dial changes when the warning light fires, but the breaker itself keeps tripping on its own fixed timer. The two get out of sync and nobody notices because the warning system and the protection system are reading from different instruction cards.
    - **Evidence:**
        ```php
        // CircuitBreaker.php — hardcoded defaults, no config reads
        public function __construct(
            private int $failureThreshold = 5,
            private int $windowSeconds = 60,
            private int $cooldownSeconds = 300,
        ) {}

        // VerifyBotToken.php — reads the SAME config key for logging only
        Redis::expire($key, (int) config('partna.bot_protection.circuit_breaker.cooldown_seconds', 300));
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#CFG-3** · P2 — Supabase webhook HMAC secrets excluded from EnvCheckService; missing secret ships unguarded webhook endpoints with no deploy-time warning
    - **Where:** app/Services/Diagnostics/EnvCheckService.php (entire REQUIRED and RECOMMENDED maps)
    - **Affects:** Production deploy safety — if `SUPABASE_AUTH_HOOK_SECRET` or `SUPABASE_EMAIL_HOOK_SECRET` is accidentally unset, both webhook endpoints return 503 to every request, but `env:check` and `php artisan env:check` report all-green.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `supabase.auth_hook_secret` → `SUPABASE_AUTH_HOOK_SECRET` to the REQUIRED map.
        - Add `services.supabase.email_hook_secret` → `SUPABASE_EMAIL_HOOK_SECRET` to the REQUIRED map.
        - Add both keys to `.env.example` with placeholder values and comments explaining they are HMAC secrets for Standard Webhooks verification.
    - **Technical:** Both `VerifySupabaseAuthHookSignature` and `VerifySupabaseEmailHookSignature` fail-closed (return 503) when their respective secrets are empty strings. This is the correct security posture — an unconfigured endpoint must not accept requests. However, `EnvCheckService` is the single source of truth for deploy-time env-var verification, consumed by both the `env:check` artisan command and the `/api/internal/env-check` HTTP endpoint. Neither webhook secret appears in `REQUIRED` or `RECOMMENDED`, so a deploy with a missing `SUPABASE_AUTH_HOOK_SECRET` produces a `status: 'ok'` report while silently breaking all Supabase auth hook deliveries (user creation, deletion, MFA enrollment events stop flowing).
    - **Plain English:** The pre-flight safety checklist that runs before every deploy checks "do we have fuel? engines? landing gear?" It's missing two items: "fire suppression system" and "cabin pressure." The plane can take off, the checklist says everything's fine, but the moment those systems are needed, they don't work. Adding those two items to the checklist means a deploy that forgets them gets caught on the ground.
    - **Evidence:**
        ```php
        // EnvCheckService.php — Cloudflare tokens ARE checked, webhook secrets ARE NOT
        // REQUIRED includes 'services.cloudflare.cache_purge_token' but has zero
        // entries for 'supabase.auth_hook_secret' or 'services.supabase.email_hook_secret'.
        // RECOMMENDED has no Supabase-hook entries either.
        public const REQUIRED = [
            // ... app, DB, Redis, cache, Supabase Auth ...
            'Cloudflare (DNS + KV + Purge)' => [
                // ...
                'services.cloudflare.cache_purge_token' => 'CLOUDFLARE_CACHE_PURGE_TOKEN',
            ],
        ];
        // No 'Supabase Webhooks' group exists.

        // Meanwhile, both middleware fail-closed on missing secret:
        // VerifySupabaseAuthHookSignature.php
        $secret = (string) config('supabase.auth_hook_secret', '');
        if ($secret === '') {
            return response()->json([...'Auth hook is not configured.'], 503);
        }

        // VerifySupabaseEmailHookSignature.php
        $secret = (string) config('services.supabase.email_hook_secret', '');
        if ($secret === '') {
            return response()->json([...'Email hook is not configured.'], 503);
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#CFG-4** · P3 — LiveStatusPoller TTL constants are hardcoded class constants with no config path
    - **Where:** app/Services/Streaming/LiveStatusPoller.php:30-48
    - **Affects:** Streaming status polling behavior in all environments — tuning cold-handle demotion thresholds requires a code change and deploy rather than a config update.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the six TTL constants (`LIVE_TTL_SECONDS`, `WARM_OFFLINE_TTL`, `COOL_OFFLINE_TTL`, `COLD_OFFLINE_TTL`, `TTL_SKIP_THRESHOLD`, `KICK_RATE_LIMITED_TTL`) into `config/partna.php` under `streaming.ttls.*` with the current values as defaults.
        - Update `LiveStatusPoller` and `KickApiClient` (for the rate-limit TTL) to read from `config('partna.streaming.ttls.*')`.
    - **Technical:** The cold-handle demotion system is the primary scalability lever for the streaming poller — it determines how often offline handles are re-polled, which directly controls API budget consumption against Twitch and Kick rate limits. Hardcoding these as private class constants means adjusting them for different environments (staging with fewer handles and a smaller API budget vs. production at scale) requires a code change. The pattern already exists elsewhere in the codebase — `VideoVariantService` reads `config('partna.video_max_duration_seconds')` for its analogous tuning parameter.
    - **Plain English:** The streaming poller has a clever "slow down for inactive streamers" system — like a delivery driver who learns which houses never answer and stops knocking every day, switching to once a week. The timing rules for when to slow down (3 misses? 11 misses? how long to wait?) are baked into the code like a recipe carved in stone. If you want to adjust them — say, to be more aggressive during a launch week when you have fewer users — you need to chisel new stone. Moving them to the config file is like putting the recipe on a whiteboard.
    - **Evidence:**
        ```php
        // LiveStatusPoller.php — all TTLs are hardcoded private constants
        private const LIVE_TTL_SECONDS = 180;
        private const WARM_OFFLINE_TTL = 180;
        private const COOL_OFFLINE_TTL = 600;
        private const COLD_OFFLINE_TTL = 1800;
        private const TTL_SKIP_THRESHOLD = 60;
        private const KICK_RATE_LIMITED_TTL = 300;
        
        // Used inline throughout pollKick(), writeStatus(), filterStaleHandles()
        // No config() call in the entire class
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#CFG-5** · P3 — Streaming API client URLs hardcoded as class constants instead of config-driven
    - **Where:** app/Services/Streaming/KickApiClient.php:20, app/Services/Streaming/TwitchApiClient.php:16
    - **Affects:** Environment parity — staging/local can't point streaming clients at mock servers or sandbox endpoints without editing source files.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `partna.streaming.twitch_api_url` and `partna.streaming.kick_api_url` to `config/partna.php` with the current production URLs as defaults.
        - Update `KickApiClient::CHANNELS_URL` and `TwitchApiClient::STREAMS_URL` to read from those config keys with the current values as fallback defaults.
    - **Technical:** `StreamingTokenManager` already uses config-driven token URLs via the `PLATFORM_CONFIG` array, but the API client URLs (`https://api.kick.com/public/v1/channels` and `https://api.twitch.tv/helix/streams`) are private string constants. In staging or local development, there is no way to redirect these to sandbox endpoints or mock HTTP servers without editing source code. The token URLs in `StreamingTokenManager` demonstrate the right pattern — they're also in a private constant, but they point at the OAuth token endpoints which are less likely to need environment-specific overrides. However, consistency would suggest moving ALL external URLs to config.
    - **Plain English:** The streaming clients know exactly which buildings to visit — the addresses are painted on the side of the truck. In production that's fine, but in the test neighborhood, those buildings don't exist. You can't give the driver a different address without repainting the truck. The OAuth token service already reads its addresses from a config file; the streaming API clients should do the same.
    - **Evidence:**
        ```php
        // KickApiClient.php
        private const CHANNELS_URL = 'https://api.kick.com/public/v1/channels';
        
        // TwitchApiClient.php
        private const STREAMS_URL = 'https://api.twitch.tv/helix/streams';
        
        // Contrast with — StreamingTokenManager.php (already config-key-driven)
        // References config('services.twitch.client_id') and config('services.kick.client_id')
        // Token URLs are still in a private constant, but the credentials are config-driven
        ```
    - `[DRAFT, confidence: 0.70]`

- [ ] **#CFG-6** · P2 — MediaDiskResolver probes $_ENV/$_SERVER directly, bypassing config cache and reading legacy SIDEST_ prefix
    - **Where:** app/Services/Media/MediaDiskResolver.php:31-33
    - **Affects:** All media storage operations (images + videos) — the resolved disk name can change between requests on the same deployed container if the runtime env differs from the cached config.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - The docblock justification (Laravel Cloud injects platform env vars at runtime) is valid. Document this as a known exception in `config/partna.php` with a clear comment that `partna.media_disk` may NOT reflect the runtime value.
        - Add the env var probes (`PARTNA_MEDIA_DISK`, `SIDEST_MEDIA_DISK`) to `EnvCheckService::RECOMMENDED` so the env-check tooling at least surfaces when they're set.
        - Add a Nightwatch breadcrumb when the runtime probe returns a different value than the cached config, so ops can detect configuration drift.
    - **Technical:** The `$_ENV`/`$_SERVER` probes are explicitly justified: Laravel Cloud caches config at deploy time but injects platform env vars at runtime, so `config('partna.media_disk')` returns the deploy-time value while the process environment holds the runtime value. This is correct for the Cloud platform but creates an invisible configuration split — `config('partna.media_disk')` and `MediaDiskResolver::resolve()` can return different disk names, and callers that read config directly (instead of calling `resolve()`) will use the wrong disk. The legacy `SIDEST_MEDIA_DISK` fallback also perpetuates an old naming convention that should eventually be retired.
    - **Plain English:** The system that decides which filing cabinet to store uploads in has two sources of truth: a printed directory (the cached config) and a sticky note on the wall (the runtime environment variable). The sticky note takes priority because the cloud platform updates it after the directory is printed. This is intentional but invisible — if someone reads the directory instead of checking the sticky note, they'll file things in the wrong cabinet. At minimum, there should be a sign on the directory saying "check the sticky note first."
    - **Evidence:**
        ```php
        // MediaDiskResolver.php — direct superglobal probes, intentional but unobservable
        $explicit = $_ENV['PARTNA_MEDIA_DISK'] ?? $_SERVER['PARTNA_MEDIA_DISK']
            ?? $_ENV['SIDEST_MEDIA_DISK'] ?? $_SERVER['SIDEST_MEDIA_DISK'] ?? null;
        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        // EnvCheckService.php — neither PARTNA_MEDIA_DISK nor SIDEST_MEDIA_DISK
        // appears in REQUIRED or RECOMMENDED. No env-check visibility.
        ```
    - `[DRAFT, confidence: 0.80]`
