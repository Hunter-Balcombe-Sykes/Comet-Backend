<?php

// app/Exceptions/Platforms/MissingPublicAllowlistException.php

namespace App\Exceptions\Platforms;

use RuntimeException;

// Reported to Nightwatch when PublicIntegrationConnectionResource hits its fail-closed
// branch — a platform with NO public allowlist entry. Unreachable by design (SEC-1
// rejects unregistered platforms at write time), so if it fires it's a config bug that
// is silently rendering an empty payload publicly: page immediately (OBS-1).
class MissingPublicAllowlistException extends RuntimeException
{
    public function __construct(public string $platform)
    {
        parent::__construct("PublicIntegrationConnectionResource: no public allowlist for platform '{$platform}'");
    }
}
