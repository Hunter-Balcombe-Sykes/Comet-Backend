<?php

namespace App\Services\V5\Scraping\Contracts;

// V5 Fetch contract — pulls fresh data from an upstream platform.
interface FetchContract
{
    /**
     * @return array The fetched payload. Shape varies by platform archetype.
     */
    public function fetch(string $identifier): array;
}
