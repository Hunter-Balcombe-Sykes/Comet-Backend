
<!-- LENS: gold | CHUNK: cache-infra -->

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

<!-- LENS: gold | CHUNK: services-prof-stripe -->

- [ ] **#CCH-1** · P1 — Plain `Cache::get` + `Cache::put` on a hot dashboard HTTP-check path with no single-flight lock
    - **Where:** app/Services/Professional/Brand/BrandStatusService.php:248-266
    - **Affects:** Every admin page load that evaluates brand status — `BrandOnboardingReadinessService::getChecklist()` → `syncBrandStatus()` → `BrandStatusService::sync()` → `determine()` → `isStorefrontReachable()`. Concurrent page loads (multiple staff tabs, brand dashboard open in two windows) all fire HTTP requests to the brand's storefront on cache miss, stampeding the origin.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inject `CacheLockService` and replace the `Cache::get` + fallback + `Cache::put` pattern with `$this->cacheLock->rememberLocked($cacheKey, $reachable ? 60 : 15, fn() => $this->performHttpCheck($url))`.
        - Extract the HTTP call into a private method so the closure passed to `rememberLocked` is clean and testable.
    - **Technical:** The current code does `Cache::get($key)` → if null, executes an HTTP GET with 5s timeout → `Cache::put($key, $result, $ttl)`. There is no atomicity guard between the get and the put. When the cache is cold (deploy boundary, scheduled flush, or natural expiry), every concurrent caller that reaches this line before the first one finishes its HTTP call sees a miss and fires its own HTTP request. With the cache on a 15–60s TTL and called from `determine()` which runs on every `BrandOnboardingReadinessService::getChecklist()` invocation (the brand onboarding checklist endpoint), this creates a stampede of outbound HTTP calls at cold start. `CacheLockService::rememberLocked` would serialise them through a Redis lock — one caller performs the HTTP check, the rest block briefly and read the cached result.
    - **Plain English:** Imagine a dozen staff members all refreshing the same dashboard page at the same moment after a deploy. Each one's request sees the cache is empty and independently calls out to the brand's storefront to see if it's alive — all at once. The fix routes all those requests through a single gatekeeper, so only one person checks the storefront and everyone else gets the answer a split second later from the cache.
    - **Evidence:**
        ```php
        $cacheKey = 'brand_status:storefront_reachable:'.sha1($url);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool) $cached;
        }

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'timeout' => 5,
                'connect_timeout' => 3,
            ])->get($url);

            $reachable = $response->successful();
        } catch (\Throwable) {
            $reachable = false;
        }

        Cache::put($cacheKey, $reachable, $reachable ? 60 : 15);
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#CCH-2** · P2 — `isStorefrontReachable` cache write has unjittered TTL and no stale-while-revalidate companion
    - **Where:** app/Services/Professional/Brand/BrandStatusService.php:265
    - **Affects:** The storefront-reachability cache for every brand. Synchronised TTL expiry causes thundering-herd HTTP checks when the cache expires naturally — every process that ticks at the same wall-clock moment (e.g. all Horizon workers hitting the dashboard endpoint at the same second after deploy) will miss together and stampede even after the single-flight lock is added. Without SWR, callers that arrive during the recomputation window wait on the lock-holder's HTTP call rather than receiving a last-good stale answer.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Route the write through `CacheLockService::rememberLocked` (which auto-applies jitter via `JitteredTtl` and manages the `:stale` companion key automatically).
        - If a bespoke write is retained, call `JitteredTtl::withJitter($ttl)` at the write site and write a `$key:stale` companion with 10× TTL.
    - **Technical:** `Cache::put($cacheKey, $reachable, $reachable ? 60 : 15)` writes a hardcoded integer TTL. When all cache entries share the same TTL, they all expire simultaneously — every process sees a miss on the same second and recomputes. `JitteredTtl::withJitter()` spreads expiry by ±20%, smoothing the miss distribution across the fleet. Additionally, `rememberLocked` maintains a `$key:stale` companion at 10× TTL that is returned immediately when the primary expires, while the lock-holder refreshes in the background — callers never wait on a cold read.
    - **Plain English:** The storefront-check cache has a fixed countdown — it expires at exactly the same moment for every server. When it does, all of them rush to check the storefront at once. Adding a random wiggle to the countdown staggers those expirations across the fleet, and keeping a "day-old but good-enough" copy means nobody has to wait for the fresh check to finish.
    - **Evidence:**
        ```php
        Cache::put($cacheKey, $reachable, $reachable ? 60 : 15);
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#CCH-3** · P2 — `isStorefrontReachable` cache key built via ad-hoc string concatenation, not via `CacheKeyGenerator`
    - **Where:** app/Services/Professional/Brand/BrandStatusService.php:248
    - **Affects:** Any future code path that needs to invalidate this key programmatically (e.g. a webhook that fires when a storefront goes live, or a staff admin "refresh status" button). Without a centralised key helper, the invalidation call site must duplicate the concatenation logic — drift between the reader and writer produces a silent cache-miss that looks like the key was never written.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a static key helper (e.g. `CacheKeyGenerator::brandStorefrontReachable(string $subdomain): string`) and call it from both the read path and any future invalidation path.
    - **Technical:** The key `'brand_status:storefront_reachable:'.sha1($url)` is assembled inline. The gold standard requires every key to originate from a single helper method so that readers and writers call the same source of truth. Currently there is no writer that invalidates this key programmatically, but if one is added later (e.g. a Shopify `app/uninstalled` webhook that resets the status), the author would need to replicate the `sha1($url)` algorithm exactly. `CacheKeyGenerator` removes that risk.
    - **Plain English:** The label on the storage box is written by hand in one place. If someone later needs to clear that box from a different part of the system, they have to write the label exactly the same way — same spelling, same abbreviations. A shared label-maker function guarantees both sides always match.
    - **Evidence:**
        ```php
        $cacheKey = 'brand_status:storefront_reachable:'.sha1($url);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CCH-4** · P2 — `failPayout` mutates payout state without bumping analytics cache version (missing push-invalidate)
    - **Where:** Write site: app/Services/Stripe/CommissionPayoutService.php:554-570 (`failPayout` method). Read site: cached analytics reads keyed off `analyticsSummaryVersion` (via `AnalyticsCacheService`).
    - **Affects:** Dashboard analytics views (commerce overview, affiliate projections) after a payout fails — they show stale payout counts and gross/net totals until the analytics cache TTL expires naturally, because the version token was never incremented. Staff and brand users see a payout as still "in flight" when it has already failed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->analyticsCache->bumpAnalyticsVersion($payout->brand_professional_id)` and `$this->analyticsCache->bumpAnalyticsVersion($payout->affiliate_professional_id)` inside `failPayout`, matching the pattern already used by `markCompleted`.
    - **Technical:** `markCompleted` (line ~480) correctly calls `$this->analyticsCache->bumpAnalyticsVersion(...)` for both the brand and affiliate so cached analytics reads pick up the completed payout on the next request. `failPayout` — which transitions the payout to `failed`, releases orders, and deletes payout items — does not bump the version token. Any cached dashboard view keyed off `analyticsSummaryVersion` will serve stale data showing the payout as still pending/processing until the underlying cache TTL expires. The version-token pattern (category 5 of the gold standard) requires every terminal state transition to increment the token.
    - **Plain English:** When a payout succeeds, the system pings the dashboard caches so they refresh immediately. When a payout fails — the brand's card was declined, the affiliate's Stripe account wasn't ready — the dashboard cache doesn't get that ping. Staff and brands keep seeing the payout as "in progress" until the cache naturally expires minutes later, which looks like the system is stuck.
    - **Evidence:**
        ```php
        // failPayout — no cache invalidation (compare with markCompleted below)
        private function failPayout(CommissionPayout $payout, string $code, string $reason): void
        {
            CommissionPayoutItem::where('payout_id', $payout->id)->delete();
            Order::where('payout_id', $payout->id)->update(['payout_id' => null]);

            $payout->forceFill([
                'status' => 'failed',
                'failure_code' => $code,
                'failure_reason' => $reason,
                'processed_at' => now(),
            ])->save();
            // ... log warning ...
        }

        // markCompleted — correctly bumps analytics version
        private function markCompleted(CommissionPayout $payout, Professional $brand, Professional $affiliate): void
        {
            $payout->forceFill([...])->save();
            $this->analyticsCache->bumpAnalyticsVersion($brand->id);
            $this->analyticsCache->bumpAnalyticsVersion($affiliate->id);
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#CCH-5** · P2 — `cancelExpiredPayout` mutates payout and orders without bumping analytics cache version (missing push-invalidate)
    - **Where:** Write site: app/Services/Stripe/CommissionVoidService.php:275-330 (`cancelExpiredPayout` method). Read site: cached analytics reads via `AnalyticsCacheService` version-token pattern.
    - **Affects:** Dashboard analytics after the nightly `VoidExpiredPayoutsJob` runs — expired payouts are cancelled and linked orders are voided, but cached analytics views (commerce overview, affiliate projections) remain stale until TTL expiry. Affiliates whose payouts expired due to grace-period timeout see out-of-date dashboard numbers.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After the payout cancellation and order voiding succeed, call `app(AnalyticsCacheService::class)->bumpAnalyticsVersion($payout->brand_professional_id)` and `->bumpAnalyticsVersion($payout->affiliate_professional_id)`.
    - **Technical:** `cancelExpiredPayout` transitions a payout to `cancelled` and voids all linked orders inside a transaction. The `voidOrder` method sets `status='voided'` which triggers the `rollup_apply_delta` trigger — so the DB-side rollup is correct. However, any cached read of analytics (e.g. the affiliate's dashboard commerce overview) keyed off the version-token pattern will not see the cancelled payout or voided orders until the token is bumped. The method already has an `$affiliate` loaded via `lockForUpdate()` and the payout carries `brand_professional_id`, so both IDs are available for the bump call.
    - **Plain English:** Every night, the system cleans up expired payouts from affiliates who never connected their bank account. The database is updated correctly, but the dashboard cache doesn't get notified. Those affiliates see stale numbers until the cache refreshes on its own schedule — so their dashboard might still show "pending" payouts that were already cancelled hours ago.
    - **Evidence:**
        ```php
        // cancelExpiredPayout — no analytics version bump after successful cancellation
        $updated = CommissionPayout::query()
            ->where('id', $payout->id)
            ->where('status', 'pending')
            ->update([
                'status' => 'cancelled',
                'failure_code' => 'grace_period_expired',
                // ...
            ]);

        if ($updated === 0) { /* ... */ return; }

        $voidedOrders = $this->voidOrdersLinkedToPayout($payout->id, 'payout_grace_expired');
        $this->clearOrderStampsForVoidedPayout($payout->id);

        $stats['cancelled_count']++;
        // ...
        // No analyticsCache->bumpAnalyticsVersion(...) call anywhere in this method
        ```
    - `[DRAFT, confidence: 0.85]`

<!-- LENS: gold | CHUNK: services-commerce -->

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

<!-- LENS: gold | CHUNK: services-rest-models -->

- [ ] **#CCH-1** · P2 — `Cache::lock()` on default store instead of `cache_locks` connection in SquareTokenService
    - **Where:** app/Services/Square/SquareTokenService.php (in `refreshAccessToken` method)
    - **Affects:** Square token refresh under concurrent requests — a `Cache::flush()` during deploy or maintenance releases the lock and allows concurrent OAuth refreshes to Square.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::lock('integration_refresh:'.$integration->id, 30)` with `Cache::store('cache_locks')->lock('integration_refresh:square:'.$integration->id, 30)`.
        - Add `square` namespace to the lock key to avoid theoretical collision with other integration token services.
    - **Technical:** Laravel's `Cache::lock()` uses the default cache store. If the default store shares a Redis DB with data caches, a `Cache::flush()` or `php artisan cache:clear` releases every held lock. The `cache_locks` connection (separate Redis DB) isolates locks from data-store flushes. The lock duration of 30s is appropriate (exceeds the 20s Square HTTP timeout), and the block timeout of 10s is reasonable, so only the connection pinning is off.
    - **Plain English:** Imagine a hotel with key-card locks on every room door. The master reset switch for the card system is on the same circuit as the hallway lights — if maintenance flips the wrong breaker, every door unlocks. The fix moves the lock system onto its own isolated circuit so no routine maintenance accidentally opens every door.
    - **Evidence:**
        ```php
        $lock = Cache::lock('integration_refresh:'.$integration->id, 30);

        try {
            $lock->block(10);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CCH-2** · P2 — `Cache::lock()` on default store instead of `cache_locks` connection in FreshaTokenService
    - **Where:** app/Services/Fresha/FreshaTokenService.php (in `refreshAccessToken` method)
    - **Affects:** Fresha token refresh under concurrent requests — same flush-vulnerability as SquareTokenService.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::lock('integration_refresh:'.$integration->id, 30)` with `Cache::store('cache_locks')->lock('integration_refresh:fresha:'.$integration->id, 30)`.
        - Add `fresha` namespace to the lock key.
    - **Technical:** Identical anti-pattern to CCH-1. `Cache::lock()` on the default store means locks live on the same Redis DB as cached data. A data-store `Cache::flush()` releases these locks, opening a window where multiple workers refresh the Fresha token concurrently. Moving to the dedicated `cache_locks` connection isolates lock lifecycle from data-cache lifecycle.
    - **Plain English:** Same hotel key-card problem as the Square integration — the lock system shares a circuit with the hallway lights. This is the Fresha wing of the hotel, built with the identical wiring mistake.
    - **Evidence:**
        ```php
        $lock = Cache::lock('integration_refresh:'.$integration->id, 30);

        try {
            $lock->block(10);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CCH-3** · P2 — NotificationPublisher does not invalidate the NotificationListingService cache on publish
    - **Where:** Write site: app/Services/Notifications/NotificationPublisher.php (in `publish` and `publishMany` methods). Read site: app/Services/Notifications/NotificationListingService.php (in `index` method).
    - **Affects:** Professional dashboard — newly published in-app notifications are invisible in the notification bell dropdown for up to 15 seconds (the listing cache TTL).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `NotificationPublisher::publish()` and `publishMany()`, after successfully inserting a notification row (`$inserted > 0`), call a new invalidation method on `NotificationListingService` for the affected `$professionalId`.
        - Expose a `bustIndexCache($professionalId)` equivalent (currently `private`) or inject `NotificationListingService` into `NotificationPublisher` and call it.
        - Forget both the primary key and `:stale` companion — `bustIndexCache` already does this correctly.
    - **Technical:** The `NotificationListingService::index()` method caches the notification list via `CacheLockService::rememberLocked` with a 15s TTL. `markRead` and `dismiss` both call `bustIndexCache()` to invalidate. But `NotificationPublisher::publish()` and `publishMany()` insert new notification rows without touching the listing cache at all. The result is a guaranteed stale window of up to 15 seconds after every notification publish. For booking-completion notifications and brand invites, this means the bell icon doesn't update until the next poll cycle after TTL expiry — exactly the sort of staleness the push-invalidation requirement is designed to prevent.
    - **Plain English:** When someone sends you a text message, your phone buzzes immediately. But imagine if the Messages app only checked for new texts every 15 seconds — you'd have an annoying delay between "sent" and "delivered." That's what happens here: the notification is saved to the database instantly, but the cached list shown in the dashboard bell doesn't refresh until its 15-second timer runs out. The fix connects the "new notification" event to the "clear the cached list" action so the bell updates right away.
    - **Evidence:**
        ```php
        // NotificationPublisher::publish() — inserts a row but never busts the listing cache:
        $inserted = DB::table('notifications.notifications')->insertOrIgnore([...]);

        if ($inserted > 0 && config('partna.notifications.email_enabled', false)) {
            SendTransactionalNotificationEmailJob::dispatch(...);
        }
        // No equivalent of NotificationListingService::bustIndexCache($professionalId) here.
        ```
        ```php
        // NotificationListingService::index() — read path that caches and needs invalidation on publish:
        return $this->cache->rememberLocked(
            $this->cacheKey($professionalId, $limit, $includeDismissed),
            (int) config('partna.notifications.listing_cache_ttl_seconds', 15),
            fn () => $this->buildIndexPayload($professionalId, $limit, $includeDismissed),
        );
        ```
        ```php
        // NotificationListingService::bustIndexCache — exists, works, but is only called from markRead/dismiss:
        private function bustIndexCache(string $professionalId): void
        {
            $store = app()->environment('testing') ? Cache::store() : Cache::store('redis');
            foreach ([50, 100, 200] as $limit) {
                foreach ([false, true] as $includeDismissed) {
                    $key = $this->cacheKey($professionalId, $limit, $includeDismissed);
                    $store->forget($key);
                    $store->forget($key.':stale');
                }
            }
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#CCH-4** · P3 — NotificationListingService bustIndexCache hardcodes limit values that may miss cache keys
    - **Where:** app/Services/Notifications/NotificationListingService.php (in `bustIndexCache` method)
    - **Affects:** Stale notification listings when a caller uses a `$limit` value not in `[50, 100, 200]` — the cache entry is never invalidated on markRead/dismiss.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either constrain `index()` to only accept limits from a known set (`[50, 100, 200]`) and validate with an enum or constant, or
        - Record the actual `$limit` used at cache-write time (e.g., in a Redis set keyed by professional) and iterate over that set during invalidation instead of a hardcoded array.
    - **Technical:** The `index()` method accepts any `int $limit` and constructs a cache key from it. The `bustIndexCache()` method only forgets keys for `$limit ∈ [50, 100, 200]`. If the frontend or a future internal caller passes a different limit (e.g., 25, 75, 150), the corresponding cache key is never invalidated on markRead/dismiss. The 15s TTL bounds the staleness, but the push-invalidation surface is incomplete. The current frontend likely only uses those three limits, so impact is bounded; flagging as P3 for defense-in-depth.
    - **Plain English:** The notification list cache has a cleanup crew that knows how to clear three specific shelf sizes — 50, 100, and 200 items. If someone puts a notification list on a shelf of a different size (say, 25 items), the cleanup crew walks right past it. The stale list sits there until its 15-second self-destruct timer fires. For now, only those three shelf sizes are in use, so no one notices — but it's a fragile setup that would break silently if a new screen used a different page size.
    - **Evidence:**
        ```php
        // Reader: accepts any int limit — open key space
        public function index(string $professionalId, int $limit, bool $includeDismissed): array
        {
            return $this->cache->rememberLocked(
                $this->cacheKey($professionalId, $limit, $includeDismissed),
                ...
            );
        }
        ```
        ```php
        // Invalidator: only covers 3 specific limits
        private function bustIndexCache(string $professionalId): void
        {
            $store = app()->environment('testing') ? Cache::store() : Cache::store('redis');
            foreach ([50, 100, 200] as $limit) {   // <-- hardcoded, incomplete
                foreach ([false, true] as $includeDismissed) {
                    $key = $this->cacheKey($professionalId, $limit, $includeDismissed);
                    $store->forget($key);
                    $store->forget($key.':stale');
                }
            }
        }
        ```
    - `[DRAFT, confidence: 0.70]`

<!-- LENS: gold | CHUNK: jobs -->

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

<!-- LENS: gold | CHUNK: ctrl-prof-a -->

- [ ] **#CCH-1** · P3 — Ad-hoc cache key + unjittered TTL in Shopify embedded connection code generation
    - **Where:** app/Http/Controllers/Api/Professional/Brand/ShopifyEmbeddedConnectionController.php:38
    - **Affects:** Developers maintaining cache key consistency; cache keys not centralised could lead to silent miss if the format ever changes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::put("shopify:embed:connect:{$code}", ...)` with a key built by `CacheKeyGenerator` (e.g. `CacheKeyGenerator::shopifyEmbedConnectCode($code)`).
        - If a time‑to‑live is ever changed to an integer, route the write through `CacheLockService` or apply `JitteredTtl::withJitter()` to avoid fleet synchronisation.
    - **Technical:** The key is built with ad‑hoc string concatenation while other cache read/write pairs in the codebase use `CacheKeyGenerator` (or domain helpers) for the same logical keys. The TTL is a `Carbon` instance (`now()->addMinutes(30)`) – not a literal int, so thundering‑herd risk is low, but the deviation from the gold‑standard centralised key pattern makes future drift likely.
    - **Plain English:** Think of a filing cabinet where every drawer has a label printed from a standard template, but one drawer has a handwritten sticky note. If someone redesigns the template, that one drawer will be missed and the file won’t be found. The fix is to print the label from the same template as everything else.
    - **Evidence:**
        ```php
        Cache::put("shopify:embed:connect:{$code}", (string) $professional->id, now()->addMinutes(30));
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#CCH-2** · P3 — Brand‑affiliate snapshot cache key not generated from `CacheKeyGenerator`
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateController.php:157
    - **Affects:** Future maintainers; a different writer or eviction job that uses `CacheKeyGenerator` could miss this key, leaving stale data.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$cacheKey = "analytics:commerce:brand_affiliate_snapshot:v1:{$brandId}:{$affiliateId}"` with a call to `CacheKeyGenerator::brandAffiliateSnapshot($brandId, $affiliateId)`.
    - **Technical:** The gold standard requires that every key for a cached value originates from one helper method so that readers and writers stay in sync. Other analytics endpoints (e.g. `AffiliateCommerceAnalyticsController`, `BrandCommerceAnalyticsController`) already delegate to `CacheKeyGenerator`; this standalone concatenation is an outlier.
    - **Plain English:** All the other dashboard charts use a shared address book to look up their data. This one chart wrote the address on a scrap of paper. If the address format changes, this chart won’t know and will show old information.
    - **Evidence:**
        ```php
        $cacheKey = "analytics:commerce:brand_affiliate_snapshot:v1:{$brandId}:{$affiliateId}";
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#CCH-3** · P3 — Deprecated shop‑summary endpoint builds cache key without `CacheKeyGenerator`
    - **Where:** app/Http/Controllers/Api/Professional/Analytics/ProfessionalAnalyticsController.php:shopSummary() (key construction around line 680)
    - **Affects:** In‑flight callers of the deprecated shopSummary endpoint; key drift if the analytics key format is ever normalised.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$cacheKey = 'analytics:shop:'.$professional->id.':'.$from->format('YmdH').':'.$to->format('YmdH')...` with a method on `CacheKeyGenerator` (e.g. `CacheKeyGenerator::analyticsShopSummary(...)`) that mirrors the shape used by the main `summary()` endpoint.
    - **Technical:** `summary()` already uses `CacheKeyGenerator::analyticsSummary` for the same logical data; `shopSummary()` constructs its key with ad‑hoc concatenation. Although the endpoint is deprecated, it remains live and cached – if the key composition drifts (e.g. changing the date‑format or adding a new segment), the two endpoints will read different cache entries for the same professional/window.
    - **Plain English:** Two different doors into the same room use the same key‑cutting machine. One door’s lock was cut by hand years ago and still works, but if the machine’s template changes, that lock won’t open any more. Moving it to the machine avoids future confusion.
    - **Evidence:**
        ```php
        $cacheKey = 'analytics:shop:'.$professional->id.':'.$from->format('YmdH').':'.$to->format('YmdH').':'
            .($useHourlyBuckets ? 'hour' : 'day').":v{$summaryVersion}";
        ```
    - `[DRAFT, confidence: 1.0]`

<!-- LENS: gold | CHUNK: ctrl-prof-b-staff -->

- [ ] **#CCH-1** · P2 — StripeConnectController uses ad-hoc string keys instead of CacheKeyGenerator (category 8)
    - **Where:** app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php (three methods)
    - **Affects:** Stripe transaction list, balance, and upcoming-payout dashboards — staff and professional surfaces.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add three key-generator methods to `CacheKeyGenerator`: `stripeTransactions(...)`, `stripeBalance(...)`, and `stripeUpcomingPayouts(...)`.
        - Replace the ad-hoc `sprintf` / string-concat key construction in `transactions()`, `balance()`, and `upcomingPayouts()` with the new `CacheKeyGenerator` methods.
    - **Technical:** The gold standard requires all cache keys to originate from `CacheKeyGenerator` so readers and writers share a single source of truth. Ad-hoc concatenation risks silent key drift if a future invalidation path (e.g. a webhook handler) constructs the key differently. The `transactions` key also includes `limit` in the filter hash, creating a separate cache entry per pagination size — low impact at 60s TTL but a design smell the `CacheKeyGenerator` method can document.
    - **Plain English:** Three dashboard panels build their cache keys by hand instead of using the shared key-making service. It’s like three employees each writing their own version of a filing label — if a cleanup job later needs to find the file, someone will use the wrong label and miss it.
    - **Evidence:**
        ```php
        // transactions()
        $cacheKey = sprintf(
            'stripe:txns:%s:%s:%s',
            $role,
            $pro->id,
            hash('xxh64', json_encode($filters) ?: ''),
        );

        // balance()
        $cacheKey = 'stripe:balance:'.$pro->id;

        // upcomingPayouts()
        $cacheKey = 'stripe:upcoming_payouts:'.$pro->id;
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#CCH-2** · P3 — StaffStatsController uses a hardcoded cache key constant instead of CacheKeyGenerator (category 8)
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffStatsController.php:26,35-37
    - **Affects:** Staff ops dashboard platform-wide stats tile.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CacheKeyGenerator::staffOpsStats()` method.
        - Replace `self::CACHE_KEY` usage with the new generator method.
    - **Technical:** A private string constant (`'staff:ops:stats'`) bypasses `CacheKeyGenerator`, the project's single source of truth for cache key composition. If a future background job needs to bust this cache (e.g. on a bulk-account import), it would need to duplicate the magic string — the exact drift scenario `CacheKeyGenerator` prevents.
    - **Plain English:** The ops dashboard stats are stored under a label typed directly into the controller, not generated by the shared labelling toolkit. If another part of the system ever needs to clear that label, it has to guess the exact spelling — and guessing wrong means the old numbers stay visible.
    - **Evidence:**
        ```php
        private const CACHE_KEY = 'staff:ops:stats';

        private const CACHE_TTL_SECONDS = 60;
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CCH-3** · P2 — BookingAnalyticsController passes a DateTimeInterface TTL to rememberLocked, bypassing jitter (category 2)
    - **Where:** app/Http/Controllers/Api/Professional/Booking/BookingAnalyticsController.php:60-61
    - **Affects:** Booking analytics dashboard — every professional calling `/api/booking/analytics/my-overview`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$ttl = now()->addMinutes(2)` / `now()->addMinutes(10)` with integer seconds (120 and 600) so `JitteredTtl` can apply ±20% spread.
        - If the `CacheLockService` closure runtime is the concern, pass the int TTL and let `rememberLocked` handle jitter internally.
    - **Technical:** `CacheLockService::rememberLocked` applies TTL jitter via the `JitteredTtl` trait, but the trait operates on integer TTLs. Passing a `DateTimeInterface` (`now()->addMinutes(2)`) sidesteps the jitter logic entirely, producing fleet-wide synchronised expiries. Two-minute and ten-minute caches that all expire at the same wall-clock second create a thundering-herd spike every time the cache goes cold across the fleet.
    - **Plain English:** Every booking-analytics cache entry for every professional expires at exactly the same moment — like setting every oven timer in a restaurant to go off at 12:00 sharp. When they all ding at once, the kitchen gets slammed. Adding a small random wobble (±20%) to each timer spreads the load naturally.
    - **Evidence:**
        ```php
        $ttl = $metricsContext['use_hourly'] ? now()->addMinutes(2) : now()->addMinutes(10);
        $cacheKey = CacheKeyGenerator::bookingAnalytics(
            $professionalId,
            (string) $metricsContext['range_from'],
            (string) $metricsContext['range_to'],
            (string) $metricsContext['group_by']
        );

        return $this->success($this->cacheLock->rememberLocked($cacheKey, $ttl, function () use (...) {
            // ...
        }));
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CCH-4** · P2 — StaffBookingController passes a DateTimeInterface TTL to rememberLocked, bypassing jitter (category 2)
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffBookingController.php:92-93
    - **Affects:** Staff-facing booking analytics inspector — mirrors the professional-side analytics endpoint.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same fix as CCH-3: replace `now()->addMinutes(2)` / `now()->addMinutes(10)` with integer seconds (120 / 600).
    - **Technical:** Identical root cause to CCH-3. The staff booking-analytics path is a copy of the professional-side `BookingAnalyticsController` and inherits the same `DateTimeInterface` TTL deviation. Both endpoints share the same `CacheKeyGenerator::bookingAnalytics` key, so their expiries are synchronised fleet-wide — doubling the thundering-herd surface.
    - **Plain English:** Same oven-timer problem as the professional booking dashboard, just on the staff side. Both dashboards share the same cache key, so they expire in lockstep, doubling the stampede when they go cold.
    - **Evidence:**
        ```php
        $ttl = $metricsContext['use_hourly'] ? now()->addMinutes(2) : now()->addMinutes(10);
        $cacheKey = CacheKeyGenerator::bookingAnalytics(
            $professionalId,
            (string) $metricsContext['range_from'],
            (string) $metricsContext['range_to'],
            (string) $metricsContext['group_by'],
        );

        return $this->success($this->cacheLock->rememberLocked($cacheKey, $ttl, function () use (...) {
            // ...
        }));
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CCH-5** · P2 — Stripe balance and upcoming-payouts caches have no push-invalidation on the write path (category 4)
    - **Where:**
        - **Read:** app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php — `balance()` (line: `$cacheKey = 'stripe:balance:'.$pro->id`) and `upcomingPayouts()` (line: `$cacheKey = 'stripe:upcoming_payouts:'.$pro->id`)
        - **Write:** No invalidation call visible in the provided scope for either key. Stripe webhook handlers (`v2.core.account.updated`, payout events) that mutate balance/payout state do not appear to call `Cache::forget` or `CacheLockService` invalidation for these keys.
    - **Affects:** Affiliate dashboard balance tile and upcoming-payouts list — both show stale data for up to 60 seconds after a Stripe event changes the true state.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In the Stripe webhook handler for `account.updated` and payout status-change events, add `Cache::forget('stripe:balance:'.$pro->id)` and `Cache::forget('stripe:upcoming_payouts:'.$pro->id)` (or route through `CacheKeyGenerator` after CCH-1 is resolved).
        - Also forget the `:stale` companion keys so the SWR layer doesn't serve last-good data after an explicit state change.
    - **Technical:** The `balance` and `upcomingPayouts` methods rely entirely on their 60-second TTL for freshness. A Stripe Connect account status change, a completed payout, or a new pending payout will not be visible on the dashboard until the TTL naturally expires. The `status()` method has a `?fresh=1` escape hatch that calls `StripeConnectService::forgetStatusCache`, but no equivalent push-invalidate exists for balance or payouts. This is a TTL-only invalidation strategy on user-visible financial data — the core anti-pattern the gold standard guards against.
    - **Plain English:** When Stripe sends us a "money arrived" or "payout completed" notification, the affiliate's dashboard doesn't find out until a 60-second timer runs out. It's like a bank that only updates your balance once a minute regardless of what just happened — the number on screen is up to a minute behind reality.
    - **Evidence:**
        ```php
        // Read site — balance()
        $cacheKey = 'stripe:balance:'.$pro->id;
        $payload = $this->cacheLock->rememberLocked($cacheKey, 60, function () use ($pro) {
            return [
                'balance' => $this->balanceService->forAffiliate($pro),
                'schedule' => $this->balanceService->payoutScheduleFor($pro),
            ];
        });

        // Read site — upcomingPayouts()
        $cacheKey = 'stripe:upcoming_payouts:'.$pro->id;
        $rows = $this->cacheLock->rememberLocked(
            $cacheKey,
            60,
            fn () => $this->balanceService->upcomingFor($pro),
        );
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#CCH-6** · P2 — StaffAnalyticsController swallows click-analytics errors inside a cached closure, permanently caching empty results (category 10)
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php (inside `rememberLocked` closure, three try-catch blocks)
    - **Affects:** Staff analytics dashboard — click counts, clickers, click-by-day chart, and top-links table silently zero out for the full 60s TTL if the `analytics.link_clicks` table is unreachable.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `try { ... } catch (Throwable) { return zeroed-object; }` wrappers around the three click-analytics queries.
        - Let exceptions bubble out of the closure — `CacheLockService` will not cache the failed result, and Nightwatch will surface the error.
        - If partial degradation is desirable (clicks fail but visits still render), move the try-catch outside the cache-fill path and apply it only to the payload assembly after a successful cache read.
    - **Technical:** Three queries inside the `rememberLocked` closure wrap their DB calls in `try { ... } catch (Throwable) { ... }` and return zeroed sentinel values on any failure. Because `CacheLockService` caches whatever the closure returns, a transient `analytics.link_clicks` outage produces a cached payload with `total_clicks=0, unique_clickers=0` that persists for the full 60s TTL — even after the table recovers. This is the exact anti-pattern: caching a failure mode turns a transient error into a fleet-wide stale-empty until TTL expiry. The visit-analytics queries in the same closure do NOT swallow errors, confirming this is inconsistent and unintentional.
    - **Plain English:** If the click-tracking database has a hiccup, the staff dashboard shows "0 clicks" for a full minute — even though clicks are still happening. It's like a speedometer that freezes at 0 when it hits a bump, and stays frozen until you restart the car.
    - **Evidence:**
        ```php
        try {
            $clicksAgg = DB::table('analytics.link_clicks')
                ->where('professional_id', $professional->id)
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw('COUNT(*) as total_clicks')
                // ...
                ->first();
        } catch (Throwable) {
            $clicksAgg = (object) [
                'total_clicks' => 0,
                'unique_clickers' => 0,
                'last_click_at' => null,
            ];
        }
        ```
    - `[DRAFT, confidence: 0.9]`

<!-- LENS: gold | CHUNK: ctrl-public-internal -->

- [ ] **#CCH-1** · P1 — Hydrogen storefront config endpoint has zero caching on a hot read path
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicShopifyStorefrontController.php:45-100
    - **Affects:** Every Hydrogen storefront initial render across all brands. Each page load runs 2+ DB queries for the same rarely-changing credentials (shop domain, storefront token, collection handle, brand status).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inject `CacheLockService` and wrap the integration resolution + payload assembly in `rememberLocked` with a 60s TTL (matching `HydrogenBrandConfigController`).
        - On write paths that mutate the returned data (storefront token creation, brand status transitions), call `Cache::forget` on the matching key.
    - **Technical:** This is a public, unauthenticated endpoint called by Hydrogen at runtime — every storefront visitor triggers it. The controller has a job-dedup `Cache::put` for storefront token creation (line ~98) which proves the endpoint is called frequently enough to need dedup, yet the read itself has no caching layer. Compare `HydrogenBrandConfigController::show` which resolves a nearly identical integration query and caches the full payload for 60s via `CacheLockService::rememberLocked` — this endpoint should follow the same pattern.
    - **Plain English:** Imagine every customer walking into your store has to wait while the cashier looks up the store's own address in a filing cabinet. That's what this endpoint does — it re-queries the database for credentials that change once a month, on every single page load, for every single storefront visitor. The fix is to put that lookup result on a sticky note at the register (cache it) and only re-check the filing cabinet when the credentials actually change.
    - **Evidence:**
        ```php
        public function storefrontConfig(Request $request): JsonResponse
        {
            // ...validation...

            // Resolve integration: shop_domain takes precedence over brand_slug
            $integration = ! empty($validated['shop_domain'])
                ? $this->resolveByShopDomain($validated['shop_domain'])
                : $this->resolveByBrandSlug($validated['brand_slug']);

            if (! $integration) {
                return $this->error('Not found.', 404);
            }

            $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
            $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
            $storefrontToken = trim((string) ($integration->storefront_token ?? ''));

            // ... no caching layer — every call queries DB ...
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CCH-2** · P2 — Affiliate services endpoint has no caching layer
    - **Where:** app/Http/Controllers/Api/Internal/HydrogenAffiliateController.php:150-193
    - **Affects:** Hydrogen lazy-fetch calls for the Services & Pricing section. Each call re-resolves the brand integration, re-validates the affiliate, and rebuilds services data from scratch.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the services resolution in `CacheLockService::rememberLocked` with a key from `CacheKeyGenerator`, matching the 60s TTL pattern used by the parent `show()` endpoint.
        - Invalidate in `SiteCacheService` on `SiteSettings` writes that affect services or section blocks.
    - **Technical:** The `show()` method already caches the full affiliate payload (including services) at 60s via `CacheLockService`. This standalone `services()` endpoint — described in the docblock as "Standalone endpoint kept for back-compat / lazy fetches" — performs the same 3 gate queries + `buildServicesData()` inline with no caching. If Hydrogen calls this endpoint independently (lazy-loaded section), it produces a stampede on cold cache with the same DB cost as the full payload, defeating the purpose of the `show()` cache.
    - **Plain English:** The main affiliate page already has a smart caching system that remembers the answer for 60 seconds. But there's a second doorbell for just the "services" section that has no memory at all — it re-does all the same ID checks and database lookups from scratch every time. If Hydrogen pulls services separately after the main page loads, the cache on the main page doesn't help. The fix is to put the same 60-second memory on this doorbell too.
    - **Evidence:**
        ```php
        public function services(Request $request): JsonResponse
        {
            // ...validation...

            $integration = ProfessionalIntegration::query()
                ->where('shopify_shop_domain', $shopDomain)
                ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
                ->first();

            // ...affiliate lookup, link check, site query...

            return $this->success($this->resolver->buildServicesData($site, $affiliate->id));
        }
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#CCH-3** · P2 — Site publish toggle does not invalidate the public site payload cache
    - **Where:** Write: app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php:18-25 — Read: app/Http/Controllers/Api/PublicSite/PublicSiteController.php:20-24
    - **Affects:** Professionals who unpublish their site — visitors continue seeing the cached published payload until the TTL expires. Conversely, professionals who publish a previously-unpublished site may wait for the next cache refresh before it becomes visible.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After `$site->save()` in `SiteVisibilityController::update()`, call `SiteCacheService::forgetPublicSitePayload($site->subdomain)` (or the equivalent invalidation helper).
        - Ensure the invalidation forgets both the primary key and the `:stale` companion so the stale-while-revalidate window doesn't serve the old published state.
    - **Technical:** `PublicSiteController::show()` reads from `SiteCacheService::getPublicSitePayload()` which is the gold-standard cached path. The write path in `SiteVisibilityController::update()` toggles `site->is_published` (via `$site->published`) but never calls any cache invalidation. This is a textbook TTL-only invalidation gap — the cached payload carries the old published state and will serve it until the TTL expires naturally. For a site being unpublished, this means the public payload remains accessible for the full cache window; for a site being published, the 404 response remains cached.
    - **Plain English:** There's a light switch that controls whether a professional's public page is visible or hidden. The public page has a cached "photo" of itself that it shows visitors to avoid rebuilding it every time. When someone flips the switch to "hidden," the system updates the database but doesn't tear down the old photo — so visitors keep seeing the old "visible" photo until it naturally fades. The fix is to shred the old photo at the same moment the switch is flipped.
    - **Evidence:**
        ```php
        // Write path — SiteVisibilityController.php:18-25
        public function update(UpdateVisibilityRequest $request): JsonResponse
        {
            $professional = $request->attributes->get('professional');
            // ...
            $site = Site::query()
                ->where('professional_id', $professional->id)
                ->firstOrFail();

            $site->published = (bool) $request->validated('published');
            $site->save();
            // ← NO cache invalidation here

            return $this->success([
                'site' => $site->fresh(),
            ]);
        }
        ```
        ```php
        // Read path — PublicSiteController.php:20-24
        $payload = $this->siteCache->getPublicSitePayload($subdomain);
        if ($payload) {
            return $this->success($this->liveStatus->injectIntoPayload($payload));
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CCH-4** · P2 — Shopify errors inside a cached closure pollute the product settings cache with incorrect defaults
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php:138-167 (call site) and 251-339 (fetchProductMetafields swallowing errors)
    - **Affects:** Embedded product settings UI for brands. During a Shopify Admin API outage, the cached payload silently defaults `active` to `true` and all collection memberships to `false`, and that incorrect data survives for 5 minutes (primary TTL) + up to 50 minutes (stale window).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `fetchProductMetafields()`, let `\Throwable` bubble out of the `rememberLocked` closure instead of returning `$empty`. `CacheLockService::rememberLocked` will not cache the result when the closure throws — the next request retries the fetch.
        - In `isInCollection()`, apply the same treatment: log the error but let the exception propagate so the closure fails rather than caching `false`.
    - **Technical:** `buildSettingsPayload()` is the closure passed to `CacheLockService::rememberLocked`. It calls `fetchProductMetafields()` which catches all `\Throwable` and returns `['metafields' => [], 'variants' => []]`. The closure then happily assembles a payload with `'active' => $metafields['active'] ?? true` — defaulting to `true` because the metafields array is empty. This payload IS cached by `writeWithJitter` inside `rememberLocked`. The gold-standard rule (category 10) is that closures inside `Cache::remember`/`rememberLocked` must not swallow errors — the exception must bubble so bad data never enters the cache. `resolveActive()` in `EmbeddedProductAnalyticsController` already follows this rule (logs warning, returns null only for `ShopifyClientException`, uses `rememberLockedNullable`). This closure does the opposite.
    - **Plain English:** Think of the cache as a whiteboard where you write down answers so you don't have to recalculate them. When Shopify is down and you can't get the real answer, this code writes "active: yes, in collections: no" on the whiteboard anyway — because those are the defaults when no data comes back. For the next 5–50 minutes, every settings page load reads that wrong answer from the whiteboard, even after Shopify recovers. The fix is: when you can't get the real answer, don't write anything on the whiteboard. Just throw up your hands and let the next person try again.
    - **Evidence:**
        ```php
        // fetchProductMetafields — catches ALL Throwable and returns empty
        } catch (\Throwable $e) {
            Log::error('Shopify Admin API exception fetching product metafields.', [...]);
            return $empty;  // $empty = ['metafields' => [], 'variants' => []]
        }
        ```
        ```php
        // buildSettingsPayload — uses empty metafields, defaults active to true
        $result = $this->fetchProductMetafields($integration, $productId);
        $metafields = $result['metafields'];
        // ...
        return [
            'active' => $metafields['active'] ?? true,  // ← defaults TRUE on error
            // ...
        ];
        ```
        ```php
        // This payload IS cached inside rememberLocked
        $payload = $this->cacheLock->rememberLocked(
            $cacheKey,
            300,
            fn () => $this->buildSettingsPayload($integration, $productGid, $productId, $professionalId),
        );
        ```
    - `[DRAFT, confidence: 0.80]`
