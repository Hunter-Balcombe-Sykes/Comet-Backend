<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\BandcampScraper;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// Re-pulls a Bandcamp artist's latest release by stored url (origin). Preserves
// url + curated highlights; refreshes artist name + the auto-latest tile (flat
// fields mirror the connect shape). Mirrors PlatformRefresher::bandcampPayload EXACTLY.
final readonly class BandcampFetch implements FetchStrategy
{
    public function __construct(private BandcampScraper $bandcamp) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $url = $payload['url'] ?? null;
        if (! $url) {
            throw new FetchShapeException('missing_key: url');
        }

        $profile = $this->bandcamp->fetchProfile($url);
        if ($profile === null || $profile['items'] === []) {
            throw new FetchUnavailableException('bandcamp_no_releases');
        }
        $latest = $this->bandcamp->enrichPrices([$profile['items'][0]])[0];

        return [
            ...$payload,
            'artist' => $profile['name'] ?? ($payload['artist'] ?? null),
            'latest' => $latest,
            'name' => $latest['name'],
            'thumbnail' => $latest['thumbnail'] ?? $profile['thumbnail'],
            'link' => $latest['link'],
        ];
    }
}
