<?php

namespace App\Services\Platforms;

// OpenTable reservations — connect by restaurant link, render the keyless
// reservation widget. OpenTable WAF-blocks server-side fetches (datacenter IPs
// time out), so we never scrape it: the restaurant id is read straight from the
// URL, and the embeddable widget — served to the VISITOR's browser, which isn't
// blocked — carries the live availability + booking. Mirrors the music-embed
// contract: a URL in, an embedUrl out.
class OpenTableService
{
    /**
     * Real OpenTable TLD suffixes, enumerated. An open-ended `[a-z.]+` suffix
     * here let `opentable.<attacker-domain>` pass as OpenTable (host spoofing);
     * a rid/embed sourced from a spoofed host points the reserve button at an
     * attacker-chosen widget, so the suffix set must stay closed.
     */
    private const TLDS = '(?:com|com\.au|com\.mx|co\.uk|co\.th|ca|de|jp|ie|sg|hk|ae|it|es|nl|at)';

    /**
     * Restaurant id (rid) from an OpenTable URL. Profile links carry it directly
     * (/restaurant/profile/<rid>); some links pass it as ?rid=. Slug links
     * (/r/<slug>) DON'T contain it and can't be resolved server-side (WAF) — the
     * caller nudges the user to the profile link instead.
     */
    public function parseRid(string $url): ?string
    {
        $url = PlatformInput::urlish($url);
        if (preg_match('~opentable\.'.self::TLDS.'/restaurant/profile/(\d+)~i', $url, $m)) {
            return $m[1];
        }
        if ($this->isOpenTableUrl($url) && preg_match('~[?&]rid=(\d+)~i', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    /** True for any real opentable.* host (closed TLD set). */
    public function isOpenTableUrl(string $url): bool
    {
        $host = strtolower((string) parse_url(PlatformInput::urlish($url), PHP_URL_HOST));

        return (bool) preg_match('~(^|\.)opentable\.'.self::TLDS.'$~', $host);
    }

    /** Best-effort display name from a /r/<slug> link; null for profile links. */
    public function nameFromUrl(string $url): ?string
    {
        if (preg_match('~opentable\.'.self::TLDS.'/r/([a-z0-9-]+)~i', PlatformInput::urlish($url), $m)) {
            $slug = preg_replace('~-\d+$~', '', $m[1]);   // drop a trailing -<rid> if present
            $name = ucwords(str_replace('-', ' ', (string) $slug));

            return $name !== '' ? $name : null;
        }

        return null;
    }

    /** OpenTable host from the input URL, defaulting to the AU site. */
    public function hostOf(string $url): string
    {
        $host = strtolower((string) parse_url(PlatformInput::urlish($url), PHP_URL_HOST));

        return $host !== '' ? $host : 'www.opentable.com.au';
    }

    /**
     * The rid-bearing OpenTable profile link we already harvested from a Google
     * Business payload's reservation provider — so the user can one-click
     * connect OpenTable without hunting for the right URL (OpenTable blocks us
     * from resolving slug links ourselves). Null when the venue's reservation
     * provider isn't OpenTable.
     *
     * @param  array<string,mixed>  $gbPayload
     * @return array{url:string, name:?string}|null
     */
    public function suggestionFromGoogleBusiness(array $gbPayload): ?array
    {
        // The primary reservation.url plus every reservation.links[].url — one
        // of them is the OpenTable profile link (with the rid) when OpenTable is
        // the venue's reservation provider.
        $candidates = array_filter([
            data_get($gbPayload, 'reservation.url'),
            ...array_map(
                fn ($link) => data_get($link, 'url'),
                (array) data_get($gbPayload, 'reservation.links', []),
            ),
        ]);

        foreach ($candidates as $url) {
            if (is_string($url) && $this->isOpenTableUrl($url) && $this->parseRid($url) !== null) {
                $name = data_get($gbPayload, 'name');

                return ['url' => $url, 'name' => is_string($name) ? $name : null];
            }
        }

        return null;
    }

    /**
     * Keyless OpenTable reservation-widget URL for an iframe — live availability
     * + in-browser booking. `domain` is OpenTable's locale code derived from the
     * host TLD (com / couk / comau). If the widget renders blank for a locale,
     * this `domain` value is the one knob to adjust.
     */
    public function embedUrl(string $rid, string $host = 'www.opentable.com.au'): string
    {
        $tld = preg_replace('~^.*?opentable\.~i', '', $host);     // "com.au"
        $domain = str_replace('.', '', (string) $tld) ?: 'com';   // "comau"

        $params = http_build_query([
            'rid' => $rid,
            'domain' => $domain,
            // wide = the responsive layout that fills the container width;
            // overlay keeps booking in-page instead of a new tab.
            'type' => 'wide',
            'theme' => 'standard',
            'overlay' => 'true',
            'iframe' => 'true',
            'lang' => 'en-AU',
        ]);

        return "https://{$host}/widget/reservation/canvas?{$params}";
    }
}
