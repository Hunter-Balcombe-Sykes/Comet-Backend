<?php

namespace App\Services\Platforms;

// Uber Eats (memo23~uber-eats-scraper) scrape driver (FOUND-23). buildInput()
// and mapItems() are moved VERBATIM off MenuApifyScraper — byte-identical
// behavior, just relocated behind the MenuPlatformDriver interface.
class UberEatsMenuDriver implements MenuPlatformDriver
{
    use NormalizesMenuData;

    /**
     * Uber Eats (memo23): startUrls[{url}] — no consumer address needed.
     * `includeItemCustomizations` makes the actor emit per-item identity
     * (itemUuid/sectionUuid/subsectionUuid), the ready-made `href` deep link,
     * and isSoldOut — proven 2026-08-26 (run dTQULHB7Vbuhb1WNN); without it
     * the flattened list carries none of these.
     */
    public function buildInput(string $storeUrl, ?string $address): array
    {
        return ['startUrls' => [['url' => $storeUrl]], 'includeItemCustomizations' => true];
    }

    /**
     * Uber Eats (memo23): one store object with a flattened `menuItems` list,
     * each item carrying its `section` (its category), name, description, dollar
     * `price`, and `imageUrl`. We group the items by section into categories,
     * preserving first-seen order. With includeItemCustomizations on,
     * `itemUuid` becomes externalId (exact cross-mode fusion instead of
     * name-matching) and `href` becomes the per-item deep link. The
     * customization option trees the flag also returns are deliberately NOT
     * carried (owner ruling D5 — no consumer).
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
            $soldOut = data_get($item, 'isSoldOut');
            $bySection[$section][] = [
                'externalId' => $this->cleanString(data_get($item, 'itemUuid')),
                'name' => $itemName,
                'description' => $this->sentenceCase($this->cleanString(data_get($item, 'description'))),
                'price' => is_numeric($price) ? round((float) $price, 2) : null,
                'currency' => $this->cleanString(data_get($item, 'currency')),
                'image' => $this->safeUrl(data_get($item, 'imageUrl')),
                'rating' => null,
                'ratingCount' => null,
                'badges' => null,
                'itemUrl' => $this->itemUrl(data_get($item, 'href')),
                'soldOut' => is_bool($soldOut) ? $soldOut : null,
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
     * The actor's `href` → an absolute item deep link. memo23 emits the
     * quickView form as a root-relative path (`/store/…?mod=quickView&…`);
     * an absolute ubereats.com URL is tolerated, anything else is dropped —
     * the contract is a REAL item link or nothing, never a guess.
     */
    private function itemUrl(mixed $href): ?string
    {
        $href = $this->cleanString($href);
        if ($href === null) {
            return null;
        }
        if (str_starts_with($href, '/')) {
            return $this->safeUrl('https://www.ubereats.com'.$href);
        }

        $host = strtolower((string) parse_url($href, PHP_URL_HOST));

        return preg_match('~(^|\.)ubereats\.com$~', $host) === 1 ? $this->safeUrl($href) : null;
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
