<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\PlatformConnectRequest;
use App\Http\Requests\Platforms\SaveAppleMusicHighlightsRequest;
use App\Http\Requests\Platforms\SaveApplePodcastHighlightsRequest;
use App\Http\Resources\Platforms\AppleMusicConnectionResource;
use App\Http\Resources\Platforms\ApplePodcastConnectionResource;
use App\Models\Core\User\User;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\Concerns\RefreshesLatestTile;
use App\Services\Platforms\Payloads\FeedPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    use ManagesIntegrationConnection;
    use RefreshesLatestTile;
    use ResolveCurrentUser;

    // Apple Music + Podcasts are independent platform values (own CHECK enum
    // entries), so this controller stores two per-user rows rather than the
    // single-key trait. resource_id mirrors the platform value (one per user).
    private const MUSIC = 'apple-music';

    private const PODCAST = 'apple-podcast';

    private const MAX_HIGHLIGHTS = 5;

    // Per-request mutable platform selector. Every generic op sets this property
    // as its first line before any trait call. Safe because Laravel resolves a
    // fresh controller instance per request — no cross-request state bleed.
    private string $activePlatform = self::MUSIC;

    public function __construct(private readonly AppleSearch $apple) {}

    // The trait's abstract platform() contract, satisfied dynamically.
    // Apple uses one controller for two platforms, so $activePlatform is set
    // at the top of each action before any trait method is called.
    protected function platform(): string
    {
        return $this->activePlatform;
    }

    // ── Music ────────────────────────────────────────────────────

    // POST /api/platforms/apple/music/connect
    public function connectMusic(PlatformConnectRequest $request): JsonResponse
    {
        return $this->connectFor($request, $this->musicConfig(), $request->validated());
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

    // GET /api/platforms/apple/music/accounts — every connected artist.
    public function musicAccounts(Request $request): JsonResponse
    {
        return $this->accountsFor($request, self::MUSIC);
    }

    // DELETE /api/platforms/apple/music/accounts/{id} — remove one artist.
    public function removeMusicAccount(Request $request, string $id): JsonResponse
    {
        return $this->removeAccountFor($request, self::MUSIC, $id);
    }

    // POST /api/platforms/apple/music/highlights — snapshot up to 5 chosen albums.
    public function musicHighlights(SaveAppleMusicHighlightsRequest $request): JsonResponse
    {
        return $this->highlightsFor($request, $this->musicConfig(), $request->validated());
    }

    // ── Podcast ───────────────────────────────────────────────────

    // POST /api/platforms/apple/podcast/connect
    public function connectPodcast(PlatformConnectRequest $request): JsonResponse
    {
        return $this->connectFor($request, $this->podcastConfig(), $request->validated());
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

    // GET /api/platforms/apple/podcast/accounts — every connected show.
    public function podcastAccounts(Request $request): JsonResponse
    {
        return $this->accountsFor($request, self::PODCAST);
    }

    // DELETE /api/platforms/apple/podcast/accounts/{id} — remove one show.
    public function removePodcastAccount(Request $request, string $id): JsonResponse
    {
        return $this->removeAccountFor($request, self::PODCAST, $id);
    }

    // POST /api/platforms/apple/podcast/highlights — snapshot up to 5 chosen episodes.
    public function podcastHighlights(SaveApplePodcastHighlightsRequest $request): JsonResponse
    {
        return $this->highlightsFor($request, $this->podcastConfig(), $request->validated());
    }

    // DELETE /api/platforms/apple — clear both. Retained for back-compat;
    // new dashboard surfaces target the per-platform routes below.
    public function forget(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        // Gate runs per-platform so each delete ability fires independently.
        // PWL-3: music and podcast are separate rows under separate lock keys
        // (the key is derived from platform()), so one withConnectionLock call
        // can't cover both — each iteration acquires its OWN platform's lock.
        // A 423 on either surfaces immediately rather than silently skipping it.
        foreach ([self::MUSIC, self::PODCAST] as $platform) {
            $this->activePlatform = $platform;
            $response = $this->withConnectionLock($user, function () use ($user): JsonResponse {
                $this->forgetAllConnections($user);

                return $this->success([]);
            });
            if ($response->getStatusCode() === 423) {
                return $response;
            }
        }

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
            // Music exposes releaseDate; podcast exposes description + releaseDate.
            'flatFields' => ['name', 'thumbnail', 'releaseDate', 'link'],
            // #76: capture the artist's primary genre (keyless iTunes) into the
            // stored payload so IdentityEvidence::musicGenre() → MusicGenreFactor
            // has a source. Music-only — apple-podcast (category Content) is never
            // read by the factor. Absent (null) resolver = no genre capture.
            'genreResolver' => fn (string $input): ?string => $this->apple->fetchGenre($input),
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
            'flatFields' => ['name', 'thumbnail', 'description', 'releaseDate', 'link'],
            'recentKey' => 'episodes',
            'notFound' => 'Could not find that Apple Podcast or an episode.',
            'connectFirst' => 'Connect an Apple Podcast first.',
            'loadError' => 'Could not load recent episodes.',
        ];
    }

    // ── Generic operations ────────────────────────────────────────

    // $validated is the pre-validated input (from the typed Form Request on the
    // public action). The helper no longer calls $request->validate().
    private function connectFor(Request $request, array $cfg, array $validated): JsonResponse
    {
        $this->activePlatform = $cfg['platform'];
        $user = $this->currentUser($request);
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

        // #76: enrich music selections with the artist's genre (best-effort — a
        // resolver miss or throw leaves the selection untouched; the resource
        // whitelists its keys so `genre` never leaks to the wire).
        $selection = $this->withGenre($selection, $cfg, $input);

        // PWL-3: this write races highlightsFor() (already locked) and
        // ScheduledRefresh (locks on the same platform+user key). The fetch +
        // genre resolver above are network calls to AppleSearch and stay
        // OUTSIDE the lock; only the read→mutate→write against the stored
        // row is wrapped.
        return $this->withConnectionLock($user, function () use ($user, $cfg, $input, $selection): JsonResponse {
            $row = $this->writeAccountConnection($user, $input, $selection);
            if ($row === null) {
                return $this->error('You can connect up to '.$this->maxAccounts().' accounts.', 422);
            }

            return $this->success(['id' => $row->resource_id, ...$this->wrapAppleSelection($cfg['platform'], $selection)]);
        });
    }

    private function selectionFor(Request $request, string $platform): JsonResponse
    {
        $this->activePlatform = $platform;
        $payload = $this->accountRows($this->currentUser($request))->first()?->payload;

        return $this->success(['selection' => $payload ? $this->wrapAppleSelection($platform, $payload) : null]);
    }

    private function accountsFor(Request $request, string $platform): JsonResponse
    {
        $this->activePlatform = $platform;

        return $this->success(['accounts' => $this->accountsListData(
            $this->currentUser($request),
            fn (array $payload) => $this->wrapAppleSelection($platform, $payload),
        )]);
    }

    private function removeAccountFor(Request $request, string $platform, string $id): JsonResponse
    {
        $this->activePlatform = $platform;
        $user = $this->currentUser($request);

        // PWL-3: races connectFor()/highlightsFor() (both locked) + ScheduledRefresh.
        return $this->withConnectionLock($user, function () use ($user, $platform, $id): JsonResponse {
            if (! $this->accountRows($user)->firstWhere('resource_id', $id)) {
                return $this->error('Account not found.', 404);
            }
            $this->forgetConnection($user, $id);

            return $this->success(['accounts' => $this->accountsListData(
                $user,
                fn (array $payload) => $this->wrapAppleSelection($platform, $payload),
            )]);
        });
    }

    private function recentFor(Request $request, array $cfg): JsonResponse
    {
        $this->activePlatform = $cfg['platform'];
        $row = $this->requestedAccountRow($this->currentUser($request), $request->query('account'));
        $input = FeedPayload::fromArray($row?->payload ?? [])->input;
        if (! $input) {
            return $this->error($cfg['connectFirst'], 404);
        }
        $items = ($cfg['fetch'])($input);
        if ($items === null) {
            return $this->error($cfg['loadError'], 422);
        }

        return $this->success([$cfg['recentKey'] => $items]);
    }

    // $validated is the pre-validated input (from the typed Form Request on the
    // public action). The helper no longer calls $request->validate().
    //
    // PWL-D1: mirrors GenericPlatformController::highlights() — the network
    // call (AppleSearch fetch, via $cfg['fetch']) runs OUTSIDE the lock, keyed
    // off an identity read that also happens outside it. The lock then wraps
    // only the authoritative RE-READ of the account row + the write: writing
    // off the outside-lock $row would reintroduce the lost update the lock
    // exists to prevent (a concurrent save or ScheduledRefresh landing in the
    // gap between that read and the lock being acquired would be silently
    // clobbered). $items themselves are NOT re-fetched inside the lock — only
    // the row they're applied to is re-read fresh.
    private function highlightsFor(Request $request, array $cfg, array $validated): JsonResponse
    {
        $this->activePlatform = $cfg['platform'];
        $user = $this->currentUser($request);
        $accountId = $request->query('account');

        $row = $this->requestedAccountRow($user, $accountId);
        $selection = $row?->payload;
        if (! $row || ! $selection) {
            return $this->error($cfg['connectFirst'], 404);
        }
        $items = ($cfg['fetch'])(data_get($selection, 'input'));
        if ($items === null) {
            return $this->error($cfg['loadError'], 422);
        }

        return $this->withConnectionLock($user, function () use ($user, $cfg, $validated, $accountId, $items): JsonResponse {
            $fresh = $this->requestedAccountRow($user, $accountId);
            $selection = $fresh?->payload;
            if (! $fresh || ! $selection) {
                return $this->error($cfg['connectFirst'], 404);
            }

            // Refresh the "Most recent" tile too — a release/episode published since
            // connect would otherwise leave `latest` (and the flat back-compat fields)
            // stale while only highlights updated.
            if (isset($items[0])) {
                $selection = $this->refreshLatestTile($selection, $items[0], $cfg['flatFields']);
            }

            $selection['highlights'] = $this->snapshot($items, $cfg['idField'], $validated[$cfg['idsField']]);
            $this->writeConnection($user, $selection, $fresh->resource_id);

            return $this->success(['id' => $fresh->resource_id, ...$this->wrapAppleSelection($cfg['platform'], $selection)]);
        });
    }

    private function forgetFor(Request $request, string $platform, string $responseKey): JsonResponse
    {
        $this->activePlatform = $platform;
        $user = $this->currentUser($request);

        // PWL-3: races connectFor()/highlightsFor()/removeAccountFor() (all
        // locked) + ScheduledRefresh, all on this same platform+user key.
        return $this->withConnectionLock($user, function () use ($user, $responseKey): JsonResponse {
            $this->forgetAllConnections($user);

            return $this->success([$responseKey => null]);
        });
    }

    // ── internals ────────────────────────────────────────────────

    /**
     * Attach a `genre` to the selection when the platform config carries a genre
     * resolver (music only) and it resolves one. Best-effort: a null result or a
     * throw leaves the selection unchanged so genre capture can NEVER fail a
     * connect (#76). The stored key feeds MusicGenreFactor; the Apple resource
     * whitelists its output keys, so `genre` stays out of the wire payload.
     *
     * @param  array<string, mixed>  $selection
     * @param  array<string, mixed>  $cfg
     * @return array<string, mixed>
     */
    private function withGenre(array $selection, array $cfg, string $input): array
    {
        $resolver = $cfg['genreResolver'] ?? null;
        if (! $resolver instanceof \Closure) {
            return $selection;
        }

        try {
            $genre = $resolver($input);
            if (is_string($genre) && trim($genre) !== '') {
                $selection['genre'] = $genre;
            }
        } catch (\Throwable $e) {
            report($e); // visible but non-fatal — connect still succeeds without genre
        }

        return $selection;
    }

    // Preserve existing highlights only when re-adding an already-connected input.
    private function keptHighlights(User $user, string $platform, string $input): array
    {
        // Temporarily set activePlatform for the read; reset is implicit since
        // connectFor already set it before calling this.
        $this->activePlatform = $platform;

        return FeedPayload::fromArray($this->matchAccountByCanonical($user, $input)?->payload ?? [])->highlights ?? [];
    }

    /** Resolve the selection array through the platform-appropriate tile Resource. */
    private function wrapAppleSelection(string $platform, array $selection): array
    {
        $resource = match ($platform) {
            self::PODCAST => new ApplePodcastConnectionResource($selection),
            self::MUSIC => new AppleMusicConnectionResource($selection),
            default => throw new \UnhandledMatchError("Unknown platform: $platform"),
        };

        return $resource->resolve();
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
