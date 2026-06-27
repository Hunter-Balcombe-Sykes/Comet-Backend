<?php

namespace App\Services\Platforms\Strategies\Contracts;

// How a platform turns raw user input (URL / handle) into the canonical stored
// selection array. Returns null when the input is not valid for the platform.
interface ConnectStrategy
{
    /** @return array<string,mixed>|null */
    public function normalize(string $input): ?array;
}
