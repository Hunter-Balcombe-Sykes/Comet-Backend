<?php

// app/Exceptions/Platforms/MissingDsarAllowlistException.php

namespace App\Exceptions\Platforms;

use RuntimeException;

// Reported to Nightwatch when DsarPayloadFilter hits its fail-closed branch —
// a platform with NO DSAR allowlist entry. Modelled on
// MissingPublicAllowlistException: a new platform must never fall through to
// a raw pass-through of its stored payload in a data-subject export, so this
// pages loudly instead of silently leaking unvetted keys.
class MissingDsarAllowlistException extends RuntimeException
{
    public function __construct(public string $platform)
    {
        parent::__construct("DsarPayloadFilter: no DSAR allowlist for platform '{$platform}'");
    }
}
