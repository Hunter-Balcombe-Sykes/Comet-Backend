<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
use App\Services\Platforms\TwitchScraper;

// Twitch connect: channel URL or handle → og tags provide display name,
// avatar, bio. Moved verbatim from TwitchController.
class TwitchConnect implements ConnectStrategy
{
    public function __construct(private readonly TwitchScraper $scraper) {}

    public function resolve(string $input): ConnectResult
    {
        $login = $this->scraper->parseLogin($input);
        if (! $login) {
            return ConnectResult::fail();
        }

        $channel = $this->scraper->fetchChannel($login);
        if ($channel === null) {
            return ConnectResult::fail('Could not find that Twitch channel.', 404);
        }

        return ConnectResult::ok([
            'url' => "https://www.twitch.tv/{$login}",
            'login' => $login,
            'name' => $channel['name'],
            'image' => $channel['image'],
            'description' => $channel['description'],
        ]);
    }
}
