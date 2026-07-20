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
    // PUBLIC render width (the `items` grid served to /selection, /accounts) —
    // NOT the picker/fetch width. Kept separate from the 15 below so widening
    // the picker snapshot never widens what's rendered publicly as a side effect.
    private const MAX_ITEMS = 12;

    public function __construct(private readonly YoutubeScraper $scraper) {}

    public function resolve(string $input): ConnectResult
    {
        $channelId = $this->scraper->channelIdFrom($input);
        if (! $channelId) {
            return ConnectResult::fail();
        }

        // Fetch 15, not self::MAX_ITEMS — matches the picker snapshot width
        // every other `recent` writer uses (YoutubeMusicFetch, YoutubeMusicHighlights
        // ::apply()), same pattern VimeoConnect uses (fetch wide, slice narrow
        // for the public `items` below, keep the full width for `recent`).
        $feed = $this->scraper->fetchUploadsFeed($channelId, 15);
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
            'items' => array_slice($items, 0, self::MAX_ITEMS),
            // Warm the picker's private snapshot at connect time (HighlightsPicker::
            // SNAPSHOT_KEY) so the picker is fast on the very first open, at the
            // same width the Fetch/Highlights writers use.
            'recent' => $items,
        ], $channelId);
    }
}
