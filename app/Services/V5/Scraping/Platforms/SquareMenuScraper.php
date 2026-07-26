<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\ApifyBase;

// V5 SquareMenuScraper — Apify-based Square menu scraper.
// Dispatches the configured Apify actor to scrape menu items from a Square Online store.
class SquareMenuScraper extends ApifyBase
{
    protected string $actorName = '';
    protected array $actorInput = [];

    public function __construct(
        \App\Services\Http\SafeUrlFetcher $fetcher,
        \App\Services\V5\Scraping\Budget\ApifyBudget $apifyBudget,
    ) {
        parent::__construct($fetcher, $apifyBudget);
        $this->actorName = config('v5.menu.square_actor', 'square-menu-scraper');
    }

    /**
     * Fetch menu items from a Square store.
     *
     * @param  string  $identifier  Square store URL or merchant ID
     * @return array{items: array}
     */
    public function fetch(string $identifier): array
    {
        $raw = $this->runActor(['merchantUrl' => $identifier]);
        if ($raw === null) {
            return ['items' => []];
        }

        $items = $this->processItems($raw);
        $this->logSuccess('square_menu', 'fetch', count($items));

        return ['items' => $items];
    }

    /** Map raw actor output to V5 menu-item format. */
    protected function mapItem(array $raw): array
    {
        $name = $raw['name'] ?? $raw['item_name'] ?? $raw['title'] ?? '';
        if ($name === '') {
            return [];
        }

        $id = $raw['id'] ?? $raw['item_id'] ?? $raw['sku'] ?? md5($name);
        $values = [];

        $values[] = ['field_name' => 'name', 'value' => $name, 'format' => 'text'];

        $description = $raw['description'] ?? $raw['desc'] ?? '';
        if ($description !== '') {
            $values[] = ['field_name' => 'description', 'value' => $description, 'format' => 'text'];
        }

        $price = $raw['price'] ?? $raw['amount'] ?? null;
        if ($price !== null) {
            $values[] = ['field_name' => 'price', 'value' => (string) $price, 'format' => 'text'];
        }

        $currency = $raw['currency'] ?? $raw['currency_code'] ?? 'AUD';
        $values[] = ['field_name' => 'currency', 'value' => $currency, 'format' => 'text'];

        $category = $raw['category'] ?? $raw['category_name'] ?? $raw['section'] ?? '';
        if ($category !== '') {
            $values[] = ['field_name' => 'category', 'value' => $category, 'format' => 'text'];
        }

        $imageUrl = $raw['image_url'] ?? $raw['image'] ?? $raw['thumbnail'] ?? '';
        if ($imageUrl !== '') {
            $values[] = ['field_name' => 'image_url', 'value' => $imageUrl, 'format' => 'url'];
        }

        $dietary = $raw['dietary'] ?? $raw['dietary_info'] ?? '';
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
