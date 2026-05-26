<?php

namespace App\Services\BotProtection\Contracts;

use App\Services\BotProtection\VerificationResult;

interface CaptchaProvider
{
    /**
     * Verify a CAPTCHA token against the provider's siteverify endpoint.
     *
     * @param  string       $token      The token from the frontend widget.
     * @param  string|null  $remoteIp   Client IP (passed to provider for fraud scoring).
     * @param  string|null  $action     Optional action tag (Turnstile analytics; reCAPTCHA v3 required).
     * @param  int|null     $timeoutMs  Override request timeout (shadow mode uses a shorter value).
     */
    public function verify(
        string $token,
        ?string $remoteIp = null,
        ?string $action = null,
        ?int $timeoutMs = null,
    ): VerificationResult;

    public function driverName(): string;
}
