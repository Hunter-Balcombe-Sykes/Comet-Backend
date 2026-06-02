<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
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
 *   1. handle.resolve:{handle}     short-TTL (30 s) Redis map of
 *                                  handle → {pro_id, site_id, updated_at_ts}.
 *                                  Skipping it would cost two DB queries on
 *                                  every cache hit; with it the hot path is
 *                                  two Redis lookups (resolve + payload).
 *                                  Staleness is bounded by the TTL — short
 *                                  enough that no mutation-driven invalidation
 *                                  is required; long enough to absorb traffic
 *                                  spikes.
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
    /** Resolve-cache TTL in seconds — short window to absorb traffic without
     * needing mutation-driven invalidation. */
    private const RESOLVE_CACHE_TTL = 30;

    /** Slow-request threshold; anything above logs a warning for Nightwatch. */
    private const SLOW_REQUEST_THRESHOLD_MS = 1_000;

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
        $resolved = Cache::remember(
            "handle.resolve:{$handleLc}",
            self::RESOLVE_CACHE_TTL,
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

        $key = "public.profile:{$handleLc}:{$resolved['updated_at_ts']}";

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

        if ($payload === null) {
            // Resolve cache pointed at a now-deleted row. Forget the resolve
            // entry so the next request rebuilds from scratch.
            Cache::forget("handle.resolve:{$handleLc}");
            $this->logIfSlow($handleLc, '404-deleted-race', $startedAt);

            return $this->error('Not found.', 404);
        }

        $this->logIfSlow($handleLc, '200', $startedAt);

        // Wrap in the standard envelope. ApiController::success() passes $data
        // directly to response()->json() — so we keep the {'data': ...} wrapper
        // that the Astro Worker subrequest expects.
        return $this->success(['data' => $payload]);
    }

    /** Emit a structured warning when a public profile fetch crosses the
     * slow threshold. Nightwatch picks these up for the P95-alert config. */
    private function logIfSlow(string $handleLc, string $outcome, float $startedAt): void
    {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        if ($durationMs < self::SLOW_REQUEST_THRESHOLD_MS) {
            return;
        }
        Log::warning('slow_public_profile', [
            'handle' => $handleLc,
            'outcome' => $outcome,
            'duration_ms' => $durationMs,
        ]);
    }
}
