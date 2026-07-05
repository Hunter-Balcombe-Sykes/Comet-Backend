<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
use App\Services\Platforms\YoutubeMusicItems;
use App\Services\Platforms\YoutubeScraper;

// YouTube Music connect: artist/channel URL or @handle → the channel's uploads
// RSS provides the releases, rewritten onto music.youtube.com. channelId is the
// canonical artist identity — passed as the accountKey. Moved verbatim from
// YoutubeMusicController.
class YoutubeMusicConnect implements ConnectStrategy
{
    private const MAX_ITEMS = 12;

    public function __construct(private readonly YoutubeScraper $scraper) {}

    public function resolve(string $input): ConnectResult
    {
        $channelId = $this->scraper->channelIdFrom($input);
        if (! $channelId) {
            return ConnectResult::fail();
        }

        $feed = $this->scraper->fetchUploadsFeed($channelId, self::MAX_ITEMS);
        if ($feed === null) {
            return ConnectResult::fail('Could not load releases for that channel.', 404);
        }

        $items = YoutubeMusicItems::map($feed['videos']);
        $url = 'https://music.youtube.com/channel/'.$channelId;

        return ConnectResult::ok([
            'url' => $url,
            'channelId' => $channelId,
            // Auto-generated artist channels are titled "<Artist> - Topic".
            'name' => $feed['title'] !== null ? preg_replace('/\s+-\s+Topic$/', '', $feed['title']) : null,
            'thumbnail' => $items[0]['thumbnail'] ?? null,
            'link' => $url,
            'latest' => $items[0] ?? null,
            'items' => $items,
        ], $channelId);
    }
}
