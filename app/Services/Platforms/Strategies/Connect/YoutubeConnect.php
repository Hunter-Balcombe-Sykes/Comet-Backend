<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\Concerns\RefreshesLatestTile;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
use App\Services\Platforms\YoutubeScraper;

// YouTube connect: channel handle/URL → channel's auto-latest video tile.
// Moved verbatim from YoutubeController; the curated-highlights preservation
// happens in GenericPlatformController (hasHighlights + preserveHighlights).
class YoutubeConnect implements ConnectStrategy
{
    use RefreshesLatestTile;

    private const FLAT_TILE_FIELDS = ['name', 'description', 'link', 'thumbnail'];

    public function __construct(private readonly YoutubeScraper $scraper) {}

    public function resolve(string $input): ConnectResult
    {
        $handle = $this->scraper->normalizeHandle($input);
        if ($handle === '') {
            return ConnectResult::fail();
        }

        $videos = $this->scraper->fetchRecentVideos($handle);
        if (empty($videos)) {
            return ConnectResult::fail('Could not find that YouTube channel or its latest video.', 404);
        }
        $latest = $videos[0];

        return ConnectResult::ok([
            'handle' => $handle,
            // Flat fields retained for partna-pages + back-compat; nested
            // `latest` is the canonical shape.
            ...$this->flatTileFields($latest, self::FLAT_TILE_FIELDS),
            'latest' => $latest,
        ], $handle);
    }
}
