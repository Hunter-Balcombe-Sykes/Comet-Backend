<?php

namespace App\Services\PublicSite;

use App\Http\Resources\PublicSite\IndividualProfileResource;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
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
 * Payload shape (skeleton system, spec §3.4):
 *   {
 *     profile: { handle, displayName, site_id, ...sections },
 *     designKit: { colors: {...}, typography: {...}, ... },  // partial, nested
 *     skeletonId: 'skeleton-1' | ... | 'skeleton-4',
 *     publicConfig: { analyticsEndpoint, ... },
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
     *   - platform-wide publicConfig fields (analytics endpoint, etc.)
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
            'public_config' => $this->buildPublicConfig(),
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
     * Read the user's design_kit row and project the stored (non-null) columns
     * into the nested camelCase wire shape (spec §5). DB columns are flat
     * snake_case with a group prefix (e.g. `color_accent`,
     * `typography_font_heading`); we group by prefix and camelCase the
     * remainder of the key.
     *
     * Returns an empty array if the site is missing, the kit row doesn't
     * exist (trigger should auto-insert one but the belt is cheap), or no
     * columns have been stored yet. partna-pages fills the gaps from its
     * code-side DESIGN_KIT_DEFAULTS via mergeDesignKit().
     *
     * Example output:
     *   { colors: { accent: '#ff0000' }, typography: { fontHeading: 'inter' } }
     *
     * @return array<string, array<string, mixed>>
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

        $stored = array_filter($cols, fn ($v) => $v !== null);
        if ($stored === []) {
            return [];
        }

        return $this->groupKitColumns($stored);
    }

    /**
     * Take a flat snake_case → value map (e.g. ['color_accent' => '#fff',
     * 'typography_font_heading' => 'inter']) and return the nested camelCase
     * wire shape (e.g. ['colors' => ['accent' => '#fff'], 'typography' =>
     * ['fontHeading' => 'inter']]).
     *
     * Group name is the snake_case prefix before the first underscore,
     * pluralised to match the spec §5 group keys (color → colors,
     * typography → typography, border → borders, etc.). The remainder is
     * camelCased.
     *
     * @param  array<string, mixed>  $cols
     * @return array<string, array<string, mixed>>
     */
    private function groupKitColumns(array $cols): array
    {
        // Map of DB column-prefix → wire group key. Pluralisation isn't
        // mechanical (typography stays singular), so the map is explicit.
        $groupMap = [
            'color' => 'colors',
            'typography' => 'typography',
            'border' => 'borders',
            'spacing' => 'spacing',
            'padding' => 'padding',
            'motion' => 'motion',
            'icon' => 'icons',
            'effect' => 'effects',
        ];

        $out = [];
        foreach ($cols as $column => $value) {
            $underscorePos = strpos($column, '_');
            if ($underscorePos === false) {
                // No prefix → no group. Skip; spec §5 groups every var.
                continue;
            }

            $prefix = substr($column, 0, $underscorePos);
            $rest = substr($column, $underscorePos + 1);
            $group = $groupMap[$prefix] ?? null;
            if ($group === null) {
                continue;
            }

            // snake_case → camelCase for the remainder.
            $key = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $rest))));
            $out[$group][$key] = $value;
        }

        return $out;
    }

    /**
     * Build the publicConfig object — platform-wide knobs the skeleton needs
     * at render time. Currently only analyticsEndpoint; other fields will be
     * added as features need them.
     *
     * @return array<string, mixed>
     */
    private function buildPublicConfig(): array
    {
        return [
            'analyticsEndpoint' => config('partna.public_profile.analytics_endpoint'),
        ];
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
