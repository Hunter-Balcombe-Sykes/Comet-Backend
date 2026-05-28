<?php

namespace App\Services\Moderation;

use RuntimeException;

class CaseAlreadyResolved extends RuntimeException
{
    public function __construct(string $caseId)
    {
        parent::__construct("Case {$caseId} is already resolved.");
    }
}
