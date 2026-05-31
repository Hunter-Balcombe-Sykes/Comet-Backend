<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;

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
     * @return list<array{collectionId:string, name:?string, thumbnail:?string, releaseDate:?string, link:?string}>|null
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
     * @return list<array{trackId:string, name:?string, thumbnail:?string, description:string, link:?string}>|null
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
                'link' => data_get($e, 'trackViewUrl'),
            ])
            ->values()
            ->all();
    }

    // ── internals ────────────────────────────────────────────────

    private function itunes(string $path): ?array
    {
        $res = $this->fetcher->fetch('https://itunes.apple.com'.$path, ['User-Agent' => self::USER_AGENT]);
        if ($res['status'] !== 200) {
            return null;
        }
        $json = json_decode($res['body'], true);

        return is_array($json) ? $json : null;
    }

    // Artwork comes back as "...100x100bb.jpg"; swap for HD without a 2nd call.
    private function hdArtwork(?string $url100, int $size = 600): ?string
    {
        return $url100 ? str_replace('100x100bb.jpg', "{$size}x{$size}bb.jpg", $url100) : null;
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
