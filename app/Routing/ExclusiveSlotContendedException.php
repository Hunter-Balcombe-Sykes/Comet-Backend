<?php

namespace App\Routing;

use App\Contracts\HttpStatusCodeInterface;

/**
 * D6 (2026-09-06): thrown when SuggestionApplier::apply()'s exclusive-slot
 * lock (the same Cache::lock key SourceReconciler::exclusiveSlotLockKey()
 * and the platform connect controllers already serialise on) cannot be
 * acquired within EXCLUSIVE_SLOT_LOCK_BLOCK seconds — a concurrent accept()
 * or connect for the same class/user is mid-write. Same 423 retry contract
 * ManagesIntegrationConnection::withConnectionLock()/withCrossPlatformLock()
 * already give the ordinary connect endpoints for this exact situation;
 * accept() had none until now, so a race here fell through to whatever
 * DB::transaction() left half-applied instead of a clean retry.
 */
final class ExclusiveSlotContendedException extends \RuntimeException implements HttpStatusCodeInterface
{
    public function __construct(public readonly string $lockKey, ?\Throwable $previous = null)
    {
        parent::__construct('Another change is still saving — please retry in a moment.', previous: $previous);
    }

    /** @return array<string, string> */
    public function context(): array
    {
        return ['lock_key' => $this->lockKey];
    }

    public function getHttpStatusCode(): int
    {
        return 423;
    }

    /** @return array<string, string|int> */
    public function getHttpHeaders(): array
    {
        return [];
    }
}
