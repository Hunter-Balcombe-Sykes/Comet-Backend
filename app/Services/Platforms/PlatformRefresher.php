<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Http\FetchBudget;
use App\Services\Http\SafeUrlException;
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
//                            payload preserved (no purge — nothing changed). Also the
//                            status recordBudgetExhausted() writes when it is OUR OWN
//                            wall-clock budget, not a vendor miss, that cut a refresh
//                            short — discriminated by last_refresh_error ===
//                            'refresh_budget_exhausted'.
//
// The whole method body runs inside FetchBudget::ensureOpen() (config
// partna.http_fetch.refresh_budget_seconds) so a pathological scrape can never ride
// RefreshConnectionJob's $timeout to a SIGKILL. ensureOpen() is re-entrant: a refresh
// fired from inside an already-open budget (e.g. a connect flow) simply shares that
// tighter outer deadline instead of opening a second one.
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
        private readonly FetchBudget $budget,
    ) {}

    public function refresh(IntegrationConnection $connection): IntegrationConnection
    {
        // Bound the refresh at this one chokepoint. The sole production caller of
        // PlatformRefresher::refresh() is RefreshConnectionJob::handle(), which serves
        // BOTH triggers — the hourly cron (RefreshIntegrationConnectionsCommand) and the
        // manual "refresh" button (RefreshController dispatches the same job) — so this
        // single wrap bounds every real refresh. ensureOpen() is re-entrant: a refresh
        // fired from inside an already-open budget (a future connect flow) would simply
        // share that tighter outer deadline instead of opening a second one; no
        // production path nests today. (ShopController, IntegrationConnectionObserver and
        // ShopBrandConnectJob call a same-named refresh() on the UNRELATED
        // IntegrationConnectionCacheRefresher — a cache-purge helper that does no
        // outbound fetch — not this class.)
        $seconds = (float) config('partna.http_fetch.refresh_budget_seconds', 90);

        return $this->budget->ensureOpen($seconds, function () use ($connection) {
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
            } catch (SafeUrlException $e) {
                // Trap B: exhausted() reads true ONLY while the budget is still OPEN — once
                // ensureOpen()'s finally clears the deadline it reads false, so this catch
                // MUST live inside the closure. Our own deadline → quiet. A genuine
                // SafeUrlException (real SSRF block / connection failure, budget NOT
                // exhausted) is rethrown unchanged so it keeps today's loud 'error' state.
                if ($this->budget->exhausted()) {
                    return $this->recordBudgetExhausted($connection);
                }

                throw $e;
            }
        });
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

    private function recordBudgetExhausted(IntegrationConnection $connection): IntegrationConnection
    {
        // Our OWN wall-clock deadline expired mid-refresh — a transient miss, NOT a vendor
        // outage. Quiet by design: preserve the last-known payload; do NOT arm the circuit
        // breaker (no consecutive_failures bump), page Nightwatch (no report()), or notify
        // the user (no healthNotifier). updateQuietly() so IntegrationConnectionObserver's
        // edge-cache purge doesn't fire on a non-change. No last_refreshed_at bump leaves
        // the row due for the next cycle; CheckPlatformRefreshBacklogCommand is the safety
        // net if a connection perpetually exhausts (it will show up as chronically stale).
        Log::warning('integrations.refresh.budget_exhausted', [
            'platform' => $connection->platform,
            'platform_connection_id' => $connection->id,
        ]);

        $connection->updateQuietly([
            'last_refresh_status' => 'unavailable',
            'last_refresh_error' => 'refresh_budget_exhausted',
        ]);

        return $connection;
    }
}
