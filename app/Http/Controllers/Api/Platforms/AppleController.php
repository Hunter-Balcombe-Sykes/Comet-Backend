<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Services\Platforms\AppleSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

// Test-mode endpoints for Apple Music + Apple Podcasts. One controller, two
// independent selections (music = latest album + highlights, podcast = latest
// episode + highlights), both on the unauthenticated iTunes Search API via
// App\Services\Platforms\AppleSearch. No auth, no key. Two keys, so this
// controller keeps its own storage rather than the single-key trait.
// Spec: ~/Developer/platform link capabilites/apple-implementation.md
class AppleController extends ApiController
{
    private const MUSIC_KEY = 'platforms.apple.music.selection';

    private const PODCAST_KEY = 'platforms.apple.podcast.selection';

    private const CACHE_TTL_DAYS = 30;

    private const MAX_HIGHLIGHTS = 5;

    public function __construct(private readonly AppleSearch $apple) {}

    // ── Music ────────────────────────────────────────────────────

    // POST /api/platforms/apple/music/connect
    public function connectMusic(Request $request): JsonResponse
    {
        $validated = $request->validate(['artist' => ['required', 'string', 'max:200']]);
        $input = trim($validated['artist']);

        $albums = $this->apple->fetchAlbums($input);
        if (empty($albums)) {
            return $this->error('Could not find that Apple Music artist or an album.', 404);
        }
        $latest = $albums[0];

        $selection = [
            'input' => $input,
            // Flat fields retained for partna-pages + back-compat. The nested
            // `latest` is the canonical shape (same as a highlight item) and
            // is what the dashboard now reads to render the "Most recent" tile.
            'name' => $latest['name'],
            'thumbnail' => $latest['thumbnail'],
            'releaseDate' => $latest['releaseDate'],
            'link' => $latest['link'],
            'latest' => $latest,
            'highlights' => $this->keptHighlights(self::MUSIC_KEY, $input),
        ];
        $this->put(self::MUSIC_KEY, $selection);

        return $this->success($selection);
    }

    // GET /api/platforms/apple/music/selection
    public function musicSelection(): JsonResponse
    {
        return $this->success(['selection' => Cache::get(self::MUSIC_KEY)]);
    }

    // GET /api/platforms/apple/music/recent — last 15 albums for the picker.
    public function musicRecent(): JsonResponse
    {
        $input = data_get(Cache::get(self::MUSIC_KEY), 'input');
        if (! $input) {
            return $this->error('Connect an Apple Music artist first.', 404);
        }
        $albums = $this->apple->fetchAlbums($input);
        if ($albums === null) {
            return $this->error('Could not load recent albums.', 502);
        }

        return $this->success(['albums' => $albums]);
    }

    // POST /api/platforms/apple/music/highlights — snapshot up to 5 chosen albums.
    public function musicHighlights(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'albumIds' => ['present', 'array', 'max:'.self::MAX_HIGHLIGHTS],
            'albumIds.*' => ['string', 'max:30'],
        ]);
        $selection = Cache::get(self::MUSIC_KEY);
        if (! $selection) {
            return $this->error('Connect an Apple Music artist first.', 404);
        }
        $albums = $this->apple->fetchAlbums(data_get($selection, 'input'));
        if ($albums === null) {
            return $this->error('Could not load recent albums.', 502);
        }

        // Refresh the "Most recent" tile too. This re-fetch is newest-first, so
        // a release that landed since connect would otherwise leave `latest`
        // (and the flat back-compat fields) stale while only highlights updated.
        if (isset($albums[0])) {
            $latest = $albums[0];
            $selection['latest'] = $latest;
            $selection['name'] = $latest['name'];
            $selection['thumbnail'] = $latest['thumbnail'];
            $selection['releaseDate'] = $latest['releaseDate'];
            $selection['link'] = $latest['link'];
        }

        $selection['highlights'] = $this->snapshot($albums, 'collectionId', $validated['albumIds']);
        $this->put(self::MUSIC_KEY, $selection);

        return $this->success($selection);
    }

    // ── Podcast ───────────────────────────────────────────────────

    // POST /api/platforms/apple/podcast/connect
    public function connectPodcast(Request $request): JsonResponse
    {
        $validated = $request->validate(['show' => ['required', 'string', 'max:200']]);
        $input = trim($validated['show']);

        $episodes = $this->apple->fetchEpisodes($input);
        if (empty($episodes)) {
            return $this->error('Could not find that Apple Podcast or an episode.', 404);
        }
        $latest = $episodes[0];

        $selection = [
            'input' => $input,
            // Flat fields retained for partna-pages + back-compat. The nested
            // `latest` is the canonical shape (same as a highlight item) and
            // is what the dashboard now reads to render the "Most recent" tile.
            'name' => $latest['name'],
            'thumbnail' => $latest['thumbnail'],
            'description' => $latest['description'],
            'link' => $latest['link'],
            'latest' => $latest,
            'highlights' => $this->keptHighlights(self::PODCAST_KEY, $input),
        ];
        $this->put(self::PODCAST_KEY, $selection);

        return $this->success($selection);
    }

    // GET /api/platforms/apple/podcast/selection
    public function podcastSelection(): JsonResponse
    {
        return $this->success(['selection' => Cache::get(self::PODCAST_KEY)]);
    }

    // GET /api/platforms/apple/podcast/recent — last 15 episodes for the picker.
    public function podcastRecent(): JsonResponse
    {
        $input = data_get(Cache::get(self::PODCAST_KEY), 'input');
        if (! $input) {
            return $this->error('Connect an Apple Podcast first.', 404);
        }
        $episodes = $this->apple->fetchEpisodes($input);
        if ($episodes === null) {
            return $this->error('Could not load recent episodes.', 502);
        }

        return $this->success(['episodes' => $episodes]);
    }

    // POST /api/platforms/apple/podcast/highlights — snapshot up to 5 chosen episodes.
    public function podcastHighlights(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'episodeIds' => ['present', 'array', 'max:'.self::MAX_HIGHLIGHTS],
            'episodeIds.*' => ['string', 'max:30'],
        ]);
        $selection = Cache::get(self::PODCAST_KEY);
        if (! $selection) {
            return $this->error('Connect an Apple Podcast first.', 404);
        }
        $episodes = $this->apple->fetchEpisodes(data_get($selection, 'input'));
        if ($episodes === null) {
            return $this->error('Could not load recent episodes.', 502);
        }

        // Refresh the "Most recent" tile too (see musicHighlights) — a newer
        // episode published since connect would otherwise leave `latest` stale.
        if (isset($episodes[0])) {
            $latest = $episodes[0];
            $selection['latest'] = $latest;
            $selection['name'] = $latest['name'];
            $selection['thumbnail'] = $latest['thumbnail'];
            $selection['description'] = $latest['description'];
            $selection['link'] = $latest['link'];
        }

        $selection['highlights'] = $this->snapshot($episodes, 'trackId', $validated['episodeIds']);
        $this->put(self::PODCAST_KEY, $selection);

        return $this->success($selection);
    }

    // DELETE /api/platforms/apple — clear both. Retained for back-compat;
    // new dashboard surfaces target the per-platform routes below.
    public function forget(): JsonResponse
    {
        Cache::forget(self::MUSIC_KEY);
        Cache::forget(self::PODCAST_KEY);

        return $this->success(['music' => null, 'podcast' => null]);
    }

    // DELETE /api/platforms/apple/music — clear just Music. Lets the dashboard
    // disconnect one platform without touching the other.
    public function forgetMusic(): JsonResponse
    {
        Cache::forget(self::MUSIC_KEY);

        return $this->success(['music' => null]);
    }

    // DELETE /api/platforms/apple/podcast — clear just Podcasts.
    public function forgetPodcast(): JsonResponse
    {
        Cache::forget(self::PODCAST_KEY);

        return $this->success(['podcast' => null]);
    }

    // ── internals ────────────────────────────────────────────────

    private function put(string $key, array $data): void
    {
        Cache::put($key, $data, now()->addDays(self::CACHE_TTL_DAYS));
    }

    // Preserve existing highlights only when reconnecting the SAME input.
    private function keptHighlights(string $key, string $input): array
    {
        $existing = Cache::get($key);

        return data_get($existing, 'input') === $input ? data_get($existing, 'highlights', []) : [];
    }

    // Snapshot the chosen items (by $idField) in the order the user posted them, capped.
    private function snapshot(array $items, string $idField, array $ids): array
    {
        $byId = collect($items)->keyBy($idField);

        return collect($ids)
            ->map(fn (string $id) => $byId->get($id))
            ->filter()
            ->take(self::MAX_HIGHLIGHTS)
            ->values()
            ->all();
    }
}
