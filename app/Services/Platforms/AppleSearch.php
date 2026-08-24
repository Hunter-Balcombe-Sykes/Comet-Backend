<?php

namespace App\Services\Platforms;

use App\Services\Cache\CacheKeyGenerator;
use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\Cache;

// Apple Music + Apple Podcasts lookups on the unauthenticated iTunes Search API
// (no key). Resolves an artist/show to its id, then returns the recent releases
// (albums) / episodes newest-first. Extracted from AppleController so the
// controller stays thin. Spec: ~/Developer/platform link capabilites/apple-implementation.md
class AppleSearch extends PlatformScraper
{
    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

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
        // SCALE-3: cache successful lookups (iTunes is keyless, ~20 req/min/IP). Only
        // a valid decoded response is stored — a null/non-200 must be retried, never
        // remembered. Matches the YoutubeThumbnailResolver "cache the verdict, not the
        // miss" pattern; key centralised in CacheKeyGenerator.
        $key = CacheKeyGenerator::itunesResponse($path);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $res = $this->fetcher->tryFetch('https://itunes.apple.com'.$path, ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }
        $json = json_decode($res['body'], true);
        if (! is_array($json)) {
            return null;
        }

        Cache::put($key, $json, (int) config('partna.refresh.host_limits.itunes.cache_ttl_seconds'));

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
