<?php

namespace App\Services\WebsiteScan;

use App\Services\Http\SafeUrlFetcher;

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
        $url = $this->findIconUrl($html, $baseUrl) ?? $this->absolutize('/favicon.ico', $baseUrl);
        if ($url === null) {
            return null;
        }

        $response = $this->fetcher->tryFetch($url);
        if (! is_array($response) || ($response['status'] ?? 0) !== 200) {
            return null;
        }

        $bytes = (string) ($response['body'] ?? '');
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }

        return ['url' => $url, 'bytes' => $bytes];
    }

    private function findIconUrl(string $html, string $baseUrl): ?string
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $best = null;
        $bestScore = -1;
        foreach ($xpath->query('//link[@rel]') as $link) {
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

        return $best !== null ? $this->absolutize($best, $baseUrl) : null;
    }

    private function absolutize(string $href, string $baseUrl): ?string
    {
        if ($href === '' || str_starts_with($href, 'data:')) {
            return null;
        }
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }
        $base = parse_url($baseUrl);
        if (! isset($base['scheme'], $base['host'])) {
            return null;
        }
        $origin = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');

        return $href[0] === '/' ? $origin.$href : $origin.'/'.ltrim($href, '/');
    }
}
