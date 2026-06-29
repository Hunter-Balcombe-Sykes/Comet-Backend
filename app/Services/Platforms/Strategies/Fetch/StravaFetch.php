<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\StravaClubScraper;

// Re-scrapes a Strava club card from its stored URL and merges the fresh fields
// over the existing payload (a null scrape value keeps the stored one). Mirrors
// PlatformRefresher::scrapedCardPayload EXACTLY.
final readonly class StravaFetch implements FetchStrategy
{
    public function __construct(private StravaClubScraper $strava) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $url = $payload['url'] ?? null;
        if (! $url) {
            throw new FetchShapeException('missing_key: url');
        }
        $card = $this->strava->fetchClub($url);
        if ($card === null) {
            throw new FetchUnavailableException('strava_fetch_failed');
        }

        // Refresh every scraped field; nulls from the scrape keep stored values.
        $merged = $payload;
        foreach ($card as $key => $value) {
            $merged[$key] = $value ?? ($payload[$key] ?? null);
        }

        return $merged;
    }
}
