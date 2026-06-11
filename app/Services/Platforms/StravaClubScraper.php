<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;

// Strava club pages (strava.com/clubs/{slug-or-id}) server-render their og
// tags plus the member count — no login wall. Athlete profiles ARE walled,
// so clubs are the one Strava surface that works keyless; ideal for trainers
// and run/ride communities ("join my club").
class StravaClubScraper extends PlatformScraper
{
    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /** Canonical club URL from any strava.com/clubs/… link (scheme optional)
     * or a bare club slug/id. */
    public function normalizeUrl(string $url): ?string
    {
        $url = PlatformInput::urlish($url);

        if (preg_match('~^https?://(?:www\.)?strava\.com/clubs/([A-Za-z0-9_-]+)~i', $url, $m)) {
            return 'https://www.strava.com/clubs/'.$m[1];
        }

        if (PlatformInput::isBareToken($url, '~^[A-Za-z0-9_-]{2,60}$~')) {
            return 'https://www.strava.com/clubs/'.PlatformInput::token($url);
        }

        return null;
    }

    /**
     * @return array{name:?string, location:?string, image:?string, description:?string, members:?int}|null
     */
    public function fetchClub(string $url): ?array
    {
        $res = $this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }
        $html = $res['body'];

        $title = $this->metaContent($html, 'og:title');
        if ($title === null) {
            return null;
        }

        // og:title is "City, Region | Club Name" — location first, name last.
        // Clubs without a location are just the name.
        $name = $title;
        $location = null;
        if (str_contains($title, '|')) {
            $pieces = array_map('trim', explode('|', $title));
            $name = array_pop($pieces) ?: $title;
            $location = implode(' | ', $pieces) ?: null;
        }

        $members = null;
        if (preg_match('~([\d,.]+)\s+members~i', $html, $m)) {
            $members = (int) str_replace([',', '.'], '', $m[1]);
        }

        // og:image is the 124px "large" avatar rendition; a ~416px "original"
        // usually sits beside it on the CDN. Probe and prefer it.
        $image = $this->metaContent($html, 'og:image');
        if ($image !== null && preg_match('~^(https://dgalywyr863hv\.cloudfront\.net/pictures/clubs/.+/)large\.(jpe?g|png)$~i', $image, $m)) {
            $original = $m[1].'original.'.$m[2];
            $probe = $this->fetcher->tryFetch($original, ['User-Agent' => self::USER_AGENT]);
            if ($probe !== null && $probe['status'] === 200) {
                $image = $original;
            }
        }

        return [
            'name' => $name,
            'location' => $location,
            'image' => $image,
            'description' => $this->metaContent($html, 'og:description'),
            'members' => $members,
        ];
    }
}
