<?php

namespace App\Http\Resources\Platforms;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Google Business card: the Maps link + place name/coordinates, plus the
 * Place Details enrichment (rating, reviews, hours, phone, …) when the
 * snapshot has been fetched. Enrichment keys are emitted only when present,
 * so never-enriched (legacy link-parse) selections keep the original 5-key
 * shape.
 *
 * `$this->resource` is the selection ARRAY.
 */
class GoogleBusinessConnectionResource extends ApiResource
{
    // photos carry resolved image URLs (refreshed weekly) — exposed to the
    // dashboard here; the PUBLIC sitepage allowlist still excludes them until
    // the sitepage design pass renders a gallery.
    private const ENRICHMENT_KEYS = [
        'placeId', 'businessStatus', 'category', 'phone', 'phoneIntl', 'website',
        'rating', 'reviewCount', 'hours', 'currentHours', 'addressParts', 'links',
        'priceLevel', 'priceRange', 'editorialSummary', 'reviewSummary', 'reviews',
        'amenities', 'photos', 'streetView', 'detailsFetchedAt',
        // Apify-sourced enrichment (menu / reservation / order / booking /
        // socials) + its async status. Dashboard-only this phase — the PUBLIC
        // allowlist still excludes them until a sitepage design pass renders them.
        'menu', 'reservation', 'order', 'booking', 'socials', 'apifyStatus', 'apifyFetchedAt',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->resource['url'] ?? null,
            'name' => $this->resource['name'] ?? null,
            'address' => $this->resource['address'] ?? null,
            'lat' => $this->resource['lat'] ?? null,
            'lng' => $this->resource['lng'] ?? null,
            ...array_intersect_key($this->resource, array_flip(self::ENRICHMENT_KEYS)),
        ];
    }
}
