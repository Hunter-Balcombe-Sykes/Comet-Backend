<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Services\SmartLinks\SafeUrlFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

// Test-mode endpoints for Apple Music + Apple Podcasts. One controller, two
// independent sections (music = latest album, podcast = latest episode), both
// on the unauthenticated iTunes Search API. Single-tenant cache, no auth, no
// migration, no API key. Full spec:
//   ~/Developer/platform link capabilites/apple-implementation.md
class AppleController extends ApiController
{
    private const MUSIC_KEY = 'platforms.apple.music.selection';

    private const PODCAST_KEY = 'platforms.apple.podcast.selection';

    private const CACHE_TTL_DAYS = 30;

    private const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    // ── Music ────────────────────────────────────────────────────

    // POST /api/platforms/apple/music/connect
    public function connectMusic(Request $request): JsonResponse
    {
        $validated = $request->validate(['artist' => ['required', 'string', 'max:200']]);
        $album = $this->fetchLatestAlbum(trim($validated['artist']));
        if ($album === null) {
            return $this->error('Could not find that Apple Music artist or an album.', 404);
        }

        $selection = ['input' => trim($validated['artist']), ...$album];
        Cache::put(self::MUSIC_KEY, $selection, now()->addDays(self::CACHE_TTL_DAYS));

        return $this->success($selection);
    }

    // GET /api/platforms/apple/music/selection
    public function musicSelection(): JsonResponse
    {
        return $this->success(['selection' => Cache::get(self::MUSIC_KEY)]);
    }

    // ── Podcast ───────────────────────────────────────────────────

    // POST /api/platforms/apple/podcast/connect
    public function connectPodcast(Request $request): JsonResponse
    {
        $validated = $request->validate(['show' => ['required', 'string', 'max:200']]);
        $episode = $this->fetchLatestEpisode(trim($validated['show']));
        if ($episode === null) {
            return $this->error('Could not find that Apple Podcast or an episode.', 404);
        }

        $selection = ['input' => trim($validated['show']), ...$episode];
        Cache::put(self::PODCAST_KEY, $selection, now()->addDays(self::CACHE_TTL_DAYS));

        return $this->success($selection);
    }

    // GET /api/platforms/apple/podcast/selection
    public function podcastSelection(): JsonResponse
    {
        return $this->success(['selection' => Cache::get(self::PODCAST_KEY)]);
    }

    // DELETE /api/platforms/apple — clear both.
    public function forget(): JsonResponse
    {
        Cache::forget(self::MUSIC_KEY);
        Cache::forget(self::PODCAST_KEY);

        return $this->success(['music' => null, 'podcast' => null]);
    }

    // ── internals ────────────────────────────────────────────────

    private function itunes(string $path): ?array
    {
        $res = $this->fetcher->fetch('https://itunes.apple.com'.$path, ['User-Agent' => self::UA]);
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

    /**
     * @return array{name:?string, thumbnail:?string, releaseDate:?string, link:?string}|null
     */
    private function fetchLatestAlbum(string $input): ?array
    {
        $artistId = $this->resolveArtistId($input);
        if ($artistId === null) {
            return null;
        }

        $data = $this->itunes("/lookup?id={$artistId}&entity=album&limit=25");
        $album = collect(data_get($data, 'results', []))
            ->filter(fn ($r) => data_get($r, 'wrapperType') === 'collection')
            ->sortByDesc(fn ($r) => data_get($r, 'releaseDate'))
            ->first();
        if (! $album) {
            return null;
        }

        return [
            'name' => data_get($album, 'collectionName'),
            'thumbnail' => $this->hdArtwork(data_get($album, 'artworkUrl100')),
            'releaseDate' => data_get($album, 'releaseDate'),
            'link' => data_get($album, 'collectionViewUrl'),
        ];
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

    /**
     * @return array{name:?string, thumbnail:?string, description:string, link:?string}|null
     */
    private function fetchLatestEpisode(string $input): ?array
    {
        $podcastId = $this->resolvePodcastId($input);
        if ($podcastId === null) {
            return null;
        }

        $data = $this->itunes("/lookup?id={$podcastId}&entity=podcastEpisode&limit=10");
        $episode = collect(data_get($data, 'results', []))
            ->filter(fn ($r) => data_get($r, 'wrapperType') === 'podcastEpisode')
            ->sortByDesc(fn ($r) => data_get($r, 'releaseDate'))
            ->first();
        if (! $episode) {
            return null;
        }

        $art = data_get($episode, 'artworkUrl600')
            ?? data_get($episode, 'artworkUrl160')
            ?? data_get($episode, 'artworkUrl60');

        return [
            'name' => data_get($episode, 'trackName'),
            'thumbnail' => $this->hdArtwork($art),
            'description' => data_get($episode, 'description') ?: (data_get($episode, 'shortDescription') ?: ''),
            'link' => data_get($episode, 'trackViewUrl'),
        ];
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
