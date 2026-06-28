<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\VimeoApi;

// Re-pulls a Vimeo profile's latest uploads by stored apiPath. Mirrors
// PlatformRefresher::vimeoPayload EXACTLY (fetchVideos before fetchProfile; the
// latest tile + 12-item slice; profile name/thumbnail fall back to stored values).
final readonly class VimeoFetch implements FetchStrategy
{
    public function __construct(private VimeoApi $vimeo) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $apiPath = $payload['apiPath'] ?? null;
        if (! $apiPath) {
            throw new FetchShapeException('missing_key: apiPath');
        }

        $videos = $this->vimeo->fetchVideos($apiPath);
        if ($videos === []) {
            throw new FetchUnavailableException('vimeo_no_videos');
        }
        $profile = $this->vimeo->fetchProfile($apiPath);

        return [
            ...$payload,
            'name' => $profile['name'] ?? ($payload['name'] ?? null),
            'thumbnail' => $profile['thumbnail'] ?? ($payload['thumbnail'] ?? null),
            'latest' => $videos[0],
            'items' => array_slice($videos, 0, 12),
        ];
    }
}
