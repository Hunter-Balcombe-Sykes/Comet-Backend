<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Cache\Concerns\JitteredTtl;
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

    public function __construct(
        private readonly PlatformRefresher $refresher,
        private readonly PlatformRegistry $registry,
    ) {}

    // POST /platforms/{platform}/refresh
    public function refresh(Request $request, string $platform): JsonResponse
    {
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
}
