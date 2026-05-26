- [ ] **#CCH-1** · P1 — `BrandCatalogService::fetchProductCustomPhotosMetafield` uses manual `Cache::get` + `Cache::put` without single-flight lock, without jitter, without SWR
    - **Where:** app/Services/Store/BrandCatalogService.php (fetchProductCustomPhotosMetafield method)
    - **Affects:** Any per-product custom-photos permission check during catalog rendering — multiple concurrent callers (e.g. several affiliates loading the same brand's products) all hit Shopify's API in parallel on cold miss.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `Cache::get` + `Cache::put` pattern with `CacheLockService::rememberLocked` (or `rememberLockedNullable` if null is a valid sentinel) using the same `CacheKeyGenerator::brandProductCustomPhotos` key.
        - Drop the manual `Cache::put` calls with `now()->addSeconds(...)` — the lock service will apply jitter and write the `:stale` companion automatically.
    - **Technical:** The current code reads from cache with a raw `Cache::get`, and on miss calls Shopify Admin API synchronously before writing back with `Cache::put($cacheKey, $sentinel, now()->addSeconds(N))`. This has three deviations: (1) no single-flight lock — N concurrent callers all call Shopify; (2) `DateTimeInterface` TTL via `now()->addSeconds` bypasses `JitteredTtl`, synchronising expiry across the fleet; (3) no `:stale` companion, so every cold caller blocks on the API call. Replacing with `rememberLocked` addresses all three.
    - **Plain English:** Imagine a library where the front-desk staff check a filing cabinet for a book before going to the stacks. But if five staff all check at the same moment and find the cabinet empty, all five walk to the stacks simultaneously instead of having one person go while the others wait at the counter. The fix is a simple sign-out sheet: first person to look claims the job, everyone else waits 2 seconds and checks the cabinet again.
    - **Evidence:**
        ```php
        $cacheKey = CacheKeyGenerator::brandProductCustomPhotos((string) $integration->professional_id, $productGid);
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return match ($cached) {
                'true' => true,
                'false' => false,
                default => null,
            };
        }
        // ... Shopify Admin API call ...
        Cache::put($cacheKey, 'unset', now()->addSeconds((int) config('partna.cache.ttls.product_custom_photos')));
        // or
        Cache::put($cacheKey, $bool ? 'true' : 'false', now()->addSeconds((int) config('partna.cache.ttls.product_custom_photos')));
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#CCH-2** · P2 — `ShopifyDisconnectService::disconnect` performs full local teardown but never invalidates the brand catalog caches
    - **Where:** Write site: app/Services/Shopify/ShopifyDisconnectService.php (disconnect method, ~line 90-130) · Read sites: app/Services/Store/BrandCatalogService.php (fetchBrandCatalog → CacheKeyGenerator::brandAdminCatalog) and app/Services/Store/AffiliateProductCatalogService.php (fetchActiveCatalog → CacheKeyGenerator::brandActiveCatalog)
    - **Affects:** After a brand disconnects from Shopify via the dashboard "Disconnect" button, the affiliate product catalog and brand admin catalog continue to serve the pre-disconnect product list for up to the cache TTL (300 seconds for active catalog). Affiliates see stale products that no longer exist in the brand's store.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After the integration row deletion and selections purge, add cache invalidation calls: `Cache::forget(CacheKeyGenerator::brandAdminCatalog($brandProfessionalId))` and `Cache::forget(CacheKeyGenerator::brandAdminCatalog($brandProfessionalId).':stale')` (and the same for `brandActiveCatalog`).
        - Consider wrapping the cache flush in a `DB::afterCommit` so a rolled-back disconnect transaction doesn't wipe a warm cache.
    - **Technical:** The disconnect service deletes the `ProfessionalIntegration` row (making future `queryAdminCatalog` calls return `[]` since no integration is found), deletes all `AffiliateProductSelection` rows, and resets `BrandProfile` state — but never touches the `brandAdminCatalog` or `brandActiveCatalog` cache keys. Both read paths use `CacheLockService::rememberLocked` with TTLs, so the stale catalog survives until natural expiry. This is a textbook category-4 deviation: a domain mutation with no corresponding push-invalidate on the cached read.
    - **Plain English:** When a store owner disconnects their Shopify store, we clean up everything locally — but we forget to take down the "what's on the shelf" posters in the affiliate break room. For up to 5 minutes after disconnect, affiliates still see the old product list as if nothing changed. The fix is adding a one-line "take down those posters" instruction right after we finish the cleanup.
    - **Evidence:**
        ```php
        // Write site — disconnect method performs these mutations with NO cache invalidation:
        ProfessionalIntegration::query()
            ->where('professional_id', $brandProfessionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->delete();

        AffiliateProductSelection::query()
            ->where('brand_professional_id', $brandProfessionalId)
            ->delete();

        BrandProfile::where('professional_id', $brandProfessionalId)
            ->update([
                'brand_status' => BrandStatus::Onboarding->value,
                'setup_complete' => false,
            ]);
        ```
        ```php
        // Read site 1 — cached with TTL, never invalidated on disconnect:
        return $this->cacheLock->rememberLocked(
            CacheKeyGenerator::brandAdminCatalog((string) $brand->id),
            (int) config('partna.cache.ttls.brand_admin_catalog'),
            fn () => $this->queryAdminCatalog($brand),
        );
        ```
        ```php
        // Read site 2 — cached with TTL, never invalidated on disconnect:
        return $this->cacheLock->rememberLocked(
            CacheKeyGenerator::brandActiveCatalog($brandProfessionalId),
            300,
            fn () => $this->queryAdminCatalog($brandProfessionalId),
        );
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CCH-3** · P2 — `BrandCatalogService::bustCatalogCaches` may leave a stale `:stale` twin for `embeddedProductActive` keys after invalidation
    - **Where:** app/Services/Store/BrandCatalogService.php (bustCatalogCaches method, embeddedProductActive forget path)
    - **Affects:** After a product's `active` metafield is toggled, if `rememberLockedNullable` writes a `:stale` companion for the `embeddedProductActive` key, the stale-while-revalidate fast path continues serving the pre-toggle value for up to 10× the base TTL — defeating the point of the invalidation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Verify whether `rememberLockedNullable` writes a `$key:stale` companion. If it does, add `Cache::forget($key.':stale')` alongside the existing `Cache::forget($key)`.
        - If `rememberLockedNullable` genuinely does not write a stale twin for this specific key, update the comment to explain why (e.g. "nullable keys use a short TTL and bypass SWR by design") to prevent future readers from making the same assumption.
    - **Technical:** The gold-standard `CacheLockService::writeWithJitter` writes every value to both `$key` (primary, jittered TTL) and `$key:stale` (10× TTL). The invalidation path in `bustCatalogCaches` calls `Cache::forget(CacheKeyGenerator::embeddedProductActive(...))` but does NOT forget the `:stale` companion, with a comment claiming "rememberLockedNullable with no `:stale` twin." If the comment is incorrect and a stale twin exists, readers continue receiving the old value through the SWR fast path for the full stale window. Confidence is 0.5 because the `CacheLockService` implementation is out of scope and the comment may reflect a deliberate design choice for nullable keys.
    - **Plain English:** Imagine a bulletin board with two copies of every announcement: a "current" copy and a "backup" copy that stays up for a while after the current one is removed, so people don't see an empty board. When someone updates an announcement, they take down the current copy — but there's a note saying "don't worry about the backup copy, there isn't one." If that note is wrong and a backup copy does exist, people keep reading the old announcement for hours after the update. The fix is either to take down both copies, or to confirm the backup really doesn't exist and make the note clearer.
    - **Evidence:**
        ```php
        // invalidation forgets primary key only — comment claims no :stale twin exists
        if ($productGid !== null) {
            $productId = preg_replace('#^gid://shopify/Product/#', '', $productGid);
            // embedded:product-active:* is written by rememberLockedNullable
            // with no `:stale` twin — single Cache::forget is sufficient.
            Cache::forget(CacheKeyGenerator::embeddedProductActive($brandId, (string) $productId));
        }
        ```
    - `[DRAFT, confidence: 0.5]`

- [ ] **#CCH-4** · P3 — `ShopifySetupTokenService` uses `DateTimeInterface` TTL and ad-hoc key concatenation instead of routing through jitter and `CacheKeyGenerator`
    - **Where:** app/Services/Shopify/ShopifySetupTokenService.php:55 (put), :68 (get), :74 (pull)
    - **Affects:** OAuth setup token store — ephemeral tokens that bridge the Shopify OAuth callback and the setup wizard. Impact is bounded because these are single-use, random 32-byte tokens with a 60-minute TTL, not a hot read path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `now()->addMinutes(self::TTL_MINUTES)` with `JitteredTtl::withJitter(self::TTL_MINUTES * 60)` (converting minutes to seconds for the int-based helper), or wrap the write through `CacheLockService`.
        - Extract the key prefix into a `CacheKeyGenerator` method (e.g. `CacheKeyGenerator::shopifySetupToken($token)`) so reader and writer stay mechanically aligned.
    - **Technical:** Two minor deviations from the gold standard: (1) `Cache::put(... , now()->addMinutes(60))` uses a `DateTimeInterface` TTL which sidesteps the `JitteredTtl` helper entirely — synchronised TTL expiry is low-risk here since tokens are single-use, but the pattern is inconsistent with the rest of the codebase; (2) the key is built ad-hoc (`self::CACHE_PREFIX.$token`) rather than through `CacheKeyGenerator`, which introduces a drift risk if another class ever needs to read or forget the same key. Both are P3 because the operational impact is negligible for single-use OAuth tokens.
    - **Plain English:** This is like having a coat check that hands out numbered tickets. The ticket system works fine — it's a short-lived token that gets used once and discarded. The two issues are minor housekeeping: (a) all the tickets expire at exactly the same time instead of being staggered, which doesn't matter because they're used once anyway; (b) the ticket format is handwritten instead of using the standard ticket-printing machine. Neither causes real problems today.
    - **Evidence:**
        ```php
        Cache::put(self::CACHE_PREFIX.$token, [
            'shop_domain' => $shopDomain,
            'access_token' => encrypt($accessToken),
            'shop_data' => $shopData,
            'scopes' => $scopes,
            'shop_email' => $shopEmail,
            'created_at' => now()->toIso8601String(),
        ], now()->addMinutes(self::TTL_MINUTES));
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CCH-5** · P3 — `AnalyticsService::versionedCacheKey` builds final cache key via ad-hoc string interpolation rather than through `CacheKeyGenerator`
    - **Where:** app/Services/Analytics/AnalyticsService.php:118-122
    - **Affects:** Brand and affiliate analytics dashboard payloads. Impact is bounded because the version-token component (`analyticsSummaryVersion`) IS centralised through `CacheKeyGenerator`, and the read/write of this key is self-contained within a single class — no other service reads or invalidates it by key name.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `CacheKeyGenerator` method (e.g. `CacheKeyGenerator::analyticsPayload(string $role, string $professionalId, int $version)`) and call it from `versionedCacheKey`. Keeps key construction centralised for future cross-service access.
    - **Technical:** The version token is correctly looked up via `Cache::get(CacheKeyGenerator::analyticsSummaryVersion(...))`, but the final cache key is built with string interpolation: `"analytics:{$role}:{$professionalId}:v{$version}"`. While both reader and writer live in the same class (`AnalyticsService`), this is a category-8 drift risk: if a future invalidation job or cache-warmer needs to target these keys by pattern, the format is defined in a private method rather than the central key registry. The risk is low today because the version-token bump invalidates without key-by-key scanning, and no external service touches this key.
    - **Plain English:** Think of a library where every book has a call number. Most books get their call numbers from the central catalog system, but one shelf of analytics reports has numbers written by hand on sticky notes. The hand-written numbers are perfectly consistent with each other (they're all written by the same person), and the system still works because the librarian uses a "new edition" stamp that makes old numbers obsolete. It's tidy to move the sticky-note system into the central catalog, but nothing breaks if you don't.
    - **Evidence:**
        ```php
        private function versionedCacheKey(string $role, string $professionalId): string
        {
            $version = Cache::get(CacheKeyGenerator::analyticsSummaryVersion($professionalId), 0);

            return "analytics:{$role}:{$professionalId}:v{$version}";
        }
        ```
    - `[DRAFT, confidence: 0.8]`
