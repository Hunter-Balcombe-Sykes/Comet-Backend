<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\ApiBase;
use App\Services\V5\Scraping\Contracts\FetchContract;

// V5 Square scraper — fetches menu items from a Square Online store.
// Uses the Apify "menus-r-us/restaurant-menu-scraper" actor which natively
// handles Square-powered restaurant sites (alongside Toast, Popmenu, PDFs).
//
// NOTE: This is currently a STUB. The full implementation will:
// - Dispatch the Apify actor with the store URL
// - Map restaurant menu categories and items
// - Resolve image URLs
// - Return menu items as V5 items with type 'service'
//
// The old implementation is at App\Services\Platforms\SquareMenuDriver
// (uses ApifyBase via MenuPlatformDriver interface).
class SquareScraper extends ApiBase implements FetchContract
{
    protected string $endpoint = '';
    protected string $authType = 'none';

    /**
     * Fetch menu items from a Square Online store.
     *
     * @param string $identifier Square store URL (e.g. https://order.fat-tuna.com)
     * @return list<array>
     */
    public function fetch(string $identifier): array
    {
        // TODO: Full implementation via ApifyBase
        // $this->actorName = 'menus-r-us/restaurant-menu-scraper';
        // $this->actorInput = ['mode' => 'url', 'url' => $identifier, 'freshness' => 'med_cache'];

        return [
            [
                'identifier' => 'square_stub',
                'name' => 'Square Menu Items (Stub)',
                'item_type' => 'service',
                'values' => [
                    ['field_name' => 'note', 'value' => 'Square scraper needs an Apify API key and uses the ApifyBase actor. See App\Services\Platforms\SquareMenuDriver for the legacy implementation.', 'format' => 'text'],
                ],
            ],
        ];
    }
}
