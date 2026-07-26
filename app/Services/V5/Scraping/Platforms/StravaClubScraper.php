<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\HtmlScrapeBase;

// V5 Strava club scraper — club pages (strava.com/clubs/{slug-or-id})
// server-render their OG tags plus the member count — no login wall.
// Athlete profiles ARE walled, so clubs are the one Strava surface that
// works keyless. Replaces the old StravaClubScraper.
class StravaClubScraper extends HtmlScrapeBase
{
    /**
     * Main entry: fetch club profile info from a strava.com/clubs URL or slug.
     *
     * @return array{display_name:?string, profile_pic_url:?string, bio:?string, location:?string, member_count:?int, items:list<array>}|null
     */
    public function fetch(string $input): ?array
    {
        $canonicalUrl = $this->normalizeClubUrl($input);
        if ($canonicalUrl === null) {
            return null;
        }

        $profile = $this->fetchProfile($canonicalUrl);
        if ($profile === null) {
            return null;
        }

        return array_merge($profile, ['items' => []]);
    }

    /**
     * Parse club profile from the club page HTML.
     *
     * og:title is "City, Region | Club Name" — location first, name last.
     * Clubs without a location are just the name. Also extracts member count
     * from the page text and resolves the large OG avatar to the "original"
     * CDN rendition (~416px). Preserved from the old StravaClubScraper.
     */
    protected function parseProfile(string $html): ?array
    {
        $title = $this->metaContent($html, 'og:title');
        if ($title === null) {
            return null;
        }

        // Parse location and name from og:title
        $name = $title;
        $location = null;
        if (str_contains($title, '|')) {
            $pieces = array_map('trim', explode('|', $title));
            $name = array_pop($pieces) ?: $title;
            $location = implode(' | ', $pieces) ?: null;
        }

        // Member count
        $members = null;
        if (preg_match('~([\d,.]+)\s+members~i', $html, $m)) {
            $members = (int) str_replace([',', '.'], '', $m[1]);
        }

        // Avatar: probe for the "original" (larger) CDN rendition.
        $image = $this->metaContent($html, 'og:image');
        if ($image !== null
            && preg_match('~^(https://dgalywyr863hv\.cloudfront\.net/pictures/clubs/.+/)large\.(jpe?g|png)$~i', $image, $m)) {
            $original = $m[1].'original.'.$m[2];
            $probeHtml = $this->fetchHtml($original);
            if ($probeHtml !== null) {
                // fetchHtml returns the body on success; if we got a body, the
                // original exists (it's an image, not HTML, but a 200 is a 200).
                $image = $original;
            }
        }

        return [
            'display_name' => $name,
            'location' => $location,
            'profile_pic_url' => $image,
            'bio' => $this->metaContent($html, 'og:description'),
            'member_count' => $members,
        ];
    }

    // -----------------------------------------------------------------------
    // URL normalization
    // -----------------------------------------------------------------------

    /**
     * Normalize any strava.com/clubs URL or bare slug to canonical form.
     */
    public function normalizeClubUrl(string $input): ?string
    {
        $input = $this->normalizeToUrl($input);

        if (preg_match('~^https?://(?:www\.)?strava\.com/clubs/([A-Za-z0-9_-]+)~i', $input, $m)) {
            return 'https://www.strava.com/clubs/'.$m[1];
        }

        // Bare slug/id
        if (preg_match('~^[A-Za-z0-9_-]{2,60}$~', trim($input))) {
            return 'https://www.strava.com/clubs/'.trim($input);
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

    /**
     * Extract a platform handle from a URL.
     */
    protected function resolveHandle(string $url): ?string
    {
        $normalized = $this->normalizeClubUrl($url);
        if ($normalized === null) {
            return null;
        }

        return basename($normalized);
    }
}
