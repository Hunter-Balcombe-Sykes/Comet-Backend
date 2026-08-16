<?php

namespace App\Ingest\Connectors;

use App\Ingest\Landing\Coverage;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Message;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;
use App\Ingest\Support\Fields;
use App\Ingest\Support\MenuRecords;

/**
 * DoorDash store menu via the dz_omar Apify actor (plan §11). Mapping ported
 * from DoorDashMenuDriver: one store object with `menu_categories[]` →
 * items[] where price arrives in cents (`price_cents`) with an "A$15.30"
 * display-string fallback. `featured_items` is skipped — it re-lists items
 * already in the categories.
 *
 * DoorDash results are location-specific; without a consumer address the
 * actor defaults to Chicago, IL. The AU fallback locale keeps results
 * Australian — the specific store URL still resolves regardless of
 * delivery range (the same reasoning the legacy driver shipped).
 */
class DoordashMenuConnector implements Connector
{
    private const FALLBACK_ADDRESS = 'Melbourne VIC, Australia';

    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('doordash'),
            identifierKind: 'url',
            hosts: [],
            streams: [
                'menu' => new StreamSpec(
                    name: 'menu',
                    target: 'menu_item',
                    profile: SourceProfile::Catalogue,
                    requires: ['name', 'category'],
                    volatile: [],
                    orderField: null,
                ),
            ],
            cost: CostClass::Actor,
            defaultIntervalSeconds: 172800,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        $storeUrl = trim($pull->identifier);

        $effect = $io->effect('actor', 'menu', [
            'actor' => (string) config('partna.menu.platforms.doordash.actor'),
            'input' => [
                'startUrls' => [['url' => $storeUrl]],
                'address' => self::FALLBACK_ADDRESS,
                'fetchReviews' => false,
            ],
            // The key categories() reads — see UberEatsMenuConnector for why the
            // driver needs telling. featured_items is deliberately not listed:
            // it re-lists items already in the categories, so a payload with
            // only that is still a menu this connector maps to nothing.
            'expect' => ['menu_categories'],
        ]);

        if (($effect['status'] ?? null) !== 'ok') {
            yield new Unavailable("menu actor effect returned status '{$effect['status']}'");

            return;
        }

        $store = is_array($effect['data']) ? ($effect['data'][0] ?? null) : null;
        if (! is_array($store)) {
            yield new Unavailable('menu actor returned no dataset item');

            return;
        }

        $records = MenuRecords::flatten(
            $this->categories($store),
            storeName: Fields::firstString($store, ['name']),
            currency: Fields::firstString($store, ['currency']) ?? 'AUD',
        );

        if ($records === []) {
            yield new Note('empty_menu', 'No menu items parsed from the actor result');

            return;
        }

        foreach ($records as $record) {
            yield new Record('menu', $record['key'], $record['doc']);
        }

        yield new Covered('menu', Coverage::exhaustive());
    }

    /** @return list<array{name: string, items: list<array<string, mixed>>}> */
    private function categories(array $store): array
    {
        $out = [];
        foreach ((array) ($store['menu_categories'] ?? []) as $category) {
            if (! is_array($category)) {
                continue;
            }
            $items = [];
            foreach ((array) ($category['items'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $cents = $item['price_cents'] ?? null;
                $items[] = [
                    'externalId' => Fields::firstString($item, ['item_id']),
                    'name' => Fields::firstString($item, ['name']),
                    'description' => Fields::firstString($item, ['description']),
                    'price' => is_numeric($cents)
                        ? round(((int) $cents) / 100, 2)
                        : $this->parseDisplayPrice($item['price_display'] ?? null),
                    'image' => Fields::firstString($item, ['image_url']),
                ];
            }
            $out[] = [
                'name' => Fields::firstString($category, ['category_name', 'name']) ?? 'Menu',
                'items' => $items,
            ];
        }

        return $out;
    }

    /** "A$15.30" display strings when price_cents is absent. */
    private function parseDisplayPrice(mixed $value): ?float
    {
        if (is_string($value) && preg_match('~([0-9]+(?:\.[0-9]+)?)~', str_replace(',', '', $value), $m) === 1) {
            return round((float) $m[1], 2);
        }

        return null;
    }
}
