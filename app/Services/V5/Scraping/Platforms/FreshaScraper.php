<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\HtmlScrapeBase;
use App\Services\V5\Scraping\Contracts\FetchContract;

// V5 Fresha scraper — fetches salon location data (team, services) from a
// Fresha public page. Fresha is a FullyCustom integration: it uses HTML
// scraping of the Next.js page for the location data (__NEXT_DATA__ JSON-LD),
// plus a booking-flow GraphQL endpoint for per-employee service menus.
//
// NOTE: This is currently a STUB. The full implementation will:
// - Fetch the Fresha page HTML and extract __NEXT_DATA__ JSON-LD
// - Parse the location blob for store name, team, and location-wide services
// - Optionally call Fresha's booking GraphQL for per-employee menus
// - Return services/team as V5 items with type 'service'
// - Handle persisted query hash rotation (FRESHA_BOOKING_INIT_HASH / client version)
//
// The old implementation is at App\Services\Platforms\FreshaScraper.
class FreshaScraper extends HtmlScrapeBase implements FetchContract
{
    /**
     * Fetch menu/services from a Fresha location URL.
     *
     * @param string $identifier Fresha location URL
     * @return list<array>
     */
    public function fetch(string $identifier): array
    {
        // TODO: Full implementation
        // - Fetch page HTML via $this->fetchHtml($url)
        // - Extract __NEXT_DATA__ JSON with regex
        // - Parse location data for store name, team members, services
        // - Optionally call GraphQL for per-employee service menus
        // - Map to V5 items with item_type 'service'

        return [
            [
                'identifier' => 'fresha_stub',
                'name' => 'Fresha Services (Stub)',
                'item_type' => 'service',
                'values' => [
                    ['field_name' => 'note', 'value' => 'Fresha scraper needs FetchBudget and GraphQL persisted query hashes. See App\Services\Platforms\FreshaScraper for the legacy implementation.', 'format' => 'text'],
                ],
            ],
        ];
    }
}
