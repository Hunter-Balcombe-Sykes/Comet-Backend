<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;

// Scrapes a Bandcamp artist/label page with no auth. The /music page renders
// a server-side album grid (#music-grid) of <li class="music-grid-item"
// data-item-id="album-…"> items carrying the album link, art, and title —
// no API needed. Lazy-loaded art keeps the real URL in data-original.
class BandcampScraper extends PlatformScraper
{
    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    // Any {sub}.bandcamp.com URL → canonical https origin, else null.
    public function normalizeOrigin(string $input): ?string
    {
        if (preg_match('~^https?://([a-z0-9][a-z0-9-]*)\.bandcamp\.com~i', trim($input), $m)) {
            return 'https://'.strtolower($m[1]).'.bandcamp.com';
        }

        return null;
    }

    /**
     * The artist profile + their releases, newest first (Bandcamp's own grid
     * order). Returns null when the page can't be read; `items` may be empty
     * for a page with no releases.
     *
     * @return array{name:?string, thumbnail:?string, items:list<array{itemId:string, name:string, thumbnail:?string, link:string}>}|null
     */
    public function fetchProfile(string $origin, int $limit = 24): ?array
    {
        $res = $this->fetcher->tryFetch($origin.'/music', ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }
        $html = $res['body'];

        // og:title on /music reads "Music | <Artist>".
        $name = $this->metaContent($html, 'og:title');
        if (is_string($name)) {
            $name = trim(preg_replace('~^Music\s*\|\s*~i', '', $name)) ?: null;
        }
        $name ??= $this->metaContent($html, 'og:site_name');

        $items = [];
        if (preg_match_all('~<li[^>]*data-item-id="((?:album|track)-\d+)"[^>]*>(.*?)</li>~s', $html, $blocks, PREG_SET_ORDER)) {
            foreach ($blocks as $block) {
                if (count($items) >= $limit) {
                    break;
                }
                [$full, $itemId, $body] = $block;
                if (! str_contains($full, 'music-grid-item')) {
                    continue;
                }
                if (! preg_match('~<a\s+href="([^"]+)"~i', $body, $href)) {
                    continue;
                }
                if (! preg_match('~<p class="title">\s*([^<]+?)\s*(?:<br|</p)~s', $body, $title)) {
                    continue;
                }
                // Lazy images keep a blank gif in src and the real art in
                // data-original; eager ones have the bcbits URL in src.
                $thumbnail = preg_match('~(?:data-original|src)="(https?://f\d\.bcbits\.com/img/[^"]+)"~i', $body, $art)
                    ? $art[1]
                    : null;

                $items[] = [
                    'itemId' => $itemId,
                    'name' => html_entity_decode(trim($title[1]), ENT_QUOTES | ENT_HTML5),
                    'thumbnail' => $thumbnail,
                    'link' => $this->absoluteUrl(html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5), $origin),
                ];
            }
        }

        return [
            'name' => $name,
            'thumbnail' => $this->metaContent($html, 'og:image'),
            'items' => $items,
        ];
    }
}
