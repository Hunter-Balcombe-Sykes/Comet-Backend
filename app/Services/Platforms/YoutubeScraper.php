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

    // Reduce any of bare handle / @handle / full URL to a bare handle.
    public function normalizeHandle(string $input): string
    {
        $s = trim($input);
        if (str_starts_with($s, 'http') && preg_match('~youtube\.com/(?:@|c/|user/)([A-Za-z0-9._-]+)~i', $s, $m)) {
            return $m[1];
        }

        return ltrim($s, '@');
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
        $headers = ['User-Agent' => self::USER_AGENT];

        $channelId = $this->resolveChannelId($handle, $headers);
        if ($channelId === null) {
            return null;
        }

        // Use the channel's uploads-playlist feed (UU…) rather than the channel
        // feed (UC…). On a fresh upload the channel_id feed can lag hours — or
        // never populate at all for new / low-volume channels — whereas the
        // uploads-playlist feed updates within minutes. The uploads playlist id
        // is the channel id with its "UC" prefix swapped to "UU".
        $uploadsPlaylistId = 'UU'.substr($channelId, 2);
        $rss = $this->fetcher->fetch('https://www.youtube.com/feeds/videos.xml?playlist_id='.$uploadsPlaylistId, $headers);
        if ($rss['status'] !== 200) {
            return null;
        }

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

        return $out;
    }

    // Channel page → the channel's OWN canonical ID. A channel page lists several
    // "channelId" values (featured/related channels, video owners) and the first
    // is NOT reliably the channel itself — so prefer "externalId" / the canonical
    // /channel/<id> URL (both the page owner's ID). Falls back to the first
    // channelId only if neither is present. (Fixes @casey → wrong side-channel.)
    private function resolveChannelId(string $handle, array $headers): ?string
    {
        $page = $this->fetcher->fetch('https://www.youtube.com/@'.rawurlencode($handle), $headers);
        if ($page['status'] !== 200) {
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
