<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Api\Platforms\Concerns\RefreshesLatestTile;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\ConnectYoutubeRequest;
use App\Http\Requests\Platforms\SaveYoutubeHighlightsRequest;
use App\Services\Platforms\YoutubeScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Test-mode endpoints for the YouTube integration. Single-tenant cache, no
// auth, no migration. Takes a channel handle (or URL) and stores the channel's
// auto-latest video PLUS up to 5 user-chosen "highlight" videos from the last 15.
// Scraping lives in App\Services\Platforms\YoutubeScraper. Full spec:
//   ~/Developer/platform link capabilites/youtube-implementation.md
class YoutubeController extends ApiController
{
    use ManagesIntegrationConnection;
    use RefreshesLatestTile;
    use ResolveCurrentUser;

    private const MAX_HIGHLIGHTS = 5;

    private const FLAT_TILE_FIELDS = ['name', 'description', 'link', 'thumbnail'];

    public function __construct(private readonly YoutubeScraper $scraper) {}

    protected function platform(): string
    {
        return 'youtube';
    }

    // POST /api/platforms/youtube/connect — store the auto-latest video for the user.
    public function connect(ConnectYoutubeRequest $request): JsonResponse
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

        // Reconnecting the SAME channel keeps the chosen highlights; switching to
        // a different channel resets them (they belonged to the old channel).
        $existing = $this->readConnection($user);
        $highlights = data_get($existing, 'handle') === $handle ? data_get($existing, 'highlights', []) : [];

        $selection = [
            'handle' => $handle,
            // Flat fields retained for partna-pages + back-compat. The nested
            // `latest` is the canonical shape (same as a highlight item) and is
            // what the dashboard reads to render the "Most recent" tile.
            ...$this->flatTileFields($latest, self::FLAT_TILE_FIELDS),
            'latest' => $latest,
            'highlights' => $highlights,
        ];
        $this->writeConnection($user, $selection);

        return $this->success($selection);
    }

    // GET /api/platforms/youtube/recent — the last 15 videos for the highlights picker.
    public function recent(Request $request): JsonResponse
    {
        $handle = data_get($this->readConnection($this->currentUser($request)), 'handle');
        if (! $handle) {
            return $this->error('Connect a YouTube channel first.', 404);
        }

        $videos = $this->scraper->fetchRecentVideos($handle);
        if ($videos === null) {
            return $this->error('Could not load recent videos for that channel.', 502);
        }

        return $this->success(['videos' => $videos]);
    }

    // POST /api/platforms/youtube/highlights — snapshot up to 5 chosen videos
    // (by videoId, from the last 15) into the saved selection. An empty list clears them.
    public function highlights(SaveYoutubeHighlightsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->currentUser($request);

        return $this->withConnectionLock($user, function () use ($user, $validated): JsonResponse {
            $selection = $this->readConnection($user);
            if (! $selection) {
                return $this->error('Connect a YouTube channel first.', 404);
            }

            $videos = $this->scraper->fetchRecentVideos(data_get($selection, 'handle'));
            if ($videos === null) {
                return $this->error('Could not load recent videos for that channel.', 502);
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

            $this->writeConnection($user, $selection);

            return $this->success($selection);
        });
    }

    // GET /api/platforms/youtube/selection — the authenticated user's saved channel.
    public function selection(Request $request): JsonResponse
    {
        return $this->success(['selection' => $this->readConnection($this->currentUser($request))]);
    }

    // DELETE /api/platforms/youtube — clear the authenticated user's connection.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
    }
}
