<?php

namespace App\Exceptions;

use App\Models\Commerce\CommissionExportAudit;
use RuntimeException;

class CommissionExportInProgressException extends RuntimeException
{
    public function __construct(public readonly CommissionExportAudit $existing)
    {
        parent::__construct(sprintf(
            'A commission export is already in progress for this account (id=%s, status=%s).',
            $existing->id,
            $existing->status,
        ));
    }
}
