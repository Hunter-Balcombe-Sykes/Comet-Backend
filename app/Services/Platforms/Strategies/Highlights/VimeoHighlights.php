<?php

namespace App\Services\Platforms\Strategies\Highlights;

use App\Services\Platforms\Payloads\FeedPayload;
use App\Services\Platforms\Strategies\Contracts\HighlightsStrategy;
use App\Services\Platforms\VimeoApi;

// Vimeo picker: latest uploads (keyless API caps a page at 20); up to 5
// curated highlights; latest tile + items grid refresh on every save. Moved
// verbatim from VimeoController::recent/highlights.
class VimeoHighlights implements HighlightsStrategy
{
    private const MAX_ITEMS = 12;

    private const MAX_HIGHLIGHTS = 5;

    public function __construct(private readonly VimeoApi $vimeo) {}

    public function identity(array $payload): ?string
    {
        $apiPath = FeedPayload::fromArray($payload)->apiPath;

        return $apiPath !== null ? (string) $apiPath : null;
    }

    public function recentItems(string $identity): ?array
    {
        $videos = $this->vimeo->fetchVideos($identity);

        // Vimeo's frozen contract treats an empty page as a load failure.
        return $videos === [] ? null : $videos;
    }

    public function apply(array $selection, array $items, array $chosenIds): array
    {
        // Keep the auto-latest tile + items grid fresh alongside the picks.
        // Profile name/avatar stay as connected — they aren't video fields.
        $selection['latest'] = $items[0];
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
        return 'Connect a Vimeo profile first.';
    }

    public function loadErrorMessage(): string
    {
        return 'Could not load recent videos for that profile.';
    }
}
