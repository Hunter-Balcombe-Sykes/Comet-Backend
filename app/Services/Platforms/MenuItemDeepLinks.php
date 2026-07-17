<?php

namespace App\Services\Platforms;

/**
 * Builds the per-item "order this dish" deep links the menu payloads expose as
 * `links: {uber_eats?, doordash?}` — a link is emitted ONLY when it opens that
 * exact dish on the platform, never a store-level fallback (the store-level
 * typed order urls already ride on each item's platforms[] rows).
 *
 * Per-platform reality (2026-07-17, verified against the live consumer sites +
 * each Apify actor's output schema):
 *  - doordash: the store page honours the share-link query form
 *    `<store>/?event_type=item_click&item_id=<id>` by auto-opening that item's
 *    modal (verified in-browser against a live AU store). The item id is the
 *    dz_omar actor's per-item `item_id`, persisted as menu_items.dd_external_id.
 *  - uber_eats: memo23's flattened `menuItems` carries NO per-item id (the
 *    ue_external_id column was dropped for exactly that reason, 20260623130000),
 *    and Uber Eats deep links need an itemUuid inside the quickView `modctx`
 *    blob — underivable from the store URL alone. The key is therefore OMITTED
 *    until an actor exposes item uuids; emitting the store URL here would
 *    violate the "real item URLs only" contract.
 *
 * Wire keys are underscore-cased per the sitepage contract (uber_eats /
 * doordash), NOT the registry's slug spelling (uber-eats).
 */
final class MenuItemDeepLinks
{
    /**
     * The per-item deep links derivable for one dish, keyed by wire key.
     * Empty when nothing item-level is knowable (callers emit null then).
     *
     * @param  string|null  $ddExternalId  menu_items.dd_external_id (DoorDash item_id)
     * @param  array<string, string|null>  $storeUrls  registry slug => menu_platform_links.store_url (normalized, query-free)
     * @return array{doordash?: string}
     */
    public static function forItem(?string $ddExternalId, array $storeUrls): array
    {
        $links = [];

        $ddStore = $storeUrls['doordash'] ?? null;
        if (is_string($ddStore) && $ddStore !== '' && is_string($ddExternalId) && trim($ddExternalId) !== '') {
            // store_url is normalized (query + trailing slash stripped), so the
            // appended query can never collide with an existing one.
            $links['doordash'] = rtrim($ddStore, '/').'/?event_type=item_click&item_id='.rawurlencode(trim($ddExternalId));
        }

        return $links;
    }
}
