<?php

namespace App\Exceptions\Platforms;

use RuntimeException;

// Reported (throttled via App\Support\ThrottledReport) whenever a vendor 4xx
// says something is wrong with OUR account/key/actor rather than with the
// caller's specific request — a rotated API key, a revoked token, an
// unrented Apify actor, or the actor's own x402 payment fault. These are the
// low-frequency, high-consequence faults B3 (#W1-OBS-1, #W2-OBS-4,
// #W2-OBS-5) found silently swallowed behind Log::warning + return null.
//
// 2026-08-15 x402 runbook: a 402 from Apify is their agentic-payment
// middleware answering a probe, not necessarily a dead card — probe with a
// live actor run before touching billing.
class VendorAccountFaultException extends RuntimeException
{
    public function __construct(
        public readonly string $vendor,
        public readonly string $reason,
        public readonly ?int $status,
    ) {
        parent::__construct("{$vendor} account fault [{$reason}] status={$status}");
    }
}
