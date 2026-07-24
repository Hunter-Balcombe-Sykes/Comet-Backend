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

// Test-mode endpoints for Skool. Connect by community URL — the community's
// public about page carries og: tags (name, avatar, description) even for
// private communities, so the sitepage can show a rich community card with
// no auth. Scraping lives in App\Services\Platforms\SkoolScraper.
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
        $row = $this->connectionFor($this->currentUser($request));

        // CA-W4 reconciliation: a pending (or otherwise non-'ok') row's payload
        // is {url} only — no name/image/description — which SkoolConnectionResource
        // would render as {url, name: null, image: null, description: null}.
        // That shape was IMPOSSIBLE before this unit: SkoolScraper::fetchCommunity()
        // never returns without a real og:title, so a stored row's `name` was
        // always non-null. Treating anything short of 'ok' as "nothing to show
        // yet" preserves that invariant and avoids rendering a half-formed card
        // indistinguishable from "a community with no metadata" — the dashboard
        // already has /connect/status for the in-flight state. Byte-identical
        // with the flag off: writeConnection() only ever writes 'pending' when
        // the (unreachable) deferred branch runs, so every row this can see
        // today is already 'ok'.
        if ($row === null || $row->last_refresh_status !== 'ok') {
            return $this->success(['selection' => null]);
        }

        return $this->success(['selection' => (new SkoolConnectionResource($row->payload))->resolve()]);
    }

    // DELETE /api/platforms/skool
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
    }
}
