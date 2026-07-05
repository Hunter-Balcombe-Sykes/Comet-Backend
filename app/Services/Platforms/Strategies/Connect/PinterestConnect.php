<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\PinterestScraper;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;

// Pinterest connect: profile URL or handle → state JSON provides name / avatar /
// follower count; public RSS provides the latest pins. Moved verbatim from
// PinterestController.
class PinterestConnect implements ConnectStrategy
{
    public function __construct(private readonly PinterestScraper $scraper) {}

    public function resolve(string $input): ConnectResult
    {
        $username = $this->scraper->parseUsername($input);
        if (! $username) {
            return ConnectResult::fail();
        }

        $profile = $this->scraper->fetchProfile($username);
        if ($profile === null) {
            return ConnectResult::fail('Could not find that Pinterest profile.', 404);
        }
        $pins = $this->scraper->fetchPins($username);

        return ConnectResult::ok([
            'url' => "https://www.pinterest.com/{$username}/",
            'username' => $username,
            'name' => $profile['name'],
            'image' => $profile['image'],
            'followers' => $profile['followers'],
            'latest' => $pins[0] ?? null,
            'items' => $pins,
        ]);
    }
}
