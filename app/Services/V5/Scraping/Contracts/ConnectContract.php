<?php

namespace App\Services\V5\Scraping\Contracts;

// V5 Connect contract — resolves user input (URL or handle) into a connection payload.
interface ConnectContract
{
    /**
     * @return array{resource_id: string, canonical_key?: string, payload: array}
     */
    public function resolve(string $input): array;
}
