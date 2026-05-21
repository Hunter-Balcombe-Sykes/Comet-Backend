- [ ] **#CCH-1** · P2 — Brand-affiliate cache invalidation leaves stale key intact after brand edit
    - **Where:** app/Jobs/Cache/InvalidateBrandAffiliatesCacheJob.php:72-78
    - **Affects:** Affiliates browsing a brand's storefront after the brand edits settings (design, profile, collections). The :stale companion key survives invalidation, so the SWR fast path serves the pre-edit content for the full stale TTL window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add the `:stale` companion key to the delete batch so both primary and stale keys are evicted together.
        - Use `CacheKeyGenerator::publicSitePayloadStale($subdomain)` (or equivalent helper) to keep key construction centralised.
    - **Technical:** Category 3 — SWR broken on invalidation. `Cache::deleteMultiple` only forgets the primary key returned by `CacheKeyGenerator::publicSitePayload()`. The `:stale` companion key, which `CacheLockService::rememberLocked` writes at 10× TTL, is never evicted. On the next request after invalidation, the primary key misses but the stale key hits — the caller gets the old pre-edit payload served from the stale companion while the lock-holder asynchronously rebuilds. For a brand settings edit (colours, slogan, active collections) fanning out to hundreds of affiliates, this means every affiliate storefront shows the old brand design for up to the stale TTL duration even though the brand explicitly saved new settings.
    - **Plain English:** Think of it like a restaurant changing its menu but forgetting to update the takeaway flyers in the window display. Customers who walk in (cache miss) see the new menu, but anyone grabbing a flyer (stale cache hit) still sees last week's dishes until someone throws the old flyers away. After a brand edits their storefront, their affiliates keep showing the old version for a while because the backup copy was never cleared.
    - **Evidence:**
        ```php
        // Delete ONLY the primary key, not the :stale twin. A brand edit
        // can fan out to hundreds of affiliates at once; keeping :stale
        // lets the SWR fast path serve last-good content while a single
        // background worker rebuilds each key, avoiding a fleet-wide
        // synchronised cold-rebuild stampede.
        $keys = [];
        foreach ($subdomains as $subdomain) {
            $keys[] = CacheKeyGenerator::publicSitePayload($subdomain);
        }

        if (! empty($keys)) {
            Cache::deleteMultiple(array_values(array_unique($keys)));
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CCH-2** · P3 — Shopify shop/update throttle uses DateTimeInterface TTL, sidestepping jitter
    - **Where:** app/Jobs/Shopify/ProcessShopifyShopUpdateJob.php:89-91
    - **Affects:** No user-facing data — this is a throttle guard preventing duplicate `SyncShopifyBrandDesignJob` dispatches within an hour. A synchronised expiry would only cause a minor burst of duplicate brand-design syncs at the deploy boundary or on clock-aligned shop/update webhooks.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `now()->addHour()` with `now()->addMinutes(random_int(54, 66))` so the throttle window is spread across a ~12-minute jitter band.
        - Alternatively, document as a deliberate low-impact throttle and exempt from jitter requirements.
    - **Technical:** Category 2 — unjittered TTL. `Cache::add($cacheKey, true, now()->addHour())` passes a `Carbon` instance as the TTL. Laravel forwards `DateTimeInterface` TTLs directly to Redis as an absolute expiry timestamp with no jitter applied. `CacheLockService` and `JitteredTtl::withJitter()` only operate on integer TTLs. While this key is a throttle guard rather than a user-facing data cache, synchronised expiry across the fleet means a deploy or a simultaneous shop/update webhook storm could let multiple workers slip past the guard at the same clock boundary.
    - **Plain English:** This is like setting every kitchen timer in a restaurant to exactly 60 minutes. When they all go off at once, every cook rushes to the same station. Here it's a guard that says "don't sync this brand's design more than once an hour" — but if every brand's guard expires at the same instant after a deploy, they all fire at once. Adding a small random fudge factor (±6 minutes) spreads them out so they don't stampede.
    - **Evidence:**
        ```php
        $cacheKey = "shopify:brand_design_sync:{$integration->id}";
        if (Cache::add($cacheKey, true, now()->addHour())) {
            SyncShopifyBrandDesignJob::dispatch((string) $integration->id);
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CCH-3** · P3 — Throttle cache key built via ad-hoc string concatenation outside CacheKeyGenerator
    - **Where:** app/Jobs/Shopify/ProcessShopifyShopUpdateJob.php:89
    - **Affects:** No data correctness risk — this key is self-contained (single writer, single reader, never needs to match another call site). It's a style deviation from the gold standard, not a bug.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the key into `CacheKeyGenerator` (e.g. `CacheKeyGenerator::shopifyBrandDesignSyncThrottle($integrationId)`) so all cache keys in the codebase originate from one place.
        - Or document as intentional one-off and close — the key has no cross-caller drift risk.
    - **Technical:** Category 8 — key generation drift. The gold standard requires every cache key to originate from a centralised helper (`CacheKeyGenerator` or equivalent domain helper). `"shopify:brand_design_sync:{$integration->id}"` is ad-hoc string concatenation. In this specific case the key is read and written in the same 3-line block (single call site), so drift between reader and writer is impossible. The risk is purely organisational — future refactors that need to share or invalidate this key would have to grep for a magic string instead of following the helper method.
    - **Plain English:** This is like writing a sticky note with a filing code instead of logging it in the shared filing index. Only you need to find it, so it works fine today. But if someone else ever needs to look it up, they'll have to search every drawer instead of checking the index. Moving the key into the central key catalogue means the next person can find it in one place.
    - **Evidence:**
        ```php
        $cacheKey = "shopify:brand_design_sync:{$integration->id}";
        if (Cache::add($cacheKey, true, now()->addHour())) {
        ```
    - `[DRAFT, confidence: 0.95]`
