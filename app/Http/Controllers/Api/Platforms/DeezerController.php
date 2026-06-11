<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Requests\Platforms\ConnectDeezerRequest;
use App\Http\Resources\Platforms\MusicEmbedConnectionResource;
use App\Services\Platforms\DeezerApi;
use Illuminate\Http\JsonResponse;

// Deezer — connect by artist link; the open JSON API resolves the name +
// artwork and the official widget embeds keylessly. Stored in the shared
// music-embed shape (same contract as Spotify / SoundCloud / TIDAL).
class DeezerController extends SingleSelectionPlatformController
{
    public function __construct(private readonly DeezerApi $deezer) {}

    protected function platform(): string
    {
        return 'deezer';
    }

    protected function resourceClass(): string
    {
        return MusicEmbedConnectionResource::class;
    }

    // POST /api/platforms/deezer/connect
    public function connect(ConnectDeezerRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $id = $this->deezer->parseArtistId($request->validated()['url']);
        if (! $id) {
            return $this->error('Enter a Deezer artist link (deezer.com/artist/...).', 422);
        }

        $artist = $this->deezer->fetchArtist($id);
        if ($artist === null) {
            return $this->error('Could not load that Deezer artist.', 422);
        }

        return $this->connected($user, [
            'url' => $artist['link'],
            'artistId' => $id,
            'name' => $artist['name'],
            'thumbnail' => $artist['thumbnail'],
            'embedUrl' => DeezerApi::embedUrlForArtist($id),
            'link' => $artist['link'],
        ]);
    }
}
