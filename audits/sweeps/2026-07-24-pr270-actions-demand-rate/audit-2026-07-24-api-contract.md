# API Contract & Resource Leakage Audit — 2026-07-24

**Branch:** HEAD
**Lens:** API Contract & Resource Leakage: raw model fields bleeding through, over-fetching, inconsistent pagination
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- `app/Http/Controllers/Api/User/SiteManagement/UserSiteActionsController.php`
- `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php`
- `app/Http/Controllers/Api/PublicSite/AnalyticsController.php`
- `app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php`
- `app/Services/Analytics/AnalyticsEvent.php`
- `app/Services/Analytics/RankedActionsComputer.php`
- `app/Services/Analytics/Writers/PostgresEventWriter.php`
- `app/Services/PublicSite/Actions/ActionVocabulary.php`
- `app/Services/PublicSite/IndividualProfilePayloadBuilder.php`
- `app/Services/PublicSite/SiteActionsService.php`
- `app/Http/Resources/PublicSite/IndividualProfileResource.php` (pulled in for verification)
- `app/Models/Core/Site/ShopBrand.php`, `app/Models/Core/Site/ShopProduct.php` (pulled in for verification)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#API-1** · P2 — Shop product objects reach the public wire with no per-field allowlist
    - **Where:** `app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php:159, 207-217`; source data from `app/Models/Core/Site/ShopBrand.php:86-125`
    - **Affects:** Unauthenticated sitepage visitors hitting `GET /api/public/profiles/{handle}/platforms` — every key present on a `ShopBrand`'s `products` array is emitted verbatim, with no enforcement layer.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `SHOP_PRODUCT_ALLOWLIST` constant naming the product-level fields the sitepage actually renders (`productId`, `handle`, `title`, `image`, `images`, `price`, `currency`, `url`, `available`, `variants`, `popularityRank`).
        - Inside `filterPayload()`'s shop branch, map each brand's `products` array through `array_intersect_key` against that allowlist before it's assigned into `$brand['products']`.
        - Every other platform in `ALLOWLIST` (lines 78-147) already enforces this per-key contract at the Resource boundary — this closes the one nested collection that doesn't.
    - **Technical:** `SHOP_BRAND_ALLOWLIST` allowlists `'products'` as a single top-level key (line 159); `filterPayload()`'s shop branch (lines 207-217) runs `array_intersect_key($b->toBrandArray($productRanks), array_flip(self::SHOP_BRAND_ALLOWLIST))`, which keeps or drops `products` as a whole — every field *inside* each product object rides through untouched. Today this is low-risk in practice: `ShopProduct::data` is populated exclusively from public storefront scrapes (`ShopifyScraper::parseProducts()`, `WooCommerceScraper`, `GenericShopScraper::readProductPage()` via `ShopProductSeeder`), so the fields present (`productId`, `title`, `handle`, `vendor`, `description`, `image`, `images`, `price`, `currency`, `variantId`, `available`, `url`, `createdAt`, `variants`) are already public catalog data with nothing resembling cost price, SKU-level margin, or supplier metadata. The finding is real as a structural gap, not a live leak: every sibling platform in this same file enforces field-level filtering at the Resource boundary; the shop branch is the only nested collection that doesn't, so a future scraper change (e.g. capturing a Shopify Admin-API-sourced `costPerItem` for internal margin tooling) would reach the public, CDN-cached wire with zero additional review gate. Closing it now costs one constant + one `array_intersect_key` call and brings the shop path to parity with the rest of the class's own established pattern.
    - **Plain English:** Think of the shop section of a professional's page as a printed product flyer. Every other type of content on this page (Instagram posts, YouTube videos, event listings) already goes through a checklist before printing — only the approved fields make it onto the flyer. The shop products skip that checklist entirely; today what's stored is all public storefront info anyway, so nothing sensitive is showing right now. But if someone later starts storing more detailed internal data alongside each product (say, for the dashboard), it would print straight onto the public flyer with nobody having decided that was OK. Adding the same checklist here that every other content type already has closes that gap before it can happen.
    - **Evidence:**
        ```php
        private const SHOP_BRAND_ALLOWLIST = ['id', 'provider', 'url', 'name', 'currency', 'favicon', 'logo', 'discountCode', 'linkMode', 'referralQuery', 'products'];
        ```
        ```php
        return $this->shopBrands
            ->mapWithKeys(function ($b) use ($linkMode, $productRanks) {
                $brand = array_intersect_key(
                    $b->toBrandArray($productRanks), array_flip(self::SHOP_BRAND_ALLOWLIST));
                if ($linkMode !== null) {
                    $brand['linkMode'] = $linkMode;
                }

                return [$b->brand_id => $brand];
            })
            ->all();
        ```

## P3 — Nice to have

- [ ] **#API-2** · P3 — Unconditional eager load of `shopBrands.products` on every public platforms request
    - **Where:** `app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php:66-88`
    - **Affects:** All visitors to `GET /api/public/profiles/{handle}/platforms` — two extra always-run queries (`shop_brands` + `shop_products`) on every request, even for the common case of a profile with no shop connection.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Drop `->with(['shopBrands.products'])` from the base query.
        - After `->groupBy('platform')`, lazy-eager-load only when a shop connection is actually present: `if ($connections->has('shop')) { $connections->get('shop')->load(['shopBrands.products']); }`.
    - **Technical:** `->with(['shopBrands.products'])` (line 84) is attached to the base query builder, so it runs unconditionally — Eloquent issues one `shop_brands` query and one `shop_products` query keyed by every loaded connection's id, regardless of whether any row is actually platform `shop`. This is not a per-row N+1 (eager loading batches into a single `WHERE connection_id IN (...)`), so DeepSeek's framing of "a redundant database query for every non-shop connection" overstates the cost — it's exactly two extra round-trips per request, not one per row. Still, for the common profile (social/media links only, no shop) those two queries are pure waste. DeepSeek's proposed fix (`when($connections->has('shop'), ...)` inside the query chain) can't work as written — `$connections` doesn't exist until after `->get()->groupBy()` runs, so the condition isn't available at the point the eager load is attached. The correct fix defers the eager load to after grouping and scopes it to the `shop` sub-collection only, which Eloquent's `groupBy()` preserves as an `Illuminate\Database\Eloquent\Collection` (so `->load()` remains available).
    - **Plain English:** For every visitor to a professional's platforms page, the code always asks the database an extra two questions about that person's online shop — even when they don't have one, which is most people. The database answers quickly since it's finding nothing, but it's still two unnecessary trips for every page view. The fix only asks those questions when there's actually a shop to ask about.
    - **Evidence:**
        ```php
        $connections = IntegrationConnection::query()
            ->where('user_id', $userId)
            ->active()
            ->whereNotIn('platform', [Platform::Booking->value, Platform::Reservations->value])
            ->orderBy('platform')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->with(['shopBrands.products'])
            ->get(['id', 'platform', 'resource_id', 'payload', 'display_settings', 'last_refreshed_at'])
            ->groupBy('platform');
        ```

- [ ] **#API-3** · P3 — `SiteActionsService::pool()` fetches the full `payload` JSONB column when only a handful of scalar keys are read
    - **Where:** `app/Services/PublicSite/SiteActionsService.php:102-110, 267-271`
    - **Affects:** Every public profile build (`GET /api/public/profiles/{handle}`, cache-miss path) — the entire stored `payload` blob for every one of the user's active integration connections is pulled into PHP memory just to read `url`/`link`/`username`/`handle`/`name`.
    - **Effort:** S (~1h)
    - **What to do:**
        - Replace the `payload` column in the `get([...])` call with targeted Postgres `->>` extractions for the handful of keys actually consumed (`url`, `link`, `username`, `handle`, `name`), via `selectRaw`.
        - Keep `connectionPayload()`'s return shape (`array<string, mixed>`) intact so `platformConnectionUrl()` and the online-ordering/custom branches don't need to change their call sites.
    - **Technical:** `pool()` (lines 102-110) selects `['id', 'platform', 'resource_id', 'payload', 'created_at']` for every active `IntegrationConnection` belonging to the profile, and `connectionPayload()` (lines 267-271) hands back the entire decoded array. Downstream, only `url`/`link` (generic platforms, custom, online-ordering), `username` (Instagram), and `handle` (YouTube) are ever read — the rest of each platform's stored payload (e.g. Instagram's `images`/`videoUrl` grid, an events row's full `venue`/`location` object, Google Business's `reviews`/`hours`) is fetched and discarded. Note: the `IntegrationConnection.payload` docblock (`app/Models/Core/Site/IntegrationConnection.php:25`) documents this column as "User-curated selection + last-fetched upstream snapshot" — there is no OAuth token/credential storage on this model (confirmed via repo-wide grep for `access_token`/`refresh_token`/`client_secret`, none of which touch `IntegrationConnection`), so this is a pure over-fetch of non-sensitive data, not a secret-exposure risk. This method runs behind the profile's cache TTL, so the cost is bounded to cache-miss requests, but it's still needless I/O and memory pressure on the platform's hottest read path.
    - **Plain English:** When building a professional's public page, the code pulls the *entire* stored record for every connected platform (Instagram, YouTube, Spotify, etc.) — photo grids, full event details, everything — just to read one or two small fields like a web address or username from each. It's like requesting someone's entire filing cabinet to read the label on one folder. Nothing sensitive is in that cabinet, so this isn't a privacy problem, just wasted effort on a page that gets built very often.
    - **Evidence:**
        ```php
        foreach (
            IntegrationConnection::query()
                ->where('user_id', $pro->id)
                ->where('is_active', true)
                ->get(['id', 'platform', 'resource_id', 'payload', 'created_at']) as $conn
        ) {
            $connectionsByPlatform[strtolower((string) $conn->platform)][] = $conn;
        }
        ```
        ```php
        private function connectionPayload(IntegrationConnection $conn): array
        {
            return is_array($conn->payload) ? $conn->payload : [];
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Public platform-integrations payload hardening:** #API-1, #API-2, #API-3
    - **Why grouped:** All three touch the same public, unauthenticated platform-connections read path (`PublicIntegrationController` → `PublicIntegrationConnectionResource`/`ShopBrand` for the platforms endpoint, `SiteActionsService` for the profile endpoint) and share a single theme — tightening what leaves the database and what reaches the public wire for integration connections.
    - **Model:** follow the file's Execution policy (Plan: Opus · Implement: Sonnet · Review: Sonnet).

## Standalone — do NOT bundle

None.
