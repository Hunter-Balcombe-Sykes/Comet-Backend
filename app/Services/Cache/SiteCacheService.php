<?php

namespace App\Services\Cache;

use App\Http\Resources\LinkBlockResource;
use App\Models\Core\MediaVariant;
use App\Models\Core\Site\Block;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteSubdomainAlias;
use App\Models\Views\PublicSitePayload;
use App\Services\Cache\Concerns\JitteredTtl;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

// V2: Public site payload caching with single-flight locking (prevents thundering herd). Handles 95% of traffic. Simplified in V2 — no more product payload caching.
class SiteCacheService
{
    use JitteredTtl;

    private const MISS_SENTINEL = '__MISS__';

    /**
     * Cache-internal flag on payloads written by writePayloadWithStale: the
     * entry was fully built (image-variant URLs resolved, block collections +
     * legal healed), so warm hits return it as-is — no per-request
     * media_variants query, no cache rewrite. Entries written before this
     * marker existed take the legacy heal path once and get re-written with
     * the marker. Never leaves the service (see withoutResolvedMarker).
     */
    private const RESOLVED_MARKER = '__resolved_v1';

    /**
     * Stale-extension multiplier — matches CacheLockService::STALE_TTL_MULTIPLIER.
     * Primary TTL 15m → :stale TTL 150m (2.5h last-good window) on the public payload.
     */
    private const PAYLOAD_STALE_TTL_MULTIPLIER = 10;

    /**
     * Short negative-cache window for "no published site for this subdomain". 30s on the
     * primary key keeps bot scans off the DB; the longer :stale window survives a primary
     * eviction so a parallel bot-burst still hits cache, not the view.
     */
    private const MISS_PRIMARY_TTL_SECONDS = 30;

    public function __construct(private readonly CacheLockService $cacheLock) {}

    /**
     * Query the DB view and build the public site payload, then cache and return it.
     * Returns null (with a short-lived sentinel cached) when the subdomain has no
     * published site in the view.
     *
     * LEGACY PATH NOTE (audit finding API-2, safe subset):
     * This method hand-assembles a raw array for GET /api/public/site and
     * GET /api/public/site-by-slug (served by PublicSiteController). Those routes
     * bypass the Resource layer enforced by the newer IndividualProfileController →
     * IndividualProfileResource path. They remain live because the external Astro
     * front-end (partna-pages) and any mobile clients may still consume them.
     * Decommissioning requires confirming with the Astro Worker / front-end that
     * these routes are no longer called before removing them.
     *
     * @return array<string, mixed>|null
     */
    protected function buildPayloadFromDb(string $subdomain, string $key): ?array
    {
        $staleKey = $key.':stale';
        $row = PublicSitePayload::query()
            ->whereRaw('lower(subdomain) = ?', [$subdomain])
            ->first();

        // View only contains published sites; if not found, treat as 404.
        if (! $row) {
            // Negative-cache briefly to reduce DB load from bot scans.
            // :stale gets a longer window so the next bot-burst still hits cache
            // even if the primary just evicted.
            Cache::put($key, self::MISS_SENTINEL, self::applyJitter(self::MISS_PRIMARY_TTL_SECONDS));
            Cache::put($staleKey, self::MISS_SENTINEL, self::applyJitter(self::MISS_PRIMARY_TTL_SECONDS * self::PAYLOAD_STALE_TTL_MULTIPLIER));

            return null;
        }

        $payload = $row->payload ?? [];

        // The view's COALESCE guarantees services is always a jsonb array,
        // so a missing key is the only thing we have to defend against.
        $services = $payload['services'] ?? [];

        $site = $payload['site'] ?? null;
        if (is_array($site)) {
            $site = $this->safeHydrateSitePayload(
                $site,
                (string) ($row->user_id ?? ''),
                (string) ($row->site_id ?? ''),
                $subdomain
            );
        }

        // Must match the controller response shape exactly.
        $links = is_array($payload['links'] ?? null) ? array_values($payload['links']) : [];
        $sections = is_array($payload['sections'] ?? null) ? array_values($payload['sections']) : [];
        $existingBlocks = is_array($payload['blocks'] ?? null) ? array_values($payload['blocks']) : [];

        $data = [
            'published' => true,
            'site' => $site,
            'professional' => $payload['professional'] ?? null,
            'services' => $services,
            'links' => $links,
            'sections' => $sections,
            'blocks' => $this->buildCombinedBlocksPayload($links, $sections, $existingBlocks),
            'legal' => $payload['legal'] ?? null,
        ];

        $this->writePayloadWithStale($key, $data);

        return $data;
    }

    /**
     * Write the payload to both the primary (jittered TTL) and :stale (×10 window) keys.
     *
     * Mirrors CacheLockService::writeWithJitter's contract so a primary expiry inside
     * the SWR fast path in getPublicSitePayload() can return last-good immediately while
     * one worker recomputes. busts must clear both keys (see invalidateSite() — keys are
     * routed through bustWithStale()).
     */
    private function writePayloadWithStale(string $key, mixed $value): void
    {
        $base = (int) config('partna.cache.ttls.public_payload');

        // Stamp array payloads as fully built (URLs resolved, blocks/legal
        // healed) so warm hits can skip re-hydration entirely — the marker is
        // cache-internal and stripped before the payload leaves this service.
        if (is_array($value)) {
            $value[self::RESOLVED_MARKER] = true;
        }

        // Independent jitter draws so primary and stale copies expire at different seconds.
        Cache::put($key, $value, self::applyJitter($base));
        Cache::put($key.':stale', $value, self::applyJitter($base * self::PAYLOAD_STALE_TTL_MULTIPLIER));
    }

    /**
     * True when a cached array carries the internal fully-built marker.
     */
    private function isResolvedPayload(array $payload): bool
    {
        return ($payload[self::RESOLVED_MARKER] ?? false) === true;
    }

    /**
     * Strip the cache-internal marker before a payload leaves this service —
     * the controller response shape must stay exactly as documented.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withoutResolvedMarker(array $payload): array
    {
        unset($payload[self::RESOLVED_MARKER]);

        return $payload;
    }

    /**
     * Get public site payload (MOST CRITICAL - 95% of traffic)
     *
     * IMPORTANT: This must match the PublicSiteController response shape exactly.
     */
    public function getPublicSitePayload(string $subdomain): ?array
    {
        $subdomain = strtolower($subdomain);

        $key = CacheKeyGenerator::publicSitePayload($subdomain);
        $staleKey = $key.':stale';
        $cached = Cache::get($key);

        if ($cached === self::MISS_SENTINEL) {
            return null;
        }
        if (is_array($cached)) {
            // Fast path — entry was written fully built (URLs resolved,
            // collections healed): return it untouched. This is 95% of public
            // traffic; it must not touch Postgres or rewrite the cache.
            if ($this->isResolvedPayload($cached)) {
                return $this->withoutResolvedMarker($cached);
            }

            // Backward-compatible cache healing for payload shape changes.
            // Older cache entries may not include `services`.
            if (array_key_exists('services', $cached)) {
                $cached = $this->ensureBlockCollections($cached);

                if (! array_key_exists('legal', $cached)) {
                    $cached['legal'] = null;
                }

                // Resolve image variant paths to URLs (pre-URL-resolution cache entries)
                $site = $cached['site'] ?? null;
                if (is_array($site)) {
                    $userId = (string) data_get($cached, 'professional.id', '');
                    $cached['site'] = $this->safeHydrateSitePayload(
                        $site,
                        $userId,
                        '',
                        $subdomain
                    );
                }

                // Re-write once with the resolved marker so the next hit takes
                // the fast path above.
                $this->writePayloadWithStale($key, $cached);

                return $cached;
            }
            Cache::forget($key);
            Cache::forget($staleKey);
        }

        // SWR fast path: primary expired but :stale still live — return last-good
        // immediately while one worker (lock winner) recomputes silently.
        $stale = Cache::get($staleKey);
        if ($stale !== null) {
            $fillLock = Cache::store('cache_locks')->lock('site:fill:'.$subdomain, 10);
            // Non-blocking attempt: if another worker is already recomputing, fall
            // through and return the stale value — that's the whole point of SWR.
            if ($fillLock->get()) {
                try {
                    // Re-check primary: another process may have filled it while we raced.
                    $rechecked = Cache::get($key);
                    if ($rechecked === self::MISS_SENTINEL) {
                        return null;
                    }
                    if (is_array($rechecked) && array_key_exists('services', $rechecked)) {
                        return $this->withoutResolvedMarker($rechecked);
                    }

                    return $this->buildPayloadFromDb($subdomain, $key);
                } catch (Throwable $e) {
                    // CCH-3: recompute failed but a known-good stale value is
                    // already in hand — report and fall through to the SAME
                    // stale-serving/healing ladder below used for "another
                    // worker is refreshing", instead of failing this one
                    // unlucky lock-winner's request. $stale is guaranteed
                    // non-null here (we're inside `if ($stale !== null)`
                    // above). This does not mask a genuinely broken cache: if
                    // $stale itself turns out unusable (old shape), the ladder
                    // below re-invokes buildPayloadFromDb and that failure
                    // still propagates normally.
                    report($e);
                } finally {
                    $this->releaseLockQuiet($fillLock);
                }
            }

            // Reached when another worker holds the fill lock, OR the lock-winner's
            // own recompute just failed above (CCH-3) — either way, serve the
            // last-good copy without blocking. Apply the same backward-compat
            // healing as the primary-hit path: stale copies may hold a pre-V2
            // payload shape (missing `services`, `legal`, or unsplit links/
            // sections). If healing fails (old shape), fall through to
            // buildPayloadFromDb — the fill-lock winner is already doing this, and
            // the bounded extra DB hit is safer than serving a broken response.
            if ($stale === self::MISS_SENTINEL) {
                return null;
            }
            if (! is_array($stale)) {
                return null;
            }
            // Fully-built stale copy — serve as-is (same fast path as primary).
            if ($this->isResolvedPayload($stale)) {
                return $this->withoutResolvedMarker($stale);
            }
            if (! array_key_exists('services', $stale)) {
                // Old payload shape — can't safely return it; rebuild.
                Cache::forget($key);
                Cache::forget($staleKey);

                return $this->buildPayloadFromDb($subdomain, $key);
            }
            $stale = $this->ensureBlockCollections($stale);
            if (! array_key_exists('legal', $stale)) {
                $stale['legal'] = null;
            }
            $staleSite = $stale['site'] ?? null;
            if (is_array($staleSite)) {
                $userId = (string) data_get($stale, 'professional.id', '');
                $stale['site'] = $this->safeHydrateSitePayload($staleSite, $userId, '', $subdomain);
            }

            return $stale;
        }

        // Cold miss (no primary, no stale) — acquire a per-subdomain fill lock so
        // only one process rebuilds the payload from the DB view while concurrent
        // requests wait (single-flight).
        $fillLock = Cache::store('cache_locks')->lock('site:fill:'.$subdomain, 10);

        try {
            // Block up to 5 s for the lock; raises LockTimeoutException if it can't.
            $fillLock->block(5);
        } catch (LockTimeoutException) {
            // Another process is (or was) filling the cache.
            $warm = Cache::get($key);
            if ($warm === self::MISS_SENTINEL) {
                return null;
            }
            if (is_array($warm)) {
                return $this->withoutResolvedMarker($warm);
            }

            // Cache still empty — compute directly rather than flash-404.
            // Bounded stampede risk: only requests in the lock-timeout window run this path.
            return $this->buildPayloadFromDb($subdomain, $key);
        }

        try {
            // Double-check: the lock winner may have filled the cache while we waited.
            $rechecked = Cache::get($key);
            if ($rechecked === self::MISS_SENTINEL) {
                return null;
            }
            if (is_array($rechecked) && array_key_exists('services', $rechecked)) {
                return $this->withoutResolvedMarker($rechecked);
            }

            return $this->buildPayloadFromDb($subdomain, $key);
        } finally {
            $this->releaseLockQuiet($fillLock);
        }
    }

    /**
     * Release a lock without letting a double-release (or driver quirk) bubble up.
     * Mirrors CacheLockService — the finally block must never throw on top of an
     * earlier exception from the closure.
     */
    private function releaseLockQuiet(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable) {
            // ignore — lock may have auto-expired or been released elsewhere
        }
    }

    /**
     * @param  array<string, mixed>  $site
     * @return array<string, mixed>
     */
    private function safeHydrateSitePayload(array $site, string $userId, string $siteId, string $subdomain): array
    {
        try {
            return $this->resolveImageVariantUrlsInSite($site, $siteId);
        } catch (Throwable $e) {
            Log::warning('Public site payload hydration failed; returning base site payload.', [
                'subdomain' => $subdomain,
                'user_id' => $userId,
                'site_id' => $siteId,
                'error' => $e->getMessage(),
            ]);

            return $site;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function ensureBlockCollections(array $payload): array
    {
        $links = is_array($payload['links'] ?? null) ? array_values($payload['links']) : [];
        $sections = is_array($payload['sections'] ?? null) ? array_values($payload['sections']) : [];
        $existingBlocks = is_array($payload['blocks'] ?? null) ? array_values($payload['blocks']) : [];

        if ($links === [] && $existingBlocks !== []) {
            $links = array_values(array_filter($existingBlocks, function ($block): bool {
                return is_array($block)
                    && strtolower((string) ($block['block_group'] ?? '')) === 'links';
            }));
        }

        if ($sections === [] && $existingBlocks !== []) {
            $sections = array_values(array_filter($existingBlocks, function ($block): bool {
                return is_array($block)
                    && strtolower((string) ($block['block_group'] ?? '')) === 'sections';
            }));
        }

        $payload['links'] = $links;
        $payload['sections'] = $sections;
        $payload['blocks'] = $this->buildCombinedBlocksPayload($links, $sections, $existingBlocks);

        return $payload;
    }

    /**
     * @param  array<int, mixed>  $links
     * @param  array<int, mixed>  $sections
     * @param  array<int, mixed>  $existingBlocks
     * @return array<int, array<string, mixed>>
     */
    private function buildCombinedBlocksPayload(array $links, array $sections, array $existingBlocks = []): array
    {
        if ($links === [] && $sections === []) {
            return array_values(array_filter($existingBlocks, fn ($block): bool => is_array($block)));
        }

        $normalizedLinks = array_map(function ($block): array {
            $data = is_array($block) ? $block : [];
            $data['block_group'] = 'links';

            return $data;
        }, $links);

        $normalizedSections = array_map(function ($block): array {
            $data = is_array($block) ? $block : [];
            $data['block_group'] = 'sections';

            return $data;
        }, $sections);

        $combined = array_merge($normalizedLinks, $normalizedSections);

        usort($combined, function (array $a, array $b): int {
            $aSort = (int) ($a['sort_order'] ?? PHP_INT_MAX);
            $bSort = (int) ($b['sort_order'] ?? PHP_INT_MAX);
            if ($aSort !== $bSort) {
                return $aSort <=> $bSort;
            }

            $aId = (string) ($a['id'] ?? '');
            $bId = (string) ($b['id'] ?? '');

            return $aId <=> $bId;
        });

        return array_values($combined);
    }

    /**
     * Resolve image variant paths to public URLs in the site payload.
     * The view returns storage paths; we need full URLs for the frontend.
     * Also resolves video variant/stream/poster paths in gallery_videos and content_videos.
     *
     * @param  array<string, mixed>  $site
     * @return array<string, mixed>
     */
    private function resolveImageVariantUrlsInSite(array $site, string $siteId): array
    {
        $allMediaIds = [];
        foreach (['gallery', 'content_images', 'gallery_videos', 'content_videos'] as $key) {
            foreach ($site[$key] ?? [] as $item) {
                if (is_array($item) && ! empty($item['id'])) {
                    $allMediaIds[] = $item['id'];
                }
            }
        }

        // Single query: one DB round-trip for all variant rows across all media types.
        // Index as: media_id → artifact_type → variant_key → URL
        $mvByMedia = [];
        if ($allMediaIds !== []) {
            $allVariants = MediaVariant::query()
                ->whereIn('media_id', array_unique($allMediaIds))
                ->get();

            foreach ($allVariants as $mv) {
                $mvByMedia[$mv->media_id][$mv->artifact_type][$mv->variant_key] = $mv->url;
            }
        }

        foreach (['gallery', 'content_images'] as $key) {
            $items = $site[$key] ?? [];
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $i => $item) {
                if (! is_array($item) || empty($item['id'])) {
                    continue;
                }
                $mediaId = $item['id'];
                $pathVariants = $item['variants'] ?? [];
                if (! is_array($pathVariants)) {
                    continue;
                }
                $urlVariants = [];
                foreach ($pathVariants as $variantKey => $path) {
                    $url = $mvByMedia[$mediaId]['webp'][$variantKey] ?? null;
                    if ($url !== null) {
                        $urlVariants[$variantKey] = $url;
                    }
                }
                $site[$key][$i]['variants'] = $urlVariants;
            }

            self::sortByOrderThenId($site[$key]);
        }

        foreach (['gallery_videos', 'content_videos'] as $key) {
            $items = $site[$key] ?? [];
            if (! is_array($items)) {
                continue;
            }
            foreach ($items as $i => $item) {
                if (! is_array($item) || empty($item['id'])) {
                    continue;
                }
                $mediaId = $item['id'];
                $byType = $mvByMedia[$mediaId] ?? [];

                $site[$key][$i]['variants'] = $byType['mp4'] ?? [];
                $site[$key][$i]['streams'] = $byType['hls_playlist'] ?? [];
                $site[$key][$i]['poster'] = ($byType['poster']['poster'] ?? null);
            }

            self::sortByOrderThenId($site[$key]);
        }

        // Ensure video keys always exist even when there are no videos (backward-compat).
        $site['gallery_videos'] = $site['gallery_videos'] ?? [];
        $site['content_videos'] = $site['content_videos'] ?? [];

        if (isset($site['document']) && is_array($site['document']) && ! empty($site['document']['preview_url'])) {
            $rawPath = (string) $site['document']['preview_url'];
            $site['document']['preview_url'] = Storage::disk(config('partna.media_disk'))->url($rawPath);
        }

        return $site;
    }

    /**
     * Sort payload media items by sort_order asc, falling back to id for a
     * stable tiebreak. Shared by both the image and video passes in
     * resolveImageVariantUrlsInSite().
     */
    private static function sortByOrderThenId(array &$items): void
    {
        usort($items, function ($a, $b) {
            $aSort = is_array($a) ? (int) ($a['sort_order'] ?? PHP_INT_MAX) : PHP_INT_MAX;
            $bSort = is_array($b) ? (int) ($b['sort_order'] ?? PHP_INT_MAX) : PHP_INT_MAX;
            if ($aSort !== $bSort) {
                return $aSort <=> $bSort;
            }
            $aId = is_array($a) ? (string) ($a['id'] ?? '') : '';
            $bId = is_array($b) ? (string) ($b['id'] ?? '') : '';

            return $aId <=> $bId;
        });
    }

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
     * Invalidate payload-related cache keys for a site (excludes image-gallery variants).
     * Use when a mutation affects site content but not media rows — service edits,
     * category renames, profile updates, block reorders.
     */
    public function invalidateSitePayload(Site $site): void
    {
        $userId = (string) ($site->user_id ?? '');

        $keys = [
            // busts: site:payload:{subdomain} + :stale (CACHE-2 — SWR fast path
            // serves :stale on primary expiry, so invalidation must clear both)
            ...self::bustWithStale(CacheKeyGenerator::publicSitePayload($site->subdomain)),
            ...self::bustWithStale(CacheKeyGenerator::siteBlocks($site->id, 'links')),
            ...self::bustWithStale(CacheKeyGenerator::siteBlocks($site->id, 'sections')),
            // White-label email branding bundle (logo, palette, reply-to). Same SWR
            // contract as the payload keys — bust both primary and :stale.
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

        // If subdomain changed, kill the OLD cache key too.
        // This is critical so old URLs redirect (via alias) instead of returning cached payload.
        if ($site->wasChanged('subdomain')) {
            $old = strtolower((string) $site->getOriginal('subdomain'));
            if ($old !== '') {
                // busts: site:payload:{old} + :stale — same SWR contract as the new key.
                foreach (self::bustWithStale(CacheKeyGenerator::publicSitePayload($old)) as $oldKey) {
                    $keys[] = $oldKey;
                }
            }
        }

        // Invalidate all alias cache keys (your Site model has no aliases() relation)
        $aliasSubdomains = SiteSubdomainAlias::query()
            ->where('site_id', $site->id)
            ->pluck('subdomain')
            ->all();

        foreach ($aliasSubdomains as $aliasSubdomain) {
            // busts: site:payload:{alias} + :stale (CACHE-2 SWR symmetry)
            foreach (self::bustWithStale(CacheKeyGenerator::publicSitePayload(strtolower($aliasSubdomain))) as $aliasKey) {
                $keys[] = $aliasKey;
            }
        }

        // Bust handle.resolve so the timestamp-keyed public.profile:* key rotates
        // on the next request — without this, the 30s resolve cache continues
        // serving the old updated_at_ts and the new key is never constructed.
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
            $this->raiseResolveFloor($handle, $site->updated_at?->timestamp);
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
     */
    private function raiseResolveFloor(string $handle, ?int $timestamp): void
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

    /**
     * Warm cache for a site (call after updates)
     */
    public function warmSiteCache(string $subdomain): void
    {
        $this->getPublicSitePayload($subdomain);
    }
}
