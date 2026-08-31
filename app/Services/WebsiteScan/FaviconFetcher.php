<?php

namespace App\Services\WebsiteScan;

use App\Services\Http\SafeUrlFetcher;
use App\Services\Http\UrlAbsolutizer;

/**
 * Finds and fetches a business's favicon from an already-fetched homepage —
 * prefers an apple-touch icon (typically higher resolution) over a generic
 * one, falls back to /favicon.ico. Feeds WebsiteAccentExtractor (dominant-
 * colour sampling) and WebsiteLogoCandidateExtractor's icon-kind candidates.
 */
class FaviconFetcher
{
    private const MAX_BYTES = 2_000_000;

    public function __construct(private SafeUrlFetcher $fetcher) {}

    /** @return array{url: string, bytes: string}|null */
    public function fetch(string $html, string $baseUrl): ?array
    {
        $url = $this->findIconUrl($html, $baseUrl) ?? UrlAbsolutizer::absolutize('/favicon.ico', $baseUrl);
        if ($url === null) {
            return null;
        }

        $response = $this->fetcher->tryFetch($url);
        if (! is_array($response) || $response['status'] !== 200) {
            return null;
        }

        $bytes = (string) $response['body'];
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }

        return ['url' => $url, 'bytes' => $bytes];
    }

    private function findIconUrl(string $html, string $baseUrl): ?string
    {
        $doc = new \DOMDocument;
        libxml_use_internal_errors(true);
        // LIBXML_NONET (#FU-1): $html is a third party's scraped page — this
        // blocks any network access libxml would make while parsing (external
        // entity/DTD/stylesheet), same flag and reasoning as MetadataParser,
        // AboutProseExtractor and WebsiteLinkHarvester::extractLinks().
        $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $best = null;
        $bestScore = -1;
        foreach ($xpath->query('//link[@rel]') as $link) {
            // Tag-name node test on 'link' — always a DOMElement at runtime.
            if (! $link instanceof \DOMElement) {
                continue;
            }
            $rel = strtolower((string) $link->getAttribute('rel'));
            if (! str_contains($rel, 'icon')) {
                continue;
            }
            $href = trim((string) $link->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            $score = str_contains($rel, 'apple-touch') ? 2 : 1;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $href;
            }
        }

        return $best !== null ? UrlAbsolutizer::absolutize($best, $baseUrl) : null;
    }
}
