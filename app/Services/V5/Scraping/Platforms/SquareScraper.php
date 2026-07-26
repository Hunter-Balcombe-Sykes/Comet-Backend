<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\ApiBase;
use App\Services\V5\Scraping\Contracts\FetchContract;
use Illuminate\Support\Facades\Log;

// V5 Square scraper — fetches services from a Square booking/appointments page.
// Square-powered booking pages do not expose a public API for server-side
// scraping. The old implementation used an Apify actor (menus-r-us/restaurant-menu-scraper)
// for menu items (handled by SquareMenuScraper), but for booking services no
// public API is reliably available.
//
// Returns empty items with a logged warning. Configuration of a Square API key
// or Apify token will be needed for a full implementation.
class SquareScraper extends ApiBase implements FetchContract
{
    protected string $endpoint = '';
    protected string $authType = 'none';

    /**
     * Fetch booking services from a Square store URL.
     *
     * @param string $identifier Square store URL
     * @return array{items: list<array>, profile: array}
     */
    public function fetch(string $identifier): array
    {
        Log::info('v5.square.not_implemented', [
            'identifier' => $identifier,
            'message' => 'Square booking scraper is not yet implemented. Configure an Apify API key and use SquareMenuScraper for menu items, or add Square API credentials for booking services.',
        ]);

        return ['items' => [], 'profile' => []];
    }
}
