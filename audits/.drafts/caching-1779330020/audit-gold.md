# Whole-Backend Caching Gold-Standard Adherence Audit — 2026-05-21

**Branch:** development
**Lens:** Whole-backend caching GOLD-STANDARD ADHERENCE audit — single-flight locks, jitter, SWR invalidation, key centralisation, no cached failures
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/FeatureFlags/FeatureFlagService.php
- app/Services/Professional/Brand/BrandStatusService.php
- app/Services/Stripe/CommissionPayoutService.php
- app/Services/Stripe/CommissionVoidService.php
- app/Services/Store/BrandCatalogService.php
- app/Services/Store/AffiliateProductCatalogService.php
- app/Services/Shopify/ShopifyDisconnectService.php
- app/Services/Shopify/ShopifySetupTokenService.php
- app/Services/Square/SquareTokenService.php
- app/Services/Fresha/FreshaTokenService.php
- app/Services/Notifications/NotificationPublisher.php
- app/Services/Notifications/NotificationListingService.php
- app/Services/Analytics/AnalyticsService.php
- app/Jobs/Cache/InvalidateBrandAffiliatesCacheJob.php
- app/Jobs/Shopify/ProcessShopifyShopUpdateJob.php
- app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php
- app/Http/Controllers/Api/Internal/HydrogenAffiliateController.php
- app/Http/Controllers/Api/PublicSite/PublicShopifyStorefrontController.php
- app/Http/Controllers/Api/PublicSite/PublicSiteController.php
- app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php
- app/Http/Controllers/Api/Professional/Analytics/ProfessionalAnalyticsController.php
- app/Http/Controllers/Api/Professional/Booking/BookingAnalyticsController.php
- app/Http/Controllers/Api/Professional/Brand/BrandAffiliateController.php
- app/Http/Controllers/Api/Professional/Brand/ShopifyEmbeddedConnectionController.php
- app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffBookingController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffStatsController.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 11 complete
- P3 Low: 0 of 5 complete

---

## P1 — Fix before pilot launch

- [ ] **#CCH-1** · P1 — `storefrontConfig` is uncached on a hot unauthenticated endpoint called by Hydrogen on every storefront render
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicShopifyStorefrontController.php:45–100 (`storefrontConfig`)
    - **Affects:** Every Hydrogen storefront page load across all brands. Each call runs 2+ DB queries to resolve credentials (shop domain, storefront token, collection handle, brand status) that change at most once per month. There is no caching layer at all.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inject `CacheLockService` and wrap the integration resolution + payload assembly in `rememberLocked` with a 60s TTL matching the pattern in `HydrogenBrandConfigController::show`.
        - Add a `CacheKeyGenerator::shopifyStorefrontConfig(string $shopDomain): string` helper so the key is centralised.
        - On write paths that mutate the returned data (storefront token creation, brand status transitions), call `Cache::forget` on the matching key + stale twin inside a `DB::afterCommit` callback.
    - **Technical:** `HydrogenBrandConfigController::show` resolves an almost identical integration query and caches the full payload for 60s via `CacheLockService::rememberLocked`. This endpoint has a dedup guard for the `CreateStorefrontAccessTokenJob` dispatch using `Cache::has`/`Cache::put` (line ~98), proving the call rate is high enough to need dedup, yet the read path itself has no cache at all. At pilot scale with 30 brands, cold cache means every affiliate storefront page load hits the DB — the endpoint is public and unauthenticated so there is no per-user TTL amortisation.
    - **Plain English:** Every time a visitor opens an affiliate's storefront, this endpoint runs database queries to look up the same store credentials that haven't changed in months. It's like a supermarket cashier looking up the store's own address in a filing cabinet for every transaction. The fix is to keep a sticky note at the register (a 60-second cache) and only re-check the filing cabinet when the address actually changes.
    - **Evidence:**
        ```php
        // No caching — DB queries run on every storefront page load
        $integration = ! empty($validated['shop_domain'])
            ? $this->resolveByShopDomain($validated['shop_domain'])
            : $this->resolveByBrandSlug($validated['brand_slug']);

        if (! $integration) {
            return $this->error('Not found.', 404);
        }

        $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $storefrontToken = trim((string) ($integration->storefront_token ?? ''));
        // ... no rememberLocked, no Cache::get/put anywhere on the happy path
        ```

- [ ] **#CCH-2** · P1 — `NotificationPublisher` inserts notification rows without busting the listing cache, causing guaranteed staleness on every publish
    - **Where:** Write site: app/Services/Notifications/NotificationPublisher.php (`publish`, `publishMany`). Read site: app/Services/Notifications/NotificationListingService.php (`index`, `bustIndexCache`).
    - **Affects:** Every professional's notification bell on the dashboard. After any notification is published (brand invite, payout settlement, commission warning), the bell dropdown continues to show the pre-publish list for up to 15 seconds. For time-critical notifications like payout settlement this is a guaranteed staleness window on every event.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Make `bustIndexCache` in `NotificationListingService` at least `protected` (or extract a public `bustForProfessional(string $professionalId): void` wrapper).
        - Inject `NotificationListingService` into `NotificationPublisher` and call `bustForProfessional($professionalId)` in `publish()` after `$inserted > 0`, and in `publishMany()` after each successfully inserted row's `$professionalId`.
        - Alternatively, dispatch a `BustNotificationCacheJob` post-insert to keep `NotificationPublisher` free of direct service injection.
    - **Technical:** `NotificationListingService::markRead` and `dismiss` both call `bustIndexCache()` to push-invalidate on mutation. `publish()` and `publishMany()` insert new notification rows via `insertOrIgnore` but never touch the listing cache. The 15s TTL is intentionally short ("short enough that a server-side notification publish surfaces within one poll cycle"), but that design presupposes a TTL-only strategy is acceptable — the existing `markRead`/`dismiss` push-invalidation shows it isn't. Every notification publish produces a guaranteed stale window for the recipient.
    - **Plain English:** When someone sends you a push notification, the database is updated instantly but the dashboard bell only checks for new messages every 15 seconds. Every time a payout settles, an affiliate gets invited, or a warning fires, the recipient's dashboard bell won't update until the timer runs out. `markRead` already fixes the bell immediately when you tap it — this just adds the same "clear the old list" step when a new message is added.
    - **Evidence:**
        ```php
        // publish() — inserts row but never busts the listing cache
        $inserted = DB::table('notifications.notifications')->insertOrIgnore([...]);

        if ($inserted > 0 && config('partna.notifications.email_enabled', false)) {
            SendTransactionalNotificationEmailJob::dispatch(...)->onQueue('mail');
        }
        // No bustIndexCache / forgetPublicSitePayload equivalent
        ```
        ```php
        // markRead correctly busts on mutation — publish should do the same
        public function markRead(Notification $notification, string $professionalId): void
        {
            $this->upsertReceipt($notification->id, $professionalId, ['read_at' => now()]);
            $this->bustIndexCache($professionalId);
        }
        ```

---

## P2 — Should fix

- [ ] **#CCH-3** · P2 — `BrandStatusService::isStorefrontReachable` uses raw `Cache::get` + `Cache::put` with no single-flight lock and no jitter
    - **Where:** app/Services/Professional/Brand/BrandStatusService.php:248–266 (`isStorefrontReachable`)
    - **Affects:** Any endpoint that evaluates brand status (onboarding checklist, embedded provision-integration, brand dashboard). Concurrent admin page loads that hit a cold cache all fire independent outbound HTTP requests to the brand's storefront simultaneously.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inject `CacheLockService` and replace the `Cache::get` + HTTP call + `Cache::put` block with `$this->cacheLock->rememberLocked($cacheKey, $reachable ? 60 : 15, fn() => $this->doHttpCheck($url))`.
        - Extract the HTTP probe into a private `doHttpCheck(string $url): bool` so the closure is testable.
        - Use integer TTLs (60/15) not `Carbon` instances so `writeWithJitter` applies ±20% spread — eliminating the synchronised expiry that causes fleet-wide cold hits at the same clock second.
    - **Technical:** The current code does `Cache::get` → on miss, executes an HTTP GET with 5s timeout → `Cache::put($key, $result, $reachable ? 60 : 15)`. There is no atomicity guard between the get and the put, and the TTL is a raw integer not passed through `JitteredTtl`. Two deviations in one: category 1 (no single-flight lock on an outbound HTTP call) and category 2 (unjittered TTL). `CacheLockService::rememberLocked` addresses both.
    - **Plain English:** When a brand's status page loads and the cache is empty, every simultaneous admin tab fires its own HTTP request to check the storefront — like all the staff calling the same phone number at once. The fix sends a single caller while the others wait, and adds a small random wobble to the cache timer so they don't all expire at exactly the same second.
    - **Evidence:**
        ```php
        $cacheKey = 'brand_status:storefront_reachable:'.sha1($url);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool) $cached;
        }

        try {
            $response = Http::withOptions([...])->get($url);
            $reachable = $response->successful();
        } catch (\Throwable) {
            $reachable = false;
        }

        Cache::put($cacheKey, $reachable, $reachable ? 60 : 15);
        ```

- [ ] **#CCH-4** · P2 — `failPayout` and `cancelExpiredPayout` mutate payout state without bumping the analytics cache version
    - **Where:** Write sites: app/Services/Stripe/CommissionPayoutService.php (`failPayout` ~line 554); app/Services/Stripe/CommissionVoidService.php (`cancelExpiredPayout` ~line 275). Read site: cached analytics reads via `AnalyticsCacheService` version-token pattern.
    - **Affects:** Brand and affiliate analytics dashboards after a payout fails (declined card) or expires (grace period). Both dashboards continue showing the payout as "in flight" and show stale commission counts until the analytics cache TTL expires naturally.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `failPayout`, add `$this->analyticsCache->bumpAnalyticsVersion($payout->brand_professional_id)` and `$this->analyticsCache->bumpAnalyticsVersion($payout->affiliate_professional_id)` after `$payout->save()` — matching the pattern already used in `markCompleted`.
        - In `cancelExpiredPayout` (CommissionVoidService), inject or resolve `AnalyticsCacheService` and call `bumpAnalyticsVersion` for both brand and affiliate IDs after a successful cancellation. Both IDs are available: `$payout->brand_professional_id` and `$payout->affiliate_professional_id`.
    - **Technical:** `markCompleted` correctly calls `$this->analyticsCache->bumpAnalyticsVersion(...)` for both parties so the next dashboard read picks up the completed payout. `failPayout` — which transitions to `failed`, releases orders, and deletes payout items — does not bump the token. `cancelExpiredPayout` in CommissionVoidService — which transitions to `cancelled`, voids orders, and clears stamps — also does not bump. Both are terminal state transitions that mutate data used by the analytics read path (version-token category 5). The DB-side rollup trigger fires correctly, but cached analytics reads won't see it until the version token increments.
    - **Plain English:** When a payout succeeds, the dashboard caches refresh immediately. When it fails or expires — the brand's card was declined, or an affiliate never connected their bank — the dashboard freezes on the old numbers until the cache timer runs out. Staff and brands see "pending" payouts that are already dead. The fix adds the same "refresh dashboard now" signal to the failure and expiry paths that success already has.
    - **Evidence:**
        ```php
        // failPayout — no analytics version bump
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
            Log::warning('Commission payout failed', [...]);
        }

        // markCompleted — correctly bumps both parties
        private function markCompleted(CommissionPayout $payout, Professional $brand, Professional $affiliate): void
        {
            $payout->forceFill([...])->save();
            $this->analyticsCache->bumpAnalyticsVersion($brand->id);
            $this->analyticsCache->bumpAnalyticsVersion($affiliate->id);
        }
        ```
        ```php
        // cancelExpiredPayout (CommissionVoidService) — no analytics bump after cancellation
        $stats['cancelled_count']++;
        $stats['cancelled_cents'] += (int) $payout->gross_commission_cents;
        $stats['voided_entries'] += $voidedOrders;
        $cancelled = true;
        // Transaction ends — no analyticsCache->bumpAnalyticsVersion() call anywhere
        ```

- [ ] **#CCH-5** · P2 — `BrandCatalogService::fetchProductCustomPhotosMetafield` uses raw `Cache::get` + `Cache::put` with no single-flight lock, no jitter, and a `DateTimeInterface` TTL
    - **Where:** app/Services/Store/BrandCatalogService.php (`fetchProductCustomPhotosMetafield`)
    - **Affects:** Any per-product custom-photos permission check during affiliate catalog rendering. Multiple concurrent affiliates loading the same brand's products all fire Shopify Admin API calls in parallel on a cold cache miss.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `Cache::get` / HTTP call / `Cache::put` block with `$this->cacheLock->rememberLockedNullable(...)` (since null is a valid sentinel meaning "metafield not set"). `rememberLockedNullable` is already used elsewhere in the codebase for nullable Shopify reads.
        - Change the TTL from `now()->addSeconds(...)` to a plain integer so `writeWithJitter` can apply ±20% jitter.
        - Drop the manual string-sentinel encoding (`'true'`/`'false'`/`'unset'`) — `rememberLockedNullable` handles null natively.
    - **Technical:** Three deviations from the gold standard: (1) no single-flight lock — N concurrent callers all call Shopify; (2) `DateTimeInterface` TTL via `now()->addSeconds(N)` bypasses `JitteredTtl`, synchronising expiry across the fleet; (3) no `:stale` companion, so every cold caller blocks on the API call. The string-sentinel pattern exists to work around `Cache::remember`'s inability to cache null — `rememberLockedNullable` removes that constraint cleanly.
    - **Plain English:** When five affiliates open a brand's catalog at the same time and the cache is cold, all five make separate calls to Shopify asking "does this product allow custom photos?" instead of one person going and the others waiting. The fix adds a gatekeeper so only one call goes out, plus a small random wobble on the timer so they don't all expire at the same second.
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

- [ ] **#CCH-6** · P2 — `ShopifyDisconnectService::disconnect` leaves brand catalog caches live after disconnection
    - **Where:** Write site: app/Services/Shopify/ShopifyDisconnectService.php (`disconnect`). Read sites: `CacheKeyGenerator::brandAdminCatalog` (BrandCatalogService), `CacheKeyGenerator::brandActiveCatalog` (AffiliateProductCatalogService).
    - **Affects:** Affiliates browsing a brand's product catalog after the brand disconnects their Shopify store. The affiliate product list continues serving the pre-disconnect catalog for up to 5 minutes (active catalog TTL), showing products that no longer exist on the store.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After the `ProfessionalIntegration::delete()` call, add cache invalidation inside a `DB::afterCommit` callback:
          ```php
          DB::afterCommit(function () use ($brandProfessionalId) {
              Cache::forget(CacheKeyGenerator::brandAdminCatalog($brandProfessionalId));
              Cache::forget(CacheKeyGenerator::brandAdminCatalog($brandProfessionalId).':stale');
              Cache::forget(CacheKeyGenerator::brandActiveCatalog($brandProfessionalId));
              Cache::forget(CacheKeyGenerator::brandActiveCatalog($brandProfessionalId).':stale');
          });
          ```
        - The `:stale` twins must be forgotten alongside their primaries or the SWR fast path serves the old catalog for up to 10× the base TTL.
    - **Technical:** `ShopifyDisconnectService::disconnect` deletes the integration row, purges affiliate selections, clears wizard progress, and resets `BrandProfile` to `Onboarding` — but never touches the `brandAdminCatalog` or `brandActiveCatalog` cache keys. Both read paths use `CacheLockService::rememberLocked` with TTLs, so the stale catalog survives until natural expiry. This is a textbook category-4 deviation: a domain mutation with no corresponding push-invalidate on the cached read. The `disconnect` service is shared by both brand-facing and staff-facing disconnect endpoints.
    - **Plain English:** When a brand disconnects their Shopify store, everything is cleaned up in the database — but the "what's on the shelf" posters in the affiliate break room stay up. For up to five minutes after disconnect, affiliates still see the old product list as if the store is still live. The fix tears down those posters at the same moment we close the store.
    - **Evidence:**
        ```php
        // All local cleanup — NO cache invalidation
        AffiliateProductSelection::query()
            ->where('brand_professional_id', $brandProfessionalId)
            ->delete();

        ProfessionalIntegration::query()
            ->where('professional_id', $brandProfessionalId)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->delete();

        BrandStoreSettings::clearWizardProgress($brandProfessionalId);
        BrandProfile::where('professional_id', $brandProfessionalId)
            ->update([
                'brand_status' => BrandStatus::Onboarding->value,
                'setup_complete' => false,
            ]);
        // Log::info('Shopify disconnected', ...) — end of method, no Cache::forget calls
        ```

- [ ] **#CCH-7** · P2 — `SquareTokenService` and `FreshaTokenService` acquire token-refresh locks on the default cache store, not `cache_locks`
    - **Where:** app/Services/Square/SquareTokenService.php (`refreshAccessToken`); app/Services/Fresha/FreshaTokenService.php (`refreshAccessToken`)
    - **Affects:** Square and Fresha OAuth token refresh under concurrent requests. If the default cache store is flushed (deploy, `php artisan cache:clear`, Redis eviction), all held token-refresh locks are silently released, opening a window where multiple workers issue concurrent OAuth refreshes with the same refresh token — which most OAuth servers reject or invalidate.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In both services, replace `Cache::lock('integration_refresh:'.$integration->id, 30)` with `Cache::store('cache_locks')->lock('integration_refresh:square:'.$integration->id, 30)` (Square) and `...'integration_refresh:fresha:'...` (Fresha) to isolate lock lifecycle from data-cache lifecycle.
        - Adding the vendor prefix (`square:`/`fresha:`) to the key eliminates theoretical namespace collision between the two services for the same integration ID.
    - **Technical:** Laravel's `Cache::lock()` uses the default cache store. The `cache_locks` connection (a separate Redis DB) isolates locks from data-store operations. A `Cache::flush()` or `php artisan cache:clear` on the default store releases every held lock instantly. The lock duration of 30s exceeds both services' HTTP timeout (20s), and the block timeout of 10s is appropriate — only the store pinning is wrong. `CacheLockService` already uses a dedicated lock store; token services should follow suit.
    - **Plain English:** Both token-refresh guards share the same lock cabinet as the data cache. If maintenance empties the data cache — which happens on every deploy flush — every lock is released at the same moment. Multiple workers then rush to refresh the same OAuth token simultaneously, which most external services treat as an error. Moving the locks to their own isolated cabinet means a cache flush never touches them.
    - **Evidence:**
        ```php
        // SquareTokenService — default store, no vendor namespace
        $lock = Cache::lock('integration_refresh:'.$integration->id, 30);

        // FreshaTokenService — identical anti-pattern
        $lock = Cache::lock('integration_refresh:'.$integration->id, 30);
        ```

- [ ] **#CCH-8** · P2 — Stripe `balance` and `upcomingPayouts` caches have no push-invalidation from webhook events
    - **Where:** Read: app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php (`balance`, `upcomingPayouts`). Write path: no `Cache::forget` call exists for these keys in Stripe webhook handlers.
    - **Affects:** Affiliate dashboard balance tile and upcoming-payouts list. After a Stripe event changes the true state (completed payout, new pending payout, account status change), the dashboard shows stale numbers for up to 60 seconds.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In the Stripe webhook handlers for `payment_intent.succeeded`, `payment_intent.payment_failed`, and `account.updated`, add `Cache::forget('stripe:balance:'.$pro->id)`, `Cache::forget('stripe:balance:'.$pro->id.':stale')`, and the same for `stripe:upcoming_payouts:`.
        - After CCH-15 is resolved and these keys are centralised in `CacheKeyGenerator`, reference the helper instead of the raw string.
        - Add a `?fresh=1` escape hatch on `balance` and `upcomingPayouts` (mirroring the existing `status` endpoint pattern) as an immediate opt-out for dashboard loads right after a known Stripe event.
    - **Technical:** The `status()` endpoint already has `StripeConnectService::forgetStatusCache($pro->stripe_connect_account_id)` behind a `?fresh=1` escape hatch, proving the staleness problem was recognised for status. `balance` and `upcomingPayouts` rely entirely on the 60-second TTL for freshness. The `markCompleted` webhook handler in `CommissionPayoutService` already calls `analyticsCache->bumpAnalyticsVersion` for commerce data — the same push-invalidation pattern should cover the Stripe balance/payout view.
    - **Plain English:** When Stripe sends a "money arrived" or "payout completed" webhook, the affiliate's balance tile doesn't find out until a 60-second timer runs out. It's like a bank that only updates your statement once a minute regardless of what just happened. Adding a "clear the cached balance" step inside the webhook handler means the dashboard reflects the new number as soon as the event lands.
    - **Evidence:**
        ```php
        // balance() — TTL-only, no webhook push-invalidation
        $cacheKey = 'stripe:balance:'.$pro->id;
        $payload = $this->cacheLock->rememberLocked($cacheKey, 60, function () use ($pro) {
            return [
                'balance' => $this->balanceService->forAffiliate($pro),
                'schedule' => $this->balanceService->payoutScheduleFor($pro),
            ];
        });

        // upcomingPayouts() — same pattern
        $cacheKey = 'stripe:upcoming_payouts:'.$pro->id;
        $rows = $this->cacheLock->rememberLocked($cacheKey, 60, fn () => $this->balanceService->upcomingFor($pro));
        ```

- [ ] **#CCH-9** · P2 — Click-analytics queries inside `rememberLocked` closures catch all errors and cache the zeroed fallback, turning transient DB faults into permanent silent zeros
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php (`summary`, three try-catch blocks); app/Http/Controllers/Api/Professional/Analytics/ProfessionalAnalyticsController.php (`summary`, same pattern)
    - **Affects:** Staff and professional analytics dashboards. During any transient outage of `analytics.link_clicks`, the cached payload silently shows `total_clicks=0, unique_clickers=0` for the full TTL (60s or 300s) even after the table recovers.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `try { ... } catch (\Throwable) { return zeroed-object; }` wrappers around the three click-analytics queries inside the `rememberLocked` closure in both controllers.
        - Let `QueryException` propagate out of the closure — `CacheLockService` will not cache the result when the closure throws, and Nightwatch will surface the error.
        - If partial degradation is desirable (visits render even when clicks fail), apply the fallback logic outside the cache-fill closure: catch after `rememberLocked` returns and substitute zeroed values at the response assembly layer, not inside the cached computation.
    - **Technical:** The visit-analytics queries in both controllers do NOT catch errors inside the closure, confirming the click-analytics guards are inconsistent and unintentional. `CacheLockService::writeWithJitter` caches whatever the closure returns — including a zeroed sentinel. A transient `analytics.link_clicks` connection error produces a cached payload with `total_clicks=0` that persists for the full cache window even after the table recovers. This is the canonical category-10 anti-pattern: caching a failure mode turns a transient error into a fleet-wide stale-zero until TTL expiry.
    - **Plain English:** If the click-tracking database has a hiccup, the analytics dashboard shows "0 clicks" for a full minute (or five minutes on the professional view) — even though clicks kept happening and the database recovered immediately. It's like a car speedometer that freezes at zero when it hits a bump and stays frozen until you restart the engine. Letting the error bubble out of the cache means the next request retries the lookup instead of reading the frozen zero.
    - **Evidence:**
        ```php
        // StaffAnalyticsController — inside rememberLocked closure
        try {
            $clicksAgg = DB::table('analytics.link_clicks')
                ->where('professional_id', $professional->id)
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw('COUNT(*) as total_clicks')
                ->selectRaw('COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as unique_clickers')
                ->selectRaw('MAX(occurred_at) as last_click_at')
                ->first();
        } catch (Throwable) {
            $clicksAgg = (object) [
                'total_clicks' => 0,
                'unique_clickers' => 0,
                'last_click_at' => null,
            ];
        }
        ```

- [ ] **#CCH-10** · P2 — `EmbeddedProductSettingsController::fetchProductMetafields` swallows all Shopify errors and returns empty defaults, caching incorrect values for the full stale window
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php (`fetchProductMetafields` ~line 300, `buildSettingsPayload` ~line 213, `show` ~line 138)
    - **Affects:** Embedded product settings UI for brands. During any Shopify Admin API outage, the cached payload silently defaults `active` to `true` and all metafields to missing, persisting for up to 50 minutes (5-minute primary + 50-minute stale window).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `fetchProductMetafields`, change the `catch (\Throwable $e)` block to re-throw the exception after logging: `throw $e;`. Do not return `$empty`.
        - `CacheLockService::rememberLocked` will not write the result when the closure throws — the next request retries the Shopify call.
        - Note: the non-success HTTP response path (lines returning `$empty` after `Log::warning`) is a different issue — those represent valid "product not found" states and can stay as-is, or be converted to a thrown exception for consistency.
    - **Technical:** `buildSettingsPayload` is the closure passed to `rememberLocked`. It calls `fetchProductMetafields` which catches all `\Throwable` and returns `$empty = ['metafields' => [], 'variants' => []]`. The closure then assembles `'active' => $metafields['active'] ?? true` — defaulting to `true` because the array is empty. This payload IS cached by `writeWithJitter`. The gold-standard rule (category 10): closures inside `rememberLocked` must not swallow errors. `fetchVariants` in the same class already follows this rule correctly (re-throws after logging) and its doc comment explains why. `fetchProductMetafields` does the opposite.
    - **Plain English:** When Shopify is briefly unreachable, the product settings page writes "this product is active: yes" onto the cached whiteboard — because that's the default when there's no answer. For the next hour, every settings page load reads that wrong answer from the whiteboard, even after Shopify is back. The fix: when you can't get the real answer, don't write anything on the whiteboard. Let the next visitor try again.
    - **Evidence:**
        ```php
        // fetchProductMetafields — catches ALL Throwable and returns empty defaults
        } catch (\Throwable $e) {
            Log::error('Shopify Admin API exception fetching product metafields.', [...]);
            return $empty;  // $empty = ['metafields' => [], 'variants' => []]
        }
        ```
        ```php
        // buildSettingsPayload — uses empty metafields, defaults active to TRUE
        $result = $this->fetchProductMetafields($integration, $productId);
        $metafields = $result['metafields'];
        return [
            'active' => $metafields['active'] ?? true,  // ← TRUE on error
            ...
        ];
        ```
        ```php
        // This payload IS cached inside rememberLocked at 300s
        $payload = $this->cacheLock->rememberLocked($cacheKey, 300, fn () => $this->buildSettingsPayload(...));
        ```

- [ ] **#CCH-11** · P2 — `SiteVisibilityController::update` toggles publish state without invalidating the public site payload cache
    - **Where:** Write: app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php:18–35 (`update`). Read: app/Http/Controllers/Api/PublicSite/PublicSiteController.php:20–24 (`show` via `SiteCacheService::getPublicSitePayload`).
    - **Affects:** Professionals who unpublish their site — visitors continue receiving the cached published payload until the TTL expires. Professionals who publish a previously hidden site may wait for the cache to refresh before their site becomes visible.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After `$site->save()`, call `SiteCacheService::forgetPublicSitePayload($site->subdomain)` (or the equivalent bust method that forgets both primary and `:stale` twin) inside a `DB::afterCommit` callback so a rolled-back save doesn't wipe a warm cache.
    - **Technical:** `PublicSiteController::show` reads from `SiteCacheService::getPublicSitePayload($subdomain)` which is the gold-standard cached path. The write path in `SiteVisibilityController::update` toggles `site->published` and calls `$site->save()` but never calls any cache invalidation. The cached payload carries the old published state and will serve it until the TTL expires. For a site being unpublished, this is a confidentiality gap: the public payload is still served as if published.
    - **Plain English:** There's a light switch that controls whether a professional's public page is visible or hidden. The public page has a cached snapshot that it shows visitors. When someone flips the switch to "hidden," the database is updated but the old snapshot keeps serving visitors as if nothing changed. The fix shreds the old snapshot at the same moment the switch is flipped.
    - **Evidence:**
        ```php
        // Write path — no cache invalidation
        $site->published = (bool) $request->validated('published');
        $site->save();

        return $this->success(['site' => $site->fresh()]);
        ```
        ```php
        // Read path — served from cache that is never invalidated on publish toggle
        $payload = $this->siteCache->getPublicSitePayload($subdomain);
        if ($payload) {
            return $this->success($this->liveStatus->injectIntoPayload($payload));
        }
        ```

- [ ] **#CCH-12** · P2 — `HydrogenAffiliateController::services` has no caching, re-running all gate queries and data assembly on every lazy-fetch call
    - **Where:** app/Http/Controllers/Api/Internal/HydrogenAffiliateController.php (`services`, lines 147–193)
    - **Affects:** Hydrogen lazy-fetch calls for the Services & Pricing section. Each call re-executes 3 gate queries (integration lookup, affiliate lookup, brand-link check) plus `buildServicesData()` with no cache, even though the `show()` endpoint caches the same data for 60s.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a cache key (e.g. `CacheKeyGenerator::hydrogenAffiliateServices(string $brandId, string $slug)`) and wrap the services resolution in `$this->cacheLock->rememberLocked($key, self::CACHE_TTL_SECONDS, fn() => ...)` matching the `show()` endpoint's pattern.
        - Ensure `SiteCacheService::forgetHydrogenAffiliate` (the existing invalidation path) also forgets the services key.
    - **Technical:** The docblock acknowledges the endpoint is "kept for back-compat / lazy fetches." `show()` caches the full affiliate payload at 60s via `CacheLockService::rememberLocked` — the `services` section is included inside that cache. This standalone endpoint bypasses that cache entirely. If Hydrogen calls services independently (lazy-loaded section after the main page), it produces full DB + service cost on every call with no single-flight protection, defeating the purpose of the `show()` cache.
    - **Plain English:** The main affiliate page has a smart 60-second memory that prevents redundant database lookups. The services-only endpoint is a second front door to the same room that has no memory at all — it re-runs all the same ID checks and database queries from scratch on every request. The fix puts the same 60-second memory on this door.
    - **Evidence:**
        ```php
        // services() — no caching, runs gate queries + buildServicesData inline
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
        ```php
        // show() — same gate + payload path, properly cached
        $payload = $this->cacheLock->rememberLocked(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn () => $this->buildAffiliatePayload($affiliate),
        );
        ```

- [ ] **#CCH-13** · P2 — `FeatureFlagService` registry cache has no model-observer push-invalidation; adding a `FeatureFlag` row requires a manual `flushRegistry()` call to take effect within the TTL
    - **Where:** app/Services/FeatureFlags/FeatureFlagService.php (`loadRegistry`, `flushRegistry`)
    - **Affects:** Any deployment or operator action that adds, modifies, or removes a `FeatureFlag` row directly (via Tinker, an admin UI, or a seeder). The new flag remains invisible to all workers for up to 360 seconds (300s + 60s jitter) unless `flushRegistry()` is called separately.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a `FeatureFlagObserver` that calls `app(FeatureFlagService::class)->flushRegistry()` on `created`, `updated`, and `deleted` events.
        - Register the observer in `AppServiceProvider::boot()`: `FeatureFlag::observe(FeatureFlagObserver::class)`.
        - Note: `setOverride`/`clearOverride` already invalidate the per-pro/brand keys via `forgetPro`/`forgetBrand` — this finding is specifically about the registry (all-flags default) key which has no automatic invalidation.
    - **Technical:** The service docblock states "setOverride/clearOverride flush the relevant pro/brand key so the next read rebuilds from DB immediately." But adding a new `FeatureFlag` row (not an override) only affects the registry key `ff:registry`. There is no observer, no service event, and no hook wired to `FeatureFlag` model mutations. `flushRegistry()` exists and works, but callers must invoke it manually after DB changes — a deployment seeder that adds a flag but omits the flush leaves the entire fleet running the old flag list until the TTL expires.
    - **Plain English:** The feature flag registry is like a printed menu in a restaurant. If the chef adds a new dish to the kitchen database, the waiters keep showing the old menu until someone physically swaps it — which currently has to be done by hand with a separate command. Adding an observer is like wiring the menu printer to the kitchen computer: whenever a dish is added or removed, new menus print automatically.
    - **Evidence:**
        ```php
        private function loadRegistry(): array
        {
            return $this->cacheLock->rememberLocked(
                self::REGISTRY_KEY,   // 'ff:registry' — static key, no version component
                $this->jitteredTtl(),
                function (): array {
                    return FeatureFlag::query()
                        ->whereNull('deleted_at')
                        ->get()
                        ->mapWithKeys(fn ($f) => [$f->key => [...]])
                        ->all();
                },
            );
        }

        /** Flush only the registry key (use after adding/editing a FeatureFlag row). */
        public function flushRegistry(): void
        {
            $this->requestCache = [];
            Cache::forget(self::REGISTRY_KEY);
            Cache::forget(self::REGISTRY_KEY.':stale');
        }
        // No model observer or automatic trigger exists — must be called manually
        ```

---

## P3 — Nice to have

- [ ] **#CCH-14** · P3 — `NotificationListingService::bustIndexCache` hardcodes three limit values and silently misses cache keys for any other limit
    - **Where:** app/Services/Notifications/NotificationListingService.php (`bustIndexCache`)
    - **Affects:** Any caller of `index()` that passes a limit outside `[50, 100, 200]`. That cache entry is never invalidated on `markRead` or `dismiss`. The 15s TTL bounds the staleness, but the push-invalidation surface is incomplete by construction.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either: constrain `index()` to only accept limits from an allowlisted set (e.g. a typed constant array `ALLOWED_LIMITS = [50, 100, 200]`) and validate at call sites — making the hardcoded loop in `bustIndexCache` provably complete.
        - Or: record the actually-used limits in a Redis set keyed by professional on every `index()` call, and iterate that set in `bustIndexCache` — making the invalidation self-adjusting.
    - **Technical:** `index()` accepts any `int $limit` and constructs a cache key from it. `bustIndexCache` iterates `[50, 100, 200]`. If the frontend or an internal caller passes `25` or `75`, the cache entry for that limit is never invalidated on `markRead` or `dismiss`. The 15s TTL provides a safety net, but introducing a new screen with a different page size would silently break push-invalidation with no test failure or runtime error.
    - **Plain English:** The notification cache cleanup knows how to clear three specific shelf sizes — 50, 100, and 200 items. Any other shelf size (say 25) gets walked past by the cleanup crew. Today only those three sizes are in use, so no one notices — but it's a fragile setup that would break silently if any future screen used a different page size.
    - **Evidence:**
        ```php
        // Reader — accepts any int
        public function index(string $professionalId, int $limit, bool $includeDismissed): array

        // Invalidator — only covers 3 limits
        private function bustIndexCache(string $professionalId): void
        {
            $store = app()->environment('testing') ? Cache::store() : Cache::store('redis');
            foreach ([50, 100, 200] as $limit) {   // hardcoded, potentially incomplete
                foreach ([false, true] as $includeDismissed) {
                    $key = $this->cacheKey($professionalId, $limit, $includeDismissed);
                    $store->forget($key);
                    $store->forget($key.':stale');
                }
            }
        }
        ```

- [ ] **#CCH-15** · P3 — `StripeConnectController` builds three cache keys via ad-hoc `sprintf`/concatenation instead of `CacheKeyGenerator`
    - **Where:** app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php (`transactions`, `balance`, `upcomingPayouts`)
    - **Affects:** When CCH-8 is fixed by adding push-invalidation from Stripe webhooks, the webhook handler will need to reference these same key strings. Ad-hoc construction in the controller and a separate construction in the webhook handler creates a drift risk — a typo in either produces a silent cache miss.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CacheKeyGenerator::stripeTransactions(string $role, string $professionalId, string $filterHash): string`, `CacheKeyGenerator::stripeBalance(string $professionalId): string`, and `CacheKeyGenerator::stripeUpcomingPayouts(string $professionalId): string`.
        - Replace the ad-hoc constructions in the controller with the helpers; use the same helpers in any webhook handler that implements CCH-8.
    - **Technical:** These three keys will need to be referenced from two separate files (controller and webhook handler) once CCH-8 is resolved. Making both call the same `CacheKeyGenerator` helper eliminates the possibility of constructing different strings for the same logical key.
    - **Plain English:** Three dashboard panels write their cache labels by hand. When the webhook handler later needs to clear those labels (CCH-8), it also has to write the label by hand — and both sides must spell it identically. A shared label-maker function guarantees they always match.
    - **Evidence:**
        ```php
        // transactions()
        $cacheKey = sprintf('stripe:txns:%s:%s:%s', $role, $pro->id, hash('xxh64', json_encode($filters) ?: ''));

        // balance()
        $cacheKey = 'stripe:balance:'.$pro->id;

        // upcomingPayouts()
        $cacheKey = 'stripe:upcoming_payouts:'.$pro->id;
        ```

- [ ] **#CCH-16** · P3 — `BookingAnalyticsController` and `StaffBookingController` pass `DateTimeInterface` TTLs to `rememberLocked`, bypassing jitter
    - **Where:** app/Http/Controllers/Api/Professional/Booking/BookingAnalyticsController.php:60–61; app/Http/Controllers/Api/Staff/StaffSite/StaffBookingController.php:92–93
    - **Affects:** Booking analytics dashboard (both professional and staff views). Both controllers share the same `CacheKeyGenerator::bookingAnalytics` cache key, so their entries expire in lockstep — doubling the thundering-herd surface. Note: the booking feature is marked dropped in project notes (2026-05-11); these files should be removed, but they are still deployed and active.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `now()->addMinutes(2)` with `120` and `now()->addMinutes(10)` with `600` (integer seconds) in both controllers so `JitteredTtl` can apply ±20% spread.
        - Alternatively, remove both controllers as part of the booking feature teardown.
    - **Technical:** `CacheLockService::rememberLocked` applies TTL jitter via `JitteredTtl`, but the trait operates on integer TTLs. Passing a `Carbon` instance sidesteps jitter entirely, producing fleet-wide synchronised expiries. Both endpoints share the same key, so expiry is synchronised across both staff and professional views simultaneously.
    - **Plain English:** Every booking-analytics cache entry expires at exactly the same moment — like setting every kitchen timer to go off at 12:00 sharp. When they all ding at once, every worker rushes to rebuild the same cache simultaneously. Using integer seconds instead of clock-based times adds a small random wobble that naturally staggers the resets.
    - **Evidence:**
        ```php
        // BookingAnalyticsController — Carbon instance bypasses JitteredTtl
        $ttl = $metricsContext['use_hourly'] ? now()->addMinutes(2) : now()->addMinutes(10);
        return $this->success($this->cacheLock->rememberLocked($cacheKey, $ttl, function () ...));
        ```
        ```php
        // StaffBookingController — identical pattern, shares same cache key
        $ttl = $metricsContext['use_hourly'] ? now()->addMinutes(2) : now()->addMinutes(10);
        return $this->success($this->cacheLock->rememberLocked($cacheKey, $ttl, function () ...));
        ```

- [ ] **#CCH-17** · P3 — `ShopifySetupTokenService` uses a `DateTimeInterface` TTL and ad-hoc key prefix instead of centralised helpers
    - **Where:** app/Services/Shopify/ShopifySetupTokenService.php:55 (put), :68 (get), :74 (pull)
    - **Affects:** OAuth setup token store — ephemeral tokens that bridge the Shopify OAuth callback and the setup wizard. Operational impact is negligible (single-use, random 32-byte tokens), but the pattern is inconsistent with every other cache write in the codebase.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `now()->addMinutes(self::TTL_MINUTES)` with `self::TTL_MINUTES * 60` (integer seconds) so the write is consistent with the jitter-aware pattern even though this TTL is intentionally precise.
        - Move the key construction into `CacheKeyGenerator::shopifySetupToken(string $token): string` so a future code path that needs to forget a token early has a canonical source to call.
    - **Technical:** `Cache::put(self::CACHE_PREFIX.$token, [...], now()->addMinutes(60))` uses a `DateTimeInterface` TTL. For single-use OAuth tokens with a precise 60-minute hard deadline, jitter is undesirable — tokens should expire at exactly `+60min`, not `+48–72min`. The correct fix is to pass `60 * 60 = 3600` as an integer (precise) and note in a comment that this key intentionally skips jitter. The key prefix deviation is the higher-priority fix.
    - **Plain English:** The OAuth token safe uses a handwritten expiry label instead of the standard label printer. For these one-time-use codes the exact expiry time matters, so the fix is to use the right number directly rather than a clock offset — and to register the label format in the shared catalogue so any code that needs to clear a token early knows where to look.
    - **Evidence:**
        ```php
        Cache::put(self::CACHE_PREFIX.$token, [
            'shop_domain' => $shopDomain,
            'access_token' => encrypt($accessToken),
            // ...
        ], now()->addMinutes(self::TTL_MINUTES));  // DateTimeInterface, bypasses jitter
        ```

- [ ] **#CCH-18** · P3 — `ProcessShopifyShopUpdateJob` throttle guard uses a `DateTimeInterface` TTL and an ad-hoc cache key
    - **Where:** app/Jobs/Shopify/ProcessShopifyShopUpdateJob.php:89–91
    - **Affects:** The once-per-hour throttle that prevents multiple `SyncShopifyBrandDesignJob` dispatches per integration. If many integrations receive `shop/update` webhooks simultaneously (e.g. after a Shopify platform event), all throttle keys expire at the same clock second and all guards fire at once.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `now()->addHour()` with `random_int(3240, 3960)` (54–66 minutes as seconds) to stagger expiry across a ±6-minute band without needing the full `JitteredTtl` helper.
        - Move the key into `CacheKeyGenerator::shopifyBrandDesignSyncThrottle(string $integrationId): string`.
    - **Technical:** `Cache::add($cacheKey, true, now()->addHour())` passes a `Carbon` instance. Laravel forwards `DateTimeInterface` TTLs as absolute expiry timestamps with no jitter. All integrations whose `shop/update` webhooks arrive in the same request window will have their throttle guards expire at exactly the same wall-clock second, allowing a burst of simultaneous `SyncShopifyBrandDesignJob` dispatches on the next webhook wave.
    - **Plain English:** This guard says "don't sync a brand's design more than once an hour." But if every brand's guard expires at exactly the same second, they all fire at once on the next trigger. Adding a small random wobble (±6 minutes) spreads them out so they trickle through instead of stampeding.
    - **Evidence:**
        ```php
        $cacheKey = "shopify:brand_design_sync:{$integration->id}";
        if (Cache::add($cacheKey, true, now()->addHour())) {
            SyncShopifyBrandDesignJob::dispatch((string) $integration->id);
        }
        ```
