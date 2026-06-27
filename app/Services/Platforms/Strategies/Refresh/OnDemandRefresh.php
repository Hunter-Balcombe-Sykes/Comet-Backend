<?php

namespace App\Services\Platforms\Strategies\Refresh;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\Strategies\Contracts\RefreshStrategy;

// Manual dashboard-button refresh. Same persist path as ScheduledRefresh; the
// per-user cooldown is enforced by the caller (today's RefreshController), not
// here, so this stays a pure building block.
class OnDemandRefresh implements RefreshStrategy
{
    public function __construct(private readonly FetchStrategy $fetch) {}

    public function isRefreshable(): bool
    {
        return true;
    }

    public function run(IntegrationConnection $connection): IntegrationConnection
    {
        $connection->update([
            'payload' => $this->fetch->fetch($connection),
            'last_refreshed_at' => now(),
            'last_refresh_status' => 'ok',
            'last_refresh_error' => null,
            'consecutive_failures' => 0,
        ]);

        return $connection;
    }
}
