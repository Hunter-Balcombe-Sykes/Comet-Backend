<?php

namespace App\Services\Cache;

use App\Models\Core\Site\SiteMedia;

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
     * filtered-view variant. Alignment with UserUploadController::index is
     * now enforced by shared constants (SiteMedia::GALLERY_POOLS and
     * SiteMedia::MEDIA_TYPE_FILTERS) and locked by CacheKeyGeneratorTest.
     *
     * @return array<int, array{0: ?string, 1: string}>
     */
    public static function siteImagesViewVariants(): array
    {
        $variants = [];
        // null pool = "all pools" (no filter); the rest mirror SiteMedia::GALLERY_POOLS —
        // the same pool inputs UserUploadController::index accepts.
        foreach (array_merge([null], SiteMedia::GALLERY_POOLS) as $pool) {
            foreach (SiteMedia::MEDIA_TYPE_FILTERS as $mediaType) {
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
     * Debounce key gating AnalyticsCacheService::bumpVersion() — at most one
     * summary-version bump per user per TTL window (#CCH-1).
     */
    public static function analyticsIngestDebounce(string $userId): string
    {
        return "analytics:ingest-debounce:{$userId}";
    }

    /**
     * Click dedup claim key (AnalyticsController::click(), #CCH-1). $target is the
     * block_id, or a site+destination hash for self-describing v2 url clicks;
     * $identifier is the strongest available visitor signal (visitor_id > session_id
     * > ip hash — see AnalyticsController::dedupIdentifier()).
     */
    public static function analyticsClickDedup(string $target, string $identifier): string
    {
        return "analytics:dedup:click:{$target}:{$identifier}";
    }

    /**
     * Section-seen dedup claim key (AnalyticsController::sectionSeen(), #CCH-1).
     */
    public static function analyticsSectionDedup(string $siteId, string $sectionKey, string $identifier): string
    {
        return "analytics:dedup:section:{$siteId}:{$sectionKey}:{$identifier}";
    }

    /**
     * Item-seen dedup claim key (AnalyticsController::itemSeen(), #CCH-1).
     */
    public static function analyticsItemDedup(string $siteId, string $itemType, string $itemId, string $identifier): string
    {
        return "analytics:dedup:item:{$siteId}:{$itemType}:{$itemId}:{$identifier}";
    }

    /**
     * Derived insights for a professional. Computed over a fixed rolling window
     * (not the dashboard's selected range), so it's keyed by user only and shares
     * the summary version token — a new ingest busts both at once.
     *
     * @multi-site: needs site_id — insights aggregate one site's traffic under the current model
     */
    public static function analyticsInsights(string $userId): string
    {
        return "analytics:insights:{$userId}";
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

    /**
     * Column list for site.design_kits, cached so each design-kit save skips an
     * information_schema round-trip. The :v{version} suffix ties the cache entry
     * to the column set: bumping config('partna.design_kit_columns_version') in
     * the same PR as a design_kits migration orphans the old key (it TTLs out)
     * instead of forcing a blunt `artisan cache:clear`. Same version-token idea
     * as the analytics version keys above, but a deploy-time config constant
     * (the column set is deploy-stable) rather than a runtime counter. (LIFE-2 / CCH-2)
     */
    public static function designKitColumns(): string
    {
        return 'design_kits:columns:v'.config('partna.design_kit_columns_version');
    }

    // Platform-integration transient keys — cost-control counters and picker-catalog cache.

    public static function shopifyBrandCatalog(string $brandId): string
    {
        return 'platforms.shopify.brands.catalog.'.$brandId;
    }

    public static function youtubeThumbnailVerdict(string $videoId): string
    {
        return "yt_thumb:{$videoId}";
    }

    /**
     * Per-user, per-platform mutex key for serialising a read→mutate→write
     * connection-payload cycle (ManagesIntegrationConnection::withConnectionLock).
     * Lock name only (locks live on the cache_locks connection via config/cache.php
     * lock_connection). $suffix scopes the lock when one controller serves two platforms.
     */
    public static function platformConnectionLock(string $platform, string $userId, ?string $suffix = null): string
    {
        return "platforms:{$platform}:lock:{$userId}".($suffix !== null ? ":{$suffix}" : '');
    }

    /** Global daily Apify claim counter across ALL actors (SCALE-2 cost ceiling). */
    public static function apifyGlobalDailyLimit(string $date): string
    {
        return 'platforms:apify:global:daily:'.$date;
    }

    /** Per-actor daily Apify claim counter (actor = instagram|menu|google-business). */
    public static function apifyActorDailyLimit(string $actor, string $date): string
    {
        return 'platforms:apify:'.$actor.':daily:'.$date;
    }

    /**
     * Marker set BEFORE the paid Google Business Apify call and cleared after
     * (GoogleBusinessEnrichJob, JOB-2). Its presence WITHOUT a corresponding
     * googleBusinessApifyResult means a previous attempt died mid-call — the
     * job refuses to re-bill and treats the run as orphaned.
     */
    public static function googleBusinessApifyInflight(string $userId, string $placeId): string
    {
        return "platforms:google-business:apify:inflight:{$userId}:{$placeId}";
    }

    /**
     * Cached Apify result for one (user, place) — set immediately after the
     * paid call returns, so a retry within the window reuses it instead of
     * paying twice (GoogleBusinessEnrichJob, JOB-2). Stores {enrichment:
     * array|null}, NEVER a bare null — a bare null would be indistinguishable
     * from a cache miss ("never ran").
     */
    public static function googleBusinessApifyResult(string $userId, string $placeId): string
    {
        return "platforms:google-business:apify:result:{$userId}:{$placeId}";
    }

    /** Cached keyless iTunes Search/Lookup response, keyed by request path (SCALE-3). */
    public static function itunesResponse(string $path): string
    {
        return 'platforms:itunes:'.sha1($path);
    }

    /**
     * Content-popularity ranks for a site (analytics.content_popularity_scores,
     * grouped by content_type). Consumers: PublicIntegrationController (shop
     * product ranks) and PublicMenuController (menu category/item ranks) —
     * both single-flight cache via CacheLockService::rememberLocked, matching
     * IndividualProfileController's pattern (CCG-102). No mutation-driven
     * invalidation: ranks only change via the analytics:compute-popularity
     * schedule, so a TTL near that cadence bounds staleness on its own.
     */
    public static function sitePopularityRanks(string $siteId): string
    {
        return "site:{$siteId}:popularity:ranks";
    }
}
