<?php

namespace App\Services\Platforms\Strategies\Refresh;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Strategies\Contracts\RefreshStrategy;

// Static platforms (link-only) — nothing to re-pull.
class NoRefresh implements RefreshStrategy
{
    public function isRefreshable(): bool
    {
        return false;
    }

    public function run(IntegrationConnection $connection): IntegrationConnection
    {
        return $connection;
    }
}
