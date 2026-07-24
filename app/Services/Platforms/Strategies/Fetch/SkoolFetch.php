<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\SkoolScraper;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// CA-W4: completes a pending Skool connect by re-scraping the stored community
// URL's og: tags and merging the fresh fields over the existing payload (a null
// scrape value keeps the stored one). Mirrors StravaFetch exactly — Skool and
// Strava are grouped as "link/card style" (PlatformRegistryServiceProvider's own
// comment). Consumed ONLY by ConnectFetchJob: Skool stays non-refreshable (no
// ->refreshable() on its descriptor), so PlatformDescriptor::refreshStrategy()
// never builds a ScheduledRefresh around this — the cron and the manual refresh
// button both stay unable to reach it, unchanged from today.
final readonly class SkoolFetch implements FetchStrategy
{
    public function __construct(private SkoolScraper $scraper) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $url = $payload['url'] ?? null;
        if (! $url) {
            throw new FetchShapeException('missing_key: url');
        }

        $community = $this->scraper->fetchCommunity($url);
        if ($community === null) {
            throw new FetchUnavailableException('skool_fetch_failed');
        }

        $merged = $payload;
        foreach ($community as $key => $value) {
            $merged[$key] = $value ?? ($payload[$key] ?? null);
        }

        return $merged;
    }
}
