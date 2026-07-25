<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\DefersBespokeConnect;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Resources\Platforms\InstagramConnectionResource;
use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Rules\PlatformInRegistry;
use App\Services\Cache\ApifyBudget;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\InstagramAutoSync;
use App\Services\Platforms\Payloads\InstagramPayload;
use App\Services\Platforms\PlatformInput;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

// Instagram integration endpoints. A single automatic connect: connect() queues a
// background job (InstagramConnectJob) that scrapes the profile and mirrors the
// SINGLE latest post — a photo, or a reel (cover + mp4) — to R2, responding 202
// immediately; connectStatus() polls until it's ready. Scraping lives in
// InstagramScraper; all mirroring in the job.
//
// BE2: synced()/applySync() are the bio-link auto-sync popup contract — mirror
// GoogleBusinessController::synced()/applySync() semantics exactly (live status
// re-derivation, conflict apply = remove existing + write found link), reading
// the findings InstagramConnectJob persisted via InstagramAutoSync.
class InstagramController extends ApiController
{
    use DefersBespokeConnect;
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    protected function platform(): string
    {
        return Platform::Instagram->value;
    }

    // POST /api/platforms/instagram/connect — queue a scrape + mirror job and
    // return 202 immediately. The cooldown guard runs HERE (not in the job) so
    // rapid re-connects are throttled before anything is queued.
    public function connect(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $username = $this->validateUsername($request);
        if ($username instanceof JsonResponse) {
            return $username;
        }

        if ($budgetError = $this->guardApifyBudget()) {
            return $budgetError;
        }

        // PWL-7: the placeholder write (and the authorize check that decides
        // create vs. update) is the only part that needs to serialize against
        // other writers of this row — the raw lock primitive is used (not
        // withConnectionLock) because the created $connection is needed below
        // for the job dispatch, which — like validateUsername/guardApifyBudget
        // above — MUST stay OUTSIDE the lock: under QUEUE_CONNECTION=sync,
        // dispatch() runs InstagramConnectJob's ~110s Apify scrape inline, and
        // holding a 10s lock across that would make every concurrent request
        // (including unrelated reads that take the same lock) queue behind it.
        try {
            $connection = Cache::lock(CacheKeyGenerator::platformConnectionLock($this->platform(), $user->id), 10)->block(5, function () use ($user) {
                // Gate the placeholder write: determine create vs. update so the correct
                // policy ability fires. This is a direct write (not via writeConnection)
                // because the placeholder shape differs from a normal selection row.
                $existing = $this->connectionFor($user);
                if ($existing) {
                    $this->authorizeForUser($user, 'update', $existing);
                } else {
                    $skeleton = new IntegrationConnection([
                        'user_id' => $user->id,
                        'platform' => $this->platform(),
                        'resource_id' => $this->defaultResourceId(),
                    ]);
                    $this->authorizeForUser($user, 'create', $skeleton);
                }

                // Write a pending placeholder so the status endpoint can respond before
                // the job runs. updateOrCreate so a re-connect replaces any prior row.
                return IntegrationConnection::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'platform' => $this->platform(),
                        'resource_id' => $this->defaultResourceId(),
                    ],
                    [
                        // Empty (not null): platform_connections.payload is NOT NULL, so a
                        // null placeholder violates the constraint and 500s the connect. The
                        // job overwrites this with the real scrape once it completes.
                        'payload' => [],
                        'is_active' => false,
                        'last_refreshed_at' => null,
                        'last_refresh_status' => 'pending',
                        'last_refresh_error' => null,
                        'consecutive_failures' => 0,
                    ],
                );
            });
        } catch (LockTimeoutException) {
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        // The one genuine "user added Instagram" dispatch, so the only one that
        // asks for the bell (GoogleBusinessAutoSync + RefreshController stay silent).
        InstagramConnectJob::dispatch($user->id, $username, $connection->id, notifyOnConnect: true);

        return $this->success([
            'status' => 'pending',
            'statusUrl' => url('/api/platforms/instagram/connect/status'),
        ], 202);
    }

    // GET /api/platforms/instagram/connect/status — poll endpoint for the connect
    // flow. Returns pending / ready (with payload) / failed, through the SAME
    // shared poll the six deferred-connect platforms use, so Instagram now has
    // the stale-pending escape hatch it was missing: a worker that dies between
    // dispatch and a terminal write left this row polling 'pending' forever (R7).
    //
    // Only bespokeConnectStatus() is used from that trait. deferredConnectResponse()
    // must never be called here — it hardcodes ConnectFetchJob, and Instagram's
    // connect is InstagramConnectJob — and shouldDeferConnect() has no Instagram
    // slug to read: Instagram is not in config('partna.connect.deferred').
    //
    // $notFoundMessage keeps Instagram's own published 404 sentence
    // (docs/frontend-contracts/instagram-connect-async.md, live since 2026-06-09)
    // rather than adopting the trait's newer default — aligning that string is a
    // separate frontend-visible decision, deliberately not folded into this fix.
    public function connectStatus(Request $request): JsonResponse
    {
        return $this->bespokeConnectStatus(
            $this->currentUser($request),
            null,
            fn (array $payload) => (new InstagramConnectionResource(InstagramPayload::fromArray($payload)->toArray()))->resolve(),
            notFoundMessage: 'No Instagram connection found.',
        );
    }

    // GET /api/platforms/instagram/selection — the authenticated user's saved selection.
    public function selection(Request $request): JsonResponse
    {
        $payload = $this->readConnection($this->currentUser($request));

        return $this->success(['selection' => $payload
            ? (new InstagramConnectionResource(InstagramPayload::fromArray($payload)->toArray()))->resolve()
            : null]);
    }

    // DELETE /api/platforms/instagram — clear the authenticated user's connection.
    public function forget(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        return $this->withConnectionLock($user, function () use ($user) {
            $this->forgetConnection($user);

            return $this->success(['selection' => null]);
        });
    }

    // GET /api/platforms/instagram/synced
    // The platforms THIS Instagram connect's bio harvest found — read from the
    // connection's recorded syncFindings (scoped to the latest scrape), each
    // re-shaped with a live status (synced / syncing / conflict), plus the bio
    // links that didn't classify into an auto-synced platform ("add as custom
    // link" leftovers). An old connection with no bio-sync data yet (or none at
    // all) simply returns both as empty — never breaks. Mirrors
    // GoogleBusinessController::synced() exactly.
    public function synced(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $ig = $user->integrationConnections()->where('platform', Platform::Instagram->value)->first();
        $payload = InstagramPayload::fromArray($ig?->payload);

        // Pre-load all connections keyed by "platform|resource_id" so shapeFinding
        // can look up each seeded row in O(1) instead of issuing a DB query per finding.
        $connections = $user->integrationConnections()
            ->get()
            ->keyBy(fn ($r) => $r->platform.'|'.$r->resource_id);

        $synced = collect($payload->syncFindings)
            ->map(fn ($f) => is_array($f) ? $this->shapeFinding($f, $connections) : null)
            ->filter()
            ->values()
            ->all();

        return $this->success(['synced' => $synced, 'unmatched' => $payload->unmatched]);
    }

    // POST /api/platforms/instagram/synced/apply
    // "Change to" — swap the user's existing connection for the one the bio
    // harvest found (a conflict finding): remove the existing, install the
    // found link, and flip the finding to seeded so it shows as synced.
    // Mirrors GoogleBusinessController::applySync() exactly.
    public function applySync(Request $request, InstagramAutoSync $autoSync): JsonResponse
    {
        $user = $this->currentUser($request);
        $platform = $request->validate(['platform' => ['required', 'string', 'max:40', new PlatformInRegistry]])['platform'];

        $ig = $user->integrationConnections()->where('platform', Platform::Instagram->value)->first();
        $igp = InstagramPayload::fromArray($ig?->payload);
        $payload = $igp->toArray();
        $findings = $igp->syncFindings;

        $idx = null;
        foreach ($findings as $i => $f) {
            if (is_array($f) && ($f['platform'] ?? null) === $platform && ($f['outcome'] ?? null) === 'conflict') {
                $idx = $i;
                break;
            }
        }
        if ($idx === null || $ig === null) {
            return $this->error('Nothing to change for that platform.', 404);
        }

        // PWL-7: applyFinding() writes OTHER platforms' rows (remove + write the
        // found connection) and may dispatch a job — it MUST stay outside the
        // lock, same reasoning as connect()'s job dispatch. Only the IG row's
        // own read-modify-write (marking the finding 'seeded' below) is the
        // authoritative, contended span, so that alone is locked — re-reading
        // $ig inside the closure for the authoritative state.
        //
        // U1: applyFinding() now takes its own lock internally (bookingXorLock
        // / reservationsXorLock) for a booking/reservations-slot finding,
        // returning false on contention. Staying outside the platform lock
        // here is ALSO what keeps that lock-ordering acyclic (mirrors
        // GoogleBusinessController::applySync — see its comment) — this call
        // fully releases before the platform lock below is even requested.
        if (! $autoSync->applyFinding((string) $user->id, $findings[$idx])) {
            // Contended booking/reservations-slot lock — nothing was removed
            // or written. Must NOT fall through to the flip below: that would
            // mark the finding 'seeded' for a change that never happened.
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        try {
            Cache::lock(CacheKeyGenerator::platformConnectionLock($this->platform(), $user->id), 10)->block(5, function () use ($user, $platform) {
                $ig = $user->integrationConnections()->where('platform', Platform::Instagram->value)->first();
                if (! $ig) {
                    return;
                }
                $igp = InstagramPayload::fromArray($ig->payload);
                $payload = $igp->toArray();
                $findings = $igp->syncFindings;

                $idx = null;
                foreach ($findings as $i => $f) {
                    if (is_array($f) && ($f['platform'] ?? null) === $platform && ($f['outcome'] ?? null) === 'conflict') {
                        $idx = $i;
                        break;
                    }
                }
                if ($idx === null) {
                    return;
                }

                $findings[$idx]['outcome'] = 'seeded';
                $findings[$idx]['apply'] = null;
                $ig->forceFill(['payload' => [...$payload, 'syncFindings' => $findings]])->saveQuietly();
            });
        } catch (LockTimeoutException) {
            return $this->error('Another change is still saving — please retry in a moment.', 423);
        }

        return $this->synced($request);
    }

    /**
     * Shape one recorded finding for the popup, re-deriving live status. Returns
     * null when a seeded row was since removed (so it drops off the list).
     *
     * @param  Collection<string, IntegrationConnection>  $connections  pre-loaded keyed by "platform|resource_id"
     * @param  array<string,mixed>  $finding
     * @return array<string,mixed>|null
     */
    private function shapeFinding(array $finding, Collection $connections): ?array
    {
        $platform = (string) ($finding['platform'] ?? '');
        $category = (string) ($finding['category'] ?? 'other');
        $label = (string) ($finding['label'] ?? $platform);
        $foundUrl = is_string($finding['foundUrl'] ?? null) ? $finding['foundUrl'] : null;

        if (($finding['outcome'] ?? 'seeded') === 'conflict') {
            return [
                'platform' => $platform,
                'category' => $category,
                'label' => $label,
                'status' => 'conflict',
                'foundUrl' => $foundUrl,
                'removePath' => null,
            ];
        }

        // Seeded — drop if the user already removed it; else derive synced/syncing.
        $resourceId = (string) ($finding['resourceId'] ?? '');
        $row = $connections->get($platform.'|'.$resourceId);
        if ($row === null) {
            return null;
        }

        return [
            'platform' => $platform,
            'category' => $category,
            'label' => $label,
            'status' => $row->last_refresh_status === 'pending' ? 'syncing' : 'synced',
            'foundUrl' => $foundUrl,
            'removePath' => '/platforms/'.$platform,
        ];
    }

    // ── internals ────────────────────────────────────────────────

    // Pilot cost guard: 429 only when the GLOBAL daily Apify cap is hit — a hard
    // ceiling on paid scrapes across the whole platform, shared with the Google
    // Business auto-sync via the ApifyBudget cache service. There is
    // intentionally NO per-user cooldown: connecting (or re-connecting /
    // switching) must be friction-free, so the daily cap alone bounds cost.
    private function guardApifyBudget(): ?JsonResponse
    {
        if (! app(ApifyBudget::class)->tryClaim('instagram')) {
            return $this->error('Instagram is busy right now — please try again later.', 429);
        }

        return null;
    }

    // Validate + normalise the username, or return a 422/500 JsonResponse the
    // caller forwards. (Apify token must be configured for any scrape.)
    private function validateUsername(Request $request): JsonResponse|string
    {
        $validated = $request->validate(['username' => ['required', 'string', 'max:200']]);
        // Accept a pasted profile URL (scheme optional) as well as @handle/handle.
        $raw = PlatformInput::urlish(trim($validated['username']));
        $username = preg_match('~instagram\.com/([A-Za-z0-9._]+)~i', $raw, $m)
            ? $m[1]
            : ltrim($raw, '@');
        if (! preg_match('/^[A-Za-z0-9._]{1,80}$/', $username)) {
            return $this->error("That doesn't look like a valid Instagram username.", 422);
        }
        if (! config('services.apify.token')) {
            return $this->error('Apify token not configured on the server.', 500);
        }

        return $username;
    }
}
