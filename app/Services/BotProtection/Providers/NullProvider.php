<?php

namespace App\Services\BotProtection\Providers;

use App\Services\BotProtection\Contracts\CaptchaProvider;
use App\Services\BotProtection\VerificationResult;

// Always succeeds. Zero network. Default driver for local dev + CI baseline.
final class NullProvider implements CaptchaProvider
{
    public function verify(
        string $token,
        ?string $remoteIp = null,
        ?string $action = null,
        ?int $timeoutMs = null,
    ): VerificationResult {
        return new VerificationResult(success: true, action: $action);
    }

    public function driverName(): string
    {
        return 'null';
    }
}
