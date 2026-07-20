<?php

namespace App\Services\Platforms\Strategies\Highlights;

use App\Services\Platforms\Payloads\FeedPayload;
use App\Services\Platforms\Strategies\Contracts\HighlightsStrategy;
use App\Services\Platforms\YoutubeMusicItems;
use App\Services\Platforms\YoutubeScraper;

// YouTube Music picker: latest releases from the uploads feed (caps at 15);
// up to 5 curated highlights; latest tile + items grid refresh on every save.
// Moved verbatim from YoutubeMusicController::recent/highlights.
class YoutubeMusicHighlights implements HighlightsStrategy
{
    private const MAX_ITEMS = 12;

    private const MAX_HIGHLIGHTS = 5;

    public function __construct(private readonly YoutubeScraper $scraper) {}

    public function identity(array $payload): ?string
    {
        $channelId = FeedPayload::fromArray($payload)->channelId;

        return $channelId !== null ? (string) $channelId : null;
    }

    public function recentItems(string $identity): ?array
    {
        $feed = $this->scraper->fetchUploadsFeed($identity);
        if ($feed === null || $feed['videos'] === []) {
            return null;
        }

        return YoutubeMusicItems::map($feed['videos']);
    }

    public function apply(array $selection, array $items, array $chosenIds): array
    {
        // Keep the auto-latest tile + items grid fresh alongside the picks.
        $selection['latest'] = $items[0] ?? null;
        $selection['items'] = array_slice($items, 0, self::MAX_ITEMS);

        $byId = collect($items)->keyBy('itemId');
        $selection['highlights'] = collect($chosenIds)
            ->map(fn (string $id) => $byId->get($id))
            ->filter()
            ->take(self::MAX_HIGHLIGHTS)
            ->values()
            ->all();

        // Keep the picker's private snapshot (HighlightsPicker::SNAPSHOT_KEY)
        // warm with the items this save was handed, so the picker stays fast
        // even between scheduled refreshes.
        $selection['recent'] = $items;

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
            // `present` (not `required`) so an empty array is a valid "clear
            // my highlights" submission.
            'itemIds' => ['present', 'array', 'max:5'],
            'itemIds.*' => ['string', 'max:30'],
        ];
    }

    public function responseKey(): string
    {
        return 'videos';
    }

    public function notConnectedMessage(): string
    {
        return 'Connect a YouTube Music artist first.';
    }

    public function loadErrorMessage(): string
    {
        return 'Could not load recent releases for that channel.';
    }
}
