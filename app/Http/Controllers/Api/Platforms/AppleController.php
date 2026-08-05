<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\DefersBespokeConnect;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\PlatformConnectRequest;
use App\Http\Resources\Platforms\AppleMusicConnectionResource;
use App\Http\Resources\Platforms\ApplePodcastConnectionResource;
use App\Models\Core\User\User;
use App\Services\Http\FetchBudget;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\Concerns\RefreshesLatestTile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Endpoints for Apple Music + Apple Podcasts (fully authenticated — 'user.api'
// middleware on the route group, routes/api/platforms.php). One controller, two
// independent selections (music = latest album, podcast = latest
// episode), both backed by the unauthenticated iTunes Search API
// via App\Services\Platforms\AppleSearch — no third-party auth/key needed
// there. Two keys, so this controller keeps its own storage rather than the
// single-key trait.
// Music and Podcast flows share generic helpers (connectFor, forgetFor)
// driven by musicConfig()/podcastConfig() — a fix
// to one platform can't silently miss the other (the `latest`-key drift that
// motivated CONS-39 was exactly that failure mode).
// Spec: ~/Developer/platform link capabilites/apple-implementation.md
class AppleController extends ApiController
{
    use DefersBespokeConnect;
    use ManagesIntegrationConnection;
    use RefreshesLatestTile;
    use ResolveCurrentUser;

    // Apple Music + Podcasts are independent platform values (own CHECK enum
    // entries), so this controller stores two per-user rows rather than the
    // single-key trait. resource_id mirrors the platform value (one per user).
    private const MUSIC = 'apple-music';

    private const PODCAST = 'apple-podcast';

    // Per-request mutable platform selector. Every generic op sets this property
    // as its first line before any trait call. Safe because Laravel resolves a
    // fresh controller instance per request — no cross-request state bleed.
    private string $activePlatform = self::MUSIC;

    public function __construct(private readonly AppleSearch $apple, private readonly FetchBudget $budget) {}

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

    // GET /api/platforms/apple/music/connect/status?account={id} — poll target
    // for the 202 above (CA-W3). Multi-account, so ?account= selects the row.
    public function musicConnectStatus(Request $request): JsonResponse
    {
        $this->activePlatform = self::MUSIC;

        return $this->bespokeConnectStatus(
            $this->currentUser($request),
            $request->query('account'),
            fn (array $payload) => $this->wrapAppleSelection(self::MUSIC, $payload),
            perAccount: true,
        );
    }

    // ── Podcast ───────────────────────────────────────────────────

    // POST /api/platforms/apple/podcast/connect
    public function connectPodcast(PlatformConnectRequest $request): JsonResponse
    {
        return $this->connectFor($request, $this->podcastConfig(), $request->validated());
    }

    // GET /api/platforms/apple/podcast/connect/status?account={id} — poll
    // target for the 202 above (CA-W3). Multi-account, so ?account= selects the row.
    public function podcastConnectStatus(Request $request): JsonResponse
    {
        $this->activePlatform = self::PODCAST;

        return $this->bespokeConnectStatus(
            $this->currentUser($request),
            $request->query('account'),
            fn (array $payload) => $this->wrapAppleSelection(self::PODCAST, $payload),
            perAccount: true,
        );
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
     * (storage, locking, tile-refresh) is shared.
     *
     * @return array{platform:string, pathSegment:string, inputField:string, idsField:string, idField:string, fetch:callable, flatFields:list<string>, notFound:string, connectFirst:string}
     */
    private function musicConfig(): array
    {
        return [
            'platform' => self::MUSIC,
            'pathSegment' => 'music',
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
            'notFound' => 'Could not find that Apple Music artist or an album.',
            'connectFirst' => 'Connect an Apple Music artist first.',
        ];
    }

    private function podcastConfig(): array
    {
        return [
            'platform' => self::PODCAST,
            'pathSegment' => 'podcast',
            'inputField' => 'show',
            'idsField' => 'episodeIds',
            'idField' => 'trackId',
            'fetch' => fn (string $input) => $this->apple->fetchEpisodes($input),
            'flatFields' => ['name', 'thumbnail', 'description', 'releaseDate', 'link'],
            'notFound' => 'Could not find that Apple Podcast or an episode.',
            'connectFirst' => 'Connect an Apple Podcast first.',
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

        // CA-W3: config('partna.connect.deferred') names this slug — skip the
        // synchronous AppleSearch round-trip entirely and let ConnectFetchJob
        // fill the row. Checked before any vendor call, never inside it.
        if ($this->shouldDeferConnect($cfg['platform'])) {
            return $this->connectDeferredFor($user, $cfg, $input);
        }

        // Budget bounds the whole fetch region — the primary lookup AND the
        // best-effort genre resolver (#76, two sequential iTunes calls) — so a
        // slow/unresponsive vendor can't hold the request thread past
        // connect_budget_seconds. The empty-result branch is signalled out of
        // the closure (null) rather than returned directly, so the 404 stays
        // identical whether it came from an empty fetch or a budget exhaustion.
        $seconds = (float) config('partna.http_fetch.connect_budget_seconds', 20);
        $selection = $this->budget->open($seconds, function () use ($cfg, $input) {
            $items = ($cfg['fetch'])($input);
            if (empty($items)) {
                return null;
            }
            $latest = $items[0];
            $selection = [
                'input' => $input,
                // Flat fields for partna-pages + back-compat; nested `latest` is the
                // canonical shape the dashboard reads for the "Most recent" tile.
                ...$this->flatTileFields($latest, $cfg['flatFields']),
                'latest' => $latest,
            ];

            // #76: enrich music selections with the artist's genre (best-effort — a
            // resolver miss or throw leaves the selection untouched; the resource
            // whitelists its keys so `genre` never leaks to the wire).
            return $this->withGenre($selection, $cfg, $input);
        });

        if ($selection === null) {
            return $this->error($cfg['notFound'], 404);
        }

        // PWL-3: this write races ScheduledRefresh (locks on the same platform+user key). The fetch +
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

    /**
     * CA-W3 deferred-connect write path: writes a pending account row carrying
     * only {input} — AppleMusicFetch/ApplePodcastFetch read exactly that key —
     * then dispatches ConnectFetchJob. Mirrors GenericPlatformController::
     * connectDeferred()'s own lock discipline: $row is captured by reference
     * because withConnectionLock()'s return type is JsonResponse only; the
     * closure's own return value is a throwaway success once $row is set, or
     * the real error response (max-accounts 422 / lock-timeout 423) when it
     * isn't. deferredConnectResponse() (DefersBespokeConnect) dispatches
     * ConnectFetchJob AFTER this lock has released — the job blocks on the
     * SAME platform+user lock key, so dispatching from inside would
     * self-deadlock under a sync queue connection.
     */
    private function connectDeferredFor(User $user, array $cfg, string $input): JsonResponse
    {
        $row = null;

        $lockResponse = $this->withConnectionLock($user, function () use ($user, $input, &$row): JsonResponse {
            $row = $this->writeAccountConnection($user, $input, ['input' => $input], pending: true);
            if ($row === null) {
                return $this->error('You can connect up to '.$this->maxAccounts().' accounts.', 422);
            }

            return $this->success([]);
        });

        if ($row === null) {
            // Either the max-accounts 422 above, or withConnectionLock's own
            // 423 lock-timeout response — both correctly short-circuit here.
            return $lockResponse;
        }

        return $this->deferredConnectResponse(
            $row,
            ['input' => $input],
            "/api/platforms/apple/{$cfg['pathSegment']}/connect/status",
            perAccount: true,
        );
    }

    private function forgetFor(Request $request, string $platform, string $responseKey): JsonResponse
    {
        $this->activePlatform = $platform;
        $user = $this->currentUser($request);

        // PWL-3: races connectFor() (locked) + ScheduledRefresh, all on this
        // same platform+user key.
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
}
