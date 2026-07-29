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
 * Uber Eats store menu via the memo23 Apify actor (plan §11). Mapping ported
 * from UberEatsMenuDriver: one store object with a FLATTENED `menuItems`
 * list, each item carrying its `section` (category), dollar price, currency
 * and imageUrl — grouped back into categories preserving first-seen order.
 * No per-item id exists, so record keys fall back to category+name digests
 * (MenuRecords), the same limitation the legacy merger name-matched around.
 */
class UberEatsMenuConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('uber_eats'),
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
            'actor' => (string) config('partna.menu.platforms.uber-eats.actor'),
            'input' => ['startUrls' => [['url' => $storeUrl]]],
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
            storeName: Fields::firstString($store, ['title', 'shopName']),
            currency: Fields::firstString($store, ['currencyCode']) ?? 'AUD',
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

    /**
     * Group the flattened items by section, preserving first-seen order.
     *
     * @return list<array{name: string, items: list<array<string, mixed>>}>
     */
    private function categories(array $store): array
    {
        $bySection = [];
        $order = [];

        foreach ((array) ($store['menuItems'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = Fields::firstString($item, ['name']);
            if ($name === null) {
                continue;
            }
            $section = Fields::firstString($item, ['section']) ?? 'Menu';
            if (! isset($bySection[$section])) {
                $bySection[$section] = [];
                $order[] = $section;
            }

            $price = $item['price'] ?? null;
            $bySection[$section][] = [
                'externalId' => null,
                'name' => $name,
                'description' => Fields::firstString($item, ['description']),
                'price' => is_numeric($price) ? round((float) $price, 2) : null,
                'currency' => Fields::firstString($item, ['currency']),
                'image' => Fields::firstString($item, ['imageUrl']),
            ];
        }

        $out = [];
        foreach ($order as $section) {
            $out[] = ['name' => $section, 'items' => $bySection[$section]];
        }

        return $out;
    }
}
