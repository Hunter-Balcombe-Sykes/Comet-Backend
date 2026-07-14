<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Resources\Platforms\InstagramConnectionResource;
use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Rules\PlatformInRegistry;
use App\Services\Cache\ApifyBudget;
use App\Services\Platforms\InstagramAutoSync;
use App\Services\Platforms\Payloads\InstagramPayload;
use App\Services\Platforms\PlatformInput;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
            'statusUrl' => url('/api/platforms/instagram/connect/status'),
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
                    ? (new InstagramConnectionResource(InstagramPayload::fromArray($connection->payload)->toArray()))->resolve()
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

        return $this->success(['selection' => $payload
            ? (new InstagramConnectionResource(InstagramPayload::fromArray($payload)->toArray()))->resolve()
            : null]);
    }

    // DELETE /api/platforms/instagram — clear the authenticated user's connection.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
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

        $autoSync->applyFinding((string) $user->id, $findings[$idx]);

        $findings[$idx]['outcome'] = 'seeded';
        $findings[$idx]['apply'] = null;
        $ig->forceFill(['payload' => [...$payload, 'syncFindings' => $findings]])->saveQuietly();

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
