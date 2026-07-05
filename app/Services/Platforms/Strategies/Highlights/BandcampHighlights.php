<?php

namespace App\Services\Platforms\Strategies\Highlights;

use App\Services\Platforms\BandcampScraper;
use App\Services\Platforms\Concerns\RefreshesLatestTile;
use App\Services\Platforms\Payloads\FeedPayload;
use App\Services\Platforms\Strategies\Contracts\HighlightsStrategy;

// Bandcamp picker: up to 15 releases; up to 5 curated highlights, each
// price-enriched (bounded concurrent fetch); the "Most recent" tile refreshes
// (price-enriched) on every save. Moved verbatim from BandcampController.
class BandcampHighlights implements HighlightsStrategy
{
    use RefreshesLatestTile;

    private const MAX_HIGHLIGHTS = 5;

    private const FLAT_FIELDS = ['name', 'thumbnail', 'link'];

    public function __construct(private readonly BandcampScraper $scraper) {}

    public function identity(array $payload): ?string
    {
        return FeedPayload::fromArray($payload)->url;
    }

    public function recentItems(string $identity): ?array
    {
        $profile = $this->scraper->fetchProfile($identity);
        if ($profile === null) {
            return null;
        }

        return array_slice($profile['items'], 0, 15);
    }

    public function apply(array $selection, array $items, array $chosenIds): array
    {
        // Refresh the "Most recent" tile too — a release published since
        // connect would otherwise leave `latest` stale while only the
        // highlights updated.
        if (isset($items[0])) {
            $selection = $this->refreshLatestTile($selection, $this->scraper->enrichPrices([$items[0]])[0], self::FLAT_FIELDS);
        }

        $byId = collect($items)->keyBy('itemId');
        $chosen = collect($chosenIds)
            ->map(fn (string $id) => $byId->get($id))
            ->filter()
            ->take(self::MAX_HIGHLIGHTS)
            ->values()
            ->all();
        // Buy price for each curated highlight (bounded concurrent fetch).
        $selection['highlights'] = $this->scraper->enrichPrices($chosen, self::MAX_HIGHLIGHTS);

        return $selection;
    }

    public function requestField(): string
    {
        return 'itemIds';
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'itemIds' => ['present', 'array', 'max:24'],
            'itemIds.*' => ['string', 'max:50'],
        ];
    }

    public function responseKey(): string
    {
        return 'items';
    }

    public function notConnectedMessage(): string
    {
        return 'Connect a Bandcamp page first.';
    }

    public function loadErrorMessage(): string
    {
        return 'Could not load recent releases.';
    }
}
