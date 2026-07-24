<?php

namespace App\Services\Site;

// Thrown by AdvisoryLock::acquire() when SET LOCAL lock_timeout aborts a
// pg_advisory_xact_lock wait (Postgres SQLSTATE 55P03) instead of letting the
// caller block forever. Resolution is the caller's call — an interactive
// write surfaces a 423 retry (see ManagesIntegrationConnection::
// withConnectionLock and the two ServiceManagementController reorder/store
// methods); a queued job folds it into its own terminal-failure path
// (FreshaConnectFetch::fetchStorewide()).
//
// Also (re)thrown by ReorderService::reorder() when its OWN `lockForUpdate()`
// row lock times out — SET LOCAL's timeout persists for the rest of the
// transaction, so a bounded advisory-lock acquire leaves the subsequent row
// lock bounded too, and it fails with the SAME SQLSTATE. Classified via
// AdvisoryLock::isLockTimeout() rather than a second detection path, so it
// folds into the identical 423 the advisory-lock case already gets — the
// caller can't tell which lock contended either way.
class AdvisoryLockTimeoutException extends \RuntimeException
{
    public function __construct(public readonly string $lockKey, ?\Throwable $previous = null)
    {
        parent::__construct("advisory lock timed out waiting on \"{$lockKey}\"", previous: $previous);
    }
}
