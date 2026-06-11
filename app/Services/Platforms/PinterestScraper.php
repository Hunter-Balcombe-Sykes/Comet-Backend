<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;
use Carbon\Carbon;

// Two keyless Pinterest surfaces: the profile page's embedded state JSON
// (full name, follower count, avatar — extracted from a window anchored on
// the username, since the page embeds many user objects) and the public RSS
// pin feed at /{username}/feed.rss (latest pins with images).
class PinterestScraper extends PlatformScraper
{
    private const RESERVED = ['pin', 'ideas', 'search', 'settings', 'business', 'today', 'watch', 'about', 'careers', 'policy', 'help', 'login', 'signup', 'news_hub', 'oauth', 'resource', 'topics'];

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /** Username from any pinterest.* profile URL or a bare handle. */
    public function parseUsername(string $input): ?string
    {
        $input = PlatformInput::urlish($input);

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

    /**
     * @return array{username:string, name:?string, image:?string, followers:?int}|null
     */
    public function fetchProfile(string $username): ?array
    {
        $res = $this->fetcher->tryFetch("https://www.pinterest.com/{$username}/", ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }
        $html = $res['body'];

        // Primary: the page state ships as <script type="application/json">
        // blocks holding MANY user objects — recursively find the one whose
        // username matches. The windowed regex below is only a fallback for a
        // future markup change (adjacent objects would bleed into windows).
        $best = ['name' => null, 'image' => null, 'followers' => null];
        foreach ($this->jsonScripts($html) as $data) {
            if ($user = $this->findUserNode($data, $username)) {
                $best = $user;
                break;
            }
        }

        if ($best['name'] === null && $best['followers'] === null && $best['image'] === null) {
            $offset = 0;
            $bestHits = 0;
            while (($pos = stripos($html, '"username":"'.$username.'"', $offset)) !== false) {
                $offset = $pos + 1;
                $window = substr($html, max(0, $pos - 3000), 6000);

                $hits = 0;
                $name = preg_match('~"full_name"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"~', $window, $m) ? json_decode('"'.$m[1].'"') : null;
                $hits += $name !== null ? 1 : 0;
                $followers = preg_match('~"follower_count"\s*:\s*(\d+)~', $window, $m) ? (int) $m[1] : null;
                $hits += $followers !== null ? 1 : 0;
                $image = preg_match('~"image_xlarge_url"\s*:\s*"([^"]+)"~', $window, $m) ? $this->cleanJsonString($m[1]) : null;
                $hits += $image !== null ? 1 : 0;

                if ($hits > $bestHits) {
                    $bestHits = $hits;
                    $best = ['name' => $name, 'image' => $image, 'followers' => $followers];
                }
                if ($bestHits === 3) {
                    break;
                }
            }
        }

        // og:title ("Display Name (username) - Profile | Pinterest") backstops
        // the name when the state JSON wasn't matched.
        if ($best['name'] === null && ($og = $this->metaContent($html, 'og:title'))) {
            $best['name'] = trim(preg_replace('~\s*\([^)]*\)\s*-\s*Profile.*$~i', '', $og)) ?: null;
        }

        if ($best['name'] === null && $best['followers'] === null && $best['image'] === null) {
            return null;
        }

        return [
            'username' => $username,
            'name' => $best['name'],
            'image' => $best['image'],
            'followers' => $best['followers'],
        ];
    }

    /**
     * Latest pins from the public RSS feed, newest first.
     *
     * @return list<array{itemId:string, thumbnail:?string, link:?string, name:?string, date:?string}>
     */
    public function fetchPins(string $username, int $limit = 12): array
    {
        $res = $this->fetcher->tryFetch("https://www.pinterest.com/{$username}/feed.rss", ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200 || stripos($res['body'], '<!DOCTYPE') !== false) {
            return [];
        }

        $doc = @simplexml_load_string($res['body'], \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if ($doc === false || ! isset($doc->channel->item)) {
            return [];
        }

        $pins = [];
        foreach ($doc->channel->item as $item) {
            if (count($pins) >= $limit) {
                break;
            }
            $link = trim((string) $item->link);
            if ($link === '') {
                continue;
            }

            // The description is HTML with the pin image inside.
            $thumbnail = null;
            if (preg_match('~<img[^>]+src=["\']([^"\']+)["\']~i', html_entity_decode((string) $item->description, ENT_QUOTES | ENT_HTML5), $m)) {
                // Feed thumbnails come at 236px; the 564px rendition uses the
                // same path and looks far better in a grid.
                $thumbnail = str_replace('/236x/', '/564x/', $m[1]);
            }

            $title = trim((string) $item->title);
            $date = trim((string) $item->pubDate);

            $pins[] = [
                'itemId' => preg_match('~/pin/(\d+)~', $link, $m) ? $m[1] : $link,
                'thumbnail' => $thumbnail,
                'link' => $link,
                'name' => $title !== '' ? $title : null,
                'date' => $date !== '' ? $this->isoDate($date) : null,
            ];
        }

        return $pins;
    }

    private function isoDate(string $date): ?string
    {
        try {
            return Carbon::parse($date)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
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
}
