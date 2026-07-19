<?php

namespace App\Services\PreAccount;

use RuntimeException;

class PreAccountBuildException extends RuntimeException
{
    public const SOURCE_PAIRING_INVALID = 'SOURCE_PAIRING_INVALID';

    public const IP_BUILD_CAP = 'IP_BUILD_CAP';

    public const SOURCE_REF_INVALID = 'SOURCE_REF_INVALID';

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
