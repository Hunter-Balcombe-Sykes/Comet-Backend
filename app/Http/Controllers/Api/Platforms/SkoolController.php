<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\DefersBespokeConnect;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\PlatformConnectRequest;
use App\Http\Resources\Platforms\SkoolConnectionResource;
use App\Models\Core\User\User;
use App\Services\Http\FetchBudget;
use App\Services\Platforms\SkoolScraper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Endpoints for Skool (fully authenticated — 'user.api' middleware applied by the
// registry-driven route loop, routes/api/platforms.php). Connect by community URL —
// the community's public about page carries og: tags (name, avatar, description)
// even for private communities, so the sitepage can show a rich community card
// with no third-party auth needed. Scraping lives in App\Services\Platforms\SkoolScraper.
class SkoolController extends ApiController
{
    use DefersBespokeConnect;
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    public function __construct(private readonly SkoolScraper $scraper, private readonly FetchBudget $budget) {}

    protected function platform(): string
    {
        return 'skool';
    }

    // POST /api/platforms/skool/connect
    public function connect(PlatformConnectRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $validated = $request->validated();

        // Local validation (URL regex + reserved-slug blocklist, both inside
        // normalizeUrl()) stays synchronous and inline either way — only the
        // vendor fetch below is what CA-W4 defers.
        $canonical = $this->scraper->normalizeUrl($validated['url']);
        if (! $canonical) {
            return $this->error('Enter your Skool community URL (skool.com/your-community).', 422);
        }

        // CA-W4: config('partna.connect.deferred') names 'skool' — skip the
        // synchronous scrape entirely and let ConnectFetchJob fill the row.
        // Checked after local validation, never before any vendor call.
        if ($this->shouldDeferConnect('skool')) {
            return $this->connectDeferred($user, $canonical);
        }

        $seconds = (float) config('partna.http_fetch.connect_budget_seconds', 20);
        $community = $this->budget->open($seconds, fn () => $this->scraper->fetchCommunity($canonical));
        if ($community === null) {
            return $this->error('Could not read that Skool community — check the URL.', 404);
        }

        $this->writeConnection($user, $community);

        return $this->success((new SkoolConnectionResource($community))->resolve());
    }

    /**
     * CA-W4 deferred-connect write path: writes a pending row carrying only
     * {url} — SkoolFetch reads exactly that key — then dispatches
     * ConnectFetchJob. Mirrors AppleController::connectDeferredFor's lock
     * discipline: this write was deliberately UNLOCKED on the synchronous path
     * (PWL-16 — no sibling writer), but ConnectFetchJob is now one, so it goes
     * through the same per-user platform lock as the job's own completion
     * write. $row is captured by reference because withConnectionLock()'s
     * return type is JsonResponse only; the closure's own return value is a
     * throwaway success once $row is set, or the lock-timeout 423 when it isn't.
     * deferredConnectResponse() (DefersBespokeConnect) dispatches
     * ConnectFetchJob AFTER this lock has released — the job blocks on the
     * SAME lock key, so dispatching from inside would self-deadlock under a
     * sync queue connection.
     */
    private function connectDeferred(User $user, string $canonical): JsonResponse
    {
        $row = null;

        $lockResponse = $this->withConnectionLock($user, function () use ($user, $canonical, &$row): JsonResponse {
            $row = $this->writeConnection($user, ['url' => $canonical], pending: true);

            return $this->success([]);
        });

        if ($row === null) {
            // withConnectionLock's own 423 lock-timeout response.
            return $lockResponse;
        }

        return $this->deferredConnectResponse($row, ['url' => $canonical], '/api/platforms/skool/connect/status');
    }

    // GET /api/platforms/skool/connect/status — poll target for the 202 above
    // (CA-W4). Single-selection (no ?account=) — bespokeConnectStatus reads
    // the platform's one row via connectionFor(), same as /selection below.
    public function connectStatus(Request $request): JsonResponse
    {
        return $this->bespokeConnectStatus(
            $this->currentUser($request),
            null,
            fn (array $payload) => (new SkoolConnectionResource($payload))->resolve(),
        );
    }

    // GET /api/platforms/skool/selection
    public function selection(Request $request): JsonResponse
    {
        $connection = $this->connectionFor($this->currentUser($request));
        $payload = $connection === null ? [] : $connection->payload;

        // R4: withheld on payload RENDERABILITY, never on last_refresh_status.
        // The only unrenderable state is a payload with no community name — the
        // {url}-only row a first deferred connect writes, which
        // SkoolConnectionResource would emit as {url, name: null, image: null,
        // description: null}. SkoolScraper::fetchCommunity() cannot return
        // without a real og:title, so "has a name" and "was fetched" are the
        // same fact, and NULL stays legal (no NOT NULL/DEFAULT on the column).
        //
        // Gating on status instead was a trap with two live edges. A RECONNECT's
        // pending/failed row keeps the previous name via upsertConnection()'s
        // payload merge — kept expressly so the card does NOT blank mid-fetch —
        // so a status gate blanked a good card on any transient scrape failure,
        // permanently. And the public wire never reads this column at all
        // (PublicIntegrationController selects on is_active only), so the owner
        // lost a card the sitepage was still serving. /connect/status remains
        // the in-flight and failure channel, as on every other platform.
        $name = $payload['name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            return $this->success(['selection' => null]);
        }

        return $this->success(['selection' => (new SkoolConnectionResource($payload))->resolve()]);
    }

    // DELETE /api/platforms/skool
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
    }
}
