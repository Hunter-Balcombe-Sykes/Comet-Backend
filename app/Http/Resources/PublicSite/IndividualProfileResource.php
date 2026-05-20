<?php

namespace App\Http\Resources\PublicSite;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-safe shape for an individual professional's profile page (§28.8).
 *
 * Consumed by the Astro Worker subrequest path. INTENTIONAL EXCLUSIONS
 * (audit TEST-4 — feature test asserts these keys are ABSENT):
 *   - placeholders, fallback_gallery, brand_logo, brand_slogan (brand-only)
 *   - product/cart/commission/order fields (commerce — not applicable)
 *   - PII (primary_email, phone, auth_user_id, street address)
 *
 * Constructed with `(Professional $pro, array $design, array $blocks)`.
 */
class IndividualProfileResource extends JsonResource
{
    /**
     * Whitelist of allowed `settings.design.*` keys returned to the public payload
     * (audit PROF-2). Any key not in this list is filtered out of `$design` at
     * the controller layer before the Resource sees it. Adding a new public
     * design field requires an explicit entry here — silent expansion is the
     * exact failure mode this constant prevents.
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
        'brand_colors',
    ];

    /**
     * @param  array<string, mixed>  $design
     * @param  list<array<string, mixed>>  $blocks
     */
    public function __construct(
        $resource,
        private readonly array $design,
        private readonly array $blocks,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'handle' => $this->handle,
            'display_name' => $this->display_name,
            'bio' => $this->bio,
            'location' => [
                'city' => $this->location_city,
                'state' => $this->location_state,
                'country' => $this->location_country,
            ],
            'design' => $this->design,
            'blocks' => $this->blocks,
        ];
    }
}
