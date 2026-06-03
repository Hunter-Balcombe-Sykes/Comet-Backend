<?php

namespace App\Services\Cache;

// V2: Central cache key naming convention. All cache keys across the application flow through this class.
//
// ONE-SITE-PER-PROFESSIONAL ASSUMPTION: Many keys below are namespaced by userId rather than siteId.
// This is intentional and correct for the current data model (each professional has exactly one site).
// If multi-site support is introduced, any key that caches site-scoped data under userId will need
// a siteId segment added — otherwise two sites owned by the same professional would share a cache entry.
// Methods carrying this assumption are annotated with "@multi-site: needs site_id".
//
// MULTI-SITE MIGRATION TASK (GS-4): Before launching multi-site, add a siteId segment to every method
// annotated "@multi-site: needs site_id" (siteImagesViewVariants, and any non-site-scoped professional
// lookup that aggregates across sites). Coordinate with a one-time global cache flush at deploy so old
// userId-only keys orphan and TTL out naturally.
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

    public static function professionalServices(string $userId): string
    {
        return "pro:{$userId}:services:active";
    }

    /**
     * Dashboard /api/services index cache. Distinct from professionalServices
     * because the management view returns active + inactive (so the user can
     * toggle is_active on/off), while professionalServices is the public-site
     * view that filters is_active=true. Same invalidation triggers as the
     * active-only key — both die on any service write through ServiceObserver.
     */
    public static function professionalDashboardServices(string $userId): string
    {
        return "pro:{$userId}:services:dashboard";
    }

    public static function siteImages(string $siteId): string
    {
        return "site:{$siteId}:images:active";
    }

    /**
     * Per-site cached email-branding bundle (logo, palette, reply-to).
     * Busted via SiteCacheService::invalidateSite().
     */
    public static function emailBrand(string $siteId): string
    {
        return "site:{$siteId}:email_brand";
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
     * accepted in UserUploadController::index.
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

    public static function customerCount(string $userId): string
    {
        return "pro:{$userId}:customers:count";
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

    public static function userIdByHandle(string $handleLc): string
    {
        return 'pro:map:handle:'.strtolower($handleLc);
    }

    public static function userIdByAuthId(string $authUserId): string
    {
        return "pro:map:auth:{$authUserId}";
    }

    /**
     * Hydrated Eloquent model cache for the auth path. Holds the Professional
     * with its `site` relation preloaded so every authenticated request reuses
     * one Redis hit instead of two Postgres round-trips. Keyed by professional
     * id (immutable), so writes that change auth_user_id or handle do not need
     * a key rewrite — only a bust.
     */
    public static function professionalModel(string $id): string
    {
        return "pro:model:{$id}";
    }

    // @multi-site: needs site_id — summary aggregates site traffic, scoped to one site under current model
    public static function analyticsSummary(string $userId, string $startDate, string $endDate): string
    {
        return "analytics:summary:q3:{$userId}:{$startDate}:{$endDate}";
    }

    /**
     * Version token used to bust all analytics summary keys for a professional at once.
     *
     * @multi-site: needs site_id — if multi-site, version tokens must be per-site
     */
    public static function analyticsSummaryVersion(string $userId): string
    {
        return "analytics:summary:ver:{$userId}";
    }

    /**
     * Staff-facing analytics summary, keyed by professional + date range.
     */
    public static function staffAnalyticsSummary(string $userId, string $from, string $to): string
    {
        return "staff:analytics:summary:{$userId}:{$from}:{$to}";
    }

    /**
     * Short-TTL resolve map: handle → {pro_id, site_id, updated_at_ts}.
     * Consumers: IndividualProfileController (read/write) and
     * SiteCacheService::invalidateSitePayload (bust).
     */
    public static function handleResolve(string $handle): string
    {
        return 'handle.resolve:'.strtolower($handle);
    }

    /**
     * Full individual profile payload, keyed by handle + updated_at timestamp
     * so the key naturally rolls forward on any site/user mutation without
     * explicit Cache::forget.
     */
    public static function publicProfile(string $handle, int $updatedAtTs): string
    {
        return 'public.profile:'.strtolower($handle).':'.$updatedAtTs;
    }
}
