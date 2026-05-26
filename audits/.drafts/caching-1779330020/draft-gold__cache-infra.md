- [ ] **#CCH-1** · P2 — Feature flag registry cache lacks a version token — flag adds/changes require manual flush to propagate
    - **Where:** app/Services/FeatureFlags/FeatureFlagService.php (loadRegistry, line `self::REGISTRY_KEY`)
    - **Affects:** Any deployment or operator action that adds, modifies, or removes a FeatureFlag row — the change remains invisible to every pod for up to 360s unless a separate manual `flushRegistry()` call is issued.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Introduce a version integer in cache (e.g. `ff:registry:version`) and embed it into the registry cache key.
        - Increment the version token on every insert, update, or delete of a `FeatureFlag` record (via model observer or service method), so the registry is busted atomically without a Redis scan.
    - **Technical:** The current `rememberLocked` call caches the whole flag set under a static key `ff:registry` with no version component. The gold-standard pattern (used by `CacheKeyGenerator::analyticsSummaryVersion`) expects a monotonic version token that is incremented on any domain change, so all dependent keys become stale simultaneously and O(1). Without it, a deployment adding a feature flag row must rely on a separate `flushRegistry()` call; a missed call causes the fleet to serve the old flag list for the full TTL (300s + jitter), leaving new flags invisible and recently-removed flags still effective.
    - **Plain English:** Imagine a restaurant menu pinned on the wall. If the chef adds a new dish, they must personally tell every waiter to throw away their copy and grab a new one. Right now the menu has no edition number — waiters only refresh every few minutes on their own. A version token is like printing “Edition 2” on the menu; when the chef updates it, all waiters instantly know to fetch the latest copy.
    - **Evidence:**
        ```php
        // FeatureFlagService.php, loadRegistry method
        return $this->cacheLock->rememberLocked(
            self::REGISTRY_KEY,  // 'ff:registry'
            $this->jitteredTtl(),
            function (): array {
                return FeatureFlag::query() … ->all();
            },
        );
        ```
    - `[DRAFT, confidence: 0.95]`
