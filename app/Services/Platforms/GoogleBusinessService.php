<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;

// Google exposes no keyless API for Business Profiles (ratings/reviews need
// the paid Places API), but a Maps share link carries the place name and
// coordinates in the URL itself, and the classic keyless embed
// (maps.google.com/maps?...&output=embed) renders a live map for them. So
// the card is built from a pure URL parse: short links are resolved first
// (maps.app.goo.gl / goo.gl/maps / share.google), full /maps/place/ URLs
// parse with no network at all.
class GoogleBusinessService extends PlatformScraper
{
    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /**
     * @return array{url:string, name:?string, lat:?float, lng:?float}|null
     */
    public function resolve(string $input): ?array
    {
        $input = trim($input);
        $host = strtolower(preg_replace('~^www\.~i', '', (string) parse_url($input, PHP_URL_HOST)));

        $url = $input;
        if (in_array($host, ['maps.app.goo.gl', 'goo.gl', 'share.google', 'g.co', 'maps.google.com'], true)) {
            $url = $this->followShortLink($input) ?? $input;
        }

        $parsed = $this->parsePlaceUrl($url);
        if ($parsed === null && $url !== $input) {
            $parsed = $this->parsePlaceUrl($input);
        }

        return $parsed;
    }

    /** Resolve a short link to the full Maps URL (redirects, then body scan). */
    private function followShortLink(string $shortUrl): ?string
    {
        $res = $this->fetcher->tryFetch($shortUrl, ['User-Agent' => self::USER_AGENT]);
        if (! is_string($res['finalUrl'] ?? null)) {
            return null;
        }

        // Normal case: the redirect chain landed on the real Maps URL.
        if (str_contains($res['finalUrl'], '/maps/')) {
            return $res['finalUrl'];
        }

        // Interstitial case: the canonical place URL is in the page body.
        if (is_string($res['body'] ?? null)
            && preg_match('~https://www\.google\.[a-z.]+/maps/place/[^"\'\\\\<>\s]+~i', $res['body'], $m)) {
            return html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5);
        }

        return null;
    }

    /**
     * @return array{url:string, name:?string, lat:?float, lng:?float}|null
     */
    private function parsePlaceUrl(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! preg_match('~(^|\.)google\.[a-z.]+$~', $host)) {
            return null;
        }

        $name = null;
        if (preg_match('~/maps/place/([^/@?]+)~i', $url, $m)) {
            $name = trim(rawurldecode(str_replace('+', ' ', $m[1])));
        }

        // The !3d…!4d… data segment is the exact place pin; the @lat,lng pair
        // is only the viewport centre — prefer the pin.
        $lat = $lng = null;
        if (preg_match('~!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)~', $url, $m)) {
            [$lat, $lng] = [(float) $m[1], (float) $m[2]];
        } elseif (preg_match('~/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)~', $url, $m)) {
            [$lat, $lng] = [(float) $m[1], (float) $m[2]];
        }

        // A q= search link (maps.google.com/?q=…) still names the place.
        if ($name === null && preg_match('~[?&]q=([^&]+)~', $url, $m)) {
            $candidate = trim(rawurldecode(str_replace('+', ' ', $m[1])));
            // Skip bare coordinate queries — they name nothing.
            if ($candidate !== '' && ! preg_match('~^-?\d+(\.\d+)?,\s*-?\d+(\.\d+)?$~', $candidate)) {
                $name = $candidate;
            }
        }

        if ($name === null && $lat === null) {
            return null;
        }

        return ['url' => $url, 'name' => $name, 'lat' => $lat, 'lng' => $lng];
    }
}
