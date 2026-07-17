<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\BandcampScraper;
use App\Services\Platforms\Concerns\RefreshesLatestTile;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;

// Bandcamp connect: artist-page URL → latest release tile (price-enriched) +
// artist profile. accountKey is the canonical page origin. Moved verbatim from
// BandcampController.
class BandcampConnect implements ConnectStrategy
{
    use RefreshesLatestTile;

    // Flat back-compat tile fields copied verbatim from the latest release
    // (mirrors the Apple Music selection so sitepages render both identically).
    private const FLAT_FIELDS = ['name', 'thumbnail', 'link'];

    public function __construct(private readonly BandcampScraper $scraper) {}

    public function resolve(string $input): ConnectResult
    {
        $origin = $this->scraper->normalizeOrigin($input);
        if (! $origin) {
            return ConnectResult::fail();
        }

        $profile = $this->scraper->fetchProfile($origin);
        if ($profile === null || $profile['items'] === []) {
            return ConnectResult::fail('Could not find releases on that Bandcamp page.', 404);
        }
        // Enrich the latest tile with its buy price (1 fetch). Null-safe.
        $latest = $this->scraper->enrichPrices([$profile['items'][0]])[0];

        $selection = [
            'url' => $origin,
            'artist' => $profile['name'],
            ...$this->flatTileFields($latest, self::FLAT_FIELDS),
            'latest' => $latest,
            // Full releases grid (already fetched — zero extra requests), stored
            // un-price-enriched so connect stays a 2-fetch operation. Served
            // publicly ONLY when the owner's show_all_releases toggle is on
            // (DisplaySettingsFilter suppresses it otherwise).
            'releases' => $profile['items'],
        ];
        // Prefer the latest release art for the tile; fall back to the page's
        // own og:image (artist avatar) when the release has none.
        $selection['thumbnail'] ??= $profile['thumbnail'];

        return ConnectResult::ok($selection, $origin);
    }
}
