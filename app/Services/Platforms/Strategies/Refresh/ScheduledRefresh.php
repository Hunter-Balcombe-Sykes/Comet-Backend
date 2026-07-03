<?php

namespace App\Services\Platforms\Strategies\Refresh;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\Strategies\Contracts\RefreshStrategy;

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
        $next = $this->fetch->fetch($connection);

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

        return $connection;
    }
}
