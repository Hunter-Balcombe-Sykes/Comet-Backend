<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\ApiBase;
use App\Services\V5\Scraping\Contracts\FetchContract;

// V5 Google Business scraper — fetches place details from the Google Places
// API (New). Requires a server-side API key with the Places API enabled.
//
// NOTE: This is currently a STUB. The full implementation will:
// - Parse a Maps URL to extract the place name and coordinates
// - Resolve short links (maps.app.goo.gl, goo.gl/maps, share.google)
// - Fetch Place Details from places.googleapis.com/v1/places/{placeId}
// - Map details (rating, reviews, hours, phone, website, photos, amenities)
// - Resolve photo refs to servable image URLs via Places Photos media endpoint
// - Use PlacesBudget for billed-call accounting
// - Return details as V5 items with type 'service'
//
// The old implementation is at App\Services\Platforms\GoogleBusinessService.
class GoogleBusinessScraper extends ApiBase implements FetchContract
{
    protected string $endpoint = 'https://places.googleapis.com/v1';
    protected string $authType = 'bearer';
    protected string $apiKey = '';

    /**
     * Fetch place details for a Google Business listing.
     *
     * @param string $identifier Google Maps URL or place ID
     * @return list<array>
     */
    public function fetch(string $identifier): array
    {
        // Requires config('services.google_maps.server_api_key')
        // Field mask: X-Goog-FieldMask header with details fields
        // Endpoint: /places/{placeId}

        return [
            [
                'identifier' => 'google_business_stub',
                'name' => 'Google Business Details (Stub)',
                'item_type' => 'service',
                'values' => [
                    ['field_name' => 'note', 'value' => 'Google Business scraper requires a Google Places API key and PlacesBudget. See App\Services\Platforms\GoogleBusinessService for the legacy implementation.', 'format' => 'text'],
                ],
            ],
        ];
    }
}
