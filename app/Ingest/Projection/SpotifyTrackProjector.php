<?php

namespace App\Ingest\Projection;

/**
 * Spotify actor track → `track`. Replaced SpotifyChannelProjector, which
 * projected the artist's oEmbed to the now-retired `channel` kind.
 */
class SpotifyTrackProjector extends MusicTrackProjector
{
    protected function provider(): string
    {
        return 'spotify';
    }

    /** Spotify's embed is keyed by the entity path, e.g. `track/abc123`. */
    protected function embedKey(string $url): string
    {
        return ltrim((string) parse_url($url, PHP_URL_PATH), '/');
    }
}
