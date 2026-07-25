<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Jobs\Platforms\InstagramConnectJob;
use App\Jobs\Platforms\RefreshConnectionJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Cache\ApifyBudget;
use App\Services\Cache\Concerns\JitteredTtl;
use App\Services\Platforms\Payloads\InstagramPayload;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\StrandedPendingWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

// Manual, user-triggered refresh of a connected platform's data — the same
// re-scrape the daily cron runs (PlatformRefresher), but on demand from the
// dashboard's per-card refresh button. Only the registry's refreshable platforms
// can be refreshed; static link cards (TikTok, Facebook, custom links) and
// booking links (Fresha, Square) have nothing to re-pull. A short per-user+platform
// cooldown keeps the button from hammering the upstream scrapers.
//
// RV-8: refresh() used to call PlatformRefresher::refresh() inline per row —
// SafeUrlFetcher's timeouts are per-hop, so a single row could hold the PHP-FPM
// worker for up to ~96s, multiplied by every connected row for the platform, in
// one request. Now it stamps each row 'pending' and hands off to the same
// RefreshConnectionJob the hourly cron already uses, returning 202 immediately.
// See docs/frontend-contracts/2026-07-23-refresh-async.md for the full contract.
class RefreshController extends ApiController
{
    use JitteredTtl;
    use ResolveCurrentUser;

    private const COOLDOWN_SECONDS = 12;

    /** Instagram re-pulls are paid Apify runs — hours, not seconds. */
    private const INSTAGRAM_COOLDOWN_SECONDS = 6 * 3600;

    public function __construct(
        private readonly PlatformRegistry $registry,
    ) {}

    // POST /platforms/{platform}/refresh
    public function refresh(Request $request, string $platform): JsonResponse
    {
        // Instagram is deliberately OUTSIDE the refresh cron (each pull is a
        // paid Apify run), but the owner can re-pull their latest post/reel on
        // demand — budget-gated + a long cooldown so the button can't burn
        // scrape spend. Handled before the refreshable gate since instagram
        // isn't in the registry's refreshable set.
        if ($platform === 'instagram') {
            return $this->refreshInstagram($request);
        }

        if (! $this->registry->isRefreshable($platform)) {
            return $this->error('This connection refreshes on its own — there’s nothing to pull manually.', 422);
        }

        $user = $this->currentUser($request);

        // Atomic add doubles as the cooldown gate: a present key means the
        // button was hit moments ago, so we reject instead of re-scraping.
        if (! Cache::add("integrations:refresh:{$user->id}:{$platform}", true, self::applyJitter(self::COOLDOWN_SECONDS))) {
            return $this->error('Just refreshed — give it a few seconds before trying again.', 429);
        }

        // Every row for this platform (multi-account music, both events kinds,
        // single-selection socials) gets queued for re-pull; each persists its
        // own last_refresh_status. RV-8: filtered to active() — inactive rows
        // aren't rendered publicly, so refreshing them buys nothing, and this
        // now matches refreshInstagram() above and the cron's dueForRefresh()
        // scope instead of silently diverging from both.
        //
        // W6-1: also excludes rows already 'pending' — a deferred connect owns
        // that row until its job clears the marker, and this controller is
        // about to stamp 'pending' itself below, so selecting it here would
        // silently steal the row out from under the connect (see
        // FreshaConnectFetch's docblock for what that wipes). Must be filtered
        // at selection, before the stamp — RefreshConnectionJob itself cannot
        // tell the two meanings of 'pending' apart. R1: same NULL-safe
        // predicate as IntegrationConnection::scopeDueForRefresh(), via the
        // shared scopeExcludingPending() (see that scope's docblock).
        $rows = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', $platform)
            ->active()
            ->excludingPending()
            ->get();

        if ($rows->isEmpty()) {
            return $this->error('Nothing connected to refresh.', 404);
        }

        foreach ($rows as $row) {
            // Quiet write: this is a status-only stamp, not a content change —
            // IntegrationConnectionObserver::saved() must not fire its edge-cache
            // purge / preset-resolve for it (mirrors PlatformRefresher::recordNotModified()).
            $row->updateQuietly(['last_refresh_status' => 'pending']);
            // manual: true gives this dispatch its own ShouldBeUnique lock lane
            // (see RefreshConnectionJob::uniqueId()) so a cron-dispatched job
            // already holding the connection's 2h dedup lock can't silently
            // swallow this user-triggered click.
            RefreshConnectionJob::dispatch($row->id, $platform, manual: true);
        }

        return $this->success([
            'status' => 'pending',
            'refreshed' => $rows->count(),
            'statusUrl' => url("/api/platforms/{$platform}/refresh/status"),
        ], 202);
    }

    // GET /platforms/{platform}/refresh/status — poll target for the 202 above.
    // User-scoped the same way as the POST query: a foreign row is never
    // visible to look up, so an empty result is 404, never 403 (matches
    // GenericPlatformController::connectStatus's documented reasoning).
    public function refreshStatus(Request $request, string $platform): JsonResponse
    {
        $user = $this->currentUser($request);

        $rows = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', $platform)
            ->active()
            ->get();

        if ($rows->isEmpty()) {
            return $this->error('Nothing connected to refresh.', 404);
        }

        // Stale-pending escape hatch, copied from connectStatus(): a row still
        // 'pending' whose updated_at is older than StrandedPendingWindow means
        // the worker died or ScheduledRefresh swallowed a
        // LockTimeoutException without writing a terminal status (see
        // PlatformRefresher/ScheduledRefresh) — treat it as no-longer-blocking
        // rather than poll forever. A null updated_at can't be proven stale,
        // so it stays 'pending'.
        $stillPending = $rows->contains(
            fn (IntegrationConnection $row) => $row->last_refresh_status === 'pending'
                && ($row->updated_at === null || $row->updated_at->gt(now()->subMinutes(StrandedPendingWindow::MINUTES)))
        );

        if ($stillPending) {
            return $this->success(['status' => 'pending']);
        }

        $refreshed = $rows->count();
        $ok = $rows->where('last_refresh_status', 'ok')->count();

        return $this->success([
            'status' => $ok > 0 ? 'ready' : 'failed',
            'refreshed' => $refreshed,
            'ok' => $ok,
        ]);
    }

    // Manual Instagram re-pull: re-dispatches the connect job against the
    // stored username. The current photo/reel keeps serving until the new
    // scrape lands (the row is NOT reset to pending), the job's success write
    // fires the observer → edge purge. 6h cooldown per user + the platform
    // Apify budget gate keep spend bounded.
    private function refreshInstagram(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $connection = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', 'instagram')
            ->where('is_active', true)
            ->first();

        $username = $connection ? InstagramPayload::fromArray($connection->payload)->username : null;
        if (! $connection || $username === null || $username === '') {
            return $this->error('Nothing connected to refresh.', 404);
        }

        if (! Cache::add("integrations:refresh:{$user->id}:instagram", true, self::applyJitter(self::INSTAGRAM_COOLDOWN_SECONDS))) {
            return $this->error('Instagram was refreshed recently — try again in a few hours.', 429);
        }

        if (! app(ApifyBudget::class)->tryClaim('instagram')) {
            return $this->error('Refresh limit reached for now — try again later.', 429);
        }

        InstagramConnectJob::dispatch((string) $user->id, $username, (string) $connection->id);

        return $this->success([
            'status' => 'pending',
            'statusUrl' => url('/api/platforms/instagram/connect/status'),
        ], 202);
    }
}
