<?php

namespace App\Services\V5\Scraping\BaseTemplates;

use App\Services\V5\Scraping\BaseScraper;

// V5 HtmlScrapeBase — for platforms scraped via unauthenticated HTML + JSON-LD + OG tags + RSS.
// Replaces the duplicated fetch-then-check-then-parse pattern from 9+ scrapers
// in the old system (Pinterest, Twitch, Skool, Bandcamp, Eventbrite, Humanitix, Strava).
abstract class HtmlScrapeBase extends BaseScraper
{
    protected string $profileUrl = '';
    protected string $feedUrl = '';

    /** Fetch and parse a profile page. Override parseProfile for platform-specific logic. */
    public function fetchProfile(string $url): ?array
    {
        $html = $this->fetchHtml($url);
        if (! $html) return null;

        return $this->parseProfile($html);
    }

    /** Fetch and parse an RSS/Atom feed. */
    public function fetchFeed(string $url, int $limit = 15): array
    {
        $xml = $this->fetchHtml($url);
        if (! $xml) return [];

        return $this->parseRssFeed($xml, $limit);
    }

    /** Override in subclass for platform-specific profile parsing. */
    abstract protected function parseProfile(string $html): ?array;

    /** Combine profile + feed into a unified payload. */
    public function fetchProfileWithFeed(string $profileUrl, string $feedUrl, int $limit = 15): ?array
    {
        $profile = $this->fetchProfile($profileUrl);
        $feed = $this->fetchFeed($feedUrl, $limit);

        if (! $profile && empty($feed)) return null;

        return array_merge($profile ?? [], ['items' => $feed]);
    }

    /** Resolve a platform username/handle from a URL. Override for platform-specific logic. */
    protected function resolveHandle(string $url): ?string
    {
        return $this->normalizeUrl($url, 'path_segment');
    }
}
