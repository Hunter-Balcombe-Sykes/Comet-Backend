<?php

namespace App\Services\Platforms\Actors;

/**
 * automation-lab~soundcloud-scraper, in userUrl mode.
 *
 * SHAPE PINNED BY LIVE PROBE 2026-08-16 (convergence-log F31). The dataset is a
 * FLAT list whose first row is the profile and whose rest are tracks, told
 * apart by `type`:
 *
 *   [{type:"user", …},
 *    {type:"track", id, title, url, artworkUrl, genre, duration, releaseDate,
 *     userName, userUrl, isrc, …}, …]
 *
 * This is the more valuable of the two music actors: it returns a real `isrc`
 * (verified live — e.g. US38Y2548239), which gives KeyClass::Isrc its first
 * producer since the column was created and turns cross-platform track dedup
 * from title-matching into a vendor-identifier match (convergence-log F10).
 *
 * Two input details cost a probe each and are easy to get wrong:
 *  - `startUrls` takes PLAIN STRINGS here. Passing Apify's usual
 *    [{url: …}] objects runs successfully and returns ZERO rows — a silent
 *    empty, not an error.
 *  - The artist is a flat `userName`, not a nested user object.
 */
class SoundcloudTracksAdapter implements MusicActorAdapter
{
    public function input(string $identifier, int $maxTracks): array
    {
        return [
            'mode' => 'userUrl',
            'startUrls' => [$identifier],
            'maxResults' => $maxTracks,
        ];
    }

    public function tracks(array $dataset): array
    {
        $out = [];

        foreach ($dataset as $row) {
            if (! is_array($row)) {
                continue;
            }

            // The profile row rides in the same list; projecting it would land
            // the artist themselves as a track.
            if (($row['type'] ?? null) !== 'track') {
                continue;
            }

            $title = is_string($row['title'] ?? null) ? trim($row['title']) : '';
            $url = is_string($row['url'] ?? null) ? trim($row['url']) : '';

            if ($title === '' || $url === '') {
                continue;
            }

            $isrc = $row['isrc'] ?? null;
            $userName = $row['userName'] ?? null;

            $out[] = [
                'external_id' => (string) ($row['id'] ?? $url),
                'title' => $title,
                'url' => $url,
                'artist' => is_string($userName) && trim($userName) !== '' ? trim($userName) : null,
                // Upper-cased because KeyClass::Isrc is a JOINING key: two
                // spellings of one code would fail to union the very rows the
                // key exists to union.
                'isrc' => is_string($isrc) && trim($isrc) !== '' ? strtoupper(trim($isrc)) : null,
                'duration_seconds' => $this->seconds($row['duration'] ?? null),
                'published' => is_string($row['releaseDate'] ?? null) ? $row['releaseDate'] : null,
                'artwork' => is_string($row['artworkUrl'] ?? null) ? $row['artworkUrl'] : null,
            ];
        }

        return $out;
    }

    /** SoundCloud reports duration in milliseconds. */
    private function seconds(mixed $ms): ?int
    {
        return is_numeric($ms) && (int) $ms > 0 ? (int) round(((int) $ms) / 1000) : null;
    }
}
