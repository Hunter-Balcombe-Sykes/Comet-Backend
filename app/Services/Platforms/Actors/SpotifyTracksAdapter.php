<?php

namespace App\Services\Platforms\Actors;

/**
 * automation-lab~spotify-scraper, in URL mode.
 *
 * SHAPE PINNED BY LIVE PROBE 2026-08-16 (convergence-log F29/F31), not by the
 * actor's listing — which claims ISRC it does not deliver. In URL mode the
 * dataset is ONE ARTIST object per input url, and the tracks hang off its
 * `topTracks` array:
 *
 *   {name, url, imageUrl, monthlyListeners, genres, topTracks: [
 *      {trackId, title, artists, duration, durationFormatted, playCount,
 *       isExplicit, isPlayable, audioPreviewUrl} ], …}
 *
 * Three consequences, all deliberate:
 *  - There is NO isrc on either Spotify actor (both probed). Spotify dedup
 *    therefore rests on TitleRelease (title|artist), the corroborating tier
 *    convergence-log F10 already planned for.
 *  - There is no per-track url; it is derived from trackId, whose format is
 *    Spotify's stable base-62 id.
 *  - `topTracks` is the artist's TOP tracks, not their catalogue — about ten.
 *    maxResults bounds the number of ARTISTS, so the track cap is applied here.
 *
 * Chosen over the search-mode alternative because the identifier is the
 * connection's own artist URL: a keyword search can resolve to a different
 * artist of the same name, which SourceProvisioner's docblock rules is worse
 * than landing no row at all.
 */
class SpotifyTracksAdapter implements MusicActorAdapter
{
    public function input(string $identifier, int $maxTracks): array
    {
        // maxResults counts ARTISTS here, so it stays 1 — one connection is one
        // artist. The track cap is applied in tracks().
        return ['mode' => 'urls', 'urls' => [$identifier], 'maxResults' => 1];
    }

    public function tracks(array $dataset): array
    {
        $out = [];

        foreach ($dataset as $artist) {
            if (! is_array($artist)) {
                continue;
            }

            foreach ((array) ($artist['topTracks'] ?? []) as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $title = is_string($row['title'] ?? null) ? trim($row['title']) : '';
                $trackId = is_string($row['trackId'] ?? null) ? trim($row['trackId']) : '';

                // Without an id there is no url to derive and no stable key.
                if ($title === '' || $trackId === '') {
                    continue;
                }

                $out[] = [
                    'external_id' => $trackId,
                    'title' => $title,
                    'url' => 'https://open.spotify.com/track/'.$trackId,
                    // A plain string on this actor, not a list of objects.
                    'artist' => $this->artist($row['artists'] ?? null) ?? $this->artist($artist['name'] ?? null),
                    // Neither Spotify actor returns one; see the class docblock.
                    'isrc' => null,
                    'duration_seconds' => $this->seconds($row['duration'] ?? null),
                    // topTracks carries no release date, and the artist-level
                    // image is not this track's artwork — emitting either would
                    // be inventing data the vendor did not give us.
                    'published' => null,
                    'artwork' => null,
                ];
            }
        }

        return $out;
    }

    private function artist(mixed $value): ?string
    {
        if (is_string($value)) {
            return trim($value) !== '' ? trim($value) : null;
        }

        // Defensive: a future build could switch to a list, as the search-mode
        // sibling actor already does.
        if (is_array($value)) {
            foreach ($value as $entry) {
                $name = is_array($entry) ? ($entry['name'] ?? null) : $entry;
                if (is_string($name) && trim($name) !== '') {
                    return trim($name);
                }
            }
        }

        return null;
    }

    private function seconds(mixed $ms): ?int
    {
        return is_numeric($ms) && (int) $ms > 0 ? (int) round(((int) $ms) / 1000) : null;
    }
}
