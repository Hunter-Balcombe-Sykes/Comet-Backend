<?php

namespace App\Services\SmartLinks;

/**
 * Extracts OpenGraph, JSON-LD, title, and favicon from an HTML document using
 * PHP's native DOMDocument (no third-party HTML-parser dependency).
 */
class MetadataParser
{
    public function parse(string $html, string $baseUrl): ParsedMetadata
    {
        if (trim($html) === '') {
            return new ParsedMetadata;
        }

        $dom = new \DOMDocument;
        $prev = libxml_use_internal_errors(true);
        // Force UTF-8 so multibyte titles/og survive parsing.
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xpath = new \DOMXPath($dom);

        $og = [];
        foreach ($xpath->query('//meta[@property]') as $node) {
            /** @var \DOMElement $node */
            $prop = strtolower($node->getAttribute('property'));
            if (str_starts_with($prop, 'og:') || str_starts_with($prop, 'product:') || str_starts_with($prop, 'music:')) {
                $og[$prop] = $node->getAttribute('content');
            }
        }

        $meta = [];
        foreach ($xpath->query('//meta[@name]') as $node) {
            /** @var \DOMElement $node */
            $meta[strtolower($node->getAttribute('name'))] = $node->getAttribute('content');
        }

        $titleNode = $xpath->query('//title')->item(0);
        $title = $titleNode ? trim($titleNode->textContent) : null;

        $jsonLd = [];
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
            $decoded = json_decode(trim($node->textContent), true);
            if (is_array($decoded)) {
                $jsonLd[] = $decoded;
            }
        }

        // Collect every icon <link>, rank by resolution, keep the largest — the
        // first-found icon is usually the tiny 16×16 .ico (§1.2). apple-touch-icon
        // (~180²) and explicit sizes win. Track apple-touch-icon separately for the
        // brand logo chain (§1.1).
        $favicon = null;
        $bestScore = -1;
        $appleTouchIcon = null;
        foreach ($xpath->query('//link[@rel]') as $node) {
            /** @var \DOMElement $node */
            $rel = strtolower($node->getAttribute('rel'));
            if (! str_contains($rel, 'icon')) {
                continue;
            }
            $href = trim($node->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            $abs = $this->absolutize($href, $baseUrl);
            $dim = preg_match('/(\d+)x\d+/', strtolower($node->getAttribute('sizes')), $m) ? (int) $m[1] : 0;
            if (str_contains($rel, 'apple-touch-icon')) {
                $dim = $dim ?: 180;
                $appleTouchIcon = $abs;
            } elseif ($dim === 0) {
                $dim = str_ends_with(strtolower($href), '.ico') ? 16 : 32;
            }
            if ($dim > $bestScore) {
                $bestScore = $dim;
                $favicon = $abs;
            }
        }
        $favicon ??= $this->absolutize('/favicon.ico', $baseUrl);

        // Shopify/theme header logo — the real brand mark for the logo chain (§1.1).
        $headerLogo = null;
        foreach ($xpath->query('//img[contains(concat(" ", normalize-space(@class), " "), " header__heading-logo ") or contains(concat(" ", normalize-space(@class), " "), " header__logo-image ")]') as $node) {
            /** @var \DOMElement $node */
            $src = trim($node->getAttribute('src')) ?: trim($node->getAttribute('data-src'));
            if ($src !== '') {
                $headerLogo = $this->absolutize($src, $baseUrl);
                break;
            }
        }

        $isShopify = str_contains($html, 'cdn.shopify.com')
            || str_contains($html, 'Shopify.theme')
            || str_contains($html, 'shopify-features');

        return new ParsedMetadata(
            og: $og,
            meta: $meta,
            title: ($title !== null && $title !== '') ? $title : null,
            jsonLd: $jsonLd,
            faviconUrl: $favicon,
            appleTouchIconUrl: $appleTouchIcon,
            headerLogoUrl: $headerLogo,
            isShopify: $isShopify,
        );
    }

    /** Resolve a possibly-relative URL against the page's base URL. */
    public function absolutize(?string $url, string $baseUrl): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        $url = trim($url);
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

            return "{$scheme}:{$url}";
        }
        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return $url;
        }
        if (str_starts_with($url, '/')) {
            return "{$scheme}://{$host}{$url}";
        }

        return "{$scheme}://{$host}/".ltrim($url, '/');
    }
}
