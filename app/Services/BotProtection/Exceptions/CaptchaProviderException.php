<?php

namespace App\Services\BotProtection\Exceptions;

use RuntimeException;

// Thrown by providers when the verify call fails for network/5xx reasons.
// Caught by VerifyBotToken middleware → fail-open + log.
class CaptchaProviderException extends RuntimeException
{
}
