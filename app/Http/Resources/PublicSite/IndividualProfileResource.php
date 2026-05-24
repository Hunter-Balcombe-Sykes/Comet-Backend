<?php

namespace App\Http\Resources\PublicSite;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Public-safe shape for an individual professional's profile page (§28.8).
 *
 * Consumed by the Astro Worker subrequest path. Mirrors the Hydrogen
 * affiliate response (`HydrogenAffiliateController::show`) minus brand-
 * fallback content (placeholders / fallback_gallery / brand_logo /
 * brand_slogan) and minus the shop section — both intentionally excluded
 * for individuals. Feature tests assert those keys remain absent.
 *
 * INTENTIONAL EXCLUSIONS (audit TEST-4 / PublicProfileShapeTest):
 *   - placeholders, fallback_gallery, brand_logo, brand_slogan (brand-only)
 *   - shop, products, cart, commission, order fields (commerce)
 *   - PII (primary_email, phone, auth_user_id, street address)
 */
class IndividualProfileResource extends ApiResource
{
    /**
     * Whitelist of allowed `settings.design.*` keys (audit PROF-2). Any key
     * not in this list is filtered out at the controller layer before the
     * Resource sees it. Adding a new public design field requires an
     * explicit entry here — silent expansion is the exact failure mode
     * this constant prevents.
     *
     * @var list<string>
     */
    public const DESIGN_KEYS = [
        'theme',
        'theme_mode',
        'accent_color',
        'background_color',
        'font_family',
        'font_size',
        'layout',
        'border_radius',
        'corner_radius',
        'border_thickness',
        'section_spacing',
        'brand_colors',
        'colors',
    ];

    /**
     * Single associative payload (#P3-01) instead of 9 positional arrays.
     * Keys mirror the output shape 1-to-1; missing keys degrade to [] so the
     * Resource never crashes on a partial build.
     *
     * @param  array{
     *     design?: array<string, mixed>,
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
            'handle' => $this->handle,
            'display_name' => $this->display_name,
            'design' => $this->sections['design'] ?? [],

            // Section envelopes + arrays, mirroring HydrogenAffiliateController::show.
            'content_images' => $this->sections['content_images'] ?? [],
            'gallery' => $this->sections['gallery'] ?? [],
            'links' => $this->sections['links'] ?? [],
            'bio' => $this->sections['bio'] ?? [],
            'document' => $this->sections['document'] ?? [],
            'newsletter' => $this->sections['newsletter'] ?? [],
            'services' => $this->sections['services'] ?? [],
            'booking' => $this->sections['booking'] ?? [],

            // Shop is structurally always-draft for individuals — they have no
            // commerce surface. Emit the envelope so consumers can treat all
            // section keys uniformly without special-casing missing keys.
            'shop' => ['state' => 'draft', 'data' => null],
        ];
    }
}
