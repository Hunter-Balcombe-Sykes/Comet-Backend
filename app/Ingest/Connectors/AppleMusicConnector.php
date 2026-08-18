<?php

namespace App\Ingest\Connectors;

use App\Ingest\Landing\Coverage;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Message;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\AppleMusicReleaseProjector;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

/**
 * Apple Music discography via the keyless iTunes Lookup API (GET
 * itunes.apple.com/lookup?id={artistId}&entity=album&limit=200) — the same
 * unauthenticated endpoint AppleSearch::fetchAlbums() already calls for the
 * connect-time "latest release" widget, generalised from a 25-row preview to
 * a 200-row catalogue pull.
 *
 * `results[0]` is always the artist itself (`wrapperType` = `artist`), never
 * an album. This mirrors AppleSearch's own positionless filter
 * (`wrapperType === 'collection'`) rather than skipping a fixed index, since
 * that filter is what is actually proven against the live API in this
 * codebase.
 *
 * The `limit` parameter caps the TOTAL row count of `results`, wrapper
 * included — confirmed by AppleSearch::fetchEpisodes()'s own `$limit + 1`
 * ("+1 because the lookup also returns the podcast collection itself"),
 * which only makes sense if the wrapper eats one of the requested slots. So a
 * `resultCount` at (or past) the requested 200 means at least one older
 * album may be hiding beyond the cap: the only honest claim is a prefix down
 * to the oldest releaseDate actually seen, never exhaustive — getting this
 * wrong at the boundary would let a real 200th-and-older album get folded
 * away as "deleted" (C5), the same class of mistake VimeoConnector's 20-video
 * cap already guards against.
 *
 * NOTE (flagged per instructions): the exact "wrapper consumes one limit
 * slot" mechanics are inferred from AppleSearch's own comment, not from a
 * fresh live capture taken for this connector — it is grounded in existing,
 * already-shipped code rather than an independent guess, but has not been
 * re-verified here.
 */
class AppleMusicConnector implements Connector
{
    private const LOOKUP_LIMIT = 200;

    public static function manifest(): Manifest
    {
        return new Manifest(
            source: SourceKey::of('apple_music'),
            identifierKind: 'artist_id',
            hosts: ['itunes.apple.com', '*.mzstatic.com'],
            streams: [
                'listen' => new StreamSpec(
                    name: 'listen',
                    target: 'release',
                    profile: SourceProfile::Catalogue,
                    // An album with no id/name is not a release; landing it
                    // would poison every projection downstream.
                    requires: ['collectionId', 'collectionName'],
                    // Artwork URLs can carry a query string on the mzstatic
                    // CDN; stripping it keeps an unchanged album from looking
                    // like a content change every run.
                    volatile: ['artworkUrl100?query'],
                    orderField: 'releaseDate',
                ),
                // Songs (owner ruling R10, overnight 2026-08-18): the SAME
                // lookup with entity=song, projected as `track` so an Apple
                // song can union with the Spotify / SoundCloud / YouTube Music
                // track for the one listen item. Albums stay their own stream.
                'songs' => new StreamSpec(
                    name: 'songs',
                    target: 'track',
                    profile: SourceProfile::Catalogue,
                    requires: ['trackId', 'title', 'url'],
                    volatile: ['artwork?query'],
                    orderField: 'published',
                ),
            ],
            cost: CostClass::Free,
            defaultIntervalSeconds: 43200,
        );
    }

    /** @return iterable<Message> */
    public function pull(Pull $pull, Io $io): iterable
    {
        // The runtime pulls ONE stream at a time (RunExecutor::drain per
        // StreamSpec), so this switch is what keeps song records out of the
        // album stream's shape check and vice versa.
        if ($pull->stream->name === 'songs') {
            yield from $this->pullSongs(trim($pull->identifier), $io);

            return;
        }

        $artistId = trim($pull->identifier);
        $response = $io->get('https://itunes.apple.com/lookup?'.http_build_query([
            'id' => $artistId,
            'entity' => 'album',
            'limit' => self::LOOKUP_LIMIT,
        ]));

        if ($response['status'] !== 200 || $response['body'] === '') {
            // A failed fetch is UNAVAILABLE, never "the artist has no
            // albums" — emitting nothing here would let absence-folding
            // conclude the whole catalogue was deleted (C5).
            yield new Unavailable("lookup returned {$response['status']}", $response['status']);

            return;
        }

        $decoded = json_decode($response['body'], true);
        if (! is_array($decoded) || ! is_array($decoded['results'] ?? null)) {
            // A 200 that isn't the expected resultCount/results shape (an
            // HTML error/interstitial page, most likely) is a shape break,
            // not an empty catalogue.
            yield new Unavailable('lookup did not decode to the expected resultCount/results shape', $response['status']);

            return;
        }

        $results = $decoded['results'];
        $resultCount = is_numeric($decoded['resultCount'] ?? null) ? (int) $decoded['resultCount'] : count($results);

        if ($resultCount === 0) {
            // Zero results means the artist id itself no longer resolves —
            // indistinguishable from "no albums" at this endpoint, and both
            // must be UNAVAILABLE, never an empty catalogue.
            yield new Unavailable('lookup returned resultCount=0', $response['status']);

            return;
        }

        $items = [];
        foreach ($results as $result) {
            if (! is_array($result) || ($result['wrapperType'] ?? null) !== 'collection') {
                continue;
            }
            $item = $this->mapAlbum($result);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $items = self::dedupeAlbums($items);

        if ($items === []) {
            yield new Note('empty_catalogue', 'No albums parsed from the iTunes lookup response');

            return;
        }

        foreach ($items as $item) {
            yield new Record('listen', $item['collectionId'], $item);
        }

        $dates = array_filter(array_column($items, 'releaseDate'));
        yield new Covered('listen', $resultCount >= self::LOOKUP_LIMIT
            ? Coverage::prefix($dates === [] ? null : min($dates), count($items))
            : Coverage::exhaustive());

    }

    /**
     * The songs stream: one more free lookup, entity=song. A failed or empty
     * songs call never taints the album stream above — it is a Note without a
     * coverage claim, so a flaky second call folds nothing into absence.
     *
     * @return iterable<Message>
     */
    private function pullSongs(string $artistId, Io $io): iterable
    {
        $response = $io->get('https://itunes.apple.com/lookup?'.http_build_query([
            'id' => $artistId,
            'entity' => 'song',
            'limit' => self::LOOKUP_LIMIT,
        ]));
        if ($response['status'] !== 200 || $response['body'] === '') {
            // A Note, not Unavailable: Unavailable is source-wide and would
            // fold the healthy album stream; with no Covered('songs') claimed,
            // no song absence is folded either.
            yield new Note('songs_unavailable', "song lookup returned {$response['status']}");

            return;
        }
        $decoded = json_decode($response['body'], true);
        $results = is_array($decoded) && is_array($decoded['results'] ?? null) ? $decoded['results'] : null;
        if ($results === null) {
            yield new Note('songs_unavailable', 'song lookup did not decode to results');

            return;
        }
        $songs = [];
        foreach ($results as $result) {
            if (! is_array($result) || ($result['wrapperType'] ?? null) !== 'track' || ($result['kind'] ?? null) !== 'song') {
                continue;
            }
            $trackId = $result['trackId'] ?? null;
            $title = is_string($result['trackName'] ?? null) ? trim($result['trackName']) : '';
            $url = is_string($result['trackViewUrl'] ?? null) ? $result['trackViewUrl'] : null;
            if ($trackId === null || $title === '' || $url === null) {
                continue;
            }
            $ms = is_numeric($result['trackTimeMillis'] ?? null) ? (int) $result['trackTimeMillis'] : null;
            $songs[] = [
                'trackId' => (string) $trackId,
                'title' => $title,
                'url' => $url,
                'artist' => is_string($result['artistName'] ?? null) ? $result['artistName'] : null,
                'album' => is_string($result['collectionName'] ?? null) ? $result['collectionName'] : null,
                'collectionArtistName' => is_string($result['collectionArtistName'] ?? null) ? $result['collectionArtistName'] : null,
                'collectionId' => isset($result['collectionId']) && is_scalar($result['collectionId']) ? (string) $result['collectionId'] : null,
                'track_number' => is_numeric($result['trackNumber'] ?? null) ? (int) $result['trackNumber'] : null,
                'disc_number' => is_numeric($result['discNumber'] ?? null) ? (int) $result['discNumber'] : null,
                'duration_seconds' => $ms !== null && $ms > 0 ? (int) round($ms / 1000) : null,
                'published' => is_string($result['releaseDate'] ?? null) ? $result['releaseDate'] : null,
                // Apple serves any square size by rewriting the path: the
                // lookup's 100x100bb thumbnail becomes 1200x1200bb (R10 best
                // quality). Kept as artwork_small too for a fallback.
                'artwork' => is_string($result['artworkUrl100'] ?? null) ? self::upscaleArtwork($result['artworkUrl100']) : null,
                'artwork_small' => is_string($result['artworkUrl100'] ?? null) ? $result['artworkUrl100'] : null,
                'isrc' => null,
            ];
        }
        if ($songs === []) {
            yield new Note('empty_songs', 'No songs parsed from the iTunes lookup response');

            return;
        }
        // ONE record per song, not one per collection it appears on: iTunes
        // returns the same recording on the artist's album, its single, and
        // every "Various Artists" compilation/playlist-album that licensed
        // it ("The Less I Know The Better" ×5: Currents, lower cortisol,
        // Anxiety Relief, Kochen & Gute Laune…). Those are the same track and
        // read as duplicates in the listen pool (owner, 2026-08-18). Keep, per
        // (title, ±2s duration): the artist's own collection over a
        // compilation, then the earliest release (the original over the
        // single/reissue), so the track's `album` names where it comes from.
        $songs = self::dedupeSongs($songs);
        foreach ($songs as $song) {
            yield new Record('songs', $song['trackId'], $song);
        }
        $count = is_numeric($decoded['resultCount'] ?? null) ? (int) $decoded['resultCount'] : count($songs);
        $dates = array_filter(array_column($songs, 'published'));
        yield new Covered('songs', $count >= self::LOOKUP_LIMIT
            ? Coverage::prefix($dates === [] ? null : min($dates), count($songs))
            : Coverage::exhaustive());
    }

    /**
     * @param  list<array<string, mixed>>  $songs
     * @return list<array<string, mixed>>
     */
    public static function dedupeSongs(array $songs): array
    {
        // ONE per title: the same song on a soundtrack (215s), the album
        // (211s) and a compilation is one song to a listener — an "edition"
        // split only made the pool read as duplicates. Preference: the
        // artist's own collection over a compilation, an album/EP over a
        // single (the album is where the song lives; the single is a
        // release, and lands as one on the releases stream), then the
        // earliest release date.
        $best = [];
        foreach ($songs as $song) {
            $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $song['title']) ?? ''));
            $current = $best[$key] ?? null;
            if ($current === null || self::songRank($song) < self::songRank($current)) {
                $best[$key] = $song;
            }
        }

        return array_values($best);
    }

    /** Lower ranks first: own collection, then album over single, then earliest release. */
    private static function songRank(array $song): string
    {
        $artist = mb_strtolower(trim((string) ($song['artist'] ?? '')));
        $collectionArtist = mb_strtolower(trim((string) ($song['collectionArtistName'] ?? '')));
        $compilation = $collectionArtist !== '' && $collectionArtist !== $artist && ! str_contains($collectionArtist, $artist);
        $single = (bool) preg_match('/\s-\s(Single|EP)$/i', (string) ($song['album'] ?? ''));
        $date = (string) ($song['published'] ?? '9999');

        return ($compilation ? '1' : '0').($single ? '1' : '0').'|'.$date;
    }

    /**
     * ONE record per release title+format: iTunes lists the same album twice
     * (clean/explicit, regional catalog copies) under different collectionIds.
     * Keep the earliest-released, then the lowest id, for a stable pick.
     *
     * @param  list<array<string, mixed>>  $albums
     * @return list<array<string, mixed>>
     */
    public static function dedupeAlbums(array $albums): array
    {
        $best = [];
        foreach ($albums as $album) {
            [$clean, $format] = AppleMusicReleaseProjector::formatFromName((string) $album['collectionName'], $album['trackCount'] ?? null);
            $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $clean) ?? '')).'|'.$format;
            $rank = ((string) ($album['releaseDate'] ?? '9999')).'|'.str_pad((string) $album['collectionId'], 20, '0', STR_PAD_LEFT);
            $current = $best[$key] ?? null;
            if ($current === null || $rank < $current[0]) {
                $best[$key] = [$rank, $album];
            }
        }

        return array_values(array_map(fn ($pair) => $pair[1], $best));
    }

    /** mzstatic serves the artwork at any square size via the path suffix. */
    public static function upscaleArtwork(string $url, int $edge = 1200): string
    {
        return preg_replace('~/\d+x\d+bb(-\d+)?\.(jpg|png|webp)(\?.*)?$~i', "/{$edge}x{$edge}bb.$2", $url) ?? $url;
    }

    /** @param  array<string, mixed>  $result
     * @return array<string, mixed>|null */
    private function mapAlbum(array $result): ?array
    {
        $collectionId = $result['collectionId'] ?? null;
        $collectionName = is_string($result['collectionName'] ?? null) ? trim($result['collectionName']) : '';

        if ($collectionId === null || $collectionId === '' || $collectionName === '') {
            return null;
        }

        return [
            'collectionId' => (string) $collectionId,
            'collectionName' => $collectionName,
            'artistName' => is_string($result['artistName'] ?? null) ? $result['artistName'] : null,
            'releaseDate' => is_string($result['releaseDate'] ?? null) ? $result['releaseDate'] : null,
            'artworkUrl100' => is_string($result['artworkUrl100'] ?? null) ? $result['artworkUrl100'] : null,
            'collectionViewUrl' => is_string($result['collectionViewUrl'] ?? null) ? $result['collectionViewUrl'] : null,
            'trackCount' => is_numeric($result['trackCount'] ?? null) ? (int) $result['trackCount'] : null,
            'collectionType' => is_string($result['collectionType'] ?? null) ? $result['collectionType'] : null,
            'primaryGenreName' => is_string($result['primaryGenreName'] ?? null) ? $result['primaryGenreName'] : null,
        ];
    }
}
