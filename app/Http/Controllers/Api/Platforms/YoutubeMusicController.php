<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\PlatformConnectRequest;
use App\Http\Requests\Platforms\SaveYoutubeMusicHighlightsRequest;
use App\Http\Resources\Platforms\YoutubeMusicConnectionResource;
use App\Services\Platforms\Payloads\FeedPayload;
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

    // Listen platform — multiple artist accounts (shop-style list).
    protected function supportsMultipleAccounts(): bool
    {
        return true;
    }

    // channelId is the canonical artist identity (urls vary per input form).
    protected function accountKeyOf(array $selection): ?string
    {
        return is_string($selection['channelId'] ?? null) ? $selection['channelId'] : parent::accountKeyOf($selection);
    }

    // POST /api/platforms/youtube-music/connect
    public function connect(PlatformConnectRequest $request): JsonResponse
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

        // Re-adding an already-connected channel keeps that account's chosen
        // highlights; a new channel starts with none.
        $highlights = $this->preserveHighlights($user, 'channelId', $channelId);

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

    // GET /api/platforms/youtube-music/recent?account={id} — the latest releases
    // for the highlights picker (the uploads feed caps at 15). Defaults to the
    // first account when no account id is given.
    public function recent(Request $request): JsonResponse
    {
        $row = $this->requestedAccountRow($this->currentUser($request), $request->query('account'));
        $channelId = FeedPayload::fromArray($row?->payload ?? [])->channelId;
        if (! $channelId) {
            return $this->error('Connect a YouTube Music artist first.', 404);
        }

        $feed = $this->scraper->fetchUploadsFeed((string) $channelId);
        if ($feed === null || $feed['videos'] === []) {
            return $this->error('Could not load recent releases for that channel.', 422);
        }

        return $this->success(['videos' => self::musicItems($feed['videos'])]);
    }

    // POST /api/platforms/youtube-music/highlights?account={id} — snapshot up to
    // 5 chosen releases (by itemId, from the recent list) onto that account.
    // An empty list clears them.
    public function highlights(SaveYoutubeMusicHighlightsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $this->currentUser($request);
        $accountId = $request->query('account');

        return $this->withConnectionLock($user, function () use ($user, $validated, $accountId): JsonResponse {
            $row = $this->requestedAccountRow($user, $accountId);
            $selection = $row?->payload;
            if (! $row || ! $selection) {
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

            $this->writeConnection($user, $selection, $row->resource_id);

            return $this->success(['id' => $row->resource_id, ...(new YoutubeMusicConnectionResource($selection))->resolve()]);
        });
    }

    /**
     * Feed videos → the music item shape: links land on music.youtube.com and
     * each item carries the standard YouTube embed for inline playback.
     *
     * @param  list<array{videoId:string, name:string, link:string, date:?string, thumbnail:string}>  $videos
     * @return list<array{itemId:string, name:string, thumbnail:string, link:string, date:?string, embedUrl:string}>
     */
    public static function musicItems(array $videos): array
    {
        return array_map(fn (array $v) => [
            'itemId' => $v['videoId'],
            'name' => $v['name'],
            'thumbnail' => $v['thumbnail'],
            'link' => 'https://music.youtube.com/watch?v='.$v['videoId'],
            // YT Music uses the uploads feed, so the upload <published> doubles as
            // the release date — carried through for chronological sorting.
            'date' => $v['date'] ?? null,
            'embedUrl' => 'https://www.youtube.com/embed/'.$v['videoId'],
        ], $videos);
    }
}
