<?php

namespace App\Services\Platforms;

use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\Log;

// Scrapes a YouTube channel's recent uploads with no API key. Two
// unauthenticated fetches: the channel page (to resolve the channel's own ID)
// then the RSS feed (the 15 most-recent uploads — YouTube's hard cap). Extracted
// from the youtube connect strategy so it stays thin and the scrape is reusable.
// Spec: ~/Developer/platform link capabilites/youtube-implementation.md
class YoutubeScraper extends PlatformScraper
{
    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly YoutubeThumbnailResolver $thumbnails,
    ) {}

    // Paths that never address a channel: video links (a video is not a
    // channel) and YouTube's own utility pages. Also catches a /channel/ URL
    // whose id is NOT a well-formed UC id — without the lookahead that would
    // fall through to the legacy-vanity branch below and yield the literal
    // handle "channel".
    private const NON_CHANNEL_PATH = '~youtu\.be/|youtube\.com/(?:watch|shorts/|live/|embed/|playlist|results|feed/|channel/(?!UC[A-Za-z0-9_-]{22}))~i';

    /**
     * Reduce any channel reference — bare handle, @handle, or full URL (scheme
     * optional) — to the identity persisted as payload.handle: either a bare
     * handle or, for /channel/ URLs, the UC… id itself. Both round-trip through
     * fetchRecentVideos(), which is what YoutubeFetch replays on every refresh.
     * Returns '' for anything that doesn't address a channel, so the caller
     * fails with the descriptor's "Enter your YouTube channel." rather than
     * sending garbage to the @handle resolver and reporting it as missing.
     */
    public function normalizeHandle(string $input): string
    {
        $s = PlatformInput::urlish($input);

        if (preg_match(self::NON_CHANNEL_PATH, $s)) {
            return '';
        }

        // /channel/UC… — YouTube's own "share channel" URL. The id IS the
        // identity here; there is no handle to recover from it without a fetch,
        // and channelIdFrom() short-circuits a raw id straight to the feed.
        if (preg_match('~youtube\.com/channel/(UC[A-Za-z0-9_-]{22})~i', $s, $m)) {
            return $m[1];
        }

        if (preg_match('~youtube\.com/(?:@|c/|user/)([A-Za-z0-9._-]+)~i', $s, $m)) {
            return $m[1];
        }

        // Legacy bare vanity ("youtube.com/MrBeast") — pre-@ custom URLs still
        // circulate and still resolve. Last, so every prefixed form above wins.
        if (preg_match('~youtube\.com/([A-Za-z0-9._-]+)~i', $s, $m)) {
            return $m[1];
        }

        return PlatformInput::token($s);
    }

    /**
     * Resolve any channel reference to its UC… channel id: a raw id, a
     * /channel/UC… URL on youtube.com OR music.youtube.com (scheme optional),
     * or a handle/@handle/handle-URL (resolved via the channel page).
     */
    public function channelIdFrom(string $input): ?string
    {
        $s = PlatformInput::urlish($input);

        if (preg_match('~(?:^|/channel/|/browse/)(UC[A-Za-z0-9_-]{22})~', $s, $m)) {
            return $m[1];
        }

        $handle = $this->normalizeHandle($s);
        if ($handle === '' || str_contains($handle, '/') || str_contains($handle, '.com')) {
            return null;
        }

        return $this->resolveChannelId($handle, ['User-Agent' => self::USER_AGENT]);
    }

    /**
     * The channel's most-recent videos, newest first, up to $limit (the RSS
     * feed itself caps at 15). Returns null when the channel or feed can't be
     * resolved; an empty array is possible for a channel with no uploads.
     *
     * @return list<array{videoId:string, name:string, description:string, link:string, date:?string, thumbnail:string}>|null
     */
    public function fetchRecentVideos(string $handle, int $limit = 15): ?array
    {
        // Via channelIdFrom(), NOT the private handle-only resolver: the stored
        // identity may be a bare handle OR a UC… id (see normalizeHandle), and
        // only channelIdFrom() accepts both — a raw id short-circuits to the
        // feed with no channel-page fetch. Reaching past it is what made a
        // pasted /channel/UC… URL 404 while the same channel's @handle worked.
        $channelId = $this->channelIdFrom($handle);
        if ($channelId === null) {
            return null;
        }

        return $this->fetchUploadsFeed($channelId, $limit)['videos'] ?? null;
    }

    /**
     * The uploads feed for a known channel id: the feed-level channel title +
     * the most-recent videos (same shape as fetchRecentVideos). Null when the
     * feed can't be fetched.
     *
     * @return array{title: ?string, videos: list<array{videoId:string, name:string, description:string, link:string, date:?string, thumbnail:string}>}|null
     */
    public function fetchUploadsFeed(string $channelId, int $limit = 15, ?ConditionalContext $cond = null): ?array
    {
        $headers = array_merge(['User-Agent' => self::USER_AGENT], $cond?->headers() ?? []);

        // The channel feed (?channel_id=UC…) is the only uploads feed YouTube
        // still serves. This used to request the uploads-PLAYLIST feed
        // (?playlist_id=UU…, the channel id with "UC" swapped to "UU") because
        // it updates within minutes where the channel feed can lag hours on a
        // fresh upload; as of 2026-07 that feed 404s/500s for every channel, so
        // the freshness win no longer exists to trade for. Every connect
        // attempt failed with a resolved-but-unfetchable channel until this
        // swapped back. Do not "restore" the UU feed without re-checking it live.
        $feedUrl = 'https://www.youtube.com/feeds/videos.xml?channel_id='.$channelId;
        $rss = $this->fetcher->tryFetch($feedUrl, $headers);

        // M-13 (B7 live): the feed intermittently 404s for channels that
        // verifiably exist — the id was resolved from the live channel page
        // moments earlier, and the identical request served 200 on the other
        // test account a minute later. On the auto-route path a terminal miss
        // permanently drops the channel (ConnectFetchJob catches the
        // exception, so job tries never engage, and F26 removes the
        // never-fetched row with nobody watching a modal to retry). One
        // re-request before giving up. Transport-level null deliberately does
        // NOT retry: SafeUrlFetcher's null covers SSRF/DNS/timeout, where an
        // immediate second attempt is noise. 304 is a healthy answer, not an
        // error.
        if (is_array($rss) && ! in_array($rss['status'], [200, 304], true)) {
            usleep(500_000);
            $rss = $this->fetcher->tryFetch($feedUrl, $headers) ?? $rss;
        }

        if ($rss === null) {
            // LIFE-26: transport-level failure (SSRF/timeout/DNS) reaching the feed.
            Log::warning('youtube.uploads_feed_failed', ['channelId' => $channelId, 'reason' => 'fetch_null']);

            return null;
        }
        // 304 Not Modified → let the caller (a fetch strategy) short-circuit. On a
        // 200, capture the fresh ETag/Last-Modified for next time. Normal on every
        // healthy poll — deliberately NOT logged (LIFE-26: would be pure noise).
        if ($cond !== null && $cond->handle($rss)) {
            return null;
        }
        if ($rss['status'] !== 200) {
            // LIFE-26: reachable but an explicit non-200 (e.g. 403/404/5xx).
            Log::warning('youtube.uploads_feed_failed', ['channelId' => $channelId, 'reason' => 'non_200:'.$rss['status']]);

            return null;
        }

        // Channel display name from the feed head (before any <entry>): the
        // feed-level <author><name> carries it verbatim; the feed's own <title>
        // is the fallback, stripped of the "Uploads from " prefix the retired
        // playlist feed used to carry.
        $head = explode('<entry>', $rss['body'], 2)[0];
        preg_match('~<author>\s*<name>([^<]*)</name>~', $head, $an);
        preg_match('~<title>([^<]*)</title>~', $head, $ft);
        $rawTitle = trim($an[1] ?? '') !== ''
            ? trim($an[1])
            : preg_replace('~^Uploads from\s+~i', '', trim($ft[1] ?? ''));
        $feedTitle = $rawTitle !== ''
            ? html_entity_decode($rawTitle, ENT_QUOTES | ENT_HTML5)
            : null;

        preg_match_all('~<entry>(.*?)</entry>~s', $rss['body'], $entries);

        $out = [];
        foreach ($entries[1] as $entry) {
            if (! preg_match('~<yt:videoId>([^<]+)</yt:videoId>~', $entry, $vm)) {
                continue;
            }
            $videoId = $vm[1];
            preg_match('~<title>([^<]+)</title>~', $entry, $tm);
            preg_match('~<media:description>(.*?)</media:description>~s', $entry, $dm);
            // Atom <published> is the upload timestamp (ISO8601) — carried through
            // so the sitepage can sort videos/releases chronologically.
            preg_match('~<published>([^<]+)</published>~', $entry, $pm);

            $out[] = [
                'videoId' => $videoId,
                'name' => html_entity_decode($tm[1] ?? '', ENT_QUOTES | ENT_HTML5),
                'description' => trim(html_entity_decode($dm[1] ?? '', ENT_QUOTES | ENT_HTML5)),
                'link' => "https://www.youtube.com/watch?v={$videoId}",
                'date' => trim($pm[1] ?? '') ?: null,
                // Filled in below from a single batched maxres-vs-hq probe.
                'thumbnail' => '',
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        // One batched probe for every video: maxresdefault.jpg (1280×720 16:9)
        // where available, hqdefault.jpg otherwise. Replaces a per-entry hq guess.
        $thumbnails = $this->thumbnails->bestForMany(array_column($out, 'videoId'));
        foreach ($out as &$entry) {
            // bestForMany() omits ids it rejects as malformed (and always has,
            // for empty ones) — a missing key is "no thumbnail", not a crash.
            $entry['thumbnail'] = $thumbnails[$entry['videoId']] ?? '';
        }
        unset($entry);

        return ['title' => $feedTitle, 'videos' => $out];
    }

    // Channel page → the channel's OWN canonical ID. A channel page lists several
    // "channelId" values (featured/related channels, video owners) and the first
    // is NOT reliably the channel itself — so prefer "externalId" / the canonical
    // /channel/<id> URL (both the page owner's ID). Falls back to the first
    // channelId only if neither is present. (Fixes @casey → wrong side-channel.)
    private function resolveChannelId(string $handle, array $headers): ?string
    {
        $page = $this->fetcher->tryFetch('https://www.youtube.com/@'.rawurlencode($handle), $headers);
        if ($page === null || $page['status'] !== 200) {
            // LIFE-25: distinguish a transport failure (SSRF/timeout/DNS — $page
            // null) from an explicit non-200 so the two show up differently in logs.
            Log::warning('youtube.channel_resolve_failed', [
                'handle' => $handle,
                'reason' => $page === null ? 'fetch_failed' : 'non_200:'.$page['status'],
            ]);

            return null;
        }

        if (! preg_match('/"externalId":"(UC[A-Za-z0-9_-]{22})"/', $page['body'], $m)
            && ! preg_match('~/channel/(UC[A-Za-z0-9_-]{22})~', $page['body'], $m)
            && ! preg_match('/"channelId":"(UC[A-Za-z0-9_-]{22})"/', $page['body'], $m)) {
            // LIFE-25: page fetched fine (200) but none of the three id patterns
            // matched — a page-layout change, not a transport failure.
            Log::warning('youtube.channel_resolve_failed', [
                'handle' => $handle,
                'reason' => 'no_channel_id_match',
            ]);

            return null;
        }

        return $m[1];
    }
}
