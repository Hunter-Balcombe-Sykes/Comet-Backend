<?php

namespace App\Services\Platforms;

use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Http\SafeUrlFetcher;

// Apple Music + Apple Podcasts lookups on the unauthenticated iTunes Search API
// (no key). Resolves an artist/show to its id, then returns the recent releases
// (albums) / episodes newest-first. Extracted from AppleController so the
// controller stays thin. Spec: ~/Developer/platform link capabilites/apple-implementation.md
class AppleSearch extends PlatformScraper
{
    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly CacheLockService $cache,
    ) {}

    /**
     * The artist's most-recent albums/releases, newest first, up to $limit.
     *
     * @return list<array{collectionId:string, name:?string, thumbnail:?string, releaseDate:?string, link:?string, artistName:?string}>|null
     */
    public function fetchAlbums(string $input, int $limit = 15): ?array
    {
        $artistId = $this->resolveArtistId($input);
        if ($artistId === null) {
            return null;
        }

        $data = $this->itunes("/lookup?id={$artistId}&entity=album&limit=25");

        return collect(data_get($data, 'results', []))
            ->filter(fn ($r) => data_get($r, 'wrapperType') === 'collection')
            ->sortByDesc(fn ($r) => data_get($r, 'releaseDate'))
            ->take($limit)
            ->map(fn ($a) => [
                'collectionId' => (string) data_get($a, 'collectionId'),
                'name' => data_get($a, 'collectionName'),
                // FI-6 (2026-08-20): the ARTIST, not the release — the
                // connection row's display name resolver skips payload.name
                // for apple_music.artist (it's a content title) and reads
                // artistName; without it the row printed the raw numeric id.
                'artistName' => data_get($a, 'artistName'),
                'thumbnail' => $this->hdArtwork(data_get($a, 'artworkUrl100')),
                'releaseDate' => data_get($a, 'releaseDate'),
                'link' => data_get($a, 'collectionViewUrl'),
            ])
            ->values()
            ->all();
    }

    /**
     * The show's most-recent episodes, newest first, up to $limit.
     *
     * @return list<array{trackId:string, name:?string, thumbnail:?string, description:string, releaseDate:?string, link:?string}>|null
     */
    public function fetchEpisodes(string $input, int $limit = 15): ?array
    {
        $podcastId = $this->resolvePodcastId($input);
        if ($podcastId === null) {
            return null;
        }

        // +1 because the lookup also returns the podcast collection itself.
        $data = $this->itunes("/lookup?id={$podcastId}&entity=podcastEpisode&limit=".($limit + 1));

        return collect(data_get($data, 'results', []))
            ->filter(fn ($r) => data_get($r, 'wrapperType') === 'podcastEpisode')
            ->sortByDesc(fn ($r) => data_get($r, 'releaseDate'))
            ->take($limit)
            ->map(fn ($e) => [
                'trackId' => (string) data_get($e, 'trackId'),
                'name' => data_get($e, 'trackName'),
                'thumbnail' => $this->hdArtwork(
                    data_get($e, 'artworkUrl600') ?? data_get($e, 'artworkUrl160') ?? data_get($e, 'artworkUrl60')
                ),
                'description' => data_get($e, 'description') ?: (data_get($e, 'shortDescription') ?: ''),
                // iTunes already returns releaseDate (we sort by it above); carry it
                // through so the sitepage can sort episodes chronologically.
                'releaseDate' => data_get($e, 'releaseDate'),
                'link' => data_get($e, 'trackViewUrl'),
            ])
            ->values()
            ->all();
    }

    /**
     * The artist's primary genre (e.g. "Dance", "Hip-Hop/Rap", "Alternative"),
     * lower-cased, from the keyless iTunes artist lookup (`primaryGenreName`), or
     * null (#76 Part B). Best-effort: any miss (unresolvable artist, no genre on
     * the record) returns null so MusicGenreFactor abstains rather than errors.
     *
     * Same keyless iTunes API + cache path as fetchAlbums — no new credential.
     */
    public function fetchGenre(string $input): ?string
    {
        $artistId = $this->resolveArtistId($input);
        if ($artistId === null) {
            return null;
        }

        $data = $this->itunes("/lookup?id={$artistId}");
        // The artist record is the wrapperType='artist' row; primaryGenreName
        // rides on it directly. data_get tolerates the array-of-results shape.
        $genre = data_get($data, 'results.0.primaryGenreName');

        return is_string($genre) && trim($genre) !== '' ? strtolower(trim($genre)) : null;
    }

    private function itunes(string $path): ?array
    {
        // SCALE-3: cache successful lookups (iTunes is keyless, ~20 req/min/IP,
        // shared across the whole fleet). CCH-1: an uncoordinated stampede on
        // expiry can 429 the lookup for every user, so this now single-flights
        // through CacheLockService rather than reading/writing Cache directly.
        //
        // rememberLockedNullable, not rememberLocked: rememberLocked's stale
        // twin is meant for a value worth serving last-known-good, and its
        // docblock forbids a null-returning callback. fetchDecoded() can
        // legitimately return null (429, decode failure), and feeding that
        // through rememberLocked would write null to BOTH the primary and the
        // stale twin — destroying the last-good response on a transient
        // failure. Do not "upgrade" this to rememberLocked; there is nothing
        // here for SWR/jitter to protect, since a failure must never be cached.
        $key = CacheKeyGenerator::itunesResponse($path);

        $result = $this->cache->rememberLockedNullable(
            $key,
            (int) config('partna.refresh.host_limits.itunes.cache_ttl_seconds'),
            fn (): ?array => $this->fetchDecoded($path),
            // nullTtl: 0 is load-bearing, not a rounding default. The negative
            // TTL here has always been zero — a miss "must be retried, never
            // remembered" (see fetchDecoded()) — and Repository::put() forwards
            // $seconds <= 0 to forget(), so nothing is written at all: no key,
            // no stale negative-cache window. RefreshHostLimitsTest pins two
            // back-to-back calls after a 429 both re-fetching; nullTtl: 1 would
            // break that (both land in the same second).
            nullTtl: 0,
            // lockSeconds 20: partna.http_fetch.timeout_seconds (15) plus a
            // ~6s connect leg — the CacheLockService default of 10 would expire
            // mid-fetch. blockSeconds 3: this runs inside a 45s connect budget.
            lockSeconds: 20,
            blockSeconds: 3,
        );

        return is_array($result) ? $result : null;
    }

    /**
     * Fetch + decode one iTunes path. Only a valid decoded response is
     * returned — a null/non-200/undecodable body must be retried, never
     * remembered. Matches the YoutubeThumbnailResolver "cache the verdict,
     * not the miss" pattern.
     */
    private function fetchDecoded(string $path): ?array
    {
        $res = $this->fetcher->tryFetch('https://itunes.apple.com'.$path, ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }
        $json = json_decode($res['body'], true);
        if (! is_array($json)) {
            return null;
        }

        return $json;
    }

    // Artwork comes back as "...100x100bb.jpg"; swap for HD without a 2nd call.
    private function hdArtwork(?string $url100): ?string
    {
        return $url100 ? str_replace('100x100bb.jpg', '600x600bb.jpg', $url100) : null;
    }

    private function resolveArtistId(string $input): ?int
    {
        if (str_starts_with($input, 'http') && str_contains($input, 'music.apple.com')
            && preg_match('~/artist/[^/]+/(\d+)~', $input, $m)) {
            return (int) $m[1];
        }

        $data = $this->itunes('/search?term='.rawurlencode($input).'&entity=musicArtist&limit=1');
        $id = data_get($data, 'results.0.artistId');

        return $id ? (int) $id : null;
    }

    private function resolvePodcastId(string $input): ?int
    {
        if (str_starts_with($input, 'http') && str_contains($input, 'podcasts.apple.com')
            && preg_match('~/id(\d+)~', $input, $m)) {
            return (int) $m[1];
        }

        $data = $this->itunes('/search?term='.rawurlencode($input).'&entity=podcast&limit=1');
        $id = data_get($data, 'results.0.collectionId');

        return $id ? (int) $id : null;
    }
}
