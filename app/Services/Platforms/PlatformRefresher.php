<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Notifications\Dispatchers\PlatformHealthNotifier;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Strategies\Fetch\FetchNotModifiedException;
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
    public function __construct(
        private readonly PlatformRegistry $registry,
        private readonly PlatformHealthNotifier $healthNotifier,
    ) {}

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
        } catch (FetchNotModifiedException $e) {
            return $this->recordNotModified($connection);
        } catch (FetchShapeException $e) {
            // OBS-1: a shape error means a stored payload lost a required key (data
            // corruption). Report it so Nightwatch pages — the Log::warning in
            // recordFailure() alone is an invisible breadcrumb. Transient upstream
            // misses (FetchUnavailableException, below) stay quiet by design.
            report($e);

            return $this->recordFailure($connection, $e->getMessage(), 'error');
        } catch (FetchUnavailableException $e) {
            return $this->recordFailure($connection, $e->getMessage(), 'unavailable');
        }
    }

    // 304 Not Modified: upstream confirmed the stored payload is still current. Bump
    // last_refreshed_at so the connection isn't re-checked until its next TTL, and
    // clear the failure counter (a 304 IS a healthy hit). Write QUIETLY: nothing
    // changed, so we must NOT fire IntegrationConnectionObserver — its saved() purges
    // the sitepage edge cache and re-resolves design presets on EVERY save.
    // updateQuietly() bypasses the observer, which is exactly right when there is no
    // content change to publish (the whole point of the 304 short-circuit).
    private function recordNotModified(IntegrationConnection $connection): IntegrationConnection
    {
        $connection->updateQuietly([
            'last_refreshed_at' => now(),
            'last_refresh_status' => 'ok',
            'last_refresh_error' => null,
            'consecutive_failures' => 0,
        ]);

        return $connection;
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

        // OV-H: warn the user (critical → in-app + email) when this failure trips the
        // circuit breaker. The notifier is dedupe-keyed + best-effort, so it fires once
        // and can never break the refresh path.
        $this->healthNotifier->connectionRefreshFailing($connection);

        return $connection;
    }
}
