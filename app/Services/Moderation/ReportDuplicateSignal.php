<?php

namespace App\Services\Moderation;

use RuntimeException;

class ReportDuplicateSignal extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Duplicate report signal (dedup_hash collision).');
    }
}
