<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 8 (G3: Spotify = SC primary): ScrapeCreators' /v1/spotify/artist is
// ONE call that carries both things the two Apify actors are billed for
// separately — discography.albums[]/singles[] (PLAIN LISTS, not {items}
// containers) for the releases stream, and discography.topTracks[] for the
// tracks stream. Both methods emit rows in the EXACT shape the Apify
// adapters pinned (SpotifyTracksAdapter / SpotifyReleasesAdapter), so
// nothing downstream can tell the lanes apart.
//
// Gate on payload shape, never HTTP status: a NotFound answer arrives as
// success:true with __typename "NotFound" and bills a credit (trial-verified
// 2026-09-01). Both methods return null unless the payload positively
// carries usable rows — a husk or an empty catalogue must read as "vendor
// miss" and fall through to Apify, which stays the only lane trusted to
// settle an empty catalogue as truth.
//
// The payload has no top-level artist name/avatar; the tracks lane's artist
// credit comes from the nested topTracks artists[].profile.name, falling
// back to profile.name (the pinned-live equivalents of the actor's flat
// `artists` string and artist-level `name`).
class SpotifyArtistNormalizer
{
    /**
     * topTracks → track rows (SpotifyTracksAdapter contract).
     *
     * @param  array<string, mixed>  $body
     * @return non-empty-list<array<string, mixed>>|null
     */
    public function tracks(array $body): ?array
    {
        $discography = $this->discography($body);
        if ($discography === null) {
            return null;
        }

        $fallbackArtist = $this->profileName($body);

        $out = [];
        foreach ((array) ($discography['topTracks'] ?? []) as $entry) {
            $track = is_array($entry) ? ($entry['track'] ?? null) : null;
            if (! is_array($track)) {
                continue;
            }

            $id = is_string($track['id'] ?? null) ? trim($track['id']) : '';
            $title = is_string($track['name'] ?? null) ? trim($track['name']) : '';
            if ($id === '' || $title === '') {
                continue;
            }

            $duration = is_array($track['duration'] ?? null) ? ($track['duration']['totalMilliseconds'] ?? null) : null;

            $out[] = [
                'external_id' => $id,
                'title' => $title,
                'url' => 'https://open.spotify.com/track/'.$id,
                'artist' => $this->firstArtist($track['artists'] ?? null) ?? $fallbackArtist,
                // Like both Apify Spotify actors: no ISRC on this surface
                // either — Spotify dedup stays on TitleRelease (F10).
                'isrc' => null,
                'duration_seconds' => is_numeric($duration) && (int) $duration > 0 ? (int) round(((int) $duration) / 1000) : null,
                // topTracks carry no release date.
                'published' => null,
                // Unlike the Apify tracks actor, the album's cover DOES ride
                // along here — providing it saves the connector one oEmbed
                // round-trip per track (it only fills artwork when absent).
                'artwork' => $this->coverUrl(is_array($track['albumOfTrack'] ?? null) ? ($track['albumOfTrack']['coverArt'] ?? null) : null),
            ];
        }

        return $out === [] ? null : $out;
    }

    /**
     * albums + singles + compilations → release rows (SpotifyReleasesAdapter
     * contract), including its one-per-(title, format) dedup — Spotify lists
     * a release again per market/edition, and the projector expects the pass.
     *
     * @param  array<string, mixed>  $body
     * @return non-empty-list<array<string, mixed>>|null
     */
    public function releases(array $body): ?array
    {
        $discography = $this->discography($body);
        if ($discography === null) {
            return null;
        }

        $out = [];
        foreach (['albums', 'singles', 'compilations'] as $list) {
            foreach ((array) ($discography[$list] ?? []) as $release) {
                if (! is_array($release)) {
                    continue;
                }

                $id = is_string($release['id'] ?? null) ? trim($release['id']) : '';
                $title = is_string($release['name'] ?? null) ? trim($release['name']) : '';
                if ($id === '' || $title === '') {
                    continue;
                }

                $type = strtolower(is_string($release['type'] ?? null) ? $release['type'] : 'album');
                $format = match (true) {
                    str_contains($type, 'single') => 'single',
                    str_contains($type, 'compilation') => 'compilation',
                    str_contains($type, 'ep') && ! str_contains($type, 'album') => 'ep',
                    default => 'album',
                };

                $tracks = is_array($release['tracks'] ?? null) ? ($release['tracks']['totalCount'] ?? null) : null;

                $out[] = [
                    'external_id' => $id,
                    'title' => $title,
                    // Derived, not sharingInfo.shareUrl — that one carries a
                    // per-request ?si= share token, which would make the same
                    // release a "changed" row on every refresh.
                    'url' => 'https://open.spotify.com/album/'.$id,
                    'format' => $format,
                    'track_count' => is_numeric($tracks) ? (int) $tracks : null,
                    'published' => $this->date($release['date'] ?? null),
                    'artwork' => $this->coverUrl($release['coverArt'] ?? null),
                ];
            }
        }

        // Same pass as SpotifyReleasesAdapter: ONE per (title, format), the
        // earliest-released then lowest id — the original, stable across runs.
        $best = [];
        foreach ($out as $release) {
            $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $release['title']) ?? '')).'|'.$release['format'];
            $rank = ((string) ($release['published'] ?? '9999')).'|'.$release['external_id'];
            if (! isset($best[$key]) || $rank < $best[$key][0]) {
                $best[$key] = [$rank, $release];
            }
        }
        $deduped = array_values(array_map(fn ($pair) => $pair[1], $best));

        return $deduped === [] ? null : $deduped;
    }

    /** @return array<string, mixed>|null the discography, only off a positively-Artist payload */
    private function discography(array $body): ?array
    {
        if (($body['__typename'] ?? null) !== 'Artist') {
            return null;
        }

        $discography = $body['discography'] ?? null;

        return is_array($discography) ? $discography : null;
    }

    private function profileName(array $body): ?string
    {
        $name = is_array($body['profile'] ?? null) ? ($body['profile']['name'] ?? null) : null;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }

    /** artists[].profile.name — first non-empty credit. */
    private function firstArtist(mixed $artists): ?string
    {
        foreach (is_array($artists) ? $artists : [] as $artist) {
            $name = is_array($artist) && is_array($artist['profile'] ?? null) ? ($artist['profile']['name'] ?? null) : null;
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return null;
    }

    /**
     * coverArt.sources[] → one URL, largest first. Width is present on
     * release covers and absent on albumOfTrack covers, so fall back to the
     * CDN size markers, then upsize to the 640 variant — the same pick the
     * Apify releases adapter makes.
     */
    private function coverUrl(mixed $coverArt): ?string
    {
        $sources = is_array($coverArt) ? ($coverArt['sources'] ?? null) : null;

        $best = null;
        foreach (is_array($sources) ? $sources : [] as $source) {
            $url = is_array($source) ? ($source['url'] ?? null) : null;
            if (! is_string($url) || ! preg_match('~^https?://~', $url)) {
                continue;
            }
            $width = is_array($source) && is_numeric($source['width'] ?? null)
                ? (int) $source['width']
                : (str_contains($url, 'ab67616d0000b273') ? 640 : (str_contains($url, 'ab67616d00001e02') ? 300 : 64));
            if ($best === null || $width > $best[0]) {
                $best = [$width, $url];
            }
        }

        return $best === null
            ? null
            : str_replace(['ab67616d00001e02', 'ab67616d00004851'], 'ab67616d0000b273', $best[1]);
    }

    /** {year, month, day} → "YYYY-MM-DD" (as much as the precision gives). */
    private function date(mixed $date): ?string
    {
        if (! is_array($date) || ! is_numeric($date['year'] ?? null)) {
            return null;
        }

        $out = sprintf('%04d', (int) $date['year']);
        if (is_numeric($date['month'] ?? null)) {
            $out .= sprintf('-%02d', (int) $date['month']);
            if (is_numeric($date['day'] ?? null)) {
                $out .= sprintf('-%02d', (int) $date['day']);
            }
        }

        return $out;
    }
}
