<?php

namespace App\Services\Platforms;

use Illuminate\Support\Arr;

/**
 * Single source of truth for how a connected platform's per-section display
 * toggles (PlatformDescriptor::displayToggles, stored sparsely in
 * site.platform_connections.display_settings — absent key = ON) translate into
 * payload-key suppression.
 *
 * This map historically lived in PublicIntegrationConnectionResource and only
 * gated the PUBLIC sitepage. WS-B2 widened the toggles' meaning from "hide on
 * the sitepage" to "don't serve / don't refresh": the SAME map now also gates
 * the dashboard Google Business card (GoogleBusinessController) and the
 * scheduled refresh (GoogleBusinessFetch), so a section the owner switched off
 * genuinely disappears from every response instead of only the public wire.
 */
final class DisplaySettingsFilter
{
    /**
     * toggle key => the payload keys it removes when switched off. `menu` is
     * included for the dashboard/refresh paths; on the public integrations
     * resource it is a harmless no-op (menu isn't in that allowlist — it ships
     * via the separate PublicMenuController, which runs its own menu gate).
     *
     * @var array<string, array<string, list<string>>>
     */
    private const SUPPRESSIONS = [
        'google-business' => [
            'reviews' => ['reviews', 'reviewSummary', 'rating', 'reviewCount'],
            'hours' => ['hours', 'currentHours'],
            'photos' => ['photos'],
            'location' => ['address', 'lat', 'lng', 'addressParts'],
            'menu' => ['menu'],
        ],
        'instagram' => [
            'gallery' => ['images', 'videoUrl', 'videoPoster', 'imagesDropped'],
        ],
    ];

    /**
     * Drop every payload key belonging to a switched-off toggle. A null/empty
     * settings map (the common case — nothing ever toggled) returns the payload
     * untouched.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $settings  the connection's display_settings
     * @return array<string, mixed>
     */
    public static function suppress(string $platform, array $payload, ?array $settings): array
    {
        $keys = self::disabledKeys($platform, $settings);

        return $keys === [] ? $payload : Arr::except($payload, $keys);
    }

    /**
     * The flat list of payload keys to remove for $platform given $settings —
     * the union of every switched-off toggle's key set. Used directly by the
     * refresh strategy to strip disabled sections out of a fresh snapshot before
     * it is persisted (so storage is never updated with data we won't serve).
     *
     * @param  array<string, mixed>|null  $settings
     * @return list<string>
     */
    public static function disabledKeys(string $platform, ?array $settings): array
    {
        $settings = (array) ($settings ?? []);
        if ($settings === []) {
            return [];
        }

        $out = [];
        foreach (self::SUPPRESSIONS[$platform] ?? [] as $toggle => $keys) {
            // Absent OR true = ON; only an explicit false suppresses.
            if (($settings[$toggle] ?? true) === false) {
                foreach ($keys as $key) {
                    $out[] = $key;
                }
            }
        }

        return $out;
    }
}
