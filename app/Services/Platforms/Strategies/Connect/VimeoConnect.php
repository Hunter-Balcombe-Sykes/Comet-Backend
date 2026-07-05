<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;
use App\Services\Platforms\VimeoApi;

// Vimeo connect: profile/channel URL → keyless Simple API provides name,
// avatar, latest uploads. apiPath is the canonical account identity (urls vary
// per input form) — passed as the accountKey. Moved verbatim from VimeoController.
class VimeoConnect implements ConnectStrategy
{
    private const MAX_ITEMS = 12;

    public function __construct(private readonly VimeoApi $vimeo) {}

    public function resolve(string $input): ConnectResult
    {
        $source = $this->vimeo->parseSource($input);
        if (! $source) {
            return ConnectResult::fail();
        }

        $profile = $this->vimeo->fetchProfile($source['apiPath']);
        $videos = $this->vimeo->fetchVideos($source['apiPath']);
        if ($profile === null && $videos === []) {
            return ConnectResult::fail('Could not find that Vimeo profile.', 404);
        }

        return ConnectResult::ok([
            'url' => $source['link'],
            'apiPath' => $source['apiPath'],
            'name' => $profile['name'] ?? null,
            'thumbnail' => $profile['thumbnail'] ?? ($videos[0]['thumbnail'] ?? null),
            'link' => $profile['link'] ?? $source['link'],
            'latest' => $videos[0] ?? null,
            'items' => array_slice($videos, 0, self::MAX_ITEMS),
        ], $source['apiPath']);
    }
}
