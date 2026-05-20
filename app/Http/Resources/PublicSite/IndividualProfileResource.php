<?php

namespace App\Http\Resources\PublicSite;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
class IndividualProfileResource extends JsonResource
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
        'border_thickness',
        'section_spacing',
        'brand_colors',
        'colors',
    ];

    /**
     * @param  array<string, mixed>  $design
     * @param  list<array<string, mixed>>  $contentImages
     * @param  array<string, mixed>  $gallery
     * @param  list<array<string, mixed>>  $links
     * @param  array<string, mixed>  $bio
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $newsletter
     * @param  array<string, mixed>  $services
     * @param  array<string, mixed>  $booking
     */
    public function __construct(
        $resource,
        private readonly array $design,
        private readonly array $contentImages,
        private readonly array $gallery,
        private readonly array $links,
        private readonly array $bio,
        private readonly array $document,
        private readonly array $newsletter,
        private readonly array $services,
        private readonly array $booking,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'handle' => $this->handle,
            'display_name' => $this->display_name,
            'location' => [
                'city' => $this->location_city,
                'state' => $this->location_state,
                'country' => $this->location_country,
            ],
            'design' => $this->design,

            // Section envelopes + arrays, mirroring HydrogenAffiliateController::show.
            'content_images' => $this->contentImages,
            'gallery' => $this->gallery,
            'links' => $this->links,
            'bio' => $this->bio,
            'document' => $this->document,
            'newsletter' => $this->newsletter,
            'services' => $this->services,
            'booking' => $this->booking,

            // Shop is structurally always-draft for individuals — they have no
            // commerce surface. Emit the envelope so consumers can treat all
            // section keys uniformly without special-casing missing keys.
            'shop' => ['state' => 'draft', 'data' => null],
        ];
    }
}
