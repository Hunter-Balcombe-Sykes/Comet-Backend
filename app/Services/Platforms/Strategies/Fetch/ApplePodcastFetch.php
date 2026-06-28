<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// Re-pulls an Apple Podcasts show's latest episode by stored input. Mirrors
// PlatformRefresher::applePodcastPayload EXACTLY (header exposes `description`
// where Apple Music exposes `releaseDate` only).
final readonly class ApplePodcastFetch implements FetchStrategy
{
    public function __construct(private AppleSearch $apple) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $input = $payload['input'] ?? null;
        if (! $input) {
            throw new FetchShapeException('missing_key: input');
        }

        $episodes = $this->apple->fetchEpisodes($input);
        if (empty($episodes)) {
            throw new FetchUnavailableException('apple_podcast_no_episodes');
        }
        $latest = $episodes[0];

        return [
            ...$payload,
            'latest' => $latest,
            'name' => $latest['name'],
            'thumbnail' => $latest['thumbnail'],
            'description' => $latest['description'],
            'link' => $latest['link'],
        ];
    }
}
