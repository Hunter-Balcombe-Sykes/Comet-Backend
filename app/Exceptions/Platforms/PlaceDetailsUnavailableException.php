<?php

// app/Exceptions/Platforms/PlaceDetailsUnavailableException.php

namespace App\Exceptions\Platforms;

use RuntimeException;

// Reported to Nightwatch when the BILLED Google Place-Details call fails or returns a
// non-OK status. Previously only Log::warning'd → invisible to Nightwatch, so a Google
// outage would silently stale every business card with nobody paged (OBS-1). Distinct
// from the transient photo/street-view misses, which stay quiet.
class PlaceDetailsUnavailableException extends RuntimeException
{
    public function __construct(public string $placeId, public ?int $status = null)
    {
        parent::__construct("Google Place-Details unavailable for {$placeId}".($status !== null ? " (status {$status})" : ''));
    }
}
