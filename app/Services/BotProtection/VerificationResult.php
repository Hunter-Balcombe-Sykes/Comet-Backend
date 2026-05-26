<?php

namespace App\Services\BotProtection;

final class VerificationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?float $score = null,
        public readonly array $errorCodes = [],
        public readonly ?string $hostname = null,
        public readonly ?string $action = null,
        public readonly ?string $challengeTs = null,
        public readonly bool $wasFailOpen = false,
    ) {}
}
