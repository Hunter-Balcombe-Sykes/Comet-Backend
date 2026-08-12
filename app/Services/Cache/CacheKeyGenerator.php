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

    // SecurityEventCooldownService's replay-spam cooldown (P4, 2026-08-12) —
    // one key per user per client-reported security event.
    public static function securityEventCooldown(string $userId, string $event): string
    {
        return "security-event-mail:{$userId}:{$event}";
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
     * Action-seen/action-tap dedup claim key (AnalyticsController::actionSeen()/
     * actionTap()). $event is 'seen'|'tap' — the two beacons dedup independently
     * (a tap must never be swallowed by an earlier seen's claim on the same action).
     */
    public static function analyticsActionDedup(string $siteId, string $event, string $actionId, string $identifier): string
    {
        return "analytics:dedup:action:{$event}:{$siteId}:{$actionId}:{$identifier}";
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
     * Monotonic floor for the handle-resolve timestamp.
     *
     * The public.profile:{handle}:{ts} key is deliberately timestamp-keyed so
     * mutations roll it forward — but {ts} is read out of handle.resolve, a 30s
     * cache. A request that read the DB before a write committed can re-put the
     * pre-write timestamp AFTER invalidation deleted it, re-leasing stale data
     * for another 30s. This key is the escape hatch: the invalidation path owns
     * it, the read path max()es against it, so a stale resolve entry can no
     * longer hold the payload key back.
     *
     * Writer: SiteCacheService::invalidateSitePayload.
     * Reader: IndividualProfileController::show.
     */
    public static function handleResolveFloor(string $handle): string
    {
        return 'handle.resolve.floor:'.strtolower($handle);
    }

    /**
     * Full individual profile payload, keyed by handle + updated_at timestamp
     * so the key naturally rolls forward on any site/user mutation without
     * explicit Cache::forget.
     *
     * Rotation abandons the previous key rather than deleting it, so every
     * superseded key lingers for its full TTL. Resident orphans are edit_rate x
     * TTL — bounded by edit flow, not by site count — which holds only while the
     * TTL stays short. Bound: config('partna.public_profile.cache_ttl_ceiling_seconds'),
     * enforced hourly by AggregateCacheMetricsJob.
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
     * lock_connection).
     *
     * Deliberately PLATFORM-WIDE, not per-account: this key must be the ONLY
     * string any writer can build for a given (platform, user), or two writers
     * that should mutually exclude silently don't (a lost update — see the
     * account-suffix bug this signature used to carry, removed 2026-07-21).
     * A multi-account platform (youtube/vimeo/youtube-music/bandcamp/spotify/
     * soundcloud) therefore serialises ALL of a user's accounts on one platform
     * behind one lock — an acceptable trade because these writes are rare,
     * human-paced events, not a hot path.
     */
    public static function platformConnectionLock(string $platform, string $userId): string
    {
        return "platforms:{$platform}:lock:{$userId}";
    }

    /**
     * Cross-platform single-slot XOR lock: the ONLY serialization point for
     * "at most one booking provider per user" (fresha/square/booking span
     * multiple platform keys, so a per-platform lock cannot enforce it — see
     * BuildsAutoSyncFindings::withBookingXorLock). Platform-suffix-free by
     * design so every booking-family writer (seedBooking + the Booking/Fresha
     * controllers) builds one identical key.
     */
    public static function bookingXorLock(string $userId): string
    {
        return "platforms:booking-xor:lock:{$userId}";
    }

    /** Cross-platform single-slot XOR lock for the reservations family (opentable/resdiary/nowbookit/custom). Mirrors bookingXorLock. */
    public static function reservationsXorLock(string $userId): string
    {
        return "platforms:reservations-xor:lock:{$userId}";
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
     * Short-lived negative marker for a menu scrape target (platform + store URL)
     * that came back empty / bot-blocked (R4-RES-1). Keyed by the exact URL that
     * would be POSTed — the billed unit — and NOT by user: two users pointing at
     * the same store must not each pay for the same blocked scrape. sha1 because
     * store URLs carry query strings (?diningMode=PICKUP) of unbounded length.
     */
    public static function menuScrapeBlocked(string $platform, string $storeUrl): string
    {
        return 'platforms:menu:blocked:'.$platform.':'.sha1($storeUrl);
    }

    /** Global daily AI menu-structuring spend counter across Mistral OCR + DeepSeek (mirrors apifyGlobalDailyLimit). */
    public static function aiSpendGlobalDailyLimit(string $date): string
    {
        return 'platforms:ai-spend:global:daily:'.$date;
    }

    /** Per-vendor-call daily AI spend counter (actor = mistral_ocr|deepseek_structure). */
    public static function aiSpendActorDailyLimit(string $actor, string $date): string
    {
        return 'platforms:ai-spend:'.$actor.':daily:'.$date;
    }

    /** Global daily Places claim counter across ALL SKUs and users (RV-6 cost ceiling). */
    public static function placesGlobalDailyLimit(string $date): string
    {
        return 'platforms:places:global:daily:'.$date;
    }

    /** Per-SKU daily Places claim counter (sku = details|photos). */
    public static function placesSkuDailyLimit(string $sku, string $date): string
    {
        return 'platforms:places:sku:'.$sku.':daily:'.$date;
    }

    /** Per-user daily Places claim counter — bounds one account's spend independent of the platform totals (RV-6). */
    public static function placesUserDailyLimit(string $userId, string $date): string
    {
        return 'platforms:places:user:'.$userId.':daily:'.$date;
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

    /**
     * Shared `:stale` suffix for every SWR (stale-while-revalidate) primary key
     * in this app. Single point of change if the convention is ever renamed —
     * previously hand-concatenated at each call site (CCH-2).
     */
    public static function staleKey(string $key): string
    {
        return $key.':stale';
    }

    /**
     * Per-user Redis SET tracking every idempotency response-cache key issued
     * for that user (App\Http\Middleware\IdempotencyKey — writer/indexer) so
     * AccountDeletionService::purgeIdempotencyCache() (reader/purger) can find
     * and forget every entry at GDPR-erasure time without a key scan (CCH-1).
     */
    public static function idempotencyIndexKey(string $userId): string
    {
        return "idempotency:index:{$userId}";
    }

    /** Global daily link-probe counter across all users (plan §11 ProbeBudget). */
    public static function routingProbeGlobalDaily(string $date): string
    {
        return 'routing:probe:global:daily:'.$date;
    }

    /** Per-user daily link-probe counter. */
    public static function routingProbeUserDaily(string $userId, string $date): string
    {
        return 'routing:probe:user:'.$userId.':daily:'.$date;
    }

    /**
     * Global daily counter for Fresha connects auto-triggered by LinkRouter.
     * Same shape and reason as the routing-probe global counter above: an
     * outbound scrape this backend makes on a user's say-so needs a ceiling.
     */
    public static function freshaAutoConnectDaily(string $date): string
    {
        return 'fresha:auto-connect:daily:'.$date;
    }

    /**
     * Salon menu scrape, keyed by URL and NOT by user — two people at the same
     * salon signing up should cost one scrape, not two. Same reasoning as the
     * per-URL probe cache below.
     */
    public static function freshaMenu(string $url): string
    {
        return 'fresha:menu:'.sha1($url);
    }

    /**
     * Per-URL probe cooldown / result cache. Keyed by the canonical URL and NOT
     * by user — the outbound request is the cost, and two users pasting the
     * same storefront should cost one probe, not two. Mirrors the menu-scrape
     * negative-marker precedent.
     */
    public static function routingProbeUrl(string $canonicalUrl): string
    {
        return 'routing:probe:url:'.sha1(strtolower($canonicalUrl));
    }
}
