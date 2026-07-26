<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\ApifyBase;

// V5 DoorDashMenuScraper — Apify-based DoorDash menu scraper.
// Dispatches the configured Apify actor to scrape menu items from DoorDash store pages.
class DoorDashMenuScraper extends ApifyBase
{
    protected string $actorName = '';
    protected array $actorInput = [];

    public function __construct(
        \App\Services\Http\SafeUrlFetcher $fetcher,
        \App\Services\V5\Scraping\Budget\ApifyBudget $apifyBudget,
    ) {
        parent::__construct($fetcher, $apifyBudget);
        $this->actorName = config('v5.menu.doordash_actor', 'doordash-menu-scraper');
    }

    /**
     * Fetch menu items from a DoorDash store.
     *
     * @param  string  $identifier  DoorDash store URL or merchant ID
     * @return array{items: array}
     */
    public function fetch(string $identifier): array
    {
        $raw = $this->runActor(['storeUrl' => $identifier]);
        if ($raw === null) {
            return ['items' => []];
        }

        $items = $this->processItems($raw);
        $this->logSuccess('doordash_menu', 'fetch', count($items));

        return ['items' => $items];
    }

    /** Map raw actor output to V5 menu-item format. */
    protected function mapItem(array $raw): array
    {
        $name = $raw['name'] ?? $raw['item_name'] ?? $raw['title'] ?? '';
        if ($name === '') {
            return [];
        }

        $id = $raw['id'] ?? $raw['item_id'] ?? $raw['merchantId'] ?? md5($name);
        $values = [];

        $values[] = ['field_name' => 'name', 'value' => $name, 'format' => 'text'];

        $description = $raw['description'] ?? $raw['desc'] ?? '';
        if ($description !== '') {
            $values[] = ['field_name' => 'description', 'value' => $description, 'format' => 'text'];
        }

        $price = $raw['price'] ?? $raw['listedPrice'] ?? $raw['listed_price'] ?? null;
        if ($price !== null) {
            $values[] = ['field_name' => 'price', 'value' => (string) $price, 'format' => 'text'];
        }

        $currency = $raw['currency'] ?? $raw['currency_code'] ?? 'AUD';
        $values[] = ['field_name' => 'currency', 'value' => $currency, 'format' => 'text'];

        $category = $raw['category'] ?? $raw['section'] ?? $raw['menuSection'] ?? $raw['menu_section'] ?? '';
        if ($category !== '') {
            $values[] = ['field_name' => 'category', 'value' => $category, 'format' => 'text'];
        }

        $imageUrl = $raw['image_url'] ?? $raw['image'] ?? $raw['thumbnail'] ?? '';
        if ($imageUrl !== '') {
            $values[] = ['field_name' => 'image_url', 'value' => $imageUrl, 'format' => 'url'];
        }

        $dietary = $raw['dietary'] ?? $raw['dietary_info'] ?? $raw['dietaryFlags'] ?? [];
        if (is_array($dietary)) {
            $dietary = implode(', ', $dietary);
        }
        if ($dietary !== '') {
            $values[] = ['field_name' => 'dietary', 'value' => (string) $dietary, 'format' => 'text'];
        }

        return [
            'identifier' => (string) $id,
            'name' => $name,
            'item_type' => 'menu item',
            'values' => $values,
            'pools' => ['menu'],
        ];
    }
}
