<?php

namespace App\Services\Moderation;

use RuntimeException;

class ReportTargetNotFound extends RuntimeException
{
    public function __construct(string $targetType, string $targetHandle)
    {
        parent::__construct("Cannot resolve target {$targetType}/{$targetHandle}");
    }
}
