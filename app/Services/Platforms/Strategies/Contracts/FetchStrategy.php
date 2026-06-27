<?php

namespace App\Services\Platforms\Strategies\Contracts;

use App\Models\Core\Site\IntegrationConnection;

// How a platform pulls its display snapshot from upstream. Returns the new
// payload array to store. NoFetch returns the existing payload unchanged.
interface FetchStrategy
{
    /** @return array<string,mixed> */
    public function fetch(IntegrationConnection $connection): array;
}
