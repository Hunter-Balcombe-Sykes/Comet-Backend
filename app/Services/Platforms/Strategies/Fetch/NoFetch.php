<?php

namespace App\Services\Platforms\Strategies\Fetch;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;

// Link-only platforms store a URL and fetch nothing — the snapshot IS the
// user-entered selection.
class NoFetch implements FetchStrategy
{
    public function fetch(IntegrationConnection $connection): array
    {
        return $connection->payload ?? [];
    }
}
