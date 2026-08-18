<?php

namespace App\Services\Platforms\Actors;

/**
 * Spotify RELEASES (listen restructure, 2026-08-18) via
 * `nifty.codes~spotify-artistdiscography-scraper` (id 7r06dwAH933JBaR5S,
 * $0.005/start + $0.002/release, W10 probe: 22/22 Tame Impala releases with
 * cover art). Input is the artist's `/discography/all` page; the actor
 * returns one row per release — album / single / compilation — with title,
 * date, track count and cover art URLs. That is what the tracks actor
 * (topTracks only, no art) cannot give: the artist's catalogue as RELEASES,
 * with covers, so a Spotify release merges with its Apple/Bandcamp twin.
 *
 * Reuses the MusicActorAdapter seam (MusicActorDriver keys everything off a
 * `partna.music.platforms.{key}` entry) — `tracks()` is the driver's name for
 * "the rows", the shape here is release rows.
 */
final class SpotifyReleasesAdapter implements MusicActorAdapter
{
    public function input(string $identifier, int $maxTracks): array
    {
        $artistId = null;
        if (preg_match('~open\.spotify\.com/(?:intl-[a-z]{2}/)?artist/([A-Za-z0-9]+)~', $identifier, $m)) {
            $artistId = $m[1];
        }
        $url = $artistId !== null
            ? "https://open.spotify.com/artist/{$artistId}/discography/all"
            : rtrim($identifier, '/').'/discography/all';

        return ['urls' => [$url], 'maxItems' => max(1, $maxTracks)];
    }

    /** @return list<array<string, mixed>> release rows */
    public function tracks(array $dataset): array
    {
        $out = [];
        foreach ($dataset as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $this->str($row['Release ID'] ?? $row['id'] ?? null);
            $title = $this->str($row['Release Name'] ?? $row['name'] ?? null);
            if ($id === null || $title === null) {
                continue;
            }
            $type = strtolower((string) ($this->str($row['Release Type'] ?? null) ?? 'album'));
            $format = match (true) {
                str_contains($type, 'single') => 'single',
                str_contains($type, 'compilation') => 'compilation',
                str_contains($type, 'ep') && ! str_contains($type, 'album') => 'ep',
                default => 'album',
            };
            $covers = $row['All Cover Art URLs'] ?? $row['coverArt'] ?? null;
            if (is_string($covers)) {
                // The actor's dataset export joins the three sizes into ONE
                // string ("https://…b273… || https://…1e02… || https://…4851…");
                // taken verbatim it became the item's cover URL and rendered
                // as a broken image (session 3, Men I Trust). Split into a list
                // and let the size pick below choose.
                $covers = preg_split('/\s*\|\|\s*|,\s*|\s+/', trim($covers), -1, PREG_SPLIT_NO_EMPTY) ?: null;
            }
            $art = null;
            if (is_array($covers)) {
                // Largest first: the actor lists 640/300/64 in no fixed order.
                $best = null;
                foreach ($covers as $c) {
                    $u = is_array($c) ? ($c['url'] ?? null) : $c;
                    if (! is_string($u) || ! preg_match('~^https?://~', $u)) {
                        continue;
                    }
                    $w = is_array($c) && is_numeric($c['width'] ?? null) ? (int) $c['width'] : (str_contains($u, 'ab67616d0000b273') ? 640 : (str_contains($u, 'ab67616d00001e02') ? 300 : 64));
                    if ($best === null || $w > $best[0]) {
                        $best = [$w, $u];
                    }
                }
                $art = $best[1] ?? null;
            }
            $date = $this->str($row['Release Date'] ?? null);
            // The CDN's 640px variant of the same image (the actor lists 300s first).
            $art = $art === null ? null : str_replace(['ab67616d00001e02', 'ab67616d00004851'], 'ab67616d0000b273', $art);
            $out[] = [
                'external_id' => $id,
                'title' => $title,
                'url' => $this->str($row['Share URL'] ?? null) ?? "https://open.spotify.com/album/{$id}",
                'format' => $format,
                'track_count' => is_numeric($row['Track Count'] ?? null) ? (int) $row['Track Count'] : null,
                'published' => $date,
                'artwork' => $art,
            ];
        }

        // ONE per (title, format): Spotify lists a release again per market /
        // explicit-clean edition under a new id. Keep the earliest-released,
        // then the lowest id — the original, and a stable pick across runs.
        $best = [];
        foreach ($out as $release) {
            $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $release['title']) ?? '')).'|'.$release['format'];
            $rank = ((string) ($release['published'] ?? '9999')).'|'.$release['external_id'];
            if (! isset($best[$key]) || $rank < $best[$key][0]) {
                $best[$key] = [$rank, $release];
            }
        }

        return array_values(array_map(fn ($pair) => $pair[1], $best));
    }

    private function str(mixed $v): ?string
    {
        return is_string($v) && trim($v) !== '' ? trim($v) : (is_numeric($v) ? (string) $v : null);
    }
}
