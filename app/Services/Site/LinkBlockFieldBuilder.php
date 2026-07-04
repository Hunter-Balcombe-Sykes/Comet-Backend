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
 *   - platform           = promoted column (column only — not in settings)
 *   - category           = promoted column (column only — not in settings)
 *   - live_check_enabled = promoted column (read from top-level request field)
 *   - handle             = promoted column (FOUND-35) — dual-written alongside
 *                          settings.handle until the settings key is stripped
 *   - settings           = user settings + {handle} JSONB tag only (dual-write)
 *
 * Custom mode produces:
 *   - url                = as supplied
 *   - icon_key           = as supplied
 *   - title              = as supplied
 *   - category           = promoted column (column only)
 *   - live_check_enabled = promoted column (read from top-level request field)
 *   - settings           = user settings (no platform/category/live_check_enabled keys)
 *
 * Category resolution order:
 *   1. Request-provided `category` wins (validated against the enum in the Form Request).
 *   2. Else the platform's default_category (platform-link case).
 *   3. Else throws (custom-link case — validation layer should catch this earlier).
 *
 * Phase 2 (current): platform/category/live_check_enabled are columns only.
 * The settings JSONB no longer carries these keys (stripped by migration 20260701180000).
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
        // Phase 2: live_check_enabled arrives at the top level of the request
        // (the request contract moved from settings.live_check_enabled in Phase 1).
        $liveCheckEnabled = (bool) ($data['live_check_enabled'] ?? false);

        if ($platform !== null && $platform !== '') {
            $normalized = $this->normalizer->normalize(
                $platform,
                $data['handle'] ?? null,
                $data['url'] ?? null
            );

            $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
            // platform/category/live_check_enabled are columns now — never in settings.
            unset($settings['platform'], $settings['category'], $settings['live_check_enabled']);
            if ($normalized['handle'] !== null) {
                $settings['handle'] = $normalized['handle'];
            }

            // Category: explicit override wins, else platform default.
            $registry = config("partna.social_platforms.{$normalized['platform_key']}", []);

            return [
                'title' => ($data['title'] ?? '') !== '' ? $data['title'] : $normalized['display_name'],
                'url' => $normalized['url'],
                'icon_key' => $normalized['icon_key'],
                'platform' => $normalized['platform_key'],
                'category' => $requestedCategory ?: ($registry['default_category'] ?? 'other'),
                'live_check_enabled' => $liveCheckEnabled,
                // FOUND-35: dual-write the column alongside settings.handle above.
                'handle' => $normalized['handle'],
                'settings' => $settings,
            ];
        }

        // Custom mode: category is required by the Form Request. Defensive
        // default here in case a future code path calls build() directly
        // with incomplete data.
        $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
        // Strip promoted keys from settings in case they arrived via old clients.
        unset($settings['platform'], $settings['category'], $settings['live_check_enabled']);
        if ($requestedCategory === null || $requestedCategory === '') {
            throw new InvalidArgumentException('A category is required for custom links.');
        }

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
