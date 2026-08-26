<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\MenuItemPlatform;
use Illuminate\Support\Collection;

/**
 * Shapes the per-item "order this dish" deep links the menu payloads expose
 * as `links: {uber_eats?, doordash?, square?}` — a link is emitted ONLY when
 * it opens that exact dish on the platform, never a store-level fallback.
 *
 * Since 2026-08-26 the links are STORED, not derived: each platform driver
 * verifies and emits the dish's own item URL at scrape time (Uber Eats
 * itemUuid href, DoorDash `?itemId=`, Square `absolute_site_link`), the
 * projection persists it on the per-platform content.offers row
 * (`item_url`), and this class only translates the platform slug into the
 * sitepage wire key. The old derivation path (composing DoorDash's retired
 * `event_type=item_click` form from menu_items.dd_external_id) is gone —
 * that recipe regressed on DoorDash's side, and dd_external_id was dropped
 * by the slice-7 projection anyway.
 *
 * Wire keys are underscore-cased per the sitepage contract (uber_eats /
 * doordash / square), NOT the menu-registry slug spelling (uber-eats).
 */
final class MenuItemDeepLinks
{
    /** Menu-registry slug → wire key. */
    private const WIRE_KEYS = [
        'uber-eats' => 'uber_eats',
        'doordash' => 'doordash',
        'square' => 'square',
    ];

    /**
     * The dish's stored per-item deep links, keyed by wire key. Empty when no
     * platform carries one (callers emit null then).
     *
     * @param  Collection<int, MenuItemPlatform>  $platformLinks
     * @return array<string, string>
     */
    public static function forItem(Collection $platformLinks): array
    {
        $links = [];
        foreach ($platformLinks as $entry) {
            $url = $entry->item_url;
            if (! is_string($url) || trim($url) === '') {
                continue;
            }
            $key = self::WIRE_KEYS[(string) $entry->platform] ?? null;
            if ($key !== null) {
                $links[$key] = trim($url);
            }
        }

        return $links;
    }
}
