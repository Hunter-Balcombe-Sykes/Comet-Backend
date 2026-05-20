<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Enums\AccountType;
use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
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
 *   - Professional is a brand   (served by Hydrogen at <handle>.partna.au)
 *   - Professional is a partner (Worker 301s to <brand>.partna.au/<handle>)
 *
 * Caching: 60s TTL (configurable) via CacheLockService::rememberLocked.
 * Cache key includes handle + site's updated_at so any SiteObserver-fired
 * mutation rolls the key forward; the CloudflareCachePurgeJob (§28.7)
 * separately drops the edge cache so the next request rebuilds from here.
 *
 * Payload shape mirrors the Hydrogen affiliate endpoint via the shared
 * `SitepageDataResolverService` — same envelope contract, minus brand-
 * fallback content (placeholders, fallback gallery, brand_logo,
 * brand_slogan) which is layered in by the Hydrogen controller only.
 * Shop is always draft for individuals (no commerce surface).
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
        $pro = Professional::query()->where('handle_lc', $handleLc)->first();
        if (! $pro || ! $this->isIndividualLike($pro)) {
            return $this->error('Not found.', 404);
        }

        $site = Site::query()->where('professional_id', $pro->id)->first();
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

    /**
     * An "individual-like" pro is anyone whose account_type is Individual, or who
     * has no account_type set (transition window) and is not a brand.
     */
    private function isIndividualLike(Professional $pro): bool
    {
        if ($pro->isBrand() || $pro->isPartner()) {
            return false;
        }

        return ! ($pro->account_type instanceof AccountType) || $pro->account_type === AccountType::Individual;
    }
}
