<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\DefersBespokeConnect;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Resources\Platforms\InstagramConnectionResource;
use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Routing\SuggestionApplier;
use App\Routing\SyncFindingsBridge;
use App\Services\Cache\ApifyBudget;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\InstagramAutoSync;
use App\Services\Platforms\Payloads\InstagramPayload;
use App\Services\Platforms\PlatformInput;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function __construct(
        private readonly SyncFindingsBridge $findingsBridge,
        private readonly SuggestionApplier $applier,
    ) {}

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
        $input = trim($validated['username']);

        // A bare @handle/handle is taken as-is BEFORE any urlish() rewriting:
        // IG allows dots, so a handle like "maha.restaurant" is TLD-shaped and
        // urlish() would rewrite it to "https://maha.restaurant" → bogus 422.
        // Excluding "instagram.com" keeps a pasted bare host on the URL path.
        $bare = ltrim($input, '@');
        if (! str_contains(strtolower($bare), 'instagram.com') && preg_match('/^[A-Za-z0-9._]{1,80}$/', $bare)) {
            $username = $bare;
        } else {
            // Accept a pasted profile URL (scheme optional) as well as @handle/handle.
            $raw = PlatformInput::urlish($input);
            $username = preg_match('~instagram\.com/([A-Za-z0-9._]+)~i', $raw, $m)
                ? $m[1]
                : ltrim($raw, '@');
        }
        if (! preg_match('/^[A-Za-z0-9._]{1,80}$/', $username)) {
            return $this->error("That doesn't look like a valid Instagram username.", 422);
        }
        if (! config('services.apify.token')) {
            return $this->error('Apify token not configured on the server.', 500);
        }

        return $username;
    }
}
