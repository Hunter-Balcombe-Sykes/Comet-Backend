<?php

namespace App\Services\Site;

use InvalidArgumentException;

/**
 * Translates a validated link-block request payload into Block fillable columns.
 *
 * Centralises the social/custom mode split so store() and update() on
 * UserLinkBlockController share one source of truth.
 *
 * Social mode produces:
 *   - url                = canonical https URL from the normalizer
 *   - icon_key           = registry icon_key for the platform
 *   - title              = user-supplied OR the platform's display_name
 *   - platform           = promoted column (Phase 1 dual-write)
 *   - category           = promoted column (Phase 1 dual-write)
 *   - live_check_enabled = promoted column (Phase 1 dual-write)
 *   - settings           = user settings + {platform, handle, category} mirror tags
 *                          (mirror stays until Phase 2 strips the JSONB keys)
 *
 * Custom mode produces:
 *   - url                = as supplied
 *   - icon_key           = as supplied
 *   - title              = as supplied
 *   - category           = promoted column (Phase 1 dual-write)
 *   - live_check_enabled = promoted column (Phase 1 dual-write)
 *   - settings           = user settings + {category} mirror (Phase 1 only)
 *
 * Category resolution order:
 *   1. Request-provided `category` wins (validated against the enum in the Form Request).
 *   2. Else the platform's default_category (platform-link case).
 *   3. Else throws (custom-link case — validation layer should catch this earlier).
 *
 * Phase 1 dual-write: all three promoted keys are returned as top-level fields
 * (Block column writes) AND kept in settings so the unchanged views, injector,
 * and resource continue reading settings JSONB. Phase 2 drops the settings mirror.
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

        // Phase 1: live_check_enabled still arrives nested at settings.live_check_enabled
        // (the request contract changes to top-level in Phase 2). Read it here so both
        // write modes populate the new column.
        $liveCheckEnabled = (bool) ($data['settings']['live_check_enabled'] ?? false);

        if ($platform !== null && $platform !== '') {
            $normalized = $this->normalizer->normalize(
                $platform,
                $data['handle'] ?? null,
                $data['url'] ?? null
            );

            // settings keeps handle + the Phase-1 platform/category mirror so the
            // unchanged views, injector, and resource keep emitting these values.
            $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
            $settings['platform'] = $normalized['platform_key'];
            if ($normalized['handle'] !== null) {
                $settings['handle'] = $normalized['handle'];
            }

            // Category: explicit override wins, else platform default.
            $registry = config("partna.social_platforms.{$normalized['platform_key']}", []);
            $resolvedCategory = $requestedCategory ?: ($registry['default_category'] ?? 'other');
            $settings['category'] = $resolvedCategory;

            return [
                'title' => ($data['title'] ?? '') !== '' ? $data['title'] : $normalized['display_name'],
                'url' => $normalized['url'],
                'icon_key' => $normalized['icon_key'],
                'platform' => $normalized['platform_key'],
                'category' => $resolvedCategory,
                'live_check_enabled' => $liveCheckEnabled,
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
        // Mirror category into settings (Phase 1 dual-write).
        $settings['category'] = $requestedCategory;

        return [
            'title' => $data['title'] ?? null,
            'url' => $data['url'] ?? null,
            'icon_key' => $data['icon_key'] ?? null,
            'category' => $requestedCategory,
            'live_check_enabled' => $liveCheckEnabled,
            'settings' => $settings,
        ];
    }
}
