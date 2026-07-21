<?php

namespace App\Services\Platforms\Strategies\Highlights;

use App\Services\Platforms\Concerns\RefreshesLatestTile;
use App\Services\Platforms\Payloads\FeedPayload;
use App\Services\Platforms\Strategies\Contracts\HighlightsStrategy;
use App\Services\Platforms\YoutubeScraper;

// YouTube picker: last 15 videos; up to 5 curated highlights; the "Most
// recent" tile (+ flat back-compat fields) refreshes on every save. Moved
// verbatim from YoutubeController::recent/highlights.
class YoutubeHighlights implements HighlightsStrategy
{
    use RefreshesLatestTile;

    private const MAX_HIGHLIGHTS = 5;

    private const FLAT_TILE_FIELDS = ['name', 'description', 'link', 'thumbnail'];

    public function __construct(private readonly YoutubeScraper $scraper) {}

    public function identity(array $payload): ?string
    {
        return FeedPayload::fromArray($payload)->handle;
    }

    public function recentItems(string $identity): ?array
    {
        return $this->scraper->fetchRecentVideos($identity);
    }

    public function apply(array $selection, array $items, array $chosenIds): array
    {
        // A video published since connect must refresh `latest` + flat fields,
        // not just the highlights (CONS-1).
        if (isset($items[0])) {
            $selection = $this->refreshLatestTile($selection, $items[0], self::FLAT_TILE_FIELDS);
        }

        $byId = collect($items)->keyBy('videoId');
        $selection['highlights'] = collect($chosenIds)
            ->map(fn (string $id) => $byId->get($id))
            ->filter()
            ->take(self::MAX_HIGHLIGHTS)
            ->values()
            ->all();

        // Keep the picker's private snapshot (HighlightsPicker::SNAPSHOT_KEY)
        // warm with the items this save was handed, so the picker stays fast
        // even between scheduled refreshes.
        $selection['recent'] = array_slice($items, 0, 15);

        return $selection;
    }

    public function requestField(): string
    {
        return 'videoIds';
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'videoIds' => ['present', 'array', 'max:5'],
            'videoIds.*' => ['string', 'max:30'],
        ];
    }

    public function responseKey(): string
    {
        return 'videos';
    }

    public function notConnectedMessage(): string
    {
        return 'Connect a YouTube channel first.';
    }

    public function loadErrorMessage(): string
    {
        return 'Could not load recent videos for that channel.';
    }
}
