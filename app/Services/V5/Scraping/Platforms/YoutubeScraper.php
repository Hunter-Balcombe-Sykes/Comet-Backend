<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\Http\SafeUrlFetcher;
use App\Services\V5\Scraping\BaseTemplates\HtmlScrapeBase;
use App\Services\V5\Scraping\Budget\FetchBudget;

// V5 YouTube scraper. Fetches channel page + RSS uploads feed. Returns V5 items
// with item_type='video'. Thumbnails use direct URL construction (i.ytimg.com).
class YoutubeScraper extends HtmlScrapeBase
{
    private const NON_CHANNEL_PATH = '~youtu\.be/|youtube\.com/(?:watch|shorts/|live/|embed/|playlist|results|feed/|channel/(?!UC[A-Za-z0-9_-]{22}))~i';

    public function __construct(
        SafeUrlFetcher $fetcher,
        ?FetchBudget $budget = null,
    ) {
        parent::__construct($fetcher, $budget);
    }

    /**
     * Main entry: fetch channel profile + recent videos as V5 items.
     *
     * @return array{display_name:?string, profile_pic_url:?string, bio:?string, follower_count:?int, items:list<array>}|null
     */
    public function fetch(string $input, int $limit = 15): ?array
    {
        $handle = $this->handleFromInput($input);
        if ($handle === null) {
            return null;
        }

        // Resolve channel ID via the channel page (needed for the RSS feed).
        $channelPageUrl = 'https://www.youtube.com/@'.rawurlencode($handle);
        $channelHtml = $this->fetchHtml($channelPageUrl);
        if ($channelHtml === null) {
            return null;
        }

        $channelId = $this->extractChannelId($channelHtml);
        if ($channelId === null) {
            $this->logFailure('youtube', 'channel_resolve', 'no_channel_id_in_page');
            return null;
        }

        $profile = $this->parseProfile($channelHtml) ?? [];

        // RSS uploads feed.
        $feedUrl = "https://www.youtube.com/feeds/videos.xml?channel_id={$channelId}";
        $feedXml = $this->fetchHtml($feedUrl);

        $items = [];
        if ($feedXml !== null) {
            $rawItems = $this->parseYouTubeFeed($feedXml, $limit);
            $videoIds = array_column($rawItems, 'videoId');
            $thumbnails = $this->resolveThumbnails($videoIds);
            $items = $this->mapItems($rawItems, $thumbnails);
            $this->logSuccess('youtube', 'fetch', count($items));
        }

        return array_merge($profile, ['items' => $items]);
    }

    /**
     * Extract profile info (name, avatar, description, subscriber count) from
     * a channel page HTML.
     */
    protected function parseProfile(string $html): ?array
    {
        $name = $this->metaContent($html, 'title');
        if ($name !== null) {
            $name = trim(preg_replace('~\s*-\s*YouTube\s*$~i', '', $name)) ?: null;
        }

        $avatar = $this->metaContent($html, 'image');

        $bio = $this->metaContent($html, 'description');

        // Subscriber count from JSON-LD or embedded script data.
        $followers = $this->extractSubscriberCount($html);

        if ($name === null && $avatar === null && $bio === null) {
            return null;
        }

        return [
            'display_name' => $name,
            'profile_pic_url' => $avatar,
            'bio' => $bio,
            'follower_count' => $followers,
        ];
    }

    // -----------------------------------------------------------------------
    // Input normalization
    // -----------------------------------------------------------------------

    /** Normalize any channel reference to a bare handle string. */
    private function handleFromInput(string $input): ?string
    {
        $s = $this->normalizeToUrl($input);

        if (preg_match(self::NON_CHANNEL_PATH, $s)) {
            return null;
        }

        // /channel/UC… — the id IS the identity here.
        if (preg_match('~youtube\.com/channel/(UC[A-Za-z0-9_-]{22})~i', $s, $m)) {
            return $m[1];
        }

        // @handle, /c/handle, /user/handle
        if (preg_match('~youtube\.com/(?:@|c/|user/)([A-Za-z0-9._-]+)~i', $s, $m)) {
            return $m[1];
        }

        // Legacy bare vanity
        if (preg_match('~youtube\.com/([A-Za-z0-9._-]+)~i', $s, $m)) {
            return $m[1];
        }

        // Bare @handle or handle
        return $this->normalizeHandle($input);
    }

    /** Ensure https:// prefix for URL normalization. */
    protected function normalizeToUrl(string $input): string
    {
        if (! str_starts_with($input, 'http')) {
            return 'https://'.$input;
        }

        return preg_replace('/^http:/', 'https:', $input);
    }

    // -----------------------------------------------------------------------
    // Channel ID resolution
    // -----------------------------------------------------------------------

    /**
     * Extract the UC… channel ID from the channel page HTML. Tries
     * "externalId" first (the channel's own canonical ID), then the
     * /channel/UC… URL, then "channelId" as fallback. Preserved from the
     * old YoutubeScraper::resolveChannelId.
     */
    private function extractChannelId(string $html): ?string
    {
        if (preg_match('/"externalId":"(UC[A-Za-z0-9_-]{22})"/', $html, $m)
            || preg_match('~/channel/(UC[A-Za-z0-9_-]{22})~', $html, $m)
            || preg_match('/"channelId":"(UC[A-Za-z0-9_-]{22})"/', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // RSS feed parsing
    // -----------------------------------------------------------------------

    /**
     * Parse YouTube's Atom uploads feed — extracts video id, title, description,
     * and published date from each <entry>. Preserved from the old
     * YoutubeScraper::fetchUploadsFeed parsing logic.
     *
     * @return list<array{videoId:string, name:string, description:string, link:string, date:?string}>
     */
    private function parseYouTubeFeed(string $xml, int $limit): array
    {
        preg_match_all('~<entry>(.*?)</entry>~s', $xml, $entries);

        $out = [];
        foreach ($entries[1] as $entry) {
            if (! preg_match('~<yt:videoId>([^<]+)</yt:videoId>~', $entry, $vm)) {
                continue;
            }
            $videoId = $vm[1];

            preg_match('~<title>([^<]+)</title>~', $entry, $tm);
            preg_match('~<media:description>(.*?)</media:description>~s', $entry, $dm);
            preg_match('~<published>([^<]+)</published>~', $entry, $pm);

            $out[] = [
                'videoId' => $videoId,
                'name' => html_entity_decode($tm[1] ?? '', ENT_QUOTES | ENT_HTML5),
                'description' => trim(html_entity_decode($dm[1] ?? '', ENT_QUOTES | ENT_HTML5)),
                'link' => "https://www.youtube.com/watch?v={$videoId}",
                'date' => trim($pm[1] ?? '') ?: null,
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // V5 item mapping
    // -----------------------------------------------------------------------

    /**
     * Map raw feed items + resolved thumbnails to V5 items, including embed items.
     *
     * @param  list<array{videoId:string, name:string, description:string, link:string, date:?string}>  $rawItems
     * @param  array<string,string>  $thumbnails  videoId → URL
     * @return list<array{identifier:string, name:string, item_type:string, values:list<array{field_name:string, value:string, format:string}>}>
     */
    private function mapItems(array $rawItems, array $thumbnails): array
    {
        $items = [];
        foreach ($rawItems as $item) {
            $videoId = $item['videoId'];
            $videoItem = $this->buildItem($item, $thumbnails[$videoId] ?? '');

            $items[] = $videoItem;

            // Add embed item alongside each video
            $embedUrl = "https://www.youtube.com/embed/{$videoId}";
            $items[] = $this->buildEmbedItem(
                embedUrl: $embedUrl,
                title: $item['name'],
                thumbnail: $thumbnails[$videoId] ?? null,
                provider: 'YouTube',
                originalIdentifier: $videoItem['identifier'],
            );
        }

        return $items;
    }

    /**
     * Build a single V5 item from a raw feed entry. Overridable by
     * YoutubeMusicScraper for music.youtube.com links.
     */
    protected function buildItem(array $item, string $thumbnail): array
    {
        $videoId = $item['videoId'];

        return [
            'identifier' => "https://www.youtube.com/watch?v={$videoId}",
            'name' => $item['name'],
            'item_type' => 'video',
            'values' => [
                ['field_name' => 'title', 'value' => $item['name'], 'format' => 'text'],
                ['field_name' => 'url', 'value' => "https://www.youtube.com/watch?v={$videoId}", 'format' => 'url'],
                ['field_name' => 'thumbnail', 'value' => $thumbnail, 'format' => 'image'],
                ['field_name' => 'description', 'value' => $item['description'] ?? '', 'format' => 'text'],
                ['field_name' => 'upload_date', 'value' => $item['date'] ?? '', 'format' => 'date'],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Subscriber count extraction
    // -----------------------------------------------------------------------

    /** Attempt to extract subscriber count from channel page HTML/JSON. */
    private function extractSubscriberCount(string $html): ?int
    {
        // JSON-LD with subscriberCount.
        foreach ($this->jsonLdNodes($html) as $node) {
            if (isset($node['subscriberCount']) && is_numeric($node['subscriberCount'])) {
                return (int) $node['subscriberCount'];
            }
        }

        // Embedded ytInitialData subscriber count.
        if (preg_match('/"subscriberCountText":\s*\{[^}]*"simpleText":\s*"([\d,.KMkMBb]+)"/i', $html, $m)) {
            return $this->parseCountString($m[1]);
        }

        // Fallback: plain text match.
        if (preg_match('~([\d,.]+)\s*(?:subscriber|subscriber)s?~i', $html, $m)) {
            return (int) str_replace([',', '.'], '', $m[1]);
        }

        return null;
    }

    private function parseCountString(string $value): ?int
    {
        $value = strtoupper(trim($value));

        if (str_ends_with($value, 'K')) {
            return (int) ((float) $value * 1000);
        }
        if (str_ends_with($value, 'M')) {
            return (int) ((float) $value * 1000000);
        }
        if (str_ends_with($value, 'B')) {
            return (int) ((float) $value * 1000000000);
        }

        return (int) str_replace([',', '.'], '', $value);
    }
    private function resolveThumbnails(array $videoIds): array
    {
        $result = [];
        foreach ($videoIds as $id) {
            $result[$id] = "https://i.ytimg.com/vi/{$id}/hqdefault.jpg";
        }
        return $result;
    }
}
