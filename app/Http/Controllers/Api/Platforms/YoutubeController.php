<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\Platforms\Concerns\RefreshesLatestTile;
use App\Http\Requests\Platforms\PlatformConnectRequest;
use App\Http\Requests\Platforms\SaveYoutubeHighlightsRequest;
use App\Http\Resources\Platforms\YoutubeConnectionResource;
use App\Services\Platforms\Payloads\FeedPayload;
use App\Services\Platforms\YoutubeScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Test-mode endpoints for the YouTube integration. Takes a channel handle (or
// URL) and stores the channel's auto-latest video PLUS up to 5 user-chosen
// "highlight" videos from the last 15. Scraping lives in
// App\Services\Platforms\YoutubeScraper. Full spec:
//   ~/Developer/platform link capabilites/youtube-implementation.md
class YoutubeController extends SingleSelectionPlatformController
{
    use RefreshesLatestTile;

    private const MAX_HIGHLIGHTS = 5;

    private const FLAT_TILE_FIELDS = ['name', 'description', 'link', 'thumbnail'];

    public function __construct(private readonly YoutubeScraper $scraper) {}

    protected function platform(): string
    {
        return 'youtube';
    }

    protected function resourceClass(): string
    {
        return YoutubeConnectionResource::class;
    }

    // Watch platform — multiple channel accounts (shop-style list).
    protected function supportsMultipleAccounts(): bool
    {
        return true;
    }

    // POST /api/platforms/youtube/connect — add a channel account with its
    // auto-latest video.
    public function connect(PlatformConnectRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $validated = $request->validated();

        $handle = $this->scraper->normalizeHandle($validated['channel']);
        if ($handle === '') {
            return $this->error('Enter your YouTube channel.', 422);
        }

        $videos = $this->scraper->fetchRecentVideos($handle);
        if (empty($videos)) {
            return $this->error('Could not find that YouTube channel or its latest video.', 404);
        }
        $latest = $videos[0];

        // Re-adding an already-connected channel keeps that account's chosen
        // highlights; a new channel starts with none.
        $existing = $this->matchAccountRow($user, 'handle', $handle)?->payload;
        $highlights = data_get($existing, 'highlights', []);

        $selection = [
            'handle' => $handle,
            // Flat fields retained for partna-pages + back-compat. The nested
            // `latest` is the canonical shape (same as a highlight item) and is
            // what the dashboard reads to render the "Most recent" tile.
            ...$this->flatTileFields($latest, self::FLAT_TILE_FIELDS),
            'latest' => $latest,
            'highlights' => $highlights,
        ];

        return $this->connected($user, $selection);
    }

    // GET /api/platforms/youtube/recent?account={id} — the last 15 videos for
    // the highlights picker. Defaults to the first account when no id is given.
    public function recent(Request $request): JsonResponse
    {
        $row = $this->requestedAccountRow($this->currentUser($request), $request->query('account'));
        $handle = FeedPayload::fromArray($row?->payload ?? [])->handle;
        if (! $handle) {
            return $this->error('Connect a YouTube channel first.', 404);
        }

        $videos = $this->scraper->fetchRecentVideos($handle);
        if ($videos === null) {
            return $this->error('Could not load recent videos for that channel.', 422);
        }

        return $this->success(['videos' => $videos]);
    }

    // POST /api/platforms/youtube/highlights?account={id} — snapshot up to 5
    // chosen videos (by videoId, from the last 15) onto that account's saved
    // selection. An empty list clears them.
    public function highlights(SaveYoutubeHighlightsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->currentUser($request);
        $accountId = $request->query('account');

        return $this->withConnectionLock($user, function () use ($user, $validated, $accountId): JsonResponse {
            $row = $this->requestedAccountRow($user, $accountId);
            $selection = $row?->payload;
            if (! $row || ! $selection) {
                return $this->error('Connect a YouTube channel first.', 404);
            }

            $videos = $this->scraper->fetchRecentVideos(data_get($selection, 'handle'));
            if ($videos === null) {
                return $this->error('Could not load recent videos for that channel.', 422);
            }

            // Refresh the "Most recent" tile too (mirrors AppleController) — a video
            // published since connect would otherwise leave `latest` (and the flat
            // back-compat fields) stale while only the highlights updated.
            if (isset($videos[0])) {
                $selection = $this->refreshLatestTile($selection, $videos[0], self::FLAT_TILE_FIELDS);
            }

            // Snapshot the chosen videos in the order the user posted them.
            $byId = collect($videos)->keyBy('videoId');
            $selection['highlights'] = collect($validated['videoIds'])
                ->map(fn (string $id) => $byId->get($id))
                ->filter()
                ->take(self::MAX_HIGHLIGHTS)
                ->values()
                ->all();

            $this->writeConnection($user, $selection, $row->resource_id);

            return $this->success(['id' => $row->resource_id, ...(new YoutubeConnectionResource($selection))->resolve()]);
        });
    }
}
