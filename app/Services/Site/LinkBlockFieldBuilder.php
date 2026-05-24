<?php

namespace App\Services\Site;

use InvalidArgumentException;

/**
 * Translates a validated link-block request payload into Block fillable columns.
 *
 * Centralises the social/custom mode split so store() and update() on
 * ProfessionalLinkBlockController share one source of truth.
 *
 * Social mode produces:
 *   - url       = canonical https URL from the normalizer
 *   - icon_key  = registry icon_key for the platform
 *   - title     = user-supplied OR the platform's display_name
 *   - settings  = user settings + {platform, handle, category} soft tags
 *
 * Custom mode produces:
 *   - url       = as supplied
 *   - icon_key  = as supplied
 *   - title     = as supplied
 *   - settings  = user settings + {category} (required)
 *
 * Category resolution order:
 *   1. Request-provided `category` wins (validated against the enum in the Form Request).
 *   2. Else the platform's default_category (platform-link case).
 *   3. Else throws (custom-link case — validation layer should catch this earlier).
 */
class LinkBlockFieldBuilder
{
    public function __construct(
        private readonly SocialLinkNormalizer $normalizer
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validated request payload
     * @return array<string, mixed> Block fillable fields
     *
     * @throws InvalidArgumentException When social-mode normalization fails or
     *                                  custom-mode category is missing (caller maps to 422)
     */
    public function build(array $data): array
    {
        $platform = $data['platform'] ?? null;
        $requestedCategory = $data['category'] ?? null;

        if ($platform !== null && $platform !== '') {
            $normalized = $this->normalizer->normalize(
                $platform,
                $data['handle'] ?? null,
                $data['url'] ?? null
            );

            // Tag settings.platform + settings.handle so the frontend can
            // re-render the edit form in social mode and so analytics can
            // group by platform later (slow but works without a column).
            $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
            $settings['platform'] = $normalized['platform_key'];
            if ($normalized['handle'] !== null) {
                $settings['handle'] = $normalized['handle'];
            }

            // Category: explicit override wins, else platform default.
            $registry = config("partna.social_platforms.{$normalized['platform_key']}", []);
            $settings['category'] = $requestedCategory ?: ($registry['default_category'] ?? 'other');

            return [
                'title' => ($data['title'] ?? '') !== '' ? $data['title'] : $normalized['display_name'],
                'url' => $normalized['url'],
                'icon_key' => $normalized['icon_key'],
                'settings' => $settings,
            ];
        }

        // Custom mode: category is required by the Form Request. Defensive
        // default here in case a future code path calls build() directly
        // with incomplete data.
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        if ($requestedCategory === null || $requestedCategory === '') {
            throw new InvalidArgumentException('A category is required for custom links.');
        }
        $settings['category'] = $requestedCategory;

        return [
            'title' => $data['title'] ?? null,
            'url' => $data['url'] ?? null,
            'icon_key' => $data['icon_key'] ?? null,
            'settings' => $settings,
        ];
    }
}
