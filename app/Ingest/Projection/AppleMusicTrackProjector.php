<?php

namespace App\Ingest\Projection;

/**
 * Apple Music `songs` stream → `track` items (owner ruling R10, overnight
 * 2026-08-18), the same shape Spotify / SoundCloud / YouTube Music produce so
 * one song unions across the four through TitleRelease / TitleDuration.
 */
class AppleMusicTrackProjector extends MusicTrackProjector
{
    protected function provider(): string
    {
        return 'apple_music';
    }

    /**
     * iTunes names a single's collection "Title - Single" (and "Title - EP");
     * the release stream strips that suffix into `format`, so the track's
     * `album` must strip it too or the song reads "from I Hope to Be Around -
     * Single" while its release item reads "I Hope to Be Around".
     */
    protected function albumTitle(?string $album): ?string
    {
        if ($album === null) {
            return null;
        }
        [$clean] = AppleMusicReleaseProjector::formatFromName($album, null);

        return $clean;
    }

    protected function embedKey(string $url): string
    {
        // music.apple.com/{cc}/album/{slug}/{albumId}?i={trackId}
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $trackId = is_string($q['i'] ?? null) ? $q['i'] : '';

        return $trackId !== '' ? $trackId : ltrim((string) parse_url($url, PHP_URL_PATH), '/');
    }
}
