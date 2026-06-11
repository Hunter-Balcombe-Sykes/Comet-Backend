<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;

// Scrapes a YouTube channel's recent uploads with no API key. Two
// unauthenticated fetches: the channel page (to resolve the channel's own ID)
// then the RSS feed (the 15 most-recent uploads — YouTube's hard cap). Extracted
// from YoutubeController so the controller stays thin and the scrape is reusable.
// Spec: ~/Developer/platform link capabilites/youtube-implementation.md
class YoutubeScraper extends PlatformScraper
{
    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly YoutubeThumbnailResolver $thumbnails,
    ) {}

    // Reduce any of bare handle / @handle / full URL (scheme optional) to a
    // bare handle.
    public function normalizeHandle(string $input): string
    {
        $s = PlatformInput::urlish($input);
        if (preg_match('~youtube\.com/(?:@|c/|user/)([A-Za-z0-9._-]+)~i', $s, $m)) {
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
     * @return list<array{videoId:string, name:string, description:string, link:string, thumbnail:string}>|null
     */
    public function fetchRecentVideos(string $handle, int $limit = 15): ?array
    {
        $channelId = $this->resolveChannelId($handle, ['User-Agent' => self::USER_AGENT]);
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
     * @return array{title: ?string, videos: list<array{videoId:string, name:string, description:string, link:string, thumbnail:string}>}|null
     */
    public function fetchUploadsFeed(string $channelId, int $limit = 15): ?array
    {
        $headers = ['User-Agent' => self::USER_AGENT];

        // Use the channel's uploads-playlist feed (UU…) rather than the channel
        // feed (UC…). On a fresh upload the channel_id feed can lag hours — or
        // never populate at all for new / low-volume channels — whereas the
        // uploads-playlist feed updates within minutes. The uploads playlist id
        // is the channel id with its "UC" prefix swapped to "UU".
        $uploadsPlaylistId = 'UU'.substr($channelId, 2);
        $rss = $this->fetcher->tryFetch('https://www.youtube.com/feeds/videos.xml?playlist_id='.$uploadsPlaylistId, $headers);
        if ($rss === null || $rss['status'] !== 200) {
            return null;
        }

        // Channel display name from the feed head (before any <entry>): the
        // feed-level <author><name> carries it verbatim; the playlist feed's
        // own <title> is "Uploads from <channel>", kept only as a stripped
        // fallback.
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

            $out[] = [
                'videoId' => $videoId,
                'name' => html_entity_decode($tm[1] ?? '', ENT_QUOTES | ENT_HTML5),
                'description' => trim(html_entity_decode($dm[1] ?? '', ENT_QUOTES | ENT_HTML5)),
                'link' => "https://www.youtube.com/watch?v={$videoId}",
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
            $entry['thumbnail'] = $thumbnails[$entry['videoId']];
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
            return null;
        }

        if (! preg_match('/"externalId":"(UC[A-Za-z0-9_-]{22})"/', $page['body'], $m)
            && ! preg_match('~/channel/(UC[A-Za-z0-9_-]{22})~', $page['body'], $m)
            && ! preg_match('/"channelId":"(UC[A-Za-z0-9_-]{22})"/', $page['body'], $m)) {
            return null;
        }

        return $m[1];
    }
}
