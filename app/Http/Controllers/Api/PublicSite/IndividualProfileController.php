<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\User\User;
use App\Models\Core\Site\Site;
use App\Services\Cache\CacheLockService;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
 * Caching: 60s TTL (configurable) via CacheLockService::rememberLocked.
 * Cache key includes handle + site's updated_at so any SiteObserver-fired
 * mutation rolls the key forward; the CloudflareCachePurgeJob (§28.7)
 * separately drops the edge cache so the next request rebuilds from here.
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
        $handleLc = strtolower(trim($handle));
        if ($handleLc === '') {
            return $this->error('Not found.', 404);
        }

        // Cheap pre-resolve so cache-key versioning sees the latest site updated_at
        // without paying full payload assembly on every hit.
        $pro = User::query()->where('handle_lc', $handleLc)->first();
        if (! $pro) {
            return $this->error('Not found.', 404);
        }

        $site = Site::query()->where('user_id', $pro->id)->first();
        $key = $this->builder->cacheKey($handleLc, $site, $pro);

        $payload = $this->cache->rememberLocked(
            $key,
            $this->builder->cacheTtl(),
            fn () => $this->builder->build($pro, $site),
        );

        // Wrap in the standard envelope. ApiController::success() passes $data
        // directly to response()->json() — so we keep the {'data': ...} wrapper
        // that the Astro Worker subrequest expects.
        return $this->success(['data' => $payload]);
    }
}
