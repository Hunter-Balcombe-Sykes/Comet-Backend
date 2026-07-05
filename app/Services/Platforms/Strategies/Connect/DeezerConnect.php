<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\DeezerApi;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\ConnectStrategy;

// Deezer connect: artist link → open JSON API resolves name + artwork; the
// official widget embeds keylessly. Moved verbatim from DeezerController.
class DeezerConnect implements ConnectStrategy
{
    public function __construct(private readonly DeezerApi $deezer) {}

    public function resolve(string $input): ConnectResult
    {
        $id = $this->deezer->parseArtistId($input);
        if (! $id) {
            return ConnectResult::fail();
        }

        $artist = $this->deezer->fetchArtist($id);
        if ($artist === null) {
            return ConnectResult::fail('Could not load that Deezer artist.');
        }

        return ConnectResult::ok([
            'url' => $artist['link'],
            'artistId' => $id,
            'name' => $artist['name'],
            'thumbnail' => $artist['thumbnail'],
            'embedUrl' => DeezerApi::embedUrlForArtist($id),
            'link' => $artist['link'],
        ]);
    }
}
