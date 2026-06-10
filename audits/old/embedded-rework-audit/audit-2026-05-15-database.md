Now I have everything I need. `BrandCatalogService::resolveCollectionGid()` already exists and is cached via `rememberLocked` — the fix for SCALE-6 is to inject the service rather than duplicating the resolution inline. All six findings have verbatim evidence confirmed. Let me produce the final audit.

`★ Insight ─────────────────────────────────────`
The most interesting pattern here: the codebase has a cached `resolveCollectionGid()` in `BrandCatalogService` that `EmbeddedProductSettingsController` never uses — it re-implements the same resolution inline without caching. This is a common drift pattern when a controller is authored separately from the service layer. The solution to SCALE-6 is injection, not data-model surgery.

Also notable: `$isNoOpRefresh` is computed at line 723 of `EmbeddedSetupController` but the token validation HTTP call at line 734 is not gated by it — the no-op path skips cache/status sync (bottom of method) but never skips the vendor round-trip. This is a classic "optimized the cheap part, left the expensive part" mistake.
`─────────────────────────────────────────────────`

---

# Database & Queue Scaling Audit — 2026-05-15

**Branch:** development
**Lens:** Database & queue scaling: N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Internal/EmbeddedSetupController.php
- app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php
- app/Http/Controllers/Api/Internal/EmbeddedProductAnalyticsController.php
- app/Http/Controllers/Api/Internal/EmbeddedOrderAnalyticsController.php
- app/Http/Controllers/Api/Internal/EmbeddedConnectController.php
- app/Http/Middleware/Auth/VerifyShopifySessionToken.php
- app/Http/Controllers/Api/Webhooks/ShopifyAppUninstalledWebhookController.php
- app/Services/Shopify/ShopifyShopResolver.php
- app/Services/Store/BrandCatalogService.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Jobs/Shopify/*.php
- config/horizon.php
- routes/console.php
- bootstrap/app.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **SCALE-1** · P1 — Shopify token validation fires synchronously on every embedded admin page load, including no-op refreshes
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedSetupController.php:provisionShopifyIntegration
    - **Affects:** Every brand staff member loading any page of the embedded Shopify admin app. The `provisionShopifyIntegration` endpoint is called on every page load for token refresh. The `validateShopifyAccessToken()` synchronous HTTP call (10s timeout) executes unconditionally, even when `$isNoOpRefresh` is true (same token, complete setup). At 200 brands × multiple staff × frequent page loads, this blocks PHP-FPM workers on vendor I/O.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Short-circuit `validateShopifyAccessToken` on the no-op path: if `$isNoOpRefresh === true`, skip validation entirely and return `['provisioned' => true]` immediately after the `$isNoOpRefresh` check — the token was already validated when it was first stored.
        - Alternatively, wrap the validation result in `Cache::remember("shopify:token-valid:{$professionalId}:".sha1($data['access_token']), 300, fn() => $this->validateShopifyAccessToken(...))` so repeated loads within 5 minutes don't re-hit the Shopify REST endpoint.
        - The REST call also consumes Shopify's bucket (2 req/s per shop); caching prevents unnecessary quota spend for shops with active staff.
    - **Technical:** `$isNoOpRefresh` is computed at line 723 but the `validateShopifyAccessToken()` call at line 734 is not guarded by it. The no-op path's optimisation (skipping `invalidateProfessional` + `BrandStatusService::sync`) is applied at line 808 — after the expensive vendor call. The code comment ("The embedded app calls this on every admin page load") documents the known-high-frequency scenario. `validateShopifyAccessToken` issues `Http::withHeaders([...])->timeout(10)->get(...)` synchronously on the request thread; a 200ms average × 10,000 calls/day (200 brands × 5 staff × 10 loads/day) = 33 minutes of cumulative worker-blocking per day, and a single Shopify degraded-response window (5s average instead of 200ms) multiplies this to 8+ hours. The no-op guard is the safest fix because it avoids re-validating a token that passed validation on first store.
    - **Plain English:** Every time a brand's team member opens the Partna tab inside Shopify, the server calls Shopify's API to re-verify the access key — even if the exact same key was checked 5 seconds ago by the same person. It's like your bank calling the card network to verify your credit card every time you walk past the checkout, including during the same shopping trip. When Shopify's servers are slow, every staff member across all 200 brands is stuck waiting. The fix is to skip the call entirely when nothing has changed, or to remember "this key is valid" for a few minutes so repeated checks aren't sent.
    - **Evidence:**
        ```php
        // $isNoOpRefresh computed here — same token, setup complete, no jobs needed
        $isNoOpRefresh = ! $needsJobDispatch
            && $existing !== null
            && $existing->access_token === $data['access_token'];

        // validateShopifyAccessToken fires unconditionally — NOT gated by $isNoOpRefresh
        $validation = $this->validateShopifyAccessToken($shopDomain, $data['access_token']);
        if (! $validation['valid']) {
            Log::warning('Shopify provision-integration: token rejected by Shopify; refusing to overwrite existing token.', [
                'professional_id' => $professionalId,
                'shop_domain' => $shopDomain,
                'reason' => $validation['reason'],
            ]);
            return response()->json([
                'message' => 'Shopify rejected the new access token...',
                'reason' => 'shopify_token_rejected',
            ], 422);
        }
        ```
        ```php
        // Inside validateShopifyAccessToken — synchronous HTTP, 10s timeout:
        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $accessToken,
            'Accept' => 'application/json',
        ])->timeout(10)->get($url);
        ```

---

## P2 — Should fix

- [ ] **SCALE-2** · P2 — Product settings `show()` makes three uncached synchronous Shopify API calls per view
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php:show
    - **Affects:** Brand staff viewing per-product Partna settings from the Shopify admin UI extension. Each view triggers one Admin API GraphQL call (metafields + variants, 15s timeout) and two Storefront API GraphQL calls (collection membership checks, 10s timeout each), all synchronous, with no caching. In contrast, `EmbeddedProductAnalyticsController::show()` caches its product rollup for 300s and `EmbeddedSetupController::brandProfile()` caches the storefront status probe.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the entire `show()` response body in `$this->cacheLock->rememberLocked(CacheKeyGenerator::embeddedProductSettings($professionalId, $productId), 300, fn() => [...])` using the existing `CacheLockService` pattern.
        - Bust the cache key from `update()` at the end of each successful field save so the next view reflects the change immediately.
        - Inject `CacheLockService` and `CacheKeyGenerator` into the controller (they're already available as services).
    - **Technical:** The two `isInCollection` calls each issue `Http::timeout(10)->post(...)` to the Storefront API. At 200 brands × multiple staff actively editing product settings, concurrent `show()` calls drain Storefront API token budget and hold PHP-FPM workers for up to 35s per request (15 + 10 + 10) in worst-case Shopify latency. The `EmbeddedProductAnalyticsController` covers an identical use-case (product-scoped, brand-scoped, staff-facing) and already uses `rememberLocked(300)` — the missing caching here is an inconsistency in the same controller family. The `BrandCatalogService::bustCatalogCaches()` pattern already demonstrates the invalidation discipline needed on writes.
    - **Plain English:** When a staff member clicks into a product's settings panel, the server makes three separate phone calls to Shopify — one to get the product's metadata, and two more to check whether the product is in each collection. Every single time. The analytics panel for the same product remembers the answers for 5 minutes. The settings panel should do the same: look up the answers once, remember them briefly, and only make fresh calls when something actually changes.
    - **Evidence:**
        ```php
        // In show() — three synchronous vendor calls per request, no caching:
        $result = $this->fetchProductMetafields($integration, $productId);
        $metafields = $result['metafields'];
        $variants = $result['variants'];

        // Check collection membership
        $inFavourites = $this->isInCollection($metadata, 'favourites_collection_handle', $productGid, $integration);
        $inDefault = $this->isInCollection($metadata, 'default_collection_handle', $productGid, $integration);
        ```
        ```php
        // fetchProductMetafields — Admin API GraphQL, 15s timeout:
        $response = \Illuminate\Support\Facades\Http::timeout(15)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $adminToken])
            ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", [
                'query' => $query,
                'variables' => ['id' => "gid://shopify/Product/{$productId}"],
            ]);
        ```
        ```php
        // isInCollection — Storefront API GraphQL, 10s timeout (called twice):
        $response = \Illuminate\Support\Facades\Http::timeout(10)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Storefront-Access-Token' => $storefrontToken])
            ->post("https://{$shopDomain}/api/{$apiVersion}/graphql.json", [
                'query' => $query,
                'variables' => [
                    'handle' => $collectionHandle,
                    'productId' => $productGid,
                ],
            ]);
        ```

- [ ] **SCALE-3** · P2 — Variant enabled-state save issues N sequential synchronous Shopify mutations on the request thread
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php:saveVariantEnabledStates
    - **Affects:** Brand staff toggling which product variants are available for affiliates. A product with N changed variants blocks the PHP-FPM worker for N+1 sequential Admin API calls (1 `fetchVariants` + up to N `productVariantUpdate` mutations). A product with 30 variants where half are toggled in one save = 16 sequential requests, worst-case 16 × 15s = 240s of blocking I/O.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the per-variant `saveVariantMetafield` loop with a single `productUpdate` mutation that sets metafields on all changed variants in one payload. Shopify's `productUpdate` mutation accepts `variants` with per-variant `metafields`, combining the full operation into one round-trip.
        - Alternatively, dispatch a queued job (`SaveVariantEnabledStatesJob`) that receives the `$integration->id`, `$productGid`, and `$disabledGids` array, then performs the fetch + loop asynchronously. The `update()` endpoint acknowledges immediately and the UI can poll the `show()` endpoint (cached, so cheap) to observe the settled state.
        - The queued-job approach is preferred if Shopify Admin API throttling under concurrent brand saves is a concern.
    - **Technical:** `saveVariantEnabledStates` first calls `fetchVariants` (one GraphQL query, 15s timeout) to read current variant state, then loops issuing `saveVariantMetafield` for each variant whose state changed. Each `saveVariantMetafield` makes a `productVariantUpdate` mutation synchronously. At the scale target, multiple staff across 200 brands editing variant states concurrently will exhaust the PHP-FPM process pool. The batched `productUpdate` approach aligns with Shopify's recommendation for bulk metafield writes and reduces Admin API point consumption from N mutations to 1.
    - **Plain English:** When a staff member toggles which product variants affiliates can promote, the server makes one phone call to Shopify per variant that changed — one at a time, waiting for each call to finish before starting the next. If 15 variants changed, that's 15 sequential calls plus one to read the current state first. The staff member sits waiting while all 16 calls complete. The fix is to bundle all the changes into a single call to Shopify, the same way you'd send one email with a list of changes rather than 16 separate emails.
    - **Evidence:**
        ```php
        // saveVariantEnabledStates — one fetchVariants call + N sequential mutations:
        private function saveVariantEnabledStates(ProfessionalIntegration $integration, string $productGid, array $disabledGids): void
        {
            $variants = $this->fetchVariants($integration, $this->extractId($productGid));

            foreach ($variants as $variant) {
                $shouldEnable = ! in_array($variant['gid'], $disabledGids, true);
                if ($variant['enabled'] !== $shouldEnable) {
                    $this->saveVariantMetafield($integration, $variant['gid'], $shouldEnable);
                }
            }
        }
        ```
        ```php
        // saveVariantMetafield — synchronous productVariantUpdate mutation, 15s timeout:
        \Illuminate\Support\Facades\Http::timeout(15)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $adminToken])
            ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", [
                'query' => $mutation,
                'variables' => ['input' => [
                    'id' => $variantGid,
                    'metafields' => [[
                        'namespace' => 'partna',
                        'key' => 'enabled',
                        'value' => $values,
                        'type' => 'boolean',
                    ]],
                ]],
            ]);
        ```

- [ ] **SCALE-4** · P2 — Product analytics builder hydrates all matching order_items into PHP memory instead of pushing aggregation into PostgreSQL
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductAnalyticsController.php:build
    - **Affects:** The embedded product analytics block. On every 5-minute cache miss per product, `build()` loads all `commerce.order_items` rows for the last 30 days for that product into a PHP `Collection`, then accumulates `totalUnits`, `totalRevenueCents`, `totalCommissionCents`, and a per-variant map in a PHP `foreach` loop. All aggregation that PostgreSQL could compute in a single query with `SUM` + `GROUP BY` is done in application memory.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the aggregate `foreach` block with a single `DB::table(...)->selectRaw('SUM(quantity) as total_units, SUM(line_total_cents) as total_revenue_cents, SUM(commission_cents) as total_commission_cents, SUM(commission_rate * commission_cents) as weighted_rate_sum, SUM(commission_cents) as rate_weight')->first()` query.
        - Replace the per-variant accumulation with a second `GROUP BY shopify_variant_id` query returning variant-level sums.
        - Keep a third `->orderByDesc('oi.occurred_at')->limit(5)->get([...])` for the `recent_sales` rows — only this query needs to hydrate rows.
        - Net result: three targeted queries returning small result sets replace one unbounded `->get()`.
    - **Technical:** At the scale target of 1M orders/year with average 2 line items per order = 2M `order_items`/year. A product receiving 5% of a brand's daily order volume accumulates ~275 rows/day over 30 days = 8,250 rows per cache miss. The PHP `Collection` object holding all these rows consumes ~2–4 MB per cache miss. The `supervisor-analytics` worker has `memory=512` in horizon.php — multiple simultaneous cache misses across products (e.g. post-deploy cold start or a cache flush event) can exhaust the worker's heap. PostgreSQL aggregates the same result in microseconds with zero PHP-heap impact. The 300s cache TTL masks this during normal operation but doesn't protect against coordinated misses.
    - **Plain English:** To answer "how many units of this product sold in the last 30 days," the server asks the database for every single sales line and adds them up in PHP code — like getting a printout of every individual receipt to tally by hand. The database already knows how to add up numbers efficiently; the server should just ask "give me the total" and receive one number back. Right now, if 200 brands all have their cache expire at the same moment (after a server restart, for example), the server pulls tens of thousands of rows into memory simultaneously.
    - **Evidence:**
        ```php
        // Unbounded ->get() loads all matching order_items rows into PHP memory:
        $rows = DB::table('commerce.order_items as oi')
            ->join('commerce.orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('core.professionals as p', 'p.id', '=', 'oi.affiliate_professional_id')
            ->where('oi.brand_professional_id', $professionalId)
            ->where('oi.shopify_product_id', $productId)
            ->where('oi.occurred_at', '>=', $thirtyDaysAgo)
            ->whereNotIn('o.status', Order::EXCLUDED_FROM_AGGREGATES)
            ->orderByDesc('oi.occurred_at')
            ->get([
                'oi.shopify_variant_id',
                'oi.title',
                'oi.quantity',
                'oi.line_total_cents',
                'oi.commission_cents',
                'oi.commission_rate',
                'oi.currency_code',
                'oi.occurred_at',
                'oi.affiliate_professional_id',
                'p.display_name as affiliate_name',
            ]);
        ```
        ```php
        // Aggregates computed in PHP instead of SQL:
        foreach ($rows as $row) {
            $qty = (int) $row->quantity;
            $revenueCents = (int) $row->line_total_cents;
            $commissionCents = (int) $row->commission_cents;

            $totalUnits += $qty;
            $totalRevenueCents += $revenueCents;
            $totalCommissionCents += $commissionCents;
            $weightedRateSum += ((float) $row->commission_rate) * $commissionCents;
            $rateWeight += $commissionCents;
            // ... per-variant accumulation into $variants map
        }
        ```

---

## P3 — Nice to have

- [ ] **SCALE-5** · P3 — Single-field metafield saves make two sequential Shopify API calls (find-then-mutate) when one would suffice
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php:saveMetafield
    - **Affects:** Brand staff changing a single product setting (active toggle, commission override, etc.). Each field change issues two synchronous Admin API calls: a `findMetafield` query followed by either a `metafieldUpdate` or `productUpdate` mutation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `findMetafield` query entirely. The `productUpdate` mutation with a `metafields` array already handles the upsert case (it's the current "create" path in `saveMetafield`). Use this mutation unconditionally for both create and update — Shopify's `productUpdate` with `metafields` upserts by `(namespace, key)` without requiring the metafield ID.
        - This reduces every field save from 2 API calls to 1 and eliminates the branching `if ($metafieldId)` logic.
    - **Technical:** `saveMetafield` issues a `findMetafield` GraphQL query (`product(id:).metafield(namespace:, key:).id`) then branches: if an ID is found, it calls `metafieldUpdate(input: {id, value, type})`; if not, it calls `productUpdate(input: {id, metafields: [...]})`. The "create" branch's `productUpdate` with `metafields` array is an upsert — it creates if absent, updates if present, without requiring the existing ID. Using it unconditionally collapses two round-trips into one. At 200 brands × multiple staff × frequent per-field saves, the lookup call is pure overhead.
    - **Plain English:** Every time a staff member flips a toggle or changes a number in the product settings, the server first asks Shopify "what's the ID of this setting?" and then makes a second call to actually change the value. But there's already a Shopify API call that handles both "create if missing" and "update if exists" in one shot — the code already uses it for new settings. Using it for existing settings too cuts every save from two calls to one.
    - **Evidence:**
        ```php
        // First call: find the existing metafield ID
        $response = \Illuminate\Support\Facades\Http::timeout(15)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $adminToken])
            ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", [
                'query' => $metafieldQuery,
                'variables' => [
                    'ownerId' => $ownerId,
                    'namespace' => 'partna',
                    'key' => $key,
                ],
            ]);
        $data = $response->json();
        $metafieldId = Arr::get($data, 'data.product.metafield.id');

        // Second call: mutate by ID if found, else productUpdate upsert
        if ($metafieldId) {
            $result = \Illuminate\Support\Facades\Http::timeout(15)
                ->acceptJson()
                ->withHeaders(['X-Shopify-Access-Token' => $adminToken])
                ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", [
                    'query' => $mutation,
                    'variables' => $variables,
                ]);
        }
        ```

- [ ] **SCALE-6** · P3 — Collection toggle resolves collection GID inline (uncached) when `BrandCatalogService::resolveCollectionGid()` already exists and is cached
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php:toggleCollection
    - **Affects:** Brand staff adding or removing a product from the Favourites or Default collection. Each toggle issues two sequential Admin API calls: a `collection(handle:)` query to resolve the GID, then a `collectionAddProducts` or `collectionRemoveProducts` mutation. The collection GID is stable for the lifetime of the collection.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inject `BrandCatalogService` into `EmbeddedProductSettingsController`. Replace the inline `collection(handle:)` GraphQL resolution with `$this->catalog->resolveCollectionGid($integration, $collectionHandle)`, which wraps the same lookup in `rememberLocked` with a configurable TTL (`partna.cache.ttls.collection_gid`).
        - This eliminates the resolution call on every toggle for the same collection, reducing each toggle from 2 API calls to 1. `BrandCatalogService::bustCatalogCaches()` already invalidates `CacheKeyGenerator::brandCollectionGid(...)` on relevant writes, so cache consistency is already maintained.
    - **Technical:** `toggleCollection` builds and fires a raw `collection(handle:) { id }` GraphQL query on every call, without any caching, then uses the returned ID in the mutation. `BrandCatalogService::resolveCollectionGid()` (line 877) wraps the identical resolution in `$this->cacheLock->rememberLocked($cacheKey, config('partna.cache.ttls.collection_gid'), fn() => ...)`. The resolution result is already cached by `BrandCatalogService` for use by other catalog operations. `EmbeddedProductSettingsController` not injecting `BrandCatalogService` is the only reason it re-implements the lookup inline.
    - **Plain English:** Every time a staff member adds or removes a product from a collection, the server first asks Shopify "what's the numeric ID for the Favourites collection?" and then makes the actual change. That collection ID was created during setup and never changes. Another part of the codebase already has a system that looks this up once and remembers the answer — the settings panel just doesn't use it, so it asks Shopify the same question every time.
    - **Evidence:**
        ```php
        // toggleCollection — inline resolution query, uncached, 15s timeout:
        $response = \Illuminate\Support\Facades\Http::timeout(15)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $adminToken])
            ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", [
                'query' => $query,
                'variables' => ['handle' => $collectionHandle],
            ]);
        $data = $response->json();
        $collectionId = Arr::get($data, 'data.collection.id');

        // Then second call — add or remove products:
        $result = \Illuminate\Support\Facades\Http::timeout(15)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $adminToken])
            ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", [
                'query' => $mutation,
                'variables' => [
                    'id' => $collectionId,
                    'productIds' => [$productGid],
                ],
            ]);
        ```
        ```php
        // BrandCatalogService::resolveCollectionGid() already exists and is cached:
        return $this->cacheLock->rememberLocked($cacheKey, (int) config('partna.cache.ttls.collection_gid'), function () use ($integration, $handle) {
            $resolved = $this->resolveCredentials($integration);
            $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::COLLECTIONS_QUERY, [
                'query' => "handle:{$handle}",
                'first' => 1,
            ]);
            return Arr::get($response->json(), 'data.collections.edges.0.node.id');
        });
        ```
