<?php

namespace App\Services\Platforms;

// Uber Eats (memo23~uber-eats-scraper) scrape driver (FOUND-23). buildInput()
// and mapItems() are moved VERBATIM off MenuApifyScraper — byte-identical
// behavior, just relocated behind the MenuPlatformDriver interface.
class UberEatsMenuDriver implements MenuPlatformDriver
{
    use NormalizesMenuData;

    /** Uber Eats (memo23): startUrls[{url}] — no consumer address needed. */
    public function buildInput(string $storeUrl, ?string $address): array
    {
        return ['startUrls' => [['url' => $storeUrl]]];
    }

    /**
     * Uber Eats (memo23): one store object with a flattened `menuItems` list,
     * each item carrying its `section` (its category), name, description, dollar
     * `price`, and `imageUrl`. We group the items by section into categories,
     * preserving first-seen order. The flattened list has no per-item id, so
     * externalId is null (cross-mode price fusion falls back to name-matching).
     * Store: title / image / ratingValue / reviewCount / currencyCode.
     *
     * @param  list<mixed>  $items
     * @return array{store:array<string,mixed>, categories:list<array{name:string, items:list<array<string,mixed>>}>}
     */
    public function mapItems(array $items): array
    {
        $store = is_array($items[0] ?? null) ? $items[0] : [];

        // Group the flattened items by section, preserving first-seen order.
        $bySection = [];
        $order = [];
        foreach ((array) data_get($store, 'menuItems', []) as $item) {
            $itemName = $this->titleCase($this->cleanString(data_get($item, 'name')));
            if ($itemName === null) {
                continue;
            }
            $section = $this->cleanString(data_get($item, 'section')) ?? 'Menu';
            if (! isset($bySection[$section])) {
                $bySection[$section] = [];
                $order[] = $section;
            }
            $price = data_get($item, 'price');
            $bySection[$section][] = [
                'externalId' => null,
                'name' => $itemName,
                'description' => $this->sentenceCase($this->cleanString(data_get($item, 'description'))),
                'price' => is_numeric($price) ? round((float) $price, 2) : null,
                'currency' => $this->cleanString(data_get($item, 'currency')),
                'image' => $this->safeUrl(data_get($item, 'imageUrl')),
                'rating' => null,
                'ratingCount' => null,
                'badges' => null,
            ];
        }

        $categories = [];
        foreach ($order as $section) {
            if ($bySection[$section] !== []) {
                $categories[] = ['name' => $section, 'items' => $bySection[$section]];
            }
        }

        $rating = data_get($store, 'ratingValue');
        $reviews = data_get($store, 'reviewCount');

        return [
            'store' => [
                'name' => $this->cleanString(data_get($store, 'title')) ?? $this->cleanString(data_get($store, 'shopName')),
                'rating' => is_numeric($rating) ? round((float) $rating, 2) : null,
                'reviewCount' => is_numeric($reviews) ? (int) $reviews : null,
                'currency' => $this->cleanString(data_get($store, 'currencyCode')) ?? 'AUD',
                'logo' => $this->safeUrl(data_get($store, 'image')),
                'diningModes' => $this->diningModes(data_get($store, 'supportedDiningModes')),
            ],
            'categories' => $categories,
        ];
    }

    /**
     * supportedDiningModes → the available mode-name strings only. Uber Eats
     * (memo23) returns [{mode, isAvailable}, ...]; tolerate a bare string list
     * too. Null when absent or nothing is available.
     *
     * @return list<string>|null
     */
    private function diningModes(mixed $modes): ?array
    {
        if (! is_array($modes)) {
            return null;
        }

        $out = [];
        foreach ($modes as $mode) {
            if (is_string($mode) && $mode !== '') {
                $out[] = $mode;

                continue;
            }
            if (is_array($mode) && ($mode['isAvailable'] ?? true) && is_string($mode['mode'] ?? null) && $mode['mode'] !== '') {
                $out[] = $mode['mode'];
            }
        }

        return $out === [] ? null : array_values($out);
    }
}
