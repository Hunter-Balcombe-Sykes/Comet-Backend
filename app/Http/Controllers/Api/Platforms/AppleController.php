<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\RefreshesLatestTile;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\AppleSearch;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

// Test-mode endpoints for Apple Music + Apple Podcasts. One controller, two
// independent selections (music = latest album + highlights, podcast = latest
// episode + highlights), both on the unauthenticated iTunes Search API via
// App\Services\Platforms\AppleSearch. No auth, no key. Two keys, so this
// controller keeps its own storage rather than the single-key trait.
// Music and Podcast flows share generic helpers (connectFor, recentFor,
// highlightsFor, forgetFor) driven by musicConfig()/podcastConfig() — a fix
// to one platform can't silently miss the other (the `latest`-key drift that
// motivated CONS-39 was exactly that failure mode).
// Spec: ~/Developer/platform link capabilites/apple-implementation.md
class AppleController extends ApiController
{
    use RefreshesLatestTile;
    use ResolveCurrentUser;

    // Apple Music + Podcasts are independent platform values (own CHECK enum
    // entries), so this controller stores two per-user rows rather than the
    // single-key trait. resource_id mirrors the platform value (one per user).
    private const MUSIC = 'apple-music';

    private const PODCAST = 'apple-podcast';

    private const MAX_HIGHLIGHTS = 5;

    public function __construct(private readonly AppleSearch $apple) {}

    // ── Music ────────────────────────────────────────────────────

    // POST /api/platforms/apple/music/connect
    public function connectMusic(Request $request): JsonResponse
    {
        return $this->connectFor($request, $this->musicConfig());
    }

    // GET /api/platforms/apple/music/selection
    public function musicSelection(Request $request): JsonResponse
    {
        return $this->selectionFor($request, self::MUSIC);
    }

    // GET /api/platforms/apple/music/recent — last 15 albums for the picker.
    public function musicRecent(Request $request): JsonResponse
    {
        return $this->recentFor($request, $this->musicConfig());
    }

    // POST /api/platforms/apple/music/highlights — snapshot up to 5 chosen albums.
    public function musicHighlights(Request $request): JsonResponse
    {
        return $this->highlightsFor($request, $this->musicConfig());
    }

    // ── Podcast ───────────────────────────────────────────────────

    // POST /api/platforms/apple/podcast/connect
    public function connectPodcast(Request $request): JsonResponse
    {
        return $this->connectFor($request, $this->podcastConfig());
    }

    // GET /api/platforms/apple/podcast/selection
    public function podcastSelection(Request $request): JsonResponse
    {
        return $this->selectionFor($request, self::PODCAST);
    }

    // GET /api/platforms/apple/podcast/recent — last 15 episodes for the picker.
    public function podcastRecent(Request $request): JsonResponse
    {
        return $this->recentFor($request, $this->podcastConfig());
    }

    // POST /api/platforms/apple/podcast/highlights — snapshot up to 5 chosen episodes.
    public function podcastHighlights(Request $request): JsonResponse
    {
        return $this->highlightsFor($request, $this->podcastConfig());
    }

    // DELETE /api/platforms/apple — clear both. Retained for back-compat;
    // new dashboard surfaces target the per-platform routes below.
    public function forget(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $this->forgetOne($user, self::MUSIC);
        $this->forgetOne($user, self::PODCAST);

        return $this->success(['music' => null, 'podcast' => null]);
    }

    // DELETE /api/platforms/apple/music — clear just Music. Lets the dashboard
    // disconnect one platform without touching the other.
    public function forgetMusic(Request $request): JsonResponse
    {
        return $this->forgetFor($request, self::MUSIC, 'music');
    }

    // DELETE /api/platforms/apple/podcast — clear just Podcasts.
    public function forgetPodcast(Request $request): JsonResponse
    {
        return $this->forgetFor($request, self::PODCAST, 'podcast');
    }

    // ── Platform configs ──────────────────────────────────────────

    /**
     * The handful of values that differ between Music and Podcast; everything else
     * (storage, locking, tile-refresh, snapshotting) is shared.
     *
     * @return array{platform:string, inputField:string, idsField:string, idField:string, fetch:callable, flatFields:list<string>, recentKey:string, notFound:string, connectFirst:string, loadError:string}
     */
    private function musicConfig(): array
    {
        return [
            'platform' => self::MUSIC,
            'inputField' => 'artist',
            'idsField' => 'albumIds',
            'idField' => 'collectionId',
            'fetch' => fn (string $input) => $this->apple->fetchAlbums($input),
            // Flat back-compat tile fields copied verbatim from the latest item.
            // Music exposes releaseDate; podcast exposes description.
            'flatFields' => ['name', 'thumbnail', 'releaseDate', 'link'],
            'recentKey' => 'albums',
            'notFound' => 'Could not find that Apple Music artist or an album.',
            'connectFirst' => 'Connect an Apple Music artist first.',
            'loadError' => 'Could not load recent albums.',
        ];
    }

    private function podcastConfig(): array
    {
        return [
            'platform' => self::PODCAST,
            'inputField' => 'show',
            'idsField' => 'episodeIds',
            'idField' => 'trackId',
            'fetch' => fn (string $input) => $this->apple->fetchEpisodes($input),
            'flatFields' => ['name', 'thumbnail', 'description', 'link'],
            'recentKey' => 'episodes',
            'notFound' => 'Could not find that Apple Podcast or an episode.',
            'connectFirst' => 'Connect an Apple Podcast first.',
            'loadError' => 'Could not load recent episodes.',
        ];
    }

    // ── Generic operations ────────────────────────────────────────

    private function connectFor(Request $request, array $cfg): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validate([$cfg['inputField'] => ['required', 'string', 'max:200']]);
        $input = trim($validated[$cfg['inputField']]);

        $items = ($cfg['fetch'])($input);
        if (empty($items)) {
            return $this->error($cfg['notFound'], 404);
        }
        $latest = $items[0];

        $selection = [
            'input' => $input,
            // Flat fields for partna-pages + back-compat; nested `latest` is the
            // canonical shape the dashboard reads for the "Most recent" tile.
            ...$this->flatTileFields($latest, $cfg['flatFields']),
            'latest' => $latest,
            'highlights' => $this->keptHighlights($user, $cfg['platform'], $input),
        ];
        $this->put($user, $cfg['platform'], $selection);

        return $this->success($selection);
    }

    private function selectionFor(Request $request, string $platform): JsonResponse
    {
        return $this->success(['selection' => $this->read($this->currentUser($request), $platform)]);
    }

    private function recentFor(Request $request, array $cfg): JsonResponse
    {
        $input = data_get($this->read($this->currentUser($request), $cfg['platform']), 'input');
        if (! $input) {
            return $this->error($cfg['connectFirst'], 404);
        }
        $items = ($cfg['fetch'])($input);
        if ($items === null) {
            return $this->error($cfg['loadError'], 502);
        }

        return $this->success([$cfg['recentKey'] => $items]);
    }

    private function highlightsFor(Request $request, array $cfg): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validate([
            $cfg['idsField'] => ['present', 'array', 'max:'.self::MAX_HIGHLIGHTS],
            $cfg['idsField'].'.*' => ['string', 'max:30'],
        ]);

        return $this->withLock($user, $cfg['platform'], function () use ($user, $cfg, $validated): JsonResponse {
            $selection = $this->read($user, $cfg['platform']);
            if (! $selection) {
                return $this->error($cfg['connectFirst'], 404);
            }
            $items = ($cfg['fetch'])(data_get($selection, 'input'));
            if ($items === null) {
                return $this->error($cfg['loadError'], 502);
            }

            // Refresh the "Most recent" tile too — a release/episode published since
            // connect would otherwise leave `latest` (and the flat back-compat fields)
            // stale while only highlights updated.
            if (isset($items[0])) {
                $selection = $this->refreshLatestTile($selection, $items[0], $cfg['flatFields']);
            }

            $selection['highlights'] = $this->snapshot($items, $cfg['idField'], $validated[$cfg['idsField']]);
            $this->put($user, $cfg['platform'], $selection);

            return $this->success($selection);
        });
    }

    private function forgetFor(Request $request, string $platform, string $responseKey): JsonResponse
    {
        $this->forgetOne($this->currentUser($request), $platform);

        return $this->success([$responseKey => null]);
    }

    // ── internals ────────────────────────────────────────────────

    /**
     * Per-user, per-platform Redis mutex around a read→mutate→write selection
     * cycle, mirroring ManagesIntegrationConnection::withConnectionLock. Apple keeps
     * its own storage (two platforms in one controller), so it locks locally; this
     * folds into the trait helper if AppleController later adopts the trait (CONS-10).
     * Returns the callback's JsonResponse, or 423 when another save holds the lock.
     */
    private function withLock(User $user, string $platform, callable $callback): JsonResponse
    {
        try {
            return Cache::lock("platforms:{$platform}:lock:{$user->id}", 10)->block(5, $callback);
        } catch (LockTimeoutException) {
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }
    }

    // Read one Apple platform's per-user selection payload (null when none).
    private function read(User $user, string $platform): ?array
    {
        return $user->integrationConnections()
            ->where('platform', $platform)
            ->where('resource_id', $platform)
            ->first()?->payload;
    }

    // Upsert one Apple platform's per-user selection. Goes through the model so
    // IntegrationConnectionObserver fires and purges the sitepage edge cache.
    private function put(User $user, string $platform, array $data): void
    {
        IntegrationConnection::updateOrCreate(
            ['user_id' => $user->id, 'platform' => $platform, 'resource_id' => $platform],
            [
                'payload' => $data,
                'is_active' => true,
                'last_refreshed_at' => now(),
                'last_refresh_status' => 'ok',
                'last_refresh_error' => null,
                'consecutive_failures' => 0,
            ],
        );
    }

    private function forgetOne(User $user, string $platform): void
    {
        $user->integrationConnections()
            ->where('platform', $platform)
            ->where('resource_id', $platform)
            ->first()?->delete();
    }

    // Preserve existing highlights only when reconnecting the SAME input.
    private function keptHighlights(User $user, string $platform, string $input): array
    {
        $existing = $this->read($user, $platform);

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
