<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesPlatformSelection;
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
    use ManagesPlatformSelection;

    private const SELECTION_KEY = 'platforms.youtube.selection';

    private const MAX_HIGHLIGHTS = 5;

    public function __construct(private readonly YoutubeScraper $scraper) {}

    protected function selectionKey(): string
    {
        return self::SELECTION_KEY;
    }

    // POST /api/platforms/youtube/connect — store the auto-latest video.
    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', 'string', 'max:200'],
        ]);

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
        $existing = $this->readSelection();
        $highlights = data_get($existing, 'handle') === $handle ? data_get($existing, 'highlights', []) : [];

        $selection = [
            'handle' => $handle,
            'name' => $latest['name'],
            'description' => $latest['description'],
            'link' => $latest['link'],
            'thumbnail' => $latest['thumbnail'],
            'highlights' => $highlights,
        ];
        $this->writeSelection($selection);

        return $this->success($selection);
    }

    // GET /api/platforms/youtube/recent — the last 15 videos for the highlights picker.
    public function recent(): JsonResponse
    {
        $handle = data_get($this->readSelection(), 'handle');
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
    public function highlights(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'videoIds' => ['present', 'array', 'max:'.self::MAX_HIGHLIGHTS],
            'videoIds.*' => ['string', 'max:30'],
        ]);

        $selection = $this->readSelection();
        if (! $selection) {
            return $this->error('Connect a YouTube channel first.', 404);
        }

        $videos = $this->scraper->fetchRecentVideos(data_get($selection, 'handle'));
        if ($videos === null) {
            return $this->error('Could not load recent videos for that channel.', 502);
        }

        // Snapshot the chosen videos in the order the user posted them.
        $byId = collect($videos)->keyBy('videoId');
        $selection['highlights'] = collect($validated['videoIds'])
            ->map(fn (string $id) => $byId->get($id))
            ->filter()
            ->take(self::MAX_HIGHLIGHTS)
            ->values()
            ->all();

        $this->writeSelection($selection);

        return $this->success($selection);
    }
}
