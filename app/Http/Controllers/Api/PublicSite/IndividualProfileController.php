<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Enums\AccountType;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\PublicSite\IndividualProfileResource;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Cache\CacheLockService;
use App\Services\PublicSite\SitepageDataResolverService;
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
        private readonly SitepageDataResolverService $resolver,
    ) {}

    public function show(Request $request, string $handle): JsonResponse
    {
        $handleLc = strtolower(trim($handle));
        if ($handleLc === '') {
            return $this->error('Not found.', 404);
        }

        $ttl = max(1, (int) config('partna.public_profile.cache_ttl_seconds', 60));

        // Cheap pre-resolve so cache-key versioning sees the latest site updated_at
        // without paying full payload assembly on every hit.
        $pro = Professional::query()->where('handle_lc', $handleLc)->first();
        if (! $pro || ! $this->isIndividualLike($pro)) {
            return $this->error('Not found.', 404);
        }

        $site = Site::query()->where('professional_id', $pro->id)->first();
        // PROF-3: when no Site row exists yet (early-setup pros) fall back to
        // the Professional's updated_at so block mutations roll the cache key
        // forward instead of being stuck at a permanent stamp=0.
        $stamp = $site?->updated_at?->timestamp
            ?? $pro->updated_at?->timestamp
            ?? 0;
        $key = "public.profile:{$handleLc}:{$stamp}";

        $payload = $this->cache->rememberLocked($key, $ttl, function () use ($pro, $site) {
            // PROF-2: filter `settings.design` through DESIGN_KEYS allow-list so
            // any non-display key that drifts into the design bag (admin tooling,
            // future settings service) doesn't leak.
            $rawDesign = (array) ($site?->settings['design'] ?? []);
            $design = array_intersect_key($rawDesign, array_flip(IndividualProfileResource::DESIGN_KEYS));

            // Pre-load section blocks once so each helper looks up its row
            // without re-querying.
            $sections = $this->resolver->loadSections($site);

            // Booking has to be computed before links because the synthesised
            // booking link is appended to the links array.
            $booking = $this->resolver->getBooking($site, $sections);

            return (new IndividualProfileResource(
                $pro,
                $design,
                contentImages: $this->resolver->getContentImages($site),
                gallery: $this->resolver->getGallery($site, $sections),
                links: $this->resolver->getLinks($site, $booking),
                bio: $this->resolver->getBio($pro, $sections),
                document: $this->resolver->getDocument($site),
                newsletter: $this->resolver->getNewsletter($sections),
                services: $this->resolver->getServices($site, $pro->id, $sections),
                booking: $booking,
            ))->resolve();
        });

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
