<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// Re-pulls an Apple Music artist's latest album by stored input. Preserves input +
// curated highlights; refreshes the "most recent" tile. Mirrors
// PlatformRefresher::appleMusicPayload EXACTLY.
final readonly class AppleMusicFetch implements FetchStrategy
{
    public function __construct(private AppleSearch $apple) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $input = $payload['input'] ?? null;
        if (! $input) {
            throw new FetchShapeException('missing_key: input');
        }

        $albums = $this->apple->fetchAlbums($input);
        if (empty($albums)) {
            throw new FetchUnavailableException('apple_music_no_albums');
        }
        $latest = $albums[0];

        return [
            ...$payload,
            'latest' => $latest,
            'name' => $latest['name'],
            'thumbnail' => $latest['thumbnail'],
            'releaseDate' => $latest['releaseDate'],
            'link' => $latest['link'],
        ];
    }
}
