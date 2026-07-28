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
use App\Ingest\Support\MenuRecords;

/**
 * Square Online ordering menu via the menus-r-us Apify actor (plan §11 —
 * budget key exists: partna.limits.apify.actors.menu). Item mapping ported
 * verbatim-in-spirit from SquareMenuDriver: one restaurant object whose
 * `menu.categories[]` (or top-level `categories[]`) carry items with name,
 * string-or-cents price, description, image. Square stores serve the same
 * menu everywhere, so no locale input.
 *
 * `hosts` is empty: everything arrives through the ledgered actor effect —
 * there is no HTTP path. CostClass::Actor keeps the source unscheduled
 * until the P7 driver lands.
 */
class SquareMenuConnector implements Connector
{
    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('square'),
            identifierKind: 'url',
            hosts: [],
            streams: [
                'menu' => new StreamSpec(
                    name: 'menu',
                    target: 'menu_item',
                    profile: SourceProfile::Catalogue,
                    requires: ['name', 'category'],
                    volatile: [],
                    // Menus have no time order to reason about a prefix with,
                    // so absence can never delete (Fresha precedent).
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
            'actor' => (string) config('partna.menu.platforms.square.actor'),
            'input' => ['mode' => 'url', 'url' => $storeUrl, 'freshness' => 'med_cache'],
        ]);

        if (($effect['status'] ?? null) !== 'ok') {
            yield new Unavailable("menu actor effect returned status '{$effect['status']}'");

            return;
        }

        $data = is_array($effect['data']) ? ($effect['data'][0] ?? null) : null;
        if (! is_array($data)) {
            yield new Unavailable('menu actor returned no dataset item');

            return;
        }

        $records = MenuRecords::flatten(
            $this->categories($data),
            storeName: $this->firstString($data, ['restaurantName', 'name']),
            // Square's actor emits no currency; AUD is the pilot's market
            // (the same assumption SquareMenuDriver shipped).
            currency: 'AUD',
        );

        if ($records === []) {
            // An empty parse is a layout/actor change or a genuinely empty
            // menu — either way no coverage, nothing can be tombstoned (C5).
            yield new Note('empty_menu', 'No menu items parsed from the actor result');

            return;
        }

        foreach ($records as $record) {
            yield new Record('menu', $record['key'], $record['doc']);
        }

        // The actor walks the whole store menu — exhaustive when it returns
        // at all (deletion stays off via the null orderField regardless).
        yield new Covered('menu', Coverage::exhaustive());
    }

    /** @return list<array{name: string, items: list<array<string, mixed>>}> */
    private function categories(array $data): array
    {
        $menu = is_array($data['menu'] ?? null) ? $data['menu'] : $data;

        $out = [];
        foreach ((array) ($menu['categories'] ?? []) as $category) {
            if (! is_array($category)) {
                continue;
            }
            $items = [];
            foreach ((array) ($category['items'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $items[] = [
                    'externalId' => $this->firstString($item, ['id', 'externalId']),
                    'name' => $this->firstString($item, ['name']),
                    'description' => $this->firstString($item, ['description']),
                    'price' => $this->parsePrice($item['price'] ?? null),
                    'image' => $this->firstString($item, ['image', 'imageUrl', 'image_url']),
                ];
            }
            $out[] = ['name' => (string) ($category['name'] ?? 'Menu'), 'items' => $items];
        }

        return $out;
    }

    /** Best-effort price → dollars float: "$15.30", "15.30", or 1530 cents. */
    private function parsePrice(mixed $value): ?float
    {
        if (is_string($value)) {
            $clean = str_replace(['$', 'A$', 'AU$', ',', ' '], '', $value);

            return is_numeric($clean) ? round((float) $clean, 2) : null;
        }
        if (is_int($value)) {
            return round($value / 100, 2);
        }
        if (is_float($value)) {
            return round($value, 2);
        }

        return null;
    }

    /** @param list<string> $keys */
    private function firstString(array $item, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $item[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
