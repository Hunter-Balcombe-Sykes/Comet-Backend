<?php

namespace App\Services\Cache;

use App\Http\Resources\LinkBlockResource;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use Illuminate\Support\Facades\Cache;

// V2: Cache invalidation for a site's public surface, plus the single-flight
// link-block read.
//
// The hand-assembled payload builder that used to live here served
// GET /api/public/site and /api/public/site-by-slug; it was removed 2026-09-04
// with those routes and PublicSiteController. The canonical public lane is
// IndividualProfileController -> IndividualProfilePayloadBuilder, cached under
// CacheKeyGenerator::publicProfile($handleLc, $updatedAtTs) — a timestamp-keyed
// key, which is why invalidateSitePayload() below busts handle.resolve rather
// than any payload key of its own.
//
// Entry points:
//   invalidateSite()        — everything; the ONLY one SiteObserver calls
//                             (SiteObserver.php:28, :90). It runs payload, then
//                             raiseResolveFloor, then images.
//   invalidateSitePayload() — blocks, email branding, the auth-path model cache,
//                             handle.resolve + its floor. No media.
//   invalidateSiteImages()  — the /api/images variants only.
//   raiseResolveFloor()     — public, because ConvergeSiteSubdomainsCommand
//                             writes subdomains by raw UPDATE and needs it alone.
class SiteCacheService
{
    public function __construct(private readonly CacheLockService $cacheLock) {}

    /**
     * Returns both the primary key and its SWR stale copy so invalidation is
     * always symmetric — prevents stale copies surviving after a primary bust.
     *
     * @return array{string, string}
     */
    private static function bustWithStale(string $key): array
    {
        return [$key, CacheKeyGenerator::staleKey($key)];
    }

    /**
     * Invalidate a site's non-media cache keys — blocks, email branding, the
     * auth-path model cache, and handle.resolve plus its floor. Excludes the
     * image-gallery variants; use when a mutation affects site content but not
     * media rows — service edits, category renames, profile updates, block reorders.
     */
    public function invalidateSitePayload(Site $site): void
    {
        $userId = (string) ($site->user_id ?? '');

        $keys = [
            // CACHE-2: the SWR fast path serves :stale on primary expiry, so
            // invalidation must clear both copies of every key.
            ...self::bustWithStale(CacheKeyGenerator::siteBlocks($site->id, 'links')),
            ...self::bustWithStale(CacheKeyGenerator::siteBlocks($site->id, 'sections')),
            // White-label email branding bundle (logo, palette, reply-to). Same
            // CACHE-2 both-copies rule as the block keys above.
            ...self::bustWithStale(CacheKeyGenerator::emailBrand($site->id)),
        ];

        // The auth-path Professional model cache (AUTH-1) holds the site relation
        // preloaded — site writes must bust it or the next 60s of authenticated
        // requests would see a stale subdomain / settings on $pro->site.
        if ($userId !== '') {
            $modelKey = CacheKeyGenerator::professionalModel($userId);
            $keys[] = $modelKey;
            $keys[] = $modelKey.':stale';
        }

        // Bust handle.resolve so the timestamp-keyed public.profile:* key rotates
        // on the next request — without this, the 30s resolve cache continues
        // serving the old updated_at_ts and the new key is never constructed.
        //
        // ONE handle here, not two, and that asymmetry with
        // ConvergeSiteSubdomainsCommand::cacheKeysFor() (which busts the OLD and
        // the NEW handle) is correct, not a missing case. This method reads
        // $site->subdomain — the value already written — so it has no access to
        // the previous one; the rename command does, because it holds both sides
        // of the raw UPDATE it is about to run. Do not "fix" either to match the
        // other: adding an old-handle bust here has nothing to bust, and dropping
        // one there would strand the pre-rename resolve entry for its full TTL.
        $handle = strtolower((string) ($site->subdomain ?? ''));
        if ($handle !== '') {
            $resolveKey = CacheKeyGenerator::handleResolve($handle);
            $keys[] = $resolveKey;
            $keys[] = $resolveKey.':stale';
        }

        Cache::deleteMultiple(array_values(array_unique($keys)));

        // Deleting handle.resolve is not sufficient — an in-flight reader that
        // queried the DB pre-commit can re-put the old timestamp after the
        // delete. The floor is the authoritative lower bound the reader can't
        // regress below.
        if ($handle !== '') {
            $this->raiseResolveFloor($handle, $site->updated_at->timestamp);
        }
    }

    /**
     * Raise the handle-resolve timestamp floor, only-ever-upward.
     *
     * INVARIANT — this may only be called POST-COMMIT. A floor written inside an
     * open transaction publishes the post-write cache key before the data is
     * visible, so a racing reader caches PRE-commit data under the authoritative
     * new key — and public.profile:* keys are never explicitly busted (rotation
     * by key is the design), so that entry survives the full payload TTL plus
     * its stale window. Every current caller of invalidateSitePayload() already
     * satisfies this (SiteObserver and ServiceCategoryObserver are
     * $afterCommit = true; UserSiteController::update busts after the save;
     * ClaimSiteService invalidates outside its transaction closure). Nothing
     * enforces it — any new caller inside a transaction MUST defer.
     *
     * Only-raise, not a blind put: several callers can hold a Site instance
     * whose updated_at predates a concurrent save, and lowering the floor
     * reopens the very race this closes. The read-modify-write is not atomic,
     * but it narrows exposure from "any invalidation within the floor TTL" to
     * microseconds, and its worst case degrades to the pre-floor behaviour.
     *
     * A null/0 timestamp (malformed row) skips the write entirely — 0 is a no-op
     * under max() but writing it would clobber a valid higher floor.
     *
     * Public: also called directly by ConvergeSiteSubdomainsCommand, which
     * writes site.sites.subdomain via a raw UPDATE (bypassing Eloquent, and
     * so bypassing invalidateSitePayload's own call site above) and must
     * still raise the floor for the handle it just repointed. Same
     * POST-COMMIT invariant applies to that caller.
     */
    public function raiseResolveFloor(string $handle, ?int $timestamp): void
    {
        if ($timestamp === null || $timestamp <= 0) {
            return;
        }

        $key = CacheKeyGenerator::handleResolveFloor($handle);
        $floor = max((int) Cache::get($key, 0), $timestamp);

        Cache::put($key, $floor, (int) config('partna.public_profile.resolve_floor_ttl', 600));
    }

    /**
     * Invalidate image-gallery cache keys for a site.
     * Use when a mutation affects media rows (SiteMediaObserver, image uploads).
     */
    public function invalidateSiteImages(Site $site): void
    {
        $keys = [CacheKeyGenerator::siteImages($site->id)];

        // CACHE-1: every (pool, media_type) variant of /api/images. The polling
        // path (?ids[]) uses unbounded fingerprint keys and relies on its own 5s
        // TTL — not enumerated here.
        foreach (CacheKeyGenerator::siteImagesViewVariants() as [$pool, $mediaType]) {
            $variantKey = CacheKeyGenerator::siteImagesView($site->id, $pool, $mediaType);
            $keys[] = $variantKey;
            $keys[] = $variantKey.':stale';
        }

        Cache::deleteMultiple(array_values(array_unique($keys)));
    }

    /**
     * Invalidate all cache keys for a site (payload + images).
     * Called by SiteObserver on any site save — full site mutations always bust everything.
     * Callers that know images are unaffected should call invalidateSitePayload() directly.
     */
    public function invalidateSite(Site $site): void
    {
        $this->invalidateSitePayload($site);
        $this->invalidateSiteImages($site);
    }

    public function getSiteLinkBlocks(string $siteId): array
    {
        // Single-flight + ±20% jitter + SWR via CacheLockService. Without the
        // lock, a cold cache after a flush lets every concurrent visitor on a
        // popular site rebuild this list in parallel; with int TTL the helper
        // also spreads expiry across the fleet.
        return $this->cacheLock->rememberLocked(
            CacheKeyGenerator::siteBlocks($siteId, 'links'),
            (int) config('partna.cache.ttls.public_payload'),
            fn () => Block::query()
                ->where('site_id', $siteId)
                ->where('block_group', 'links')
                ->active()
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Block $b) => (new LinkBlockResource($b))->resolve())
                ->all()
        );
    }
}
