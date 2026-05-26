<?php

namespace App\Services\PublicSite;

use App\Http\Resources\PublicSite\IndividualProfileResource;
use App\Models\Core\User\User;
use App\Models\Core\Site\Site;
use Illuminate\Support\Facades\DB;

/**
 * Pure projection helper — assembles the §28.8 public profile payload from a
 * User + Site without owning the cache. The controller and the warm
 * job both consume this so the two paths can't drift on field shape.
 *
 * Cache wrapper (CacheLockService::rememberLocked) is the caller's concern;
 * this class exposes the canonical cache key + TTL so both call sites stay
 * aligned on key shape.
 *
 * Payload shape (post-skeleton-cleanup):
 *   {
 *     profile: { handle, display_name, site_id, ...sections },
 *     designKit: { ...partial design vars from site.design_kits },
 *     skeletonId: 'skeleton-1' | ... | 'skeleton-4',
 *   }
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
     * Build the §28.8 resolved payload. Reads:
     *   - the user's content sections via SitepageDataResolverService
     *   - the per-user design_kit row (partial — only stored non-null cols)
     *   - the site's skeleton_id (TEXT enum)
     *
     * @return array<string, mixed>
     */
    public function build(User $pro, ?Site $site): array
    {
        $sections = $this->resolver->loadSections($site);
        $booking = $this->resolver->getBooking($site, $sections);

        return (new IndividualProfileResource($pro, [
            'site_id' => $site?->id,
            'design_kit' => $this->loadDesignKit($site),
            'skeleton_id' => $site?->skeleton_id ?? 'skeleton-1',
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
     * Read the user's design_kit row as an associative array. All columns
     * except `site_id` are returned (var columns are added incrementally per
     * layer-sweep step 4). NULL values are stripped — partna-pages fills the
     * gaps from its code-side DESIGN_KIT_DEFAULTS.
     *
     * Returns an empty array if the site is missing or the kit row doesn't
     * exist (the trigger guarantees one per site, but the safety belt is
     * cheap).
     *
     * @return array<string, mixed>
     */
    private function loadDesignKit(?Site $site): array
    {
        if (! $site) {
            return [];
        }

        $row = DB::connection('pgsql')
            ->table('site.design_kits')
            ->where('site_id', $site->id)
            ->first();

        if (! $row) {
            return [];
        }

        // Convert stdClass → array, drop the FK column + any null values
        // (partna-pages fills nulls from code-side defaults).
        $cols = (array) $row;
        unset($cols['site_id']);

        return array_filter($cols, fn ($v) => $v !== null);
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
