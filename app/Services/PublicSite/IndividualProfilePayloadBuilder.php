<?php

namespace App\Services\PublicSite;

use App\Http\Resources\PublicSite\IndividualProfileResource;
use App\Models\Core\User\User;
use App\Models\Core\Site\Site;

/**
 * Pure projection helper — assembles the §28.8 public profile payload from a
 * Professional + Site without owning the cache. The controller and the warm
 * job both consume this so the two paths can't drift on field shape.
 *
 * Cache wrapper (CacheLockService::rememberLocked) is the caller's concern;
 * this class exposes the canonical cache key + TTL so both call sites stay
 * aligned on key shape.
 *
 * @see \App\Http\Controllers\Api\PublicSite\IndividualProfileController
 * @see \App\Jobs\Cache\WarmPublicSiteCacheJob
 */
class IndividualProfilePayloadBuilder
{
    public function __construct(
        private readonly SitepageDataResolverService $resolver,
    ) {}

    /**
     * Build the §28.8 resolved payload. Filters site.settings.design through
     * IndividualProfileResource::DESIGN_KEYS (audit PROF-2), then routes the
     * section envelopes through the shared resolver so the shape mirrors
     * HydrogenAffiliateController.
     *
     * @return array<string, mixed>
     */
    public function build(User $pro, ?Site $site): array
    {
        $rawDesign = (array) ($site?->settings['design'] ?? []);
        $design = array_intersect_key($rawDesign, array_flip(IndividualProfileResource::DESIGN_KEYS));

        $sections = $this->resolver->loadSections($site);
        $booking = $this->resolver->getBooking($site, $sections);

        // Keys mirror the Resource output shape 1-to-1 (#P3-01).
        return (new IndividualProfileResource($pro, [
            'site_id' => $site?->id,
            'design' => $design,
            'content_images' => $this->resolver->getContentImages($site),
            'gallery' => $this->resolver->getGallery($site, $sections),
            'links' => $this->resolver->getLinks($site, $booking),
            'bio' => $this->resolver->getBio($pro, $sections),
            'document' => $this->resolver->getDocument($site),
            'newsletter' => $this->resolver->getNewsletter($sections),
            'services' => $this->resolver->getServices($site, $pro->id, $sections),
            'booking' => $booking,
        ]))->resolve();
    }

    /**
     * Canonical cache key — includes the site's updated_at so any mutation
     * naturally rolls the key forward. Falls back to the pro's updated_at
     * for early-setup individuals without a Site row.
     */
    public function cacheKey(string $handleLc, ?Site $site, User $pro): string
    {
        $stamp = $site?->updated_at?->timestamp
            ?? $pro->updated_at?->timestamp
            ?? 0;

        return "public.profile:{$handleLc}:{$stamp}";
    }

    public function cacheTtl(): int
    {
        return max(1, (int) config('partna.public_profile.cache_ttl_seconds', 60));
    }
}
