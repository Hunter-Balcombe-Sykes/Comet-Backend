<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\ConditionalContext;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\YoutubeMusicItems;
use App\Services\Platforms\YoutubeScraper;

// Re-pulls a YouTube Music artist's uploads feed by stored channelId. Strips the
// "- Topic" suffix the auto-channels carry; reshapes the RSS videos into music items.
// Mirrors PlatformRefresher::youtubeMusicPayload EXACTLY (incl. the 12-item fetch +
// slice and the YoutubeMusicItems::map reshape).
final readonly class YoutubeMusicFetch implements FetchStrategy
{
    public function __construct(private YoutubeScraper $youtube) {}

    public function fetch(IntegrationConnection $connection): array
    {
        $payload = $connection->payload ?? [];

        $channelId = $payload['channelId'] ?? null;
        if (! $channelId) {
            throw new FetchShapeException('missing_key: channelId');
        }

        $cond = ConditionalContext::for($connection);
        $feed = $this->youtube->fetchUploadsFeed((string) $channelId, 12, $cond);
        if ($cond?->notModified) {
            throw new FetchNotModifiedException('youtube-music');
        }
        if ($feed === null || $feed['videos'] === []) {
            throw new FetchUnavailableException('youtube_music_no_releases');
        }
        $cond?->applyTo($connection);
        $items = YoutubeMusicItems::map($feed['videos']);

        return [
            ...$payload,
            'name' => $feed['title'] !== null
                ? preg_replace('/\s+-\s+Topic$/', '', $feed['title'])
                : ($payload['name'] ?? null),
            'thumbnail' => $items[0]['thumbnail'] ?? ($payload['thumbnail'] ?? null),
            'latest' => $items[0],
            'items' => array_slice($items, 0, 12),
        ];
    }
}
