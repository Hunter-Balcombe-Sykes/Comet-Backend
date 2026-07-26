<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\HtmlScrapeBase;

// V5 Bandcamp scraper — scrapes an artist/label /music page for the server-side
// album grid (#music-grid). Extracts album/track tiles (link, art, title) and
// profile info (name, avatar). No API needed. Replaces the old BandcampScraper.
class BandcampScraper extends HtmlScrapeBase
{
    /**
     * Main entry: fetch artist profile + releases as V5 items.
     *
     * @return array{items:list<array>, profile:array{display_name:?string, profile_pic_url:?string, bio:?string, follower_count:?int}}|null
     */
    public function fetch(string $input, int $limit = 24): ?array
    {
        $origin = $this->normalizeOrigin($input);
        if ($origin === null) {
            return null;
        }

        $html = $this->fetchHtml($origin.'/music');
        if ($html === null) {
            return null;
        }

        return $this->parseProfileWithOrigin($html, $origin, $limit);
    }

    /**
     * Parse profile + releases from the /music page HTML with origin for URL
     * resolution. Returns the V5 {@see fetch()} shape.
     *
     * @return array{items:list<array>, profile:array{display_name:?string, profile_pic_url:?string, bio:?string, follower_count:?int}}
     */
    private function parseProfileWithOrigin(string $html, string $origin, int $limit): array
    {
        $name = $this->metaContent($html, 'og:title');
        if (is_string($name)) {
            $name = trim(preg_replace('~^Music\s*\|\s*~i', '', $name)) ?: null;
        }
        $name ??= $this->metaContent($html, 'og:site_name');

        $avatar = $this->metaContent($html, 'og:image');

        $items = $this->parseGridItems($html, $origin, $limit);

        return [
            'items' => $items,
            'profile' => [
                'display_name' => $name,
                'profile_pic_url' => $avatar,
                'bio' => null,
                'follower_count' => null,
            ],
        ];
    }

    // Required by HtmlScrapeBase but not used directly — profile is built via
    // parseProfileWithOrigin which also handles URL resolution.
    protected function parseProfile(string $html): ?array
    {
        return null;
    }

    // -----------------------------------------------------------------------
    // URL normalization
    // -----------------------------------------------------------------------

    /**
     * Normalize a Bandcamp URL or subdomain to the canonical https origin.
     * Accepts {sub}.bandcamp.com URLs (scheme optional) or bare subdomains.
     */
    private function normalizeOrigin(string $input): ?string
    {
        $input = $this->normalizeToUrl($input);

        if (preg_match('~^https?://([a-z0-9][a-z0-9-]*)\.bandcamp\.com~i', $input, $m)) {
            return 'https://'.strtolower($m[1]).'.bandcamp.com';
        }

        // Bare token (subdomain only)
        if (preg_match('~^[a-z0-9][a-z0-9-]*$~i', trim($input))) {
            return 'https://'.strtolower(trim($input)).'.bandcamp.com';
        }

        return null;
    }

    protected function normalizeToUrl(string $input): string
    {
        if (! str_starts_with($input, 'http')) {
            return 'https://'.$input;
        }

        return preg_replace('/^http:/', 'https:', $input);
    }

    // -----------------------------------------------------------------------
    // Grid parsing
    // -----------------------------------------------------------------------

    /**
     * Parse the album/track grid from the /music page HTML.
     * Preserved from the old BandcampScraper grid parsing logic.
     * Uses the artist origin to resolve relative URLs.
     *
     * @return list<array{identifier:string, name:string, item_type:string, values:list<array{field_name:string, value:string, format:string}>}>
     */
    private function parseGridItems(string $html, string $origin, int $limit): array
    {
        $items = [];

        if (! preg_match_all('~<li[^>]*data-item-id="((?:album|track)-\d+)"[^>]*>(.*?)</li>~s', $html, $blocks, PREG_SET_ORDER)) {
            return $items;
        }

        foreach ($blocks as $block) {
            if (count($items) >= $limit) {
                break;
            }

            [$full, $itemId, $body] = $block;

            // Must be a music-grid-item
            if (! str_contains($full, 'music-grid-item')) {
                continue;
            }

            if (! preg_match('~<a\s+href="([^"]+)"~i', $body, $href)) {
                continue;
            }

            if (! preg_match('~<p class="title">\s*([^<]+?)\s*(?:<br|</p)~s', $body, $title)) {
                continue;
            }

            // Lazy images: data-original has the real art URL; eager ones use src.
            $thumbnail = preg_match('~(?:data-original|src)="(https?://f\d\.bcbits\.com/img/[^"]+)"~i', $body, $art)
                ? $art[1]
                : null;

            $name = html_entity_decode(trim($title[1]), ENT_QUOTES | ENT_HTML5);
            $link = $this->resolveItemUrl($href[1], $origin);

            $values = [
                ['field_name' => 'title', 'value' => $name, 'format' => 'text'],
                ['field_name' => 'url', 'value' => $link, 'format' => 'url'],
            ];

            if ($thumbnail !== null) {
                $values[] = ['field_name' => 'thumbnail', 'value' => $thumbnail, 'format' => 'image'];
            }

            $items[] = [
                'identifier' => $itemId,
                'name' => $name,
                'item_type' => str_starts_with($itemId, 'track') ? 'track' : 'track',
                'values' => $values,
            ];
        }

        return $items;
    }

    /**
     * Resolve a potentially-relative URL to an absolute URL using the artist
     * origin (e.g. /album/name → https://artist.bandcamp.com/album/name).
     */
    private function resolveItemUrl(string $url, string $origin): string
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);

        if (str_starts_with($url, 'http')) {
            return $url;
        }

        return $origin.rtrim('/'.ltrim($url, '/'), '/');
    }
}
