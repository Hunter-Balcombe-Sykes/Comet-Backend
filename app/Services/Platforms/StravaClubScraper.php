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

    /** Canonical club URL from any strava.com/clubs/… link. */
    public function normalizeUrl(string $url): ?string
    {
        if (preg_match('~^https?://(?:www\.)?strava\.com/clubs/([A-Za-z0-9_-]+)~i', trim($url), $m)) {
            return 'https://www.strava.com/clubs/'.$m[1];
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

        return [
            'name' => $name,
            'location' => $location,
            'image' => $this->metaContent($html, 'og:image'),
            'description' => $this->metaContent($html, 'og:description'),
            'members' => $members,
        ];
    }
}
