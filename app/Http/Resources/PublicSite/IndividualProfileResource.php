<?php

namespace App\Http\Resources\PublicSite;

use App\Http\Resources\ApiResource;
use App\Models\Core\Site\Site;
use Illuminate\Http\Request;
use stdClass;

/**
 * Public-safe shape for an individual professional's profile page (§28.8).
 *
 * Consumed by the Astro Worker subrequest path (partna-pages). The payload
 * mirrors the skeleton-system contract (spec §3.4 + phase-8 engines):
 *   - `profile` — content (engine fields + base profile)
 *   - `designKit` — per-user design vars (nested camelCase), partial
 *   - `designMedia` — content-pool media (polymorphic image/video, camelCase, ordered)
 *   - `skeletonId` — picks which code-side skeleton renders
 *   - `publicConfig` — analytics endpoint + platform-wide keys
 *
 * partna-pages does the read-time merge of the partial `designKit` with
 * code-side defaults before passing to the skeleton.
 *
 * Each engine field falls back to a stable empty state:
 *   - object engines (bio, document, newsletter) → null when nothing authored
 *   - list engines (gallery, links, services) → empty array
 *
 * Booking is a link-engine category, not a separate field — `bucketLinks`
 * in @partnaau/design-system splits the list at render time.
 *
 * INTENTIONAL EXCLUSIONS:
 *   - Legacy themeMode / accent / fontFamily / settings.design.* — removed in
 *     the skeleton-system cleanup. The full design surface is now design_kits.
 *   - Legacy `profile.content_images` — promoted to top-level `designMedia`
 *     and made polymorphic (images + videos, camelCase). See spec 2026-05-30.
 *   - PII (primary_email, phone, auth_user_id, street address)
 *   - Anything brand- or commerce-related (the platform is individual-only).
 */
class IndividualProfileResource extends ApiResource
{
    /**
     * Single associative payload — keys mirror the output shape 1-to-1.
     * Missing keys degrade to sensible empties so the Resource never crashes
     * on a partial build.
     *
     * @param  array{
     *     site_id?: string|null,
     *     design_kit?: array<string, mixed>,
     *     design_media?: list<array<string, mixed>>,
     *     skeleton_id?: string|null,
     *     public_config?: array<string, mixed>,
     *     bio?: array<string, mixed>|null,
     *     gallery?: list<array<string, mixed>>,
     *     links?: list<array<string, mixed>>,
     *     services?: list<array<string, mixed>>,
     *     document?: array<string, mixed>|null,
     *     newsletter?: array<string, mixed>|null,
     *     contact?: array<string, mixed>|null,
     *     workplace?: array<string, mixed>|null,
     * }  $sections
     */
    public function __construct(
        $resource,
        private readonly array $sections = [],
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        // Empty designKit must serialize as `{}` (object), not `[]` (array).
        // PHP's array → JSON encoder emits `[]` for any empty associative
        // array; cast to stdClass when there are no stored vars so the wire
        // payload matches the spec contract (designKit is always an object).
        $designKit = $this->sections['design_kit'] ?? [];
        $designKitOut = $designKit === [] ? new stdClass : $designKit;

        // Same empty-object coercion as $designKit above.
        $publicConfig = $this->sections['public_config'] ?? [];
        $publicConfigOut = $publicConfig === [] ? new stdClass : $publicConfig;

        // Same empty-object coercion as $designKit above.
        $siteImages = $this->sections['site_images'] ?? [];
        $siteImagesOut = $siteImages === [] ? new stdClass : $siteImages;

        // Engine fields preserve null-vs-array distinction precisely:
        //   - bio/document/newsletter: null when no data is authored.
        //   - gallery/links/services: always emitted as an array.
        return [
            // Content data — the profile itself + engine outputs. camelCase
            // keys for engine fields per spec §5 wire convention.
            'profile' => [
                'handle' => $this->handle,
                'displayName' => $this->display_name,
                'site_id' => $this->sections['site_id'] ?? null,

                // Engine outputs (phase 8).
                'bio' => $this->sections['bio'] ?? null,
                'gallery' => $this->sections['gallery'] ?? [],
                'links' => $this->sections['links'] ?? [],
                'services' => $this->sections['services'] ?? [],
                'document' => $this->sections['document'] ?? null,
                'newsletter' => $this->sections['newsletter'] ?? null,
                'contact' => $this->sections['contact'] ?? null,
                'workplace' => $this->sections['workplace'] ?? null,
                'smartLinks' => $this->sections['smart_links'] ?? [],
            ],

            // Per-user design kit. Partial — only contains stored (non-null)
            // columns from site.design_kits, mapped from flat snake_case DB
            // columns to nested camelCase groups. partna-pages merges this with
            // DESIGN_KIT_DEFAULTS (code-side) before passing to the skeleton.
            'designKit' => $designKitOut,

            // Design-layer media — polymorphic image/video items ordered by
            // sortOrder. The skeleton paints with these (backgrounds, section
            // covers, decorative imagery). Always an array. Wire shape is
            // camelCase per §5 convention; the builder's buildDesignMedia()
            // remaps the resolver's snake_case before it lands here. See spec
            // docs/superpowers/specs/2026-05-30-design-media-promotion-design.md.
            'designMedia' => $this->sections['design_media'] ?? [],

            // Which of the four code-side skeletons to render. One of
            // 'skeleton-1', 'skeleton-2', 'skeleton-3', 'skeleton-4'.
            'skeletonId' => $this->sections['skeleton_id'] ?? Site::DEFAULT_SKELETON_ID,

            // Platform-wide knobs the skeleton needs at render time (analytics
            // endpoint, etc.). Always an object.
            'publicConfig' => $publicConfigOut,

            // Site image singletons — brand logos + per-integration cover
            // images, keyed by camelCase purpose (logoFull, coverFresha, ...).
            // Always an object. partna-pages reads logos for the profile and
            // covers per integration; rendering is the theme's concern.
            'siteImages' => $siteImagesOut,
        ];
    }
}
