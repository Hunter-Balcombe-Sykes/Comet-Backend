<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\HtmlScrapeBase;

// V5 Skool scraper — reads a Skool community's public profile (name, avatar,
// description) from the OG tags on its about page. skool.com/{slug}/about
// is public even for private communities; the bare community root works for
// public ones. No API needed. Replaces the old SkoolScraper.
class SkoolScraper extends HtmlScrapeBase
{
    // og:title values that mean "not a community page" (signup wall / chrome).
    private const NON_COMMUNITY_TITLES = [
        'skool: sign up',
        'skool',
        'skool: discover communities or create your own',
    ];

    /**
     * Main entry: fetch community profile info from a Skool URL or slug.
     *
     * @return array{items:list<array>, profile:array{display_name:?string, profile_pic_url:?string, bio:?string}}|null
     */
    public function fetch(string $input): ?array
    {
        $canonicalUrl = $this->normalizeCommunityUrl($input);
        if ($canonicalUrl === null) {
            return null;
        }

        $profile = $this->fetchProfile($canonicalUrl);
        if ($profile === null) {
            return null;
        }

        return [
            'items' => [],
            'profile' => $profile,
        ];
    }

    /**
     * Parse community profile from the about page HTML.
     *
     * Tries /about first (public for all communities), then the bare URL
     * (for public communities). Returns null when the page is a signup wall
     * or product chrome. Preserved from the old SkoolScraper::fetchCommunity.
     */
    protected function parseProfile(string $html): ?array
    {
        $name = $this->metaContent($html, 'title');
        if (! is_string($name) || in_array(strtolower(trim($name)), self::NON_COMMUNITY_TITLES, true)) {
            return null;
        }

        return [
            'display_name' => trim($name),
            'profile_pic_url' => $this->metaContent($html, 'image'),
            'bio' => $this->metaContent($html, 'description'),
        ];
    }

    /**
     * Override fetchProfile to try /about first, then bare URL.
     * Preserved from the old SkoolScraper's two-URL strategy.
     */
    public function fetchProfile(string $url): ?array
    {
        foreach ([$url.'/about', $url] as $candidate) {
            $html = $this->fetchHtml($candidate);
            if ($html === null) {
                continue;
            }

            $profile = $this->parseProfile($html);
            if ($profile !== null) {
                return $profile;
            }
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // URL normalization
    // -----------------------------------------------------------------------

    /**
     * Normalize any skool.com community URL or bare slug to canonical form.
     * Rejects product pages (signup, login, discovery, etc.).
     */
    public function normalizeCommunityUrl(string $input): ?string
    {
        $input = $this->normalizeToUrl($input);

        $slug = null;
        if (preg_match('~^https?://(?:www\.)?skool\.com/([a-z0-9][a-z0-9-]*)~i', $input, $m)) {
            $slug = strtolower($m[1]);
        } elseif (preg_match('~^[a-z0-9][a-z0-9-]*$~i', trim($input))) {
            $slug = strtolower(trim($input));
        }

        if ($slug === null) {
            return null;
        }

        // Reject product pages
        if (in_array($slug, ['signup', 'login', 'discovery', 'games', 'about', 'legal', 'careers', 'affiliates'], true)) {
            return null;
        }

        return 'https://www.skool.com/'.$slug;
    }

    protected function normalizeToUrl(string $input): string
    {
        if (! str_starts_with($input, 'http')) {
            return 'https://'.$input;
        }

        return preg_replace('/^http:/', 'https:', $input);
    }

    /**
     * Extract a platform handle from a URL.
     */
    protected function resolveHandle(string $url): ?string
    {
        $normalized = $this->normalizeCommunityUrl($url);
        if ($normalized === null) {
            return null;
        }

        return basename($normalized);
    }
}
