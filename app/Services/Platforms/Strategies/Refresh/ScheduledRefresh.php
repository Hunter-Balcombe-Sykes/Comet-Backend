<?php

namespace App\Services\Platforms\Strategies\Refresh;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\Strategies\Contracts\RefreshStrategy;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// Daily-cron refresh: re-run the platform's fetch and persist on success.
// Mirrors PlatformRefresher's success path (writes through the model so
// IntegrationConnectionObserver purges the sitepage edge cache). A later plan
// swaps the match()-based refresher for registry iteration over this strategy.
class ScheduledRefresh implements RefreshStrategy
{
    public function __construct(private readonly FetchStrategy $fetch) {}

    public function isRefreshable(): bool
    {
        return true;
    }

    public function run(IntegrationConnection $connection): IntegrationConnection
    {
        // The fetch is multi-second and touches nothing shared — left unlocked.
        $next = $this->fetch->fetch($connection);

        // LIFE-10: this write is the SECOND writer of the connection payload
        // (GoogleBusinessEnrichJob is the first) — same per-user/platform lock,
        // same suffix rule (ManagesIntegrationConnection::withConnectionLock),
        // so a scheduled refresh can never race a dashboard save or an
        // in-flight enrichment. Only the write is guarded, not the fetch above.
        $suffix = $connection->resource_id === $connection->platform ? null : $connection->resource_id;
        $key = CacheKeyGenerator::platformConnectionLock($connection->platform, $connection->user_id, $suffix);

        try {
            Cache::lock($key, 10)->block(5, function () use ($connection, $next) {
                $connection->update([
                    'payload' => $next,
                    'last_refreshed_at' => now(),
                    'last_refresh_status' => 'ok',
                    'last_refresh_error' => null,
                    'consecutive_failures' => 0,
                    // Conditional-request validators (Plan 5). A wired fetch strategy set these
                    // via ConditionalContext::applyTo() before returning; a non-wired strategy
                    // leaves them at their stored value, so this is a harmless no-op write there.
                    'refresh_etag' => $connection->refresh_etag,
                    'refresh_last_modified' => $connection->refresh_last_modified,
                ]);
            });
        } catch (LockTimeoutException) {
            // Skip, don't throw: a thrown exception would burn consecutive_failures
            // on pure lock contention and could trip the circuit breaker. Skipping
            // is correct for an hourly cron — the next tick retries the refresh.
            Log::warning('platform.scheduled_refresh.lock_timeout', [
                'connection_id' => $connection->id,
                'platform' => $connection->platform,
                'user_id' => $connection->user_id,
            ]);
        }

        return $connection;
    }
}
