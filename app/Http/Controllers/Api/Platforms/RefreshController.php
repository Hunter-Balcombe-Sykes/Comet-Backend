<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Cache\Concerns\JitteredTtl;
use App\Jobs\Platforms\InstagramConnectJob;
use App\Services\Platforms\ApifyBudget;
use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\Registry\PlatformRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

// Manual, user-triggered refresh of a connected platform's data — the same
// re-scrape the daily cron runs (PlatformRefresher), but on demand from the
// dashboard's per-card refresh button. Only the registry's refreshable platforms
// can be refreshed; static link cards (TikTok, Facebook, custom links) and
// booking links (Fresha, Square) have nothing to re-pull. A short per-user+platform
// cooldown keeps the button from hammering the upstream scrapers.
class RefreshController extends ApiController
{
    use JitteredTtl;
    use ResolveCurrentUser;

    private const COOLDOWN_SECONDS = 12;

    /** Instagram re-pulls are paid Apify runs — hours, not seconds. */
    private const INSTAGRAM_COOLDOWN_SECONDS = 6 * 3600;

    public function __construct(
        private readonly PlatformRefresher $refresher,
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
        // single-selection socials) gets re-pulled; each persists its own
        // last_refresh_status. The sitepage edge cache is purged per row by the
        // IntegrationConnectionObserver when the payload actually changes.
        $rows = IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('platform', $platform)
            ->get();

        if ($rows->isEmpty()) {
            return $this->error('Nothing connected to refresh.', 404);
        }

        $ok = 0;
        foreach ($rows as $row) {
            $this->refresher->refresh($row);
            if ($row->last_refresh_status === 'ok') {
                $ok++;
            }
        }

        return $this->success(['refreshed' => $rows->count(), 'ok' => $ok]);
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

        $username = data_get($connection?->payload, 'username');
        if (! $connection || ! is_string($username) || $username === '') {
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
