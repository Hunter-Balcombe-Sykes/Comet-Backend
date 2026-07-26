<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\ApifyBase;

// V5 UberEatsMenuScraper — Apify-based Uber Eats menu scraper.
// Dispatches the configured Apify actor to scrape menu items from Uber Eats store pages.
class UberEatsMenuScraper extends ApifyBase
{
    protected string $actorName = '';
    protected array $actorInput = [];

    public function __construct(
        \App\Services\Http\SafeUrlFetcher $fetcher,
        \App\Services\V5\Scraping\Budget\ApifyBudget $apifyBudget,
    ) {
        parent::__construct($fetcher, $apifyBudget);
        $this->actorName = config('v5.menu.ubereats_actor', 'ubereats-menu-scraper');
    }

    /**
     * Fetch menu items from an Uber Eats store.
     *
     * @param  string  $identifier  Uber Eats store URL or UUID
     * @return array{v5_items: array}
     */
    public function fetch(string $identifier): array
    {
        $raw = $this->runActor(['storeUrl' => $identifier]);
        if ($raw === null) {
            return ['v5_items' => []];
        }

        $items = $this->processItems($raw);
        $this->logSuccess('ubereats_menu', 'fetch', count($items));

        return ['v5_items' => $items];
    }

    /** Map raw actor output to V5 menu-item format. */
    protected function mapItem(array $raw): array
    {
        $name = $raw['name'] ?? $raw['item_name'] ?? $raw['title'] ?? '';
        if ($name === '') {
            return [];
        }

        $id = $raw['id'] ?? $raw['item_id'] ?? $raw['uuid'] ?? md5($name);
        $values = [];

        $values[] = ['field_name' => 'name', 'value' => $name, 'format' => 'text'];

        $description = $raw['description'] ?? $raw['desc'] ?? '';
        if ($description !== '') {
            $values[] = ['field_name' => 'description', 'value' => $description, 'format' => 'text'];
        }

        $price = $raw['price'] ?? $raw['basePrice'] ?? $raw['base_price'] ?? null;
        if ($price !== null) {
            $values[] = ['field_name' => 'price', 'value' => (string) $price, 'format' => 'text'];
        }

        $currency = $raw['currency'] ?? $raw['currency_code'] ?? 'AUD';
        $values[] = ['field_name' => 'currency', 'value' => $currency, 'format' => 'text'];

        $category = $raw['category'] ?? $raw['section'] ?? $raw['sectionName'] ?? $raw['section_name'] ?? '';
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
