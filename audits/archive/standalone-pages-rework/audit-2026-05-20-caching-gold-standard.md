`★ Insight ─────────────────────────────────────`
Two of the three DeepSeek findings required significant adjudication changes:
- CACHE-2's stale-serving claim only holds if the `siteImages` key is ever written via `rememberLocked` — a grep across the full codebase confirms the key has zero writers, only a dead invalidation entry. Drop.
- CACHE-3's "comment discrepancy" diagnosis was underspecified because the provided scope excluded `BrandStoreSettingsController`. The controller shows a deploy action that correctly calls `Cache::forget` on the primary but omits the `:stale` companion — a real missing-bust (10-min stale window post-deploy) that promotes the finding from P3 to P2.
`─────────────────────────────────────────────────`

# Caching Gold Standard Audit — 2026-05-20

**Branch:** development
**Lens:** caching gold standard
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Cache/AnalyticsCacheService.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/CacheLockService.php
- app/Services/Cache/ProfessionalCacheService.php
- app/Services/Cache/SiteCacheService.php
- app/Observers/Brand/BrandStoreSettingsObserver.php
- app/Observers/Commerce/AffiliateProductSelectionObserver.php
- app/Observers/Core/BlockObserver.php
- app/Observers/Core/BrandPartnerLinkObserver.php
- app/Observers/Core/BrandProfileObserver.php
- app/Observers/Core/CommissionMovementObserver.php
- app/Observers/Core/CommissionPayoutObserver.php
- app/Observers/Core/CustomerObserver.php
- app/Observers/Core/ProfessionalIntegrationObserver.php
- app/Observers/Core/ServiceCategoryObserver.php
- app/Observers/Core/ServiceObserver.php
- app/Observers/Core/SiteMediaObserver.php
- app/Observers/Core/SiteObserver.php
- app/Observers/Professional/ProfessionalObserver.php
- app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php (verified via grep)
- app/Services/Stripe/CommissionPayoutRefundService.php (verified via grep)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **#CACHE-1** · P2 — `CommissionPayoutObserver` status transitions don't bust `affiliatePayoutState` cache
    - **Where:** app/Observers/Core/CommissionPayoutObserver.php:28–60 (the `updated` method), app/Services/Cache/AnalyticsCacheService.php:invalidateAnalytics
    - **Affects:** Affiliate dashboard payout-state display. When a payout transitions to `completed`, `failed`, or `pending` (with a failure code newly set), the observer publishes notifications but never clears the cached payout-state snapshot. The affiliate's "pending" badge or "failed" alert persists until TTL expiry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `CommissionPayoutObserver::updated`, after each status-change branch (`completed`, `failed`, `pending`+failure-code), add a bust of both the primary and `:stale` keys: `$key = CacheKeyGenerator::affiliatePayoutState($payout->affiliate_professional_id); Cache::forget($key); Cache::forget($key.':stale');`
        - Alternatively, add `affiliatePayoutState` (+ `:stale` twin) to `AnalyticsCacheService::invalidateAnalytics()` if the analytics service is already called downstream — but the observer is the cleaner, tighter coupling.
        - Note: `CommissionPayoutRefundService::bustPayoutCaches()` already correctly busts both primary and `:stale` on the refund path — mirror that pattern here for the status-transition path.
    - **Technical:** `affiliatePayoutState` is written via `CacheLockService::rememberLocked` (confirmed by `AffiliateCommerceAnalyticsController`), which writes both a primary key and a `:stale` companion at 10× the base TTL. `invalidateAnalytics()` bumps the version token (which busts windowed summaries) and explicitly forgets `affiliateProjections` + `embeddedSetupOverview`, but never touches `affiliatePayoutState`. `CommissionPayoutObserver::updated` handles the `completed`, `failed`, and `pending`+failure-code branches and sends notifications to both parties — but contains zero cache busting. The refund path in `CommissionPayoutRefundService::bustPayoutCaches()` correctly clears both the primary and `:stale` key (line 148–150), demonstrating the pattern is known. The observer's omission means a payout landing as `completed` serves a stale "pending" badge until TTL expires.
    - **Plain English:** Think of the dashboard's payout status as a sticky note — it says "payment pending." When the money actually lands (the payout goes to `completed`), the system sends an in-app notification but doesn't pull the sticky note off and write a new one saying "paid." The note stays up until a timer expires. The fix is to always update the sticky note the moment a payment's status changes, not just when a refund happens (which already does this correctly).
    - **Evidence:**
        ```php
        // CommissionPayoutObserver::updated — notifications only, no cache bust
        if ($statusChanged && $payout->status === 'completed') {
            $this->notifyCompleted($payout);
            return;
        }
        if ($statusChanged && $payout->status === 'failed') {
            $this->notifyFailed($payout);
            return;
        }
        // ... no Cache::forget(CacheKeyGenerator::affiliatePayoutState(...)) anywhere
        ```
        ```php
        // AnalyticsCacheService::invalidateAnalytics — payout-state key not touched
        Cache::forget(CacheKeyGenerator::embeddedSetupOverview($professionalId));
        Cache::forget(CacheKeyGenerator::embeddedSetupOverview($professionalId).':stale');
        // ❌ No affiliatePayoutState forget here either
        ```
        ```php
        // CommissionPayoutRefundService::bustPayoutCaches — the correct pattern to mirror
        $stateKey = CacheKeyGenerator::affiliatePayoutState($order->affiliate_professional_id);
        Cache::forget($stateKey);
        Cache::forget($stateKey.':stale');
        ```

- [ ] **#CACHE-2** · P2 — Deploy trigger busts `brandStorefrontStatus` primary but leaves `:stale` twin alive for up to 10 minutes
    - **Where:** app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php:285
    - **Affects:** Brand operators who trigger a deployment and immediately check their store settings. The storefront status probe is cached via `rememberLocked` (which writes both a primary key and a `:stale` twin at 10× the 60s TTL). The deploy action only calls `Cache::forget` on the primary key — the `:stale` twin survives and is served as last-good for up to 600 seconds (10 minutes) after the deploy, making the brand's own dashboard show "unreachable" long after their Hydrogen storefront is live.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `BrandStoreSettingsController`'s deploy action, replace the bare `Cache::forget(CacheKeyGenerator::brandStorefrontStatus($pro->id))` with a two-key delete: `$key = CacheKeyGenerator::brandStorefrontStatus($pro->id); Cache::deleteMultiple([$key, $key.':stale']);`
        - The `forceRefresh` path inside `cachedStorefrontStatus()` already correctly clears both (`Cache::forget($key); Cache::forget($key.':stale')`). The deploy action should use the same pattern.
        - Also correct the docblock on `CacheKeyGenerator::brandStorefrontStatus()` — it states "both write actions that bust this key" but the deploy action currently only performs a partial bust.
    - **Technical:** `brandStorefrontStatus` is written via `CacheLockService::rememberLocked` (confirmed at `BrandStoreSettingsController:313`), which calls `writeWithJitter` to store both the primary key and `$key.':stale'` at 10× the base TTL. When `rememberLocked` finds the primary expired but `:stale` present, it immediately returns the stale value and attempts a non-blocking lock to recompute — meaning a freshly-cleared primary does not cause a DB/HTTP probe on the next request if the stale copy is still live. The deploy trigger at line 285 performs `Cache::forget($key)` (primary only), so the stale copy survives. Any request in the subsequent 10-minute stale window gets the pre-deploy "unreachable" status without triggering a fresh outbound HTTP probe to the Oxygen endpoint. The `forceRefresh` path used by update() correctly clears both, showing the two-key bust pattern is known — the deploy path simply missed applying it.
    - **Plain English:** Imagine a brand deploys their website. The system is supposed to erase the old "offline" sign and check if the site is live. It does erase the main sign, but it has a backup sign in a drawer (the stale cache copy) that it forgets to erase. For the next 10 minutes, every time someone opens the drawer — like when the brand refreshes their dashboard — they see the backup sign saying "offline," even though the site is fully live. The fix is a one-liner: erase both the sign and the backup at the same time.
    - **Evidence:**
        ```php
        // BrandStoreSettingsController deploy action — primary bust only, :stale survives
        Cache::forget(CacheKeyGenerator::brandStorefrontStatus($pro->id));  // line 285

        // vs. the forceRefresh path in cachedStorefrontStatus() — correctly busts both:
        if ($forceRefresh) {
            Cache::forget($key);
            Cache::forget($key.':stale');  // lines 309-310
        }

        // The key IS written via rememberLocked (creates :stale twin):
        return $this->cacheLock->rememberLocked(
            $key,     // line 313 — so :stale twin always exists after first probe
        ```
        ```php
        // CacheKeyGenerator docblock — promises busts that only partially exist:
        /**
         * The status only changes on deploy or domain reconfiguration — both
         * write actions that bust this key. …
         */
        public static function brandStorefrontStatus(string $professionalId): string
        {
            return "brand:{$professionalId}:storefront-status";
        }
        ```
