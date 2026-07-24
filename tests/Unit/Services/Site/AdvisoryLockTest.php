<?php

/**
 * U2 — pure unit coverage for AdvisoryLock::isLockTimeout()'s SQLSTATE/message
 * detection. This is the ONE piece of the bounded-lock mechanism that can be
 * proven without a real Postgres connection: the classification logic that
 * decides "was this QueryException actually a lock_timeout abort (55P03)".
 *
 * What this test does NOT prove: that SET LOCAL lock_timeout actually bounds
 * pg_advisory_xact_lock in production, or that Postgres raises 55P03 the way
 * these fixtures assume — SQLite has neither concept (see
 * shimPgAdvisoryLockForSqlite() in tests/Pest.php, which no-ops the lock
 * entirely), so that half is unverifiable from this suite. See
 * InsertWithSortOrderTest/ReorderServiceTest for the structural proof that
 * passing $lockTimeoutMs doesn't break the SQLite path, and
 * FreshaStorewideDeferredConnectTest's job-path test for the exception being
 * correctly funnelled into ConnectFetchJob's terminal handling.
 */

use App\Services\Site\AdvisoryLock;
use Illuminate\Database\QueryException;

/** Builds a QueryException wrapping a PDOException with a forced SQLSTATE code. */
function queryExceptionWithSqlstate(string $sqlstate, string $message): QueryException
{
    $pdoException = new PDOException($message);
    // PDOException::$code is untyped (holds a string SQLSTATE) but protected —
    // the real pdo_pgsql driver sets it internally, bypassing the public,
    // int-typed Exception::__construct() signature. Reflection is the only way
    // to reproduce that from userland PHP.
    $prop = new ReflectionProperty(PDOException::class, 'code');
    $prop->setAccessible(true);
    $prop->setValue($pdoException, $sqlstate);

    return new QueryException('pgsql', 'select pg_advisory_xact_lock(hashtext(?))', ['services:x'], $pdoException);
}

it('classifies a real lock_timeout SQLSTATE as a lock timeout', function () {
    $e = queryExceptionWithSqlstate('55P03', 'SQLSTATE[55P03]: Lock not available: 7 ERROR:  canceling statement due to lock timeout');

    expect(AdvisoryLock::isLockTimeout($e))->toBeTrue();
});

it('classifies by message when the code is absent but the Postgres wording is present', function () {
    $e = queryExceptionWithSqlstate('', 'ERROR:  canceling statement due to lock timeout');

    expect(AdvisoryLock::isLockTimeout($e))->toBeTrue();
});

it('does not classify an unrelated SQLSTATE as a lock timeout', function () {
    $e = queryExceptionWithSqlstate('23505', 'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint');

    expect(AdvisoryLock::isLockTimeout($e))->toBeFalse();
});
