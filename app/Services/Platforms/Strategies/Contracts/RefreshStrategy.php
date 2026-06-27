<?php

namespace App\Services\Platforms\Strategies\Contracts;

use App\Models\Core\Site\IntegrationConnection;

// When/whether a platform's stored snapshot is re-pulled. Composes with the
// platform's FetchStrategy — Scheduled/OnDemand call fetch and persist; NoRefresh
// is a no-op. Replaces PlatformRefresher's hard-coded match() in a later plan.
interface RefreshStrategy
{
    public function isRefreshable(): bool;

    public function run(IntegrationConnection $connection): IntegrationConnection;
}
