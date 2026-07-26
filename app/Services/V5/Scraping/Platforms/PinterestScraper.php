<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\HtmlScrapeBase;

// V5 Pinterest scraper — two keyless surfaces: the profile page's embedded
// state JSON (full name, follower count, avatar) and the public RSS pin feed
// at /{username}/feed.rss (latest pins with images). Replaces the old
// PinterestScraper.
class PinterestScraper extends HtmlScrapeBase
{
    // Pinterest reserved path segments that are not real profiles.
    private const RESERVED = [
        'pin', 'ideas', 'search', 'settings', 'business', 'today', 'watch',
        'about', 'careers', 'policy', 'help', 'login', 'signup', 'news_hub',
        'oauth', 'resource', 'topics',
    ];

    /**
     * Main entry: fetch profile + recent pins as V5 items.
     *
     * @return array{display_name:?string, profile_pic_url:?string, follower_count:?int, username:?string, items:list<array>}|null
     */
    public function fetch(string $input, int $limit = 12): ?array
    {
        $username = $this->parseUsername($input);
        if ($username === null) {
            return null;
        }

        // Profile from profile page
        $profile = $this->fetchProfile("https://www.pinterest.com/{$username}/");
        if ($profile === null) {
            return null;
        }

        // Pins from RSS feed
        $feedXml = $this->fetchHtml("https://www.pinterest.com/{$username}/feed.rss");
        $items = $feedXml !== null ? $this->parsePinFeed($feedXml, $limit) : [];

        return array_merge($profile, ['items' => $items]);
    }

    /**
     * Parse profile from Pinterest profile page HTML.
     *
     * Uses two strategies:
     * 1. JSON-LD / state scripts — deep-search for a user node matching the
     *    username (most accurate — returns name, image, follower count).
     * 2. Windowed regex fallback — scans around username occurrences in raw
     *    HTML for full_name, follower_count, image_xlarge_url.
     * 3. og:title backstop for the display name.
     *
     * Preserved from the old PinterestScraper::fetchProfile parsing logic.
     */
    protected function parseProfile(string $html): ?array
    {
        // First, try to extract username from the page context.
        $username = null;

        // Attempt to find the profile owner's username from JSON scripts.
        $best = $this->findInJsonScripts($html);
        if ($best !== null) {
            $username = $best['username'];
        }

        if ($username === null) {
            // Fallback: extract from the URL or meta.
            return null;
        }

        // json: best has ['name', 'image', 'followers', 'username']
        $profile = [
            'display_name' => $best['name'],
            'profile_pic_url' => $best['image'],
            'follower_count' => $best['followers'],
        ];

        // og:title backstop for name
        if ($profile['display_name'] === null && ($og = $this->metaContent($html, 'og:title'))) {
            $profile['display_name'] = trim(preg_replace('~\s*\([^)]*\)\s*-\s*Profile.*$~i', '', $og)) ?: null;
        }

        if ($profile['display_name'] === null && $profile['profile_pic_url'] === null && $profile['follower_count'] === null) {
            return null;
        }

        return $profile;
    }

    // -----------------------------------------------------------------------
    // Username extraction
    // -----------------------------------------------------------------------

    /** Extract a Pinterest username from a URL or bare handle. */
    private function parseUsername(string $input): ?string
    {
        $input = $this->normalizeToUrl($input);

        if (preg_match('~^https?://(?:[a-z]{2,3}\.)?pinterest\.(?:com|com\.au|co\.uk|ca|fr|de|es|it|jp|nz)/([A-Za-z0-9_]{3,30})/?~i', $input, $m)) {
            $candidate = $m[1];
        } elseif (preg_match('~^@?([A-Za-z0-9_]{3,30})$~', $input, $m)) {
            $candidate = $m[1];
        } else {
            return null;
        }

        $username = strtolower($candidate);

        return in_array($username, self::RESERVED, true) ? null : $username;
    }

    private function normalizeToUrl(string $input): string
    {
        if (! str_starts_with($input, 'http')) {
            return 'https://'.$input;
        }

        return preg_replace('/^http:/', 'https:', $input);
    }

    // -----------------------------------------------------------------------
    // JSON script extraction (preserved from old PinterestScraper)
    // -----------------------------------------------------------------------

    /**
     * Fetch every <script type="application/json"> block from the HTML and
     * deep-search for a user node matching the profile owner.
     *
     * @return array{username:?string, name:?string, image:?string, followers:?int}|null
     */
    private function findInJsonScripts(string $html): ?array
    {
        // Collect all candidate usernames from the page
        $candidates = $this->findCandidateUsernames($html);
        if (empty($candidates)) {
            return null;
        }

        // For each candidate, search the JSON scripts for a matching user node
        foreach ($candidates as $candidateUsername) {
            $result = $this->searchJsonForUser($html, $candidateUsername);
            if ($result !== null) {
                return $result + ['username' => $candidateUsername];
            }
        }

        // Fallback: windowed regex scan
        return $this->windowedFallback($html, $candidates);
    }

    /** Extract all potential Pinterest usernames from the page. */
    private function findCandidateUsernames(string $html): array
    {
        $usernames = [];
        if (preg_match_all('~"username"\s*:\s*"([a-z0-9_]+)"~i', $html, $m)) {
            $usernames = array_values(array_unique($m[1]));
        }

        return $usernames;
    }

    /**
     * Search JSON scripts for a user node matching the given username.
     * Preserved from the old PinterestScraper::findUserNode.
     */
    private function searchJsonForUser(string $html, string $username): ?array
    {
        $scripts = $this->jsonScripts($html);
        foreach ($scripts as $data) {
            $user = $this->findUserNode($data, $username);
            if ($user !== null) {
                return $user;
            }
        }

        return null;
    }

    /** Every parseable <script type="application/json"> payload on the page. */
    private function jsonScripts(string $html): array
    {
        $payloads = [];
        if (preg_match_all('~<script[^>]+type=["\']application/json["\'][^>]*>(.*?)</script>~si', $html, $m)) {
            foreach ($m[1] as $block) {
                $data = json_decode(trim($block), true);
                if (is_array($data)) {
                    $payloads[] = $data;
                }
            }
        }

        return $payloads;
    }

    /**
     * Depth-first search for the user object with this username.
     * Preserved from the old PinterestScraper::findUserNode.
     *
     * @return array{name:?string, image:?string, followers:?int}|null
     */
    private function findUserNode(array $node, string $username): ?array
    {
        if (isset($node['username']) && is_string($node['username'])
            && strcasecmp($node['username'], $username) === 0) {
            $name = is_string($node['full_name'] ?? null) ? trim($node['full_name']) : null;
            $image = $node['image_xlarge_url'] ?? $node['image_large_url'] ?? null;
            $followers = isset($node['follower_count']) && is_numeric($node['follower_count'])
                ? (int) $node['follower_count']
                : null;

            if ($name !== null || $image !== null || $followers !== null) {
                return ['name' => $name, 'image' => is_string($image) ? $image : null, 'followers' => $followers];
            }
        }

        foreach ($node as $value) {
            if (is_array($value) && ($found = $this->findUserNode($value, $username))) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Windowed regex fallback: scan around username occurrences in raw HTML
     * for full_name, follower_count, image_xlarge_url. Preserved from the
     * old PinterestScraper's fallback logic.
     */
    private function windowedFallback(string $html, array $usernames): ?array
    {
        $bestHits = 0;
        $best = null;

        foreach ($usernames as $username) {
            $offset = 0;
            while (($pos = stripos($html, '"username":"'.$username.'"', $offset)) !== false) {
                $offset = $pos + 1;
                $window = substr($html, max(0, $pos - 3000), 6000);

                $hits = 0;
                $name = preg_match('~"full_name"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"~', $window, $m)
                    ? json_decode('"'.$m[1].'"')
                    : null;
                $hits += $name !== null ? 1 : 0;

                $followers = preg_match('~"follower_count"\s*:\s*(\d+)~', $window, $m)
                    ? (int) $m[1]
                    : null;
                $hits += $followers !== null ? 1 : 0;

                $image = preg_match('~"image_xlarge_url"\s*:\s*"([^"]+)"~', $window, $m)
                    ? $this->cleanJsonString($m[1])
                    : null;
                $hits += $image !== null ? 1 : 0;

                if ($hits > $bestHits) {
                    $bestHits = $hits;
                    $best = [
                        'username' => $username,
                        'name' => $name,
                        'image' => $image,
                        'followers' => $followers,
                    ];
                }

                if ($bestHits === 3) {
                    break 2;
                }
            }
        }

        return $best;
    }

    /** Strip JSON escapes from a scraped string value. */
    private function cleanJsonString(string $value): string
    {
        return stripslashes(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
    }

    // -----------------------------------------------------------------------
    // Pin feed parsing
    // -----------------------------------------------------------------------

    /**
     * Parse the public RSS pin feed. Preserved from the old
     * PinterestScraper::fetchPins logic.
     *
     * @return list<array{identifier:string, name:?string, item_type:string, values:list<array{field_name:string, value:string, format:string}>}>
     */
    private function parsePinFeed(string $xml, int $limit): array
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            return [];
        }

        $doc = @simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if ($doc === false || ! isset($doc->channel->item)) {
            return [];
        }

        $items = [];
        foreach ($doc->channel->item as $item) {
            if (count($items) >= $limit) {
                break;
            }

            $link = trim((string) $item->link);
            if ($link === '') {
                continue;
            }

            // Thumbnail from description HTML (feed gives 236px; upgrade to 564px).
            $thumbnail = null;
            if (preg_match('~<img[^>]+src=["\']([^"\']+)["\']~i', html_entity_decode((string) $item->description, ENT_QUOTES | ENT_HTML5), $m)) {
                $thumbnail = str_replace('/236x/', '/564x/', $m[1]);
            }

            $title = trim((string) $item->title);
            $date = trim((string) $item->pubDate);

            $identifier = preg_match('~/pin/(\d+)~', $link, $m) ? $m[1] : $link;

            $values = [
                ['field_name' => 'url', 'value' => $link, 'format' => 'url'],
            ];

            if ($thumbnail !== null) {
                $values[] = ['field_name' => 'thumbnail', 'value' => $thumbnail, 'format' => 'image'];
            }
            if ($title !== '') {
                $values[] = ['field_name' => 'title', 'value' => $title, 'format' => 'text'];
            }
            if ($date !== '') {
                $values[] = ['field_name' => 'published_date', 'value' => $this->toIso8601($date), 'format' => 'date'];
            }

            $items[] = [
                'identifier' => $identifier,
                'name' => $title !== '' ? $title : null,
                'item_type' => 'link',
                'values' => $values,
            ];
        }

        return $items;
    }

    /** Parse a date string to ISO-8601. */
    private function toIso8601(string $date): ?string
    {
        try {
            return \Carbon\Carbon::parse($date)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
