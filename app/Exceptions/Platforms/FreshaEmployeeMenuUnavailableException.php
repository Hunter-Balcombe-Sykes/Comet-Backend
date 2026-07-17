<?php

// app/Exceptions/Platforms/FreshaEmployeeMenuUnavailableException.php

namespace App\Exceptions\Platforms;

use RuntimeException;
use Throwable;

// Reported to Nightwatch when FreshaScraper::fetchEmployeeServices() can't return a
// per-employee menu — a connection-level exception, a non-2xx HTTP response, or a
// 2xx response missing `categories` (the classic BOOKING_INIT_HASH/client-version
// rotation symptom). Previously only Log::warning'd → invisible to Nightwatch, and
// the caller (FreshaFetch) silently falls back to the whole-location menu, so a
// rotated hash degrades every BY-EMPLOYEE salon to the full menu forever with
// nobody paged (OBS-1/OBS-2 sweep). Purely additive: the null-return + fallback
// contract is unchanged, this only makes the failure visible.
class FreshaEmployeeMenuUnavailableException extends RuntimeException
{
    public function __construct(
        public string $slug,
        public string $employeeId,
        public string $reason,
        public ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "Fresha employee menu unavailable for {$slug}/{$employeeId}: {$reason}".($status !== null ? " (status {$status})" : ''),
            previous: $previous,
        );
    }
}
