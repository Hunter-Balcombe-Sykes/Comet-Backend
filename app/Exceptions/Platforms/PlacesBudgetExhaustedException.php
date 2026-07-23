<?php

namespace App\Exceptions\Platforms;

use App\Services\Cache\PlacesClaim;
use RuntimeException;

// Thrown by GoogleBusinessService when a billed Places call site (details or
// photos) is denied a PlacesBudget claim (RV-6). Carries the SKU, the bound
// dimension, and the place id so callers can branch on WHY: GoogleBusinessController
// converts UserCapReached to a 429 (the caller's own doing, actionable) and
// PlatformCapReached/Unavailable to a quiet degrade + report() (nothing the
// user can do — a spend ceiling must not become an outage). GoogleBusinessFetch
// and GoogleBusinessSourceGenerator treat every reason the same way (their
// existing best-effort/resettable-failure contracts already cover it).
class PlacesBudgetExhaustedException extends RuntimeException
{
    public function __construct(public readonly string $sku, public readonly PlacesClaim $reason, public readonly string $placeId)
    {
        parent::__construct("Google Places {$sku} budget exhausted for {$placeId} ({$reason->value})");
    }
}
