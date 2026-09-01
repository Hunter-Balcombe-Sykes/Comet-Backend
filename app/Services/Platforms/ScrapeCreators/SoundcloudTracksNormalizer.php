<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 8 (G3: SoundCloud = SC primary): /v1/soundcloud/artist/tracks
// answers {tracks: [...]} — already tracks-only, no profile row riding in
// the list the way the Apify actor's flat dataset carries one. Rows come
// out in SoundcloudTracksAdapter's exact shape, ISRC included (from
// publisher_metadata — still the joining key KeyClass::Isrc unions on, so
// still upper-cased).
//
// Returns null unless the payload positively carries tracks. An EMPTY
// tracks list is deliberately a null too, not an answer: handles are
// exact-match with no fuzzy resolution, so a squatter or husk answering
// "successfully" with nothing must fall through to Apify — the only lane
// trusted to settle an empty catalogue (MusicActorDriver's Answered([])).
class SoundcloudTracksNormalizer
{
    /**
     * @param  array<string, mixed>  $body
     * @return non-empty-list<array<string, mixed>>|null
     */
    public function tracks(array $body): ?array
    {
        $tracks = $body['tracks'] ?? null;
        if (! is_array($tracks)) {
            return null;
        }

        $out = [];
        foreach ($tracks as $row) {
            if (! is_array($row)) {
                continue;
            }
            // Defensive twin of the Apify adapter's type guard.
            if (($row['kind'] ?? 'track') !== 'track') {
                continue;
            }

            $title = is_string($row['title'] ?? null) ? trim($row['title']) : '';
            $url = is_string($row['permalink_url'] ?? null) ? trim($row['permalink_url']) : '';
            if ($title === '' || $url === '') {
                continue;
            }

            $publisher = is_array($row['publisher_metadata'] ?? null) ? $row['publisher_metadata'] : [];
            $isrc = $publisher['isrc'] ?? null;
            $artist = is_array($row['user'] ?? null) ? ($row['user']['username'] ?? null) : null;

            $out[] = [
                'external_id' => (string) ($row['id'] ?? $url),
                'title' => $title,
                'url' => $url,
                'artist' => is_string($artist) && trim($artist) !== '' ? trim($artist) : null,
                'isrc' => is_string($isrc) && trim($isrc) !== '' ? strtoupper(trim($isrc)) : null,
                'duration_seconds' => $this->seconds($row['duration'] ?? null),
                // release_date is only present on label releases; created_at
                // keeps self-uploads orderable (the stream's orderField).
                'published' => $this->str($row['release_date'] ?? null) ?? $this->str($row['created_at'] ?? null),
                'artwork' => $this->str($row['artwork_url'] ?? null),
            ];
        }

        return $out === [] ? null : $out;
    }

    /** SoundCloud reports duration in milliseconds. */
    private function seconds(mixed $ms): ?int
    {
        return is_numeric($ms) && (int) $ms > 0 ? (int) round(((int) $ms) / 1000) : null;
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
