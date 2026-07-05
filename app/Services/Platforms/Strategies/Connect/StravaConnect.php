<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
use App\Services\Platforms\StravaClubScraper;

// Strava connect: club URL (athlete profiles are login-walled) → club page
// provides name, location, photo, live member count. Moved verbatim from
// StravaController.
class StravaConnect implements ConnectStrategy
{
    public function __construct(private readonly StravaClubScraper $scraper) {}

    public function resolve(string $input): ConnectResult
    {
        $url = $this->scraper->normalizeUrl($input);
        if (! $url) {
            return ConnectResult::fail();
        }

        $club = $this->scraper->fetchClub($url);
        if ($club === null) {
            return ConnectResult::fail('Could not read that Strava club page.', 404);
        }

        return ConnectResult::ok(['url' => $url, ...$club]);
    }
}
