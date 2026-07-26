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
     * @return array{display_name:?string, profile_pic_url:?string, origin:?string, items:list<array>}|null
     */
    public function fetch(string $input, int $limit = 24): ?array
    {
        $origin = $this->normalizeOrigin($input);
        if ($origin === null) {
            return null;
        }

        $profile = $this->fetchProfile($origin.'/music');
        if ($profile === null) {
            return null;
        }

        return array_merge($profile, ['origin' => $origin, 'items' => $profile['items'] ?? []]);
    }

    /**
     * Parse profile + releases from the /music page HTML.
     *
     * Scrapes the server-side album grid (<li class="music-grid-item"
     * data-item-id="album-…">) for album link, art, and title. og:title on
     * /music reads "Music | <Artist>". Preserved from the old
     * BandcampScraper::fetchProfile logic.
     */
    protected function parseProfile(string $html): ?array
    {
        // Profile name from og:title ("Music | <Artist>") or og:site_name.
        $name = $this->metaContent($html, 'og:title');
        if (is_string($name)) {
            $name = trim(preg_replace('~^Music\s*\|\s*~i', '', $name)) ?: null;
        }
        $name ??= $this->metaContent($html, 'og:site_name');

        $avatar = $this->metaContent($html, 'og:image');

        // Parse music grid items
        $items = $this->parseGridItems($html, 24);

        return [
            'display_name' => $name,
            'profile_pic_url' => $avatar,
            'items' => $items,
        ];
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

    private function normalizeToUrl(string $input): string
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
     *
     * @return list<array{identifier:string, name:string, item_type:string, values:list<array{field_name:string, value:string, format:string}>}>
     */
    private function parseGridItems(string $html, int $limit): array
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
            $link = $this->resolveUrl($href[1]);

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
     * Resolve a potentially-relative URL to an absolute bandcamp.com URL.
     */
    private function resolveUrl(string $url): string
    {
        if (str_starts_with($url, 'http')) {
            return html_entity_decode($url, ENT_QUOTES | ENT_HTML5);
        }

        // Relative URL — prepend bandcamp.com
        return 'https://bandcamp.com'.ltrim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5), '/');
    }
}
