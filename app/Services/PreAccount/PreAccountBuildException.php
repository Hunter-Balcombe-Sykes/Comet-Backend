<?php

namespace App\Services\PreAccount;

use RuntimeException;

class PreAccountBuildException extends RuntimeException
{
    public const SOURCE_PAIRING_INVALID = 'SOURCE_PAIRING_INVALID';

    public const IP_BUILD_CAP = 'IP_BUILD_CAP';

    public const SOURCE_REF_INVALID = 'SOURCE_REF_INVALID';

    /** A staff request carried a contact_email that disagrees with the one
     *  already on the live build for this source. Never silently overwritten:
     *  the address on file decides who may claim a real business's site. */
    public const CONTACT_EMAIL_CONFLICT = 'CONTACT_EMAIL_CONFLICT';

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
