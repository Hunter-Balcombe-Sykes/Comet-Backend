<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\DeferredConnect;
use App\Services\Platforms\TwitchScraper;

// Twitch connect: channel URL or handle → og tags provide display name,
// avatar, bio. Moved verbatim from TwitchController.
class TwitchConnect implements DeferredConnect
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

    // DeferredConnect — no network. Writes exactly what TwitchFetch reads
    // (login), plus the deterministic url TwitchFetch preserves via its
    // payload spread.
    public function identify(string $input): ConnectResult
    {
        $login = $this->scraper->parseLogin($input);
        if (! $login) {
            return ConnectResult::fail(); // same as resolve() — descriptor's parse-fail message
        }

        return ConnectResult::ok([
            'url' => "https://www.twitch.tv/{$login}",
            'login' => $login,
        ]);
    }
}
