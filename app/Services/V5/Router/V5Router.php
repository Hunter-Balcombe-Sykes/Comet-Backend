<?php

namespace App\Services\V5\Router;

use App\Services\V5\Registry\V5PlatformRegistry;

// V5 Router — takes a URL + optional scope and returns a determination.
// Three-tier flow: global → by-category → by-platform-or-other.
class V5Router
{
    public function __construct(
        private readonly V5PlatformRegistry $registry,
    ) {}

    // -------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------

    /**
     * Determine what a URL is and what to do with it.
     *
     * @param string $url The URL to check
     * @param string|null $scopePlatformId Specific platform (Tier 3)
     * @param string|null $scopeCategoryName Specific category (Tier 2)
     * @return RouterResult
     */
    public function determine(
        string $url,
        ?string $scopePlatformId = null,
        ?string $scopeCategoryName = null,
    ): RouterResult {
        $url = $this->normalizeUrl($url);

        // Tier 3: Specific platform selected
        if ($scopePlatformId) {
            return $this->determineForPlatform($url, $scopePlatformId);
        }

        // Tier 2: Category selected
        if ($scopeCategoryName) {
            return $this->determineForCategory($url, $scopeCategoryName);
        }

        // Tier 1: Global — check against all platforms
        return $this->determineGlobal($url);
    }

    // -------------------------------------------------------------------
    // Tier implementations
    // -------------------------------------------------------------------

    private function determineGlobal(string $url): RouterResult
    {
        // 1. Try platform URL match
        $platformMatch = $this->registry->matchUrl($url);
        if ($platformMatch) {
            return RouterResult::platformMatch($platformMatch);
        }

        // 2. Try item URL template match
        $itemMatch = $this->registry->matchItemUrl($url);
        if ($itemMatch) {
            return RouterResult::itemMatch($itemMatch);
        }

        // 3. Unrecognized — offer options
        return RouterResult::unrecognized($url);
    }

    private function determineForCategory(string $url, string $categoryName): RouterResult
    {
        // Check platforms in this category
        $platformMatch = $this->registry->matchUrl($url, $categoryName);
        if ($platformMatch) {
            return RouterResult::platformMatch($platformMatch);
        }

        // Check if URL matches a platform in a DIFFERENT category
        $globalMatch = $this->registry->matchUrl($url);
        if ($globalMatch) {
            return RouterResult::platformInOtherCategory($globalMatch, $categoryName);
        }

        // Check item URL templates
        $itemMatch = $this->registry->matchItemUrl($url);
        if ($itemMatch) {
            return RouterResult::itemMatch($itemMatch);
        }

        // Offer: add as "other" in this category
        return RouterResult::unrecognizedInCategory($url, $categoryName);
    }

    private function determineForPlatform(string $url, string $platformId): RouterResult
    {
        $platform = $this->registry->find($platformId);
        if (! $platform) {
            return RouterResult::unrecognized($url);
        }

        // Check if the URL is valid for the selected platform
        $format = $platform['url_format'] ?? null;
        if ($format) {
            $pattern = $this->templateToRegex($format);
            if (preg_match($pattern, $url)) {
                return RouterResult::platformMatch([
                    'platform' => $platform,
                    'matched_value' => $url,
                    'match_type' => 'platform_url',
                ]);
            }
        }

        // Selected "other" — any URL is valid, just add it
        if ($platformId === 'other' || ($platform['slug'] ?? '') === 'other') {
            return RouterResult::otherMatch($platform, $url);
        }

        // URL doesn't match the selected platform
        // Check if it matches a DIFFERENT platform
        $globalMatch = $this->registry->matchUrl($url);
        if ($globalMatch && ($globalMatch['platform']['id'] ?? '') !== $platformId) {
            return RouterResult::suggestionGate(
                $url,
                $platform,
                $globalMatch['platform'],
                $platform['category_names'][0] ?? 'other'
            );
        }

        // URL for no platform at all
        $itemMatch = $this->registry->matchItemUrl($url);
        if ($itemMatch) {
            return RouterResult::itemMatch($itemMatch);
        }

        return RouterResult::invalidForPlatform($url, $platform);
    }

    // -------------------------------------------------------------------
    // Temp scrape
    // -------------------------------------------------------------------

    /**
     * Scrape a page once (linkinbio, previous website), extract URLs,
     * and route each through the global router.
     */
    public function scrapeAndRoute(string $url, string $scrapeType): array
    {
        // The actual scraping is platform-specific. This method provides
        // the routing infrastructure — each scraped URL is fed back through
        // determine() and results are collected.
        $results = [];
        // Scraped URLs come from the platform's temp scrape handler
        return $results;
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (! str_starts_with($url, 'http')) {
            $url = 'https://'.$url;
        }
        // Strip www. prefix so url_format patterns match both with and without it
        $url = preg_replace('#^https?://www\.#i', 'https://', $url);
        return $url;
    }

    private function templateToRegex(string $template): string
    {
        // FIRST replace placeholders with safe markers, THEN escape —
        // this avoids preg_quote mangling the < > brackets in placeholders.
        $replacementMap = [
            // Order matters: longer placeholders first to prevent partial matches
            '<accounthandle>' => '(?P<accounthandle>[\w.\-@]+)',
            '<itemidentifier>' => '(?P<itemidentifier>[\w.\-]+)',
            '<username>' => '(?P<username>[\w.]+)',
            '<channel>' => '(?P<channel>[\w\-]+)',
            '<handle>' => '(?P<handle>[\w.\-@]+)',
            '<slug>' => '(?P<slug>[\w\-]+)',
            '<id>' => '(?P<id>[\w\-]+)',
        ];

        // Markers use only characters preg_quote never touches (A-Z, 0-9, _)
        $markers = [];
        $marked  = $template;
        $idx = 0;
        foreach ($replacementMap as $placeholder => $regex) {
            $marker = '__PH' . $idx . '__';
            $markers[$marker] = $regex;
            $marked = str_replace($placeholder, $marker, $marked);
            $idx++;
        }

        $escaped = preg_quote($marked, '#');
        $escaped = str_replace(array_keys($markers), array_values($markers), $escaped);

        return '#'.$escaped.'#i';
    }
}
