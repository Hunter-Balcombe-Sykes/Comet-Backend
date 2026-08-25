<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * §28.8 — Public profile API for individual professionals.
 *
 *   GET /api/public/profiles/{handle}
 *
 * Authentication: NONE — public. Consumed by the Astro Worker subrequest.
 *
 * 404 rules (audit: avoid existence leak on public endpoints):
 *   - Handle not found
 *
 * Caching (audit Phase G P3):
 *   1. handle.resolve:{handle}     short-TTL (default 30 s, config-driven) Redis map of
 *                                  handle → {pro_id, site_id, updated_at_ts}.
 *                                  Skipping it would cost two DB queries on
 *                                  every cache hit; with it the hot path is
 *                                  two Redis lookups (resolve + payload).
 *                                  Staleness is bounded by the TTL, but the TTL
 *                                  alone is NOT sufficient: the payload key's
 *                                  rotation signal (updated_at_ts) lives inside
 *                                  this cached value, so a stale entry gates the
 *                                  rotation. handle.resolve.floor:{handle} (below)
 *                                  is the fix — do not rely on the TTL alone.
 *   1b. handle.resolve.floor:{handle}
 *                                  Monotonic post-commit timestamp floor written
 *                                  by SiteCacheService::invalidateSitePayload.
 *                                  max()'d against updated_at_ts so a stale
 *                                  resolve entry can't hold the payload key back.
 *                                  NOT covered: the ['not_found' => true] variant
 *                                  — a stale-set can re-install that too and this
 *                                  method 404s before reaching the max(). Matters
 *                                  for first publish/claim, not design edits; the
 *                                  follow-up purge chain is the rescue there.
 *   2. public.profile:{handle}:{updated_at_ts}
 *                                  Full payload, single-flight via
 *                                  CacheLockService::rememberLocked (jittered,
 *                                  SWR). Key naturally rolls forward when the
 *                                  underlying site updates — no explicit
 *                                  Cache::forget needed in observers.
 *
 * Slow-request log (audit O4): any response > 1 s emits a structured
 * `slow_public_profile` warning so Nightwatch surfaces P95 creep. The
 * alert itself is configured in the Nightwatch dashboard.
 *
 * Payload is assembled by IndividualProfilePayloadBuilder.
 */
class IndividualProfileController extends ApiController
{
    public function __construct(
        private readonly CacheLockService $cache,
        private readonly IndividualProfilePayloadBuilder $builder,
    ) {}

    public function show(Request $request, string $handle): JsonResponse
    {
        $startedAt = microtime(true);
        $handleLc = strtolower(trim($handle));
        if ($handleLc === '') {
            return $this->error('Not found.', 404);
        }

        // ── handle.resolve cache ────────────────────────────────────────────
        // Short-TTL lookup that maps handle → {pro_id, site_id, updated_at_ts}
        // OR ['not_found' => true]. Saves the User + Site pre-resolve DB
        // queries on every cache-hit request. Sentinel-style not-found
        // caching means enumeration spikes don't repeatedly query for
        // non-existent handles.
        $resolved = $this->cache->rememberLocked(
            CacheKeyGenerator::handleResolve($handleLc),
            (int) config('partna.public_profile.resolve_cache_ttl', 30),
            function () use ($handleLc) {
                $pro = User::query()->where('handle_lc', $handleLc)->first();
                if (! $pro) {
                    return ['not_found' => true];
                }
                $site = Site::query()->where('user_id', $pro->id)->first();

                return [
                    'pro_id' => $pro->id,
                    'site_id' => $site?->id,
                    // Use site.updated_at when the site row exists, otherwise
                    // fall back to the user's updated_at so the cache key
                    // still rolls forward on early-setup changes.
                    'updated_at_ts' => $site?->updated_at?->timestamp
                        ?? $pro->updated_at?->timestamp
                        ?? 0,
                ];
            }
        );

        if ($resolved['not_found'] ?? false) {
            $this->logIfSlow($handleLc, '404', $startedAt);

            return $this->error('Not found.', 404);
        }

        // handle.resolve can hand back a pre-write timestamp: its delete-then-set
        // window lets an in-flight reader re-install the old stamp with a fresh
        // lease. The floor is written post-commit by invalidateSitePayload and
        // only ever rises, so max() pins the key to the post-write value.
        // Costs a third Redis round-trip on the hot path — a per-request cost
        // paid to close a per-save race.
        $ts = max(
            (int) $resolved['updated_at_ts'],
            $this->handleResolveFloor($handleLc),
        );

        $key = CacheKeyGenerator::publicProfile($handleLc, $ts);

        $payload = $this->cache->rememberLocked(
            $key,
            $this->builder->cacheTtl(),
            function () use ($resolved) {
                // Cache miss — hydrate the models and build. Guard against
                // a race where the row was deleted between the resolve
                // cache write and the builder call.
                $pro = User::find($resolved['pro_id']);
                if (! $pro) {
                    return null;
                }
                $site = $resolved['site_id'] ? Site::find($resolved['site_id']) : null;

                return $this->builder->build($pro, $site);
            }
        );

        // CCH-5: a presence probe that threw answers "this section does not
        // exist", and rememberLocked has just cached that answer under the full
        // payload TTL plus a ×10 stale twin — so one second of DB trouble hid a
        // page section for the best part of ten minutes, with only a
        // Log::warning to show for it. Re-write both keys under a short TTL so
        // the page heals seconds after the database does. Only the request that
        // actually built the degraded payload gets here; a cache hit never runs
        // the resolver, so lastBuildDegraded() is false for served copies.
        if ($payload !== null && $this->builder->lastBuildDegraded()) {
            $this->cache->shortenDegraded($key, $payload, $this->builder->degradedCacheTtl());
        }

        if ($payload === null) {
            // Resolve cache pointed at a now-deleted row. rememberLocked wrote
            // BOTH the primary resolve key and a longer-lived :stale twin, so
            // forget both — clearing only the primary lets the SWR fast path
            // resurrect the stale entry on the very next request, re-trigger
            // this same null-payload path, and loop for the full stale TTL.
            // Mirrors SiteCacheService::invalidateSitePayload's bustWithStale. (CCH-1)
            $resolveKey = CacheKeyGenerator::handleResolve($handleLc);
            Cache::deleteMultiple([$resolveKey, $resolveKey.':stale']);
            $this->logIfSlow($handleLc, '404-deleted-race', $startedAt);

            return $this->error('Not found.', 404);
        }

        $this->logIfSlow($handleLc, '200', $startedAt);

        // INTENTIONAL: the outer {'data': ...} envelope is part of the Astro Worker
        // contract (partna-pages reads payload.data.*). Do NOT "normalise" this to
        // match other endpoints — the Worker subrequest depends on this exact shape.
        // Any change here requires a coordinated deploy with the Worker code.
        return $this->success(['data' => $payload]);
    }

    /**
     * The handle-resolve floor, degrading to 0 when the cache store is dead.
     *
     * The only bare Cache call left on this route — CacheLockService guards its
     * own now, and a raw RedisException here would 500 the page five lines
     * after the fail-open limiter went to the trouble of letting it through.
     * Zero is a safe floor: max() already treats it as "no floor recorded", so
     * the request falls back to the resolver's own timestamp exactly as it does
     * before the first invalidation ever writes this key.
     */
    private function handleResolveFloor(string $handleLc): int
    {
        try {
            return (int) Cache::get(CacheKeyGenerator::handleResolveFloor($handleLc), 0);
        } catch (\Throwable $e) {
            Log::warning('cache.store_unavailable', [
                'operation' => 'handle_resolve_floor',
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /** Emit a structured warning when a public profile fetch crosses the
     * slow threshold. Nightwatch picks these up for the P95-alert config. */
    private function logIfSlow(string $handleLc, string $outcome, float $startedAt): void
    {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        if ($durationMs < (int) config('partna.public_profile.slow_request_threshold_ms', 1000)) {
            return;
        }
        Log::warning('slow_public_profile', [
            'handle' => $handleLc,
            'outcome' => $outcome,
            'duration_ms' => $durationMs,
        ]);
    }
}
