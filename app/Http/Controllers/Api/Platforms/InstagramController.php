<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Resources\Platforms\InstagramConnectionResource;
use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Cache\InstagramApifyBudget;
use App\Services\Platforms\PlatformInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Instagram integration endpoints. A single automatic connect: connect() queues a
// background job (InstagramConnectJob) that scrapes the profile and mirrors the
// SINGLE latest post — a photo, or a reel (cover + mp4) — to R2, responding 202
// immediately; connectStatus() polls until it's ready. Scraping lives in
// InstagramScraper; all mirroring in the job.
class InstagramController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    protected function platform(): string
    {
        return 'instagram';
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
        $connection = IntegrationConnection::updateOrCreate(
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

        InstagramConnectJob::dispatch($user->id, $username, $connection->id);

        return $this->success([
            'status' => 'pending',
            'statusUrl' => url('/api/integrations/instagram/connect/status'),
        ], 202);
    }

    // GET /api/platforms/instagram/connect/status — poll endpoint for the connect
    // flow. Returns pending / ready (with payload) / failed. 404 when no connection
    // exists for the caller.
    public function connectStatus(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $connection = $this->connectionFor($user);

        if (! $connection) {
            return $this->error('No Instagram connection found.', 404);
        }

        $status = $connection->last_refresh_status;

        if ($status === 'ok') {
            return $this->success([
                'status' => 'ready',
                'connection' => $connection->payload
                    ? (new InstagramConnectionResource($connection->payload))->resolve()
                    : null,
            ]);
        }

        if ($status === 'pending') {
            return $this->success(['status' => 'pending']);
        }

        // 'unavailable', 'error', or any other terminal failure state.
        return $this->success([
            'status' => 'failed',
            'error' => $connection->last_refresh_error,
        ]);
    }

    // GET /api/platforms/instagram/selection — the authenticated user's saved selection.
    public function selection(Request $request): JsonResponse
    {
        $payload = $this->readConnection($this->currentUser($request));

        return $this->success(['selection' => $payload ? (new InstagramConnectionResource($payload))->resolve() : null]);
    }

    // DELETE /api/platforms/instagram — clear the authenticated user's connection.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
    }

    // ── internals ────────────────────────────────────────────────

    // Pilot cost guard: 429 only when the GLOBAL daily Apify cap is hit — a hard
    // ceiling on paid scrapes across the whole platform, shared with the Google
    // Business auto-sync via the InstagramApifyBudget cache service. There is
    // intentionally NO per-user cooldown: connecting (or re-connecting /
    // switching) must be friction-free, so the daily cap alone bounds cost.
    private function guardApifyBudget(): ?JsonResponse
    {
        if (! app(InstagramApifyBudget::class)->tryClaim()) {
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
