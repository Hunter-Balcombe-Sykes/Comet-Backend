<?php

namespace App\Services\Http;

/**
 * Origin-root URL resolver shared by the WebsiteScan extractors — NOT
 * directory-aware. WebsiteLinkHarvester and LogoAutoGrabber deliberately use
 * a different directory-aware algorithm and must not be folded in here.
 */
final class UrlAbsolutizer
{
    public static function absolutize(string $href, string $baseUrl): ?string
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

        // Protocol-relative ("//cdn.example.com/x") — the Squarespace CDN's
        // standard src form. Without this branch it was treated as a path and
        // mangled into "<origin>//cdn.example.com/x" (2026-07-23 live find).
        if (str_starts_with($href, '//')) {
            return $base['scheme'].':'.$href;
        }

        $origin = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');

        return $href[0] === '/' ? $origin.$href : $origin.'/'.ltrim($href, '/');
    }
}
