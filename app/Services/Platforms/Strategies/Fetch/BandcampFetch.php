<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\BandcampScraper;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// Re-pulls a Bandcamp artist's latest release by stored url (origin). Preserves
// url + curated highlights; refreshes artist name + the auto-latest tile (flat
// fields mirror the connect shape) + the full releases grid. Mirrors
// PlatformRefresher::bandcampPayload EXACTLY (releases capture + the
// auto_sync_latest gate are the two additions since).
final readonly class BandcampFetch implements FetchStrategy
{
    public function __construct(private BandcampScraper $bandcamp) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        // Owner switched "Auto sync latest release" off (sparse display_settings;
        // absent = ON) → freeze the stored tile + releases via 304 semantics, so
        // last_refreshed_at still advances and the hourly dispatcher doesn't
        // re-select the row forever. Mirrors the events auto_sync_latest gate.
        if ((data_get($connection->display_settings, 'auto_sync_latest') ?? true) === false) {
            throw new FetchNotModifiedException('bandcamp');
        }

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
            // Keep the stored releases grid current (same fetch, no extra cost) —
            // pre-capture rows gain it on their first refresh after this shipped.
            'releases' => $profile['items'],
        ];
    }
}
