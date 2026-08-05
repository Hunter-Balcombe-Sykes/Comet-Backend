<?php

namespace App\Services\Platforms;

use Illuminate\Support\Arr;

/**
 * Single source of truth for how a connected platform's per-section display
 * toggles (PlatformDescriptor::displayToggles, stored sparsely in
 * site.platform_connections.display_settings — absent key = the toggle's
 * declared default, ON unless TOGGLE_DEFAULTS below says otherwise) translate
 * into payload-key suppression.
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
            // WS-F4: the dashboard Location map keys off `placeId` and the street-view
            // panel off `streetView` ({panoId,lat,lng}) — both must drop with the
            // section, or turning "Location & map" OFF still renders the map the
            // Controls copy promises it hides. (persist paths preserve placeId as the
            // refresh identity key — see GoogleBusinessFetch merge + GoogleBusinessEnrichJob.)
            'location' => ['address', 'lat', 'lng', 'addressParts', 'placeId', 'streetView'],
            'menu' => ['menu'],
        ],
        'instagram' => [
            'auto_sync_latest' => ['images', 'videoUrl', 'videoPoster', 'imagesDropped'],
        ],
        // The full releases list is stored on every scrape but only SERVED when
        // the owner opted in — show_all_releases is default-OFF (see
        // TOGGLE_DEFAULTS), so an absent key suppresses, the inverse of every
        // other toggle. Flipping the switch is therefore instant (read-time
        // reveal), no re-scrape needed.
        'bandcamp' => [
            'show_all_releases' => ['releases'],
        ],
    ];

    /**
     * Per-toggle defaults for the suppression check. Absent = ON (the historical
     * rule); a false entry makes the toggle opt-IN — its keys are suppressed
     * until the owner explicitly enables it. Kept in lockstep with the
     * 'default' field on the registry's displayToggles defs.
     *
     * @var array<string, array<string, bool>>
     */
    private const TOGGLE_DEFAULTS = [
        'bandcamp' => ['show_all_releases' => false],
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

        $out = [];
        foreach (self::SUPPRESSIONS[$platform] ?? [] as $toggle => $keys) {
            // Absent = the toggle's declared default (ON unless TOGGLE_DEFAULTS
            // says otherwise); a stored value always wins. The old empty-settings
            // early-return is gone: a default-OFF toggle must suppress even when
            // nothing was ever stored.
            $default = self::TOGGLE_DEFAULTS[$platform][$toggle] ?? true;
            if ((bool) ($settings[$toggle] ?? $default) === false) {
                foreach ($keys as $key) {
                    $out[] = $key;
                }
            }
        }

        return $out;
    }
}
