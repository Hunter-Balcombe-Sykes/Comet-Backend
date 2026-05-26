<?php

namespace App\Services\BotProtection\Exceptions;

use RuntimeException;

// Thrown at boot by BotProtectionServiceProvider when config is invalid
// (missing secret, null driver in prod enforce, test site key in prod).
// Refuses to boot — surfaces in Laravel Cloud deploy logs.
class CaptchaConfigurationException extends RuntimeException
{
}
