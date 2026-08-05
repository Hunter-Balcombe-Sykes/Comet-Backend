<?php

namespace App\Services\Redis\Exceptions;

use RedisException;

/**
 * Thrown by GuardedPhpRedisConnection/GuardedPhpRedisConnector in place of a
 * real Redis operation while RedisRequestBreaker::isOpen() is true.
 *
 * EXTENDS \RedisException ON PURPOSE. Every existing fail-open arm in this
 * app is written as `catch (Throwable $e)` or `catch (RedisException $e)` —
 * CacheLockService, ResilientRateLimiter, QueuedIngestor, VerifySupabaseJwt's
 * revocation check. Extending RedisException means all of them make the
 * IDENTICAL decision they make today against a real timeout, just reached in
 * ~0 ms instead of ~3 s. A plain RuntimeException would silently miss any
 * `catch (RedisException)` arm and turn a designed degrade into an unhandled
 * 500 — the opposite of what this class exists to do.
 *
 * NOT a LockTimeoutException, and must never become one. Several callers
 * (CacheLockService, ManagesIntegrationConnection) have a SEPARATE
 * `catch (LockTimeoutException)` arm meaning "another process holds this
 * lock, proceed differently" — a contention outcome, not an outage. Routing
 * a store-unavailable skip down that path would make a Redis outage look
 * like lock contention and trigger the wrong recovery behaviour. Throwing
 * RedisException (via this subclass) instead of LockTimeoutException from
 * the guarded connection is what keeps those two meanings apart.
 */
final class RedisUnavailableException extends RedisException
{
    public static function forSkippedCommand(?string $connection, string $command, ?string $reason): self
    {
        $message = sprintf(
            'Redis command [%s] skipped on connection [%s]: request breaker is open (%s)',
            $command,
            $connection ?? 'unknown',
            $reason ?? 'no reason recorded',
        );

        return new self($message);
    }
}
