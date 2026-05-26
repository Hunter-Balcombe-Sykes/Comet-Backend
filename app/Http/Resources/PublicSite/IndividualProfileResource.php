<?php

namespace App\Http\Resources\PublicSite;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Public-safe shape for an individual professional's profile page (§28.8).
 *
 * Consumed by the Astro Worker subrequest path (partna-pages). The payload
 * mirrors the skeleton-system contract: content lives under `profile`,
 * per-user design vars under `designKit`, and `skeletonId` picks which of
 * skeleton-1..4 to render. partna-pages does the read-time merge of the
 * partial `designKit` with code-side defaults before passing to the skeleton.
 *
 * INTENTIONAL EXCLUSIONS:
 *   - Legacy themeMode / accent / fontFamily / settings.design.* — removed in
 *     the skeleton-system cleanup. The full design surface is now design_kits.
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
     *     skeleton_id?: string|null,
     *     content_images?: list<array<string, mixed>>,
     *     gallery?: array<string, mixed>,
     *     links?: list<array<string, mixed>>,
     *     bio?: array<string, mixed>,
     *     document?: array<string, mixed>,
     *     newsletter?: array<string, mixed>,
     *     services?: array<string, mixed>,
     *     booking?: array<string, mixed>,
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
        return [
            // Content data — the profile itself + sections (links, gallery, etc).
            'profile' => [
                'handle' => $this->handle,
                'display_name' => $this->display_name,
                'site_id' => $this->sections['site_id'] ?? null,

                // Section envelopes + arrays.
                'content_images' => $this->sections['content_images'] ?? [],
                'gallery' => $this->sections['gallery'] ?? [],
                'links' => $this->sections['links'] ?? [],
                'bio' => $this->sections['bio'] ?? [],
                'document' => $this->sections['document'] ?? [],
                'newsletter' => $this->sections['newsletter'] ?? [],
                'services' => $this->sections['services'] ?? [],
                'booking' => $this->sections['booking'] ?? [],
            ],

            // Per-user design kit. Partial — only contains stored (non-null)
            // columns from site.design_kits. partna-pages merges this with
            // DESIGN_KIT_DEFAULTS (code-side) before passing to the skeleton.
            // At this phase the design_kits table has no var columns yet so
            // this is always an empty object.
            'designKit' => $this->sections['design_kit'] ?? [],

            // Which of the four code-side skeletons to render. One of
            // 'skeleton-1', 'skeleton-2', 'skeleton-3', 'skeleton-4'.
            // Falls back to 'skeleton-1' if the site row is missing — same as
            // the column default.
            'skeletonId' => $this->sections['skeleton_id'] ?? 'skeleton-1',
        ];
    }
}
