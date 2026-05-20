<?php

namespace App\Services\Cache;

// V2: Central cache key naming convention. All cache keys across the application flow through this class.
//
// ONE-SITE-PER-PROFESSIONAL ASSUMPTION: Many keys below are namespaced by professionalId rather than siteId.
// This is intentional and correct for the current data model (each professional has exactly one site).
// If multi-site support is introduced, any key that caches site-scoped data under professionalId will need
// a siteId segment added — otherwise two sites owned by the same professional would share a cache entry.
// Methods carrying this assumption are annotated with "@multi-site: needs site_id".
//
// MULTI-SITE MIGRATION TASK (GS-4): Before launching multi-site, add a siteId segment to every method
// annotated "@multi-site: needs site_id" (siteImagesViewVariants, and any non-site-scoped professional
// lookup that aggregates across sites). Coordinate with a one-time global cache flush at deploy so old
// professionalId-only keys orphan and TTL out naturally.
class CacheKeyGenerator
{
    public static function publicSite(string $subdomain): string
    {
        return 'site:public:'.strtolower($subdomain);
    }

    public static function publicSitePayload(string $subdomain): string
    {
        return 'site:payload:'.strtolower($subdomain);
    }

    public static function professionalByHandle(string $handle): string
    {
        // Handle validation enforces [a-z0-9_-] on every write path
        // (BootstrapRequest / UpdateSiteRequest / ReclaimHandleRequest), so
        // colons cannot appear in $handle and key-namespace collisions are
        // not possible.
        return 'pro:handle:'.strtolower($handle);
    }

    public static function professionalById(string $id): string
    {
        return "pro:id:{$id}";
    }

    public static function professionalByAuthId(string $authUserId): string
    {
        return "pro:auth:{$authUserId}";
    }

    public static function theme(string $themeId): string
    {
        return "theme:{$themeId}";
    }

    public static function siteBlocks(string $siteId, string $group): string
    {
        return "site:{$siteId}:blocks:{$group}";
    }

    public static function professionalServices(string $professionalId): string
    {
        return "pro:{$professionalId}:services:active";
    }

    /**
     * Dashboard /api/services index cache. Distinct from professionalServices
     * because the management view returns active + inactive (so the user can
     * toggle is_active on/off), while professionalServices is the public-site
     * view that filters is_active=true. Same invalidation triggers as the
     * active-only key — both die on any service write through ServiceObserver.
     */
    public static function professionalDashboardServices(string $professionalId): string
    {
        return "pro:{$professionalId}:services:dashboard";
    }

    public static function siteImages(string $siteId): string
    {
        return "site:{$siteId}:images:active";
    }

    /**
     * Filtered gallery-view cache for /api/images. Keyed by site + (pool,
     * media_type) so the dashboard's pool/type filter chips don't poison
     * one another. Polling requests with ?ids[] use siteImagesPolling
     * instead — those have unbounded cardinality.
     *
     * Bustable by invalidateSite() because the (pool, media_type) space is
     * small and enumerable.
     */
    public static function siteImagesView(string $siteId, ?string $pool, string $mediaType): string
    {
        return "site:{$siteId}:images:active:p=".($pool ?? 'all').":t={$mediaType}";
    }

    /**
     * Polling cache for /api/images?ids[]=uuid. The ids hash makes each
     * caller's batch of in-progress uploads its own single-flight bucket,
     * collapsing the 3–5s frontend poll cadence onto a single DB read while
     * still letting the next 5s window pick up `pending → ready` transitions.
     * Not enumerable in invalidateSite (unbounded cardinality); the 5s TTL
     * is the only bust mechanism.
     */
    public static function siteImagesPolling(string $siteId, ?string $pool, string $mediaType, string $idsHash): string
    {
        return "site:{$siteId}:images:active:p=".($pool ?? 'all').":t={$mediaType}:i={$idsHash}";
    }

    /**
     * Pool/media_type tuples enumerated by invalidateSite to bust every
     * filtered-view variant. Keep this aligned with the filter-input space
     * accepted in ProfessionalUploadController::index.
     *
     * @return array<int, array{0: ?string, 1: string}>
     */
    public static function siteImagesViewVariants(): array
    {
        $variants = [];
        foreach ([null, 'gallery', 'content'] as $pool) {
            foreach (['image', 'video', 'all'] as $mediaType) {
                $variants[] = [$pool, $mediaType];
            }
        }

        return $variants;
    }

    public static function customerCount(string $professionalId): string
    {
        return "pro:{$professionalId}:customers:count";
    }

    public static function professionalPayloadById(string $id): string
    {
        return "pro:payload:id:{$id}";
    }

    public static function professionalPayloadByHandle(string $handleLc): string
    {
        return 'pro:payload:handle:'.strtolower($handleLc);
    }

    public static function professionalPayloadByAuthId(string $authUserId): string
    {
        return "pro:payload:auth:{$authUserId}";
    }

    public static function professionalIdByHandle(string $handleLc): string
    {
        return 'pro:map:handle:'.strtolower($handleLc);
    }

    public static function professionalIdByAuthId(string $authUserId): string
    {
        return "pro:map:auth:{$authUserId}";
    }

    /**
     * Hydrated Eloquent model cache for the auth path. Holds the Professional
     * with its `site` + `squareIntegration` relations preloaded so every
     * authenticated request reuses one Redis hit instead of two Postgres
     * round-trips. Keyed by professional id (immutable), so writes that
     * change auth_user_id or handle do not need a key rewrite — only a bust.
     */
    public static function professionalModel(string $id): string
    {
        return "pro:model:{$id}";
    }

    // @multi-site: needs site_id — summary aggregates site traffic, scoped to one site under current model
    public static function analyticsSummary(string $professionalId, string $startDate, string $endDate): string
    {
        // q3: commerce fields now read from commerce.orders instead of commission_movements (Phase 3)
        return "analytics:summary:q3:{$professionalId}:{$startDate}:{$endDate}";
    }

    /**
     * Version token used to bust all analytics summary keys for a professional at once.
     * Incrementing this key makes every date-range summary key for the professional stale
     * without requiring a full key-space scan.
     *
     * @multi-site: needs site_id — if multi-site, version tokens must be per-site
     */
    public static function analyticsSummaryVersion(string $professionalId): string
    {
        return "analytics:summary:ver:{$professionalId}";
    }

    public static function brandFontActive(string $brandProfessionalId): string
    {
        return "brand:{$brandProfessionalId}:font:active";
    }

    // @multi-site: needs site_id if booking widgets are ever per-site
    public static function bookingAnalytics(string $professionalId, string $from, string $to, string $groupBy): string
    {
        return "analytics:booking:{$professionalId}:{$from}:{$to}:{$groupBy}";
    }

    /**
     * Cache key for the per-professional affiliate projections payload.
     * No date-range component — projections are always computed "as of now".
     *
     * Optional $windowDays argument: when null, the key is the adaptive default;
     * when set (one of the allowlist tiers), it appends a `:wN` suffix so explicit
     * overrides don't share cache state with the default. Bump v1 → v2 if the
     * response shape changes.
     */
    public static function affiliateProjections(string $professionalId, ?int $windowDays = null): string
    {
        $base = "analytics:commerce:affiliate:projections:v1:{$professionalId}";

        return $windowDays === null ? $base : "{$base}:w{$windowDays}";
    }

    // @multi-site: needs site_id — commerce traffic is tied to a site storefront
    public static function affiliateCommerceAnalytics(string $professionalId, string $from, string $to): string
    {
        // v3: version token embedded so bumpAnalyticsVersion() busts every date-window variant atomically.
        // v2 was unversioned — bumping the token was a silent no-op for windowed keys.
        $version = \Illuminate\Support\Facades\Cache::get(self::analyticsSummaryVersion($professionalId), 0);

        return "analytics:commerce:affiliate:v3:{$professionalId}:{$version}:{$from}:{$to}";
    }

    // Payout + grace state are current-state snapshots, not window-dependent.
    // Cached per-professional so switching date ranges reuses the same entry.
    public static function affiliatePayoutState(string $professionalId): string
    {
        return "analytics:commerce:affiliate:{$professionalId}:payout-state";
    }

    // @multi-site: needs site_id — commerce traffic is tied to a site storefront
    public static function brandCommerceAnalytics(string $professionalId, string $from, string $to): string
    {
        // v7: timeseries entries gain commission_accrued_cents per bucket so the
        // chart's Revenue ↔ Commissions toggle has both metrics on one x-axis.
        // v6 added page_views per bucket; v5 added earned_cents + avg rates;
        // v4 added the version token; v3 was unversioned.
        $version = \Illuminate\Support\Facades\Cache::get(self::analyticsSummaryVersion($professionalId), 0);

        return "analytics:commerce:brand:v7:{$professionalId}:{$version}:{$from}:{$to}";
    }

    public static function brandActiveCatalog(string $brandProfessionalId): string
    {
        return "brand:{$brandProfessionalId}:catalog:active";
    }

    public static function brandAdminCatalog(string $brandProfessionalId): string
    {
        return "brand:{$brandProfessionalId}:catalog:admin";
    }

    public static function brandCollectionGid(string $brandProfessionalId, string $handle): string
    {
        return "brand:{$brandProfessionalId}:collection_gid:{$handle}";
    }

    // Hydrogen brand-design response cache. Keyed by site_id (not professional)
    // so BrandDesignMediaService can bust with just the site handle. The `v1`
    // segment lets us bust every entry at once by bumping to v2 if the payload
    // shape changes. TTL is intentionally tight (5s) so Hydrogen sees dashboard
    // saves within its staleWhileRevalidate window — invalidation keeps the
    // stale window near zero in practice.
    public static function hydrogenBrandDesign(string $siteId): string
    {
        return "hydrogen:brand-design:v1:{$siteId}";
    }

    // Hydrogen affiliate-page response cache. Keyed by (brand_professional_id,
    // affiliate_handle) — the two pieces of identity HydrogenAffiliateController
    // resolves before assembling the 13-DB-query payload. The slug part is the
    // request input (not affiliate->handle), so aliases populate separate cache
    // entries; invalidation walks aliases to clear all variants. 60s TTL is the
    // safety net; SiteCacheService::forgetHydrogenAffiliate is the fast path.
    public static function hydrogenAffiliate(string $brandProfessionalId, string $affiliateHandle): string
    {
        return 'hydrogen:affiliate:v1:'.$brandProfessionalId.':'.strtolower($affiliateHandle);
    }

    // Hydrogen brand-config response cache. Keyed by shop_domain — the only
    // request input, and 1:1 with brand_professional_id via integration row.
    // Busted on writes to ProfessionalIntegration, BrandStoreSettings, the
    // brand's Site (design tokens), and SiteMedia (pool=brand_gallery).
    public static function hydrogenBrandConfig(string $shopDomain): string
    {
        return 'hydrogen:brand-config:v1:'.strtolower($shopDomain);
    }

    // Hydrogen affiliate-products response cache. Keyed by affiliate_id — the
    // only request input. Busted on AffiliateProductSelection writes and on
    // SiteMedia writes where pool=POOL_PRODUCT (custom product photos).
    public static function hydrogenAffiliateProducts(string $affiliateId): string
    {
        return "hydrogen:affiliate-products:v1:{$affiliateId}";
    }

    public static function brandProductCustomPhotos(string $brandProfessionalId, string $productGid): string
    {
        return "brand:{$brandProfessionalId}:product:{$productGid}:custom_photos";
    }

    /**
     * Cached BrandStoreSettings row for /api/me's dashboard payload. The model
     * itself changes only when a brand edits store settings (rare), so a long
     * TTL is safe — invalidation is observer-driven on any BrandStoreSettings
     * write. Returns the row as an array (or sentinel-null) so the cache is
     * portable across deploys that change Eloquent attribute order.
     */
    public static function brandStoreSettings(string $professionalId): string
    {
        return "pro:{$professionalId}:brand-store-settings";
    }

    /**
     * Cached brand-partner status snapshot for /api/me when an affiliate has a
     * configured brand_partner. Holds the (brand_status, display_name) tuple
     * that the dashboard uses to render the affiliate's "linked brand" banner.
     * Keyed by the brand's professional id (not the affiliate's), so one cache
     * entry serves every affiliate connected to that brand. Busted by
     * BrandProfileObserver on brand_status change and by ProfessionalObserver
     * on display_name change.
     */
    public static function brandPartnerStatus(string $brandProfessionalId): string
    {
        return "pro:{$brandProfessionalId}:brand-partner-status";
    }

    /**
     * Cached "is this brand's Hydrogen storefront serving requests?" probe
     * result. The status check itself is a synchronous outbound HTTP GET
     * with timeouts, so /api/brand/store-settings paid up to 8s of P95 on
     * every read before this cache existed. The status only changes on
     * deploy or domain reconfiguration — both write actions that bust this
     * key. 60s TTL is short enough that brand operators see deploys reflect
     * within a poll interval but long enough to absorb the dashboard's open-
     * close-reopen pattern. Keyed by professional (1:1 with the brand).
     */
    public static function brandStorefrontStatus(string $professionalId): string
    {
        return "brand:{$professionalId}:storefront-status";
    }

    /**
     * Booking-milestone totals snapshot. Caches the (lifetime bookings_count,
     * lifetime total_spent_cents) tuple for the milestone-notification path so
     * a burst of bookings doesn't re-scan analytics.booking_events for each one.
     * Once a pro crosses the highest threshold, the same value is read from cache
     * and the publisher's dedupe key suppresses the redundant notification.
     */
    public static function bookingMilestoneTotals(string $professionalId): string
    {
        return "pro:{$professionalId}:bookings:milestone-totals";
    }

    /**
     * Cached overview payload for the embedded Shopify admin setup wizard.
     * Covers affiliate_count, all-time + 30-day commission/revenue, and
     * recent_sales — all derived from commerce.orders + brand_affiliate_rollup.
     * Busted by AnalyticsCacheService::invalidateAnalytics() on any commerce write.
     */
    public static function embeddedSetupOverview(string $professionalId): string
    {
        return "embedded:setup:overview:v1:{$professionalId}";
    }

    /**
     * Per-product analytics rollup shown in the embedded Shopify admin
     * product-block extension (30-day units, revenue, commission, variant
     * breakdown, recent sales). Keyed by (brand, Shopify product ID) so
     * AnalyticsCacheService::invalidateProductAnalytics() can target just the
     * products on a freshly-paid or refunded order.
     */
    public static function embeddedProductAnalytics(string $professionalId, string $productId): string
    {
        return "embedded:product-analytics:{$professionalId}:{$productId}";
    }

    /**
     * Per-product sidest.active flag (1-product scope). Resolves the active
     * boolean for a single product without re-fetching the whole brand catalog,
     * avoiding a thundering-herd of paginated Admin GraphQL calls when many
     * concurrent requests miss at once. Master Pattern 17 / DB-F#SCALE-6.
     */
    public static function embeddedProductActive(string $professionalId, string $productId): string
    {
        return "embedded:product-active:{$professionalId}:{$productId}";
    }

    /**
     * Per-product full settings payload shown in the embedded Shopify admin
     * product-settings extension (metafields + variants + collection membership
     * + global settings). Mirrors embeddedProductAnalytics in shape; busted by
     * EmbeddedProductSettingsController on every successful patch so the next
     * mount sees the new value within a request, not a 5-minute TTL.
     */
    public static function embeddedProductSettings(string $professionalId, string $productId): string
    {
        return "embedded:product-settings:{$professionalId}:{$productId}";
    }

    /**
     * Staff-facing analytics summary, keyed by professional + date range.
     * Append ":v{analyticsSummaryVersion}" at call-site so busting the
     * professional's own analytics version invalidates staff views too.
     */
    public static function staffAnalyticsSummary(string $professionalId, string $from, string $to): string
    {
        return "staff:analytics:summary:{$professionalId}:{$from}:{$to}";
    }
}
