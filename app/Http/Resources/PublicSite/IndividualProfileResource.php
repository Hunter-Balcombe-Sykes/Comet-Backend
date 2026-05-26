<?php

namespace App\Http\Resources\PublicSite;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;
use stdClass;

/**
 * Public-safe shape for an individual professional's profile page (§28.8).
 *
 * Consumed by the Astro Worker subrequest path (partna-pages). The payload
 * mirrors the skeleton-system contract (spec §3.4):
 *   - `profile` — content (incrementally added by engines)
 *   - `designKit` — per-user design vars (nested camelCase), partial
 *   - `skeletonId` — picks which code-side skeleton renders
 *   - `publicConfig` — analytics endpoint + platform-wide keys
 *
 * partna-pages does the read-time merge of the partial `designKit` with
 * code-side defaults before passing to the skeleton.
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
     *     public_config?: array<string, mixed>,
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
        // Empty designKit must serialize as `{}` (object), not `[]` (array).
        // PHP's array → JSON encoder emits `[]` for any empty associative
        // array; cast to stdClass when there are no stored vars so the wire
        // payload matches the spec contract (designKit is always an object).
        $designKit = $this->sections['design_kit'] ?? [];
        $designKitOut = $designKit === [] ? new stdClass : $designKit;

        // Same story for publicConfig — always an object, even if every field
        // is absent. Empty associative arrays would JSON-encode to `[]`.
        $publicConfig = $this->sections['public_config'] ?? [];
        $publicConfigOut = $publicConfig === [] ? new stdClass : $publicConfig;

        return [
            // Content data — the profile itself + sections (links, gallery, etc).
            // camelCase keys per spec §5 wire convention. The first content
            // field exposed to skeletons is displayName (sourced from
            // core.users.display_name). Future engines extend `profile`.
            'profile' => [
                'handle' => $this->handle,
                'displayName' => $this->display_name,
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
            // columns from site.design_kits, mapped from flat snake_case DB
            // columns to nested camelCase groups (e.g. color_accent →
            // colors.accent). partna-pages merges this with DESIGN_KIT_DEFAULTS
            // (code-side) before passing to the skeleton.
            'designKit' => $designKitOut,

            // Which of the four code-side skeletons to render. One of
            // 'skeleton-1', 'skeleton-2', 'skeleton-3', 'skeleton-4'.
            // Falls back to 'skeleton-1' if the site row is missing — same as
            // the column default.
            'skeletonId' => $this->sections['skeleton_id'] ?? 'skeleton-1',

            // Platform-wide knobs the skeleton needs at render time (analytics
            // endpoint, etc.). Always an object — empty fields stay absent
            // rather than nulled-out, so the contract is "what's present is
            // current; everything else is unset".
            'publicConfig' => $publicConfigOut,
        ];
    }
}
