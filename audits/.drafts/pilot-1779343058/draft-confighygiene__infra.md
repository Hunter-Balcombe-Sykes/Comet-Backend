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
