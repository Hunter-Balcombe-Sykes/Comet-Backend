<?php

namespace App\Services\Site;

use App\Contracts\HttpStatusCodeInterface;

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
class AdvisoryLockTimeoutException extends \RuntimeException implements HttpStatusCodeInterface
{
    public function __construct(public readonly string $lockKey, ?\Throwable $previous = null)
    {
        parent::__construct("advisory lock timed out waiting on \"{$lockKey}\"", previous: $previous);
    }

    /**
     * 423, matching what the controllers above already return by hand.
     *
     * Declared here rather than added as another per-controller catch because #LIFE-1 made this
     * exception reachable from resolveItems(), i.e. from EVERY writeManualItem() caller —
     * PoolItemCreateController, the menu and shop writers, both service controllers. A contended
     * identity resolve is a retry-in-a-moment, not a server fault, and a 500 would tell the
     * dashboard the opposite. The hand-written catches still win where they exist: they run
     * first and carry compensations this cannot (UserServiceController::store() marks the
     * already-committed item removed before returning its own 423).
     */
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
