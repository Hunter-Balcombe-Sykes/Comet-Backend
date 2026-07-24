<?php

namespace App\Services\Site;

// Thrown by AdvisoryLock::acquire() when SET LOCAL lock_timeout aborts a
// pg_advisory_xact_lock wait (Postgres SQLSTATE 55P03) instead of letting the
// caller block forever. Resolution is the caller's call — an interactive
// write surfaces a 423 retry (see ManagesIntegrationConnection::
// withConnectionLock and the two ServiceManagementController reorder/store
// methods); a queued job folds it into its own terminal-failure path
// (FreshaConnectFetch::fetchStorewide()).
class AdvisoryLockTimeoutException extends \RuntimeException
{
    public function __construct(public readonly string $lockKey, ?\Throwable $previous = null)
    {
        parent::__construct("advisory lock timed out waiting on \"{$lockKey}\"", previous: $previous);
    }
}
