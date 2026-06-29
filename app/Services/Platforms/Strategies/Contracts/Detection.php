<?php

namespace App\Services\Platforms\Strategies\Contracts;

// Whether a pasted URL belongs to this platform, for the smart-detect categories
// (booking / reservations / events). Host-level only — the provider's own connect
// endpoint does the strict path/rid validation.
interface Detection
{
    public function matches(string $url): bool;
}
