<?php

namespace App\Services\Platforms;

// Square Online ordering menu scraper (2026-07-26). Uses the Apify actor
// menus-r-us/restaurant-menu-scraper which natively handles Square-powered
// restaurant sites alongside Toast, Popmenu, and PDFs.
//
// Input:  a store URL (e.g. https://order.fat-tuna.com)
// Output: categories[] → items[] with name, description, price, image.
//
// Priority: config('partna.menu.platforms') lists Square FIRST so it wins
// over Uber Eats and DoorDash for pricing and images when a Square link is
// connected — the canonical source of truth for menu content.
class SquareMenuDriver implements MenuPlatformDriver
{
    use NormalizesMenuData;

    /**
     * Square (menus-r-us): single URL, medium-cache tier for cost efficiency.
     * No locale needed — Square stores serve the same menu everywhere.
     */
    public function buildInput(string $storeUrl, ?string $address): array
    {
        return [
            'mode' => 'url',
            'url' => $storeUrl,
            'freshness' => 'med_cache',
        ];
    }

    /**
     * Restaurant Menu Scraper: one restaurant object with menu → categories[]
     * → items[]. Each item has name, price, description, and optionally image.
     *
     * @param  list<mixed>  $items
     * @return array{store:array<string,mixed>, categories:list<array{name:string, items:list<array<string,mixed>>}>}
     */
    public function mapItems(array $items): array
    {
        $data = is_array($items[0] ?? null) ? $items[0] : [];
        $menu = is_array(data_get($data, 'menu')) ? data_get($data, 'menu') : $data;

        $categories = [];
        foreach ((array) data_get($menu, 'categories', []) as $category) {
            if (! is_array($category)) {
                continue;
            }
            $name = $this->cleanString(data_get($category, 'name')) ?? 'Menu';
            $catItems = [];
            foreach ((array) data_get($category, 'items', []) as $item) {
                $itemName = $this->titleCase($this->cleanString(data_get($item, 'name')));
                if ($itemName === null) {
                    continue;
                }
                $price = $this->parsePrice(data_get($item, 'price'));
                $catItems[] = [
                    'externalId' => $this->cleanString(data_get($item, 'id'))
                        ?? $this->cleanString(data_get($item, 'externalId')),
                    'name' => $itemName,
                    'description' => $this->sentenceCase($this->cleanString(data_get($item, 'description'))),
                    'price' => $price,
                    'image' => $this->safeUrl(data_get($item, 'image'))
                        ?? $this->safeUrl(data_get($item, 'imageUrl'))
                        ?? $this->safeUrl(data_get($item, 'image_url')),
                ];
            }
            if ($catItems !== []) {
                $categories[] = ['name' => $name, 'items' => $catItems];
            }
        }

        return [
            'store' => [
                'name' => $this->cleanString(data_get($data, 'restaurantName'))
                    ?? $this->cleanString(data_get($data, 'name')),
                'rating' => null,
                'reviewCount' => null,
                'currency' => 'AUD',
                'logo' => $this->safeUrl(data_get($data, 'logo'))
                    ?? $this->safeUrl(data_get($data, 'restaurantImage')),
                'diningModes' => null,
            ],
            'categories' => $categories,
        ];
    }

    /**
     * Best-effort price → dollars float. Handles "$15.30", "15.30", 1530 (cents).
     */
    private function parsePrice(mixed $value): ?float
    {
        if (is_string($value)) {
            $clean = str_replace(['$', 'A$', 'AU$', ',', ' '], '', $value);
            if (is_numeric($clean)) {
                return round((float) $clean, 2);
            }

            return null;
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
