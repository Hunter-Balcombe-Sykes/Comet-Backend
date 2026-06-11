<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\ConnectYoutubeMusicRequest;
use App\Http\Requests\Platforms\SaveYoutubeMusicHighlightsRequest;
use App\Http\Resources\Platforms\YoutubeMusicConnectionResource;
use App\Services\Platforms\YoutubeScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// YouTube Music — connect by artist/channel URL (music.youtube.com/channel/UC…,
// a youtube.com channel URL, or an @handle). YouTube Music has no keyless API
// of its own, but every artist page IS a YouTube channel, so the channel's
// uploads RSS feed provides the releases — links and the canonical profile URL
// are rewritten onto music.youtube.com so visitors land in YouTube Music.
// Vimeo-style curation on top: a recent-releases picker feeds up to 5
// user-chosen "highlight" items on the selection.
class YoutubeMusicController extends SingleSelectionPlatformController
{
    private const MAX_ITEMS = 12;

    private const MAX_HIGHLIGHTS = 5;

    public function __construct(private readonly YoutubeScraper $scraper) {}

    protected function platform(): string
    {
        return 'youtube-music';
    }

    protected function resourceClass(): string
    {
        return YoutubeMusicConnectionResource::class;
    }

    // POST /api/platforms/youtube-music/connect
    public function connect(ConnectYoutubeMusicRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $channelId = $this->scraper->channelIdFrom($request->validated()['url']);
        if (! $channelId) {
            return $this->error('Enter your YouTube Music artist URL (music.youtube.com/channel/…) or your channel @handle.', 422);
        }

        $feed = $this->scraper->fetchUploadsFeed($channelId, self::MAX_ITEMS);
        if ($feed === null) {
            return $this->error('Could not load releases for that channel.', 404);
        }

        $items = self::musicItems($feed['videos']);
        $url = 'https://music.youtube.com/channel/'.$channelId;

        // Reconnecting the SAME channel keeps the chosen highlights; switching
        // to a different one resets them (they belonged to the old channel).
        $existing = $this->readConnection($user);
        $highlights = data_get($existing, 'channelId') === $channelId
            ? data_get($existing, 'highlights', [])
            : [];

        return $this->connected($user, [
            'url' => $url,
            'channelId' => $channelId,
            // Auto-generated artist channels are titled "<Artist> - Topic".
            'name' => $feed['title'] !== null ? preg_replace('/\s+-\s+Topic$/', '', $feed['title']) : null,
            'thumbnail' => $items[0]['thumbnail'] ?? null,
            'link' => $url,
            'latest' => $items[0] ?? null,
            'items' => $items,
            'highlights' => $highlights,
        ]);
    }

    // GET /api/platforms/youtube-music/recent — the latest releases for the
    // highlights picker (the uploads feed caps at 15).
    public function recent(Request $request): JsonResponse
    {
        $channelId = data_get($this->readConnection($this->currentUser($request)), 'channelId');
        if (! $channelId) {
            return $this->error('Connect a YouTube Music artist first.', 404);
        }

        $feed = $this->scraper->fetchUploadsFeed((string) $channelId);
        if ($feed === null || $feed['videos'] === []) {
            return $this->error('Could not load recent releases for that channel.', 422);
        }

        return $this->success(['videos' => self::musicItems($feed['videos'])]);
    }

    // POST /api/platforms/youtube-music/highlights — snapshot up to 5 chosen
    // releases (by itemId, from the recent list). An empty list clears them.
    public function highlights(SaveYoutubeMusicHighlightsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->currentUser($request);

        return $this->withConnectionLock($user, function () use ($user, $validated): JsonResponse {
            $selection = $this->readConnection($user);
            if (! $selection) {
                return $this->error('Connect a YouTube Music artist first.', 404);
            }

            $feed = $this->scraper->fetchUploadsFeed((string) data_get($selection, 'channelId'));
            if ($feed === null || $feed['videos'] === []) {
                return $this->error('Could not load recent releases for that channel.', 422);
            }
            $items = self::musicItems($feed['videos']);

            // Keep the auto-latest tile + items grid fresh alongside the picks.
            $selection['latest'] = $items[0] ?? null;
            $selection['items'] = array_slice($items, 0, self::MAX_ITEMS);

            // Snapshot the chosen releases in the order the user posted them.
            $byId = collect($items)->keyBy('itemId');
            $selection['highlights'] = collect($validated['itemIds'])
                ->map(fn (string $id) => $byId->get($id))
                ->filter()
                ->take(self::MAX_HIGHLIGHTS)
                ->values()
                ->all();

            $this->writeConnection($user, $selection);

            return $this->success((new YoutubeMusicConnectionResource($selection))->resolve());
        });
    }

    /**
     * Feed videos → the music item shape: links land on music.youtube.com and
     * each item carries the standard YouTube embed for inline playback.
     *
     * @param  list<array{videoId:string, name:string, link:string, thumbnail:string}>  $videos
     * @return list<array{itemId:string, name:string, thumbnail:string, link:string, embedUrl:string}>
     */
    public static function musicItems(array $videos): array
    {
        return array_map(fn (array $v) => [
            'itemId' => $v['videoId'],
            'name' => $v['name'],
            'thumbnail' => $v['thumbnail'],
            'link' => 'https://music.youtube.com/watch?v='.$v['videoId'],
            'embedUrl' => 'https://www.youtube.com/embed/'.$v['videoId'],
        ], $videos);
    }
}
