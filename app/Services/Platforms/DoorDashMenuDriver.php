<?php

namespace App\Services\Platforms;

// DoorDash (dz_omar~doordash-scraper) scrape driver (FOUND-23). buildInput()
// and mapItems() are moved VERBATIM off MenuApifyScraper — byte-identical
// behavior, just relocated behind the MenuPlatformDriver interface.
class DoorDashMenuDriver implements MenuPlatformDriver
{
    use NormalizesMenuData;

    // DoorDash results are location-specific; with no consumer address the actor
    // defaults to Chicago, IL (wrong country). A valid AU locale keeps results
    // Australian even when the store sits in another AU city — the specific store
    // URL still resolves and its menu returns regardless of delivery range.
    private const DOORDASH_FALLBACK_ADDRESS = 'Melbourne VIC, Australia';

    /**
     * DoorDash (dz_omar): startUrls[{url}] + a consumer `address` (locale) —
     * fetchReviews off (we don't surface reviews).
     */
    public function buildInput(string $storeUrl, ?string $address): array
    {
        return [
            'startUrls' => [['url' => $storeUrl]],
            'address' => $this->cleanString($address) ?? self::DOORDASH_FALLBACK_ADDRESS,
            'fetchReviews' => false,
        ];
    }

    /**
     * DoorDash (dz_omar): one store object with menu_categories[] → items[].
     * Item price is in cents; image_url / rating_pct / rating_count / badges are
     * per item. `featured_items` is skipped — it re-lists items already in the
     * categories.
     *
     * @param  list<mixed>  $items
     * @return array{store:array<string,mixed>, categories:list<array{name:string, items:list<array<string,mixed>>}>}
     */
    public function mapItems(array $items): array
    {
        $store = is_array($items[0] ?? null) ? $items[0] : [];

        $categories = [];
        foreach ((array) data_get($store, 'menu_categories', []) as $category) {
            if (! is_array($category)) {
                continue;
            }
            $name = $this->cleanString(data_get($category, 'category_name'))
                ?? $this->cleanString(data_get($category, 'name'))
                ?? 'Menu';
            $catItems = [];
            foreach ((array) data_get($category, 'items', []) as $item) {
                $itemName = $this->titleCase($this->cleanString(data_get($item, 'name')));
                if ($itemName === null) {
                    continue;
                }
                $cents = data_get($item, 'price_cents');
                $price = is_numeric($cents)
                    ? round(((int) $cents) / 100, 2)
                    : $this->parsePrice(data_get($item, 'price_display'));
                $ratingPct = data_get($item, 'rating_pct');
                $ratingCount = data_get($item, 'rating_count');
                $catItems[] = [
                    'externalId' => $this->cleanString((string) data_get($item, 'item_id')),
                    'name' => $itemName,
                    'description' => $this->sentenceCase($this->cleanString(data_get($item, 'description'))),
                    'price' => $price,
                    'image' => $this->safeUrl(data_get($item, 'image_url')),
                    'rating' => is_numeric($ratingPct) ? round((float) $ratingPct, 2) : null,
                    'ratingCount' => is_numeric($ratingCount) ? (int) $ratingCount : null,
                    'badges' => $this->badges(data_get($item, 'badges')),
                ];
            }
            if ($catItems !== []) {
                $categories[] = ['name' => $name, 'items' => $catItems];
            }
        }

        $rating = data_get($store, 'rating');
        $reviews = data_get($store, 'num_ratings');

        return [
            'store' => [
                'name' => $this->cleanString(data_get($store, 'name')),
                'rating' => is_numeric($rating) ? round((float) $rating, 2) : null,
                'reviewCount' => is_numeric($reviews) ? (int) $reviews : null,
                'currency' => $this->cleanString(data_get($store, 'currency')) ?? 'AUD',
                'logo' => $this->safeUrl(data_get($store, 'cover_square_image'))
                    ?? $this->safeUrl(data_get($store, 'cover_image'))
                    ?? $this->safeUrl(data_get($store, 'header_image')),
                // DoorDash's actor exposes no dining-mode field (Uber Eats only).
                'diningModes' => null,
            ],
            'categories' => $categories,
        ];
    }

    /**
     * DoorDash badges → normalized [{ text, type? }]. The element shape isn't
     * fixed (a quiet store returns none), so extract defensively: a plain string,
     * or a { text|name|label, type } object.
     *
     * @return list<array{text:string, type?:string}>|null
     */
    private function badges(mixed $badges): ?array
    {
        if (! is_array($badges) || $badges === []) {
            return null;
        }
        $out = [];
        foreach ($badges as $badge) {
            if (is_string($badge)) {
                $text = $this->cleanString($badge);
            } else {
                $text = $this->cleanString(data_get($badge, 'text'))
                    ?? $this->cleanString(data_get($badge, 'name'))
                    ?? $this->cleanString(data_get($badge, 'label'));
            }
            if ($text === null) {
                continue;
            }
            $type = is_array($badge) ? $this->cleanString(data_get($badge, 'type')) : null;
            $out[] = array_filter(['text' => $text, 'type' => $type], fn ($v) => $v !== null);
        }

        return $out === [] ? null : $out;
    }

    /**
     * Best-effort price → dollars float. DoorDash sends "A$15.30" display strings
     * as a fallback when price_cents is absent; also tolerates plain numerics.
     */
    private function parsePrice(mixed $value): ?float
    {
        if (is_string($value)) {
            return preg_match('~([0-9]+(?:\.[0-9]+)?)~', str_replace(',', '', $value), $m) === 1
                ? round((float) $m[1], 2)
                : null;
        }
        if (is_int($value)) {
            return round($value / 100, 2);
        }
        if (is_float($value)) {
            return round($value, 2);
        }

        return null;
    }
}
