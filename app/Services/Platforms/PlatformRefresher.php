<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Strategies\Fetch\FetchShapeException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use Illuminate\Support\Facades\Log;

// Daily / on-demand refresh of the auto-content platform connections. The
// registry owns the per-platform refresh behaviour (descriptor->refreshStrategy()
// → ScheduledRefresh re-pulls + persists); this orchestrator adds the
// cross-cutting failure bookkeeping the strategies intentionally don't carry:
//   - status='ok'          → success (the strategy persisted through the model so
//                            IntegrationConnectionObserver purges the sitepage cache
//                            when the payload actually changed).
//   - status='error'       → a data-shape problem (missing required key); logged loudly.
//   - status='unavailable' → a transient upstream miss; recorded quietly, last-known
//                            payload preserved (no purge — nothing changed).
//
// A generic (non-Fetch*) exception is deliberately NOT caught here: it bubbles to
// the command's per-connection catch, which reports it to Nightwatch. FetchShape/
// FetchUnavailable both extend RuntimeException, so we catch each subclass
// explicitly and never the parent — a real scraper crash must not masquerade as a
// quiet 'unavailable'.
class PlatformRefresher
{
    public function __construct(private readonly PlatformRegistry $registry) {}

    public function refresh(IntegrationConnection $connection): IntegrationConnection
    {
        $descriptor = $this->registry->get($connection->platform);
        $strategy = $descriptor?->refreshStrategy();

        // Unknown or non-refreshable platform — mirrors the old match()'s default
        // arm. Unreachable from the cron/controller (both gate on the refreshable
        // set) but kept as a fail-loud guard.
        if ($strategy === null || ! $strategy->isRefreshable()) {
            return $this->recordFailure($connection, 'unsupported_platform', 'error');
        }

        try {
            return $strategy->run($connection);
        } catch (FetchShapeException $e) {
            return $this->recordFailure($connection, $e->getMessage(), 'error');
        } catch (FetchUnavailableException $e) {
            return $this->recordFailure($connection, $e->getMessage(), 'unavailable');
        }
    }

    // Persist a failed refresh: log a shape error loudly, then atomically bump
    // consecutive_failures. increment() avoids the read-modify-write race and fires
    // only updating/updated — a safe no-op in IntegrationConnectionObserver on a
    // failed refresh (it touches no payload).
    private function recordFailure(IntegrationConnection $connection, string $error, string $status): IntegrationConnection
    {
        if ($status === 'error') {
            Log::warning('integrations.refresh.bad_shape', [
                'platform' => $connection->platform,
                'platform_connection_id' => $connection->id,
                'error' => $error,
            ]);
        }

        $connection->increment('consecutive_failures', 1, [
            'last_refresh_status' => $status,
            'last_refresh_error' => $error,
        ]);

        return $connection;
    }
}
