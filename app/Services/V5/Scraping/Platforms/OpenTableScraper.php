<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\ApiBase;
use App\Services\V5\Scraping\Contracts\FetchContract;

// V5 OpenTable scraper — extracts the restaurant id (rid) from an OpenTable
// URL and returns the embeddable reservation widget URL. OpenTable WAF-blocks
// server-side fetches (datacenter IPs time out), so no server-side scraping is
// possible — the widget is rendered client-side in the visitor's browser.
//
// NOTE: This is currently a STUB. The full implementation will:
// - Parse the URL for the rid (/restaurant/profile/<rid>, or ?rid= query param)
// - Generate the widget URL with locale-aware domain parameter
// - Return the widget embed info as V5 items with type 'service'
// - For slug links (/r/<slug>), prompt the user for the profile link instead
//
// The old implementation is at App\Services\Platforms\OpenTableService.
class OpenTableScraper extends ApiBase implements FetchContract
{
    protected string $endpoint = '';
    protected string $authType = 'none';

    /**
     * Parse an OpenTable URL and return reservation widget info.
     *
     * @param string $identifier OpenTable restaurant URL
     * @return list<array>
     */
    public function fetch(string $identifier): array
    {
        // TODO: Full implementation
        // Parse rid from URL, generate widget embed URL
        // WAF blocks server-side — widget is iframe-based, rendered client-side

        return [
            [
                'identifier' => 'opentable_stub',
                'name' => 'OpenTable Reservation Widget (Stub)',
                'item_type' => 'service',
                'values' => [
                    ['field_name' => 'note', 'value' => 'OpenTable scraper parses URLs only — server-side fetches are WAF-blocked. Embed widget renders client-side. See App\Services\Platforms\OpenTableService for the legacy implementation.', 'format' => 'text'],
                ],
            ],
        ];
    }
}
