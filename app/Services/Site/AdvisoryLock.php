<?php

namespace App\Services\Site;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Acquires a Postgres advisory xact lock — auto-released by Postgres on
 * commit/rollback/connection loss, so it must be called from inside an
 * explicit transaction on the SAME connection (every caller below already
 * wraps in DB::connection('pgsql')->transaction()).
 *
 * $timeoutMs bounds the wait via SET LOCAL lock_timeout, which only holds for
 * the CURRENT transaction — that's why it's set immediately before the lock
 * call rather than once at connection level (DatabaseServiceProvider's own
 * session-level SET would survive a reconnect losing its value, and wouldn't
 * apply retroactively to a lock already being waited on). Omit $timeoutMs
 * (null) to keep the old unbounded pg_advisory_xact_lock behaviour for keys
 * this unit doesn't touch — every OTHER pg_advisory_xact_lock call site in
 * the app (site-images, site-documents, blocks-sections, service-layout,
 * feedback, claim-invite, pre_account_build_ip) still calls DB::select(...)
 * directly and is deliberately untouched here.
 *
 * SQLite (the test driver — see tests/TestCase.php's connection swap) has
 * neither SET LOCAL nor pg_advisory_xact_lock/hashtext; Pest registers those
 * two as no-op UDF shims (shimPgAdvisoryLockForSqlite() in tests/Pest.php) so
 * the surrounding transaction logic still runs for real under the suite. SET
 * LOCAL is skipped outright there (it would be a SQLite syntax error), which
 * means the timeout/exception path below is Postgres-only and NOT exercised
 * by a green suite — see the callers' tests for what they assert instead.
 */
final class AdvisoryLock
{
    // Bound for the "services:{user_id}" key — FreshaServiceProjector::sync(),
    // InsertWithSortOrder's manual-service create, and ReorderService's
    // services reorder, the three writers CA-W7 identified as contending on
    // it. Matches ManagesIntegrationConnection::withConnectionLock()'s
    // already-established interactive wait bound (Cache::lock TTL 10s /
    // block(5)) so the app's two locking mechanisms feel consistent to a
    // user, while staying comfortably under statement_timeout (30s,
    // DB_STATEMENT_TIMEOUT) and ConnectFetchJob's 45s hard timeout.
    public const SERVICES_LOCK_TIMEOUT_MS = 5000;

    private const LOCK_NOT_AVAILABLE_SQLSTATE = '55P03';

    public static function acquire(string $key, ?int $timeoutMs = null): void
    {
        $connection = DB::connection('pgsql');

        if ($timeoutMs !== null && $connection->getDriverName() === 'pgsql') {
            $connection->statement("SET LOCAL lock_timeout = '{$timeoutMs}ms'");
        }

        try {
            $connection->select('select pg_advisory_xact_lock(hashtext(?))', [$key]);
        } catch (QueryException $e) {
            if (self::isLockTimeout($e)) {
                throw new AdvisoryLockTimeoutException($key, $e);
            }

            throw $e;
        }
    }

    /** Exposed for direct unit testing — real Postgres is the only place this fires for real. */
    public static function isLockTimeout(QueryException $e): bool
    {
        return $e->getCode() === self::LOCK_NOT_AVAILABLE_SQLSTATE
            || str_contains($e->getMessage(), 'lock timeout');
    }
}
