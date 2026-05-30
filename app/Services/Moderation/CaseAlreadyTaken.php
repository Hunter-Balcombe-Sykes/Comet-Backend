<?php

namespace App\Services\Moderation;

use RuntimeException;

/**
 * Thrown when a staffer tries to take a case another staffer already claimed
 * (status is already 'under_review'). A concurrency conflict (HTTP 409), not an
 * illegal transition (422).
 */
class CaseAlreadyTaken extends RuntimeException
{
    public function __construct(string $caseId)
    {
        parent::__construct("Case {$caseId} is already taken (under review).");
    }
}
