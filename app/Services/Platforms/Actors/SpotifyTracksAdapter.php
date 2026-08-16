<?php

namespace App\Services\Platforms\Actors;

/**
 * automation-lab~spotify-scraper, in URL mode.
 *
 * Chosen over the actor that DOCUMENTS isrc because that one accepts keyword
 * searches only, and a connection's identity is its artist URL: anchoring on
 * the URL means the run can never return a different artist of the same name.
 * ISRC is read opportunistically here — if the build returns it, the Isrc
 * joining key starts working; if not, dedup falls back to title|artist, which
 * is the tier convergence-log F10 already planned for.
 */
class SpotifyTracksAdapter implements MusicActorAdapter
{
    public function input(string $identifier, int $maxTracks): array
    {
        return ['mode' => 'urls', 'urls' => [$identifier], 'maxResults' => $maxTracks];
    }

    public function tracks(array $dataset): array
    {
        $out = [];

        foreach ($dataset as $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = is_string($row['name'] ?? null) ? trim($row['name']) : '';
            $url = is_string($row['url'] ?? null) ? trim($row['url']) : '';

            // A row missing either is unusable: the projector rejects it anyway,
            // and landing it would burn a coord on nothing.
            if ($title === '' || $url === '') {
                continue;
            }

            $out[] = [
                'external_id' => (string) ($row['id'] ?? $url),
                'title' => $title,
                'url' => $url,
                'artist' => $this->firstArtist($row['artists'] ?? null),
                'isrc' => $this->isrc($row),
                'duration_seconds' => $this->seconds($row['durationMs'] ?? null),
                'published' => is_string($row['releaseDate'] ?? null) ? $row['releaseDate'] : null,
                'artwork' => is_string($row['coverUrl'] ?? null) ? $row['coverUrl'] : null,
            ];
        }

        return $out;
    }

    private function firstArtist(mixed $artists): ?string
    {
        if (is_string($artists)) {
            return trim($artists) !== '' ? trim($artists) : null;
        }

        if (is_array($artists)) {
            foreach ($artists as $artist) {
                // Builds differ: a bare string list, or objects carrying `name`.
                $name = is_array($artist) ? ($artist['name'] ?? null) : $artist;
                if (is_string($name) && trim($name) !== '') {
                    return trim($name);
                }
            }
        }

        return null;
    }

    /** Both shapes read, so an actor build change degrades to null, never to a wrong code. */
    private function isrc(array $row): ?string
    {
        $isrc = $row['isrc'] ?? ($row['external_ids']['isrc'] ?? null);

        // Upper-cased because KeyClass::Isrc is a JOINING key: two spellings of
        // one code would fail to union the rows the key exists to union.
        return is_string($isrc) && trim($isrc) !== '' ? strtoupper(trim($isrc)) : null;
    }

    private function seconds(mixed $ms): ?int
    {
        return is_numeric($ms) && (int) $ms > 0 ? (int) round(((int) $ms) / 1000) : null;
    }
}
