<?php

namespace App\Services\Moderation;

use RuntimeException;

class NcmecSubmissionFailedTooManyTimes extends RuntimeException
{
    public function __construct(string $submissionId)
    {
        parent::__construct("NCMEC submission {$submissionId} hit max attempts");
    }
}
