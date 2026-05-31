<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesPlatformSelection;
use App\Services\SmartLinks\SafeUrlFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Test-mode endpoints for the YouTube integration. Single-tenant cache, no
// auth, no migration. Takes a channel handle (or URL), resolves it to a
// channel ID, and stores the channel's latest video. Full spec:
//   ~/Developer/platform link capabilites/youtube-implementation.md
//
// Two unauthenticated server-side fetches: the channel page (for the channel
// ID) then the RSS feed (for the latest video). No API key.
class YoutubeController extends ApiController
{
    use ManagesPlatformSelection;

    private const SELECTION_KEY = 'platforms.youtube.selection';

    private const SCRAPE_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    protected function selectionKey(): string
    {
        return self::SELECTION_KEY;
    }

    // POST /api/platforms/youtube/connect — fetch the latest video, store + return.
    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', 'string', 'max:200'],
        ]);

        $handle = $this->normalizeHandle($validated['channel']);
        if ($handle === '') {
            return $this->error('Enter your YouTube channel.', 422);
        }

        $video = $this->fetchLatestVideo($handle);
        if ($video === null) {
            return $this->error('Could not find that YouTube channel or its latest video.', 404);
        }

        $selection = ['handle' => $handle, ...$video];
        $this->writeSelection($selection);

        return $this->success($selection);
    }

    // ── internals ────────────────────────────────────────────────

    // Reduce any of bare handle / @handle / full URL to a bare handle.
    private function normalizeHandle(string $input): string
    {
        $s = trim($input);
        if (str_starts_with($s, 'http') && preg_match('~youtube\.com/(?:@|c/|user/)([A-Za-z0-9._-]+)~i', $s, $m)) {
            return $m[1];
        }

        return ltrim($s, '@');
    }

    /**
     * @return array{name:string, description:string, link:string, thumbnail:string}|null
     */
    private function fetchLatestVideo(string $handle): ?array
    {
        $headers = ['User-Agent' => self::SCRAPE_USER_AGENT];

        // Stage 1 — channel page → the channel's OWN canonical ID. A channel
        // page lists several "channelId" values (featured/related channels,
        // video owners) and the first is NOT reliably the channel itself — so
        // prefer "externalId" / the canonical /channel/<id> URL, both of which
        // are the page owner's ID. Fall back to the first channelId only if
        // neither is present.
        $page = $this->fetcher->fetch('https://www.youtube.com/@'.rawurlencode($handle), $headers);
        if ($page['status'] !== 200) {
            return null;
        }
        if (! preg_match('/"externalId":"(UC[A-Za-z0-9_-]{22})"/', $page['body'], $m)
            && ! preg_match('~/channel/(UC[A-Za-z0-9_-]{22})~', $page['body'], $m)
            && ! preg_match('/"channelId":"(UC[A-Za-z0-9_-]{22})"/', $page['body'], $m)) {
            return null;
        }
        $channelId = $m[1];

        // Stage 2 — RSS feed → first entry.
        $rss = $this->fetcher->fetch('https://www.youtube.com/feeds/videos.xml?channel_id='.$channelId, $headers);
        if ($rss['status'] !== 200 || ! preg_match('~<entry>(.*?)</entry>~s', $rss['body'], $em)) {
            return null;
        }
        $entry = $em[1];

        if (! preg_match('~<yt:videoId>([^<]+)</yt:videoId>~', $entry, $vm)) {
            return null;
        }
        $videoId = $vm[1];
        preg_match('~<title>([^<]+)</title>~', $entry, $tm);
        preg_match('~<media:description>(.*?)</media:description>~s', $entry, $dm);

        return [
            'name' => html_entity_decode($tm[1] ?? '', ENT_QUOTES | ENT_HTML5),
            'description' => trim(html_entity_decode($dm[1] ?? '', ENT_QUOTES | ENT_HTML5)),
            'link' => "https://www.youtube.com/watch?v={$videoId}",
            'thumbnail' => "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
        ];
    }
}
