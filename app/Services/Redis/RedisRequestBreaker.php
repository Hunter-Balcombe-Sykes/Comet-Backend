<?php

namespace App\Services\Redis;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RedisException;
use Throwable;

/**
 * Per-request circuit breaker over every phpredis connection.
 *
 * Container singleton, so state is process-lifetime by default — which is why
 * every field is reset explicitly by arm() rather than relying on construction.
 * Inert (isOpen() always false) until something calls arm(): see
 * ArmRedisRequestBreaker for the only caller that does, and its docblock for
 * why console/queue contexts deliberately never arm it.
 *
 * ONE BREAKER FOR ALL CONNECTIONS. config/database.php's seven redis
 * connections and all four config/queue.php redis connections point at one
 * Valkey instance (CLAUDE.md). A transport failure on `cache` is evidence
 * about the *server*, not about DB 1, so a trip on one connection opens the
 * breaker for all of them. This assumption would need revisiting the day
 * Redis is split across more than one instance.
 *
 * Trips are recorded, never acted on here — GuardedPhpRedisConnection and
 * GuardedPhpRedisConnector are what actually skip work once isOpen() is true.
 * This class only holds the shared state and the trip predicate so both of
 * them agree on it.
 */
class RedisRequestBreaker
{
    /**
     * Laravel's own transport-failure vocabulary from
     * PhpRedisConnection::command()'s eager-reconnect check, minus READONLY,
     * plus the connect-side messages Laravel's list never needed.
     *
     * READONLY means "you wrote to a replica" — a topology/routing error, not
     * an unresponsive server. It is excluded so a replica misroute does not
     * skip the rest of the request's Redis work, which a correctly-routed
     * retry would have served. (Note this app runs a single Valkey node with
     * no Sentinel, so the case is theoretical here.)
     *
     * 'operation timed out' and 'getaddrinfo' are NOT in Laravel's list and
     * are load-bearing additions: phpredis 6.3.0 emits
     * `RedisException('Operation timed out')` for a TCP connect that never
     * completes — the packet-drop outage shape (security group changed, node
     * fenced, network partition), which is exactly the shape
     * GuardedPhpRedisConnector's connect guard exists for. Verified against
     * real phpredis on an unroutable address; the `DEBUG SLEEP` drill CANNOT
     * exercise it, because a sleeping server still completes the TCP handshake
     * and fails later at SELECT with 'read error on connection'. A green drill
     * is not evidence that this line is unnecessary.
     *
     * Case-insensitive: the message text comes from the phpredis extension,
     * not from us, and its casing is not a contract worth trusting.
     */
    private const TRANSPORT_FAILURE_FRAGMENTS = [
        'went away',
        'socket',
        'error while reading',
        'read error on connection',
        'connection lost',
        'connection refused',
        'connection timed out',
        'operation timed out',
        'connection reset',
        'getaddrinfo',
        "can't connect",
        'connection closed',
        'no connection',
    ];

    private bool $armed = false;

    private bool $open = false;

    private ?string $reason = null;

    private int $skipped = 0;

    /**
     * Arm the breaker for a new request and clear all prior state.
     *
     * Reset happens HERE, at the start, and nowhere else. Clearing at the end
     * of a request instead would erase the tripped state before terminate-
     * phase work runs — RecordCacheMetrics' terminating() flush and any
     * defer()ed SWR recompute (CacheLockService::rememberLocked) execute
     * after the response is sent but must still see this request's trip and
     * skip, not re-pay the 3 s bound on work nobody is waiting for. Doing the
     * reset at arm-time also means state can never leak from one request into
     * the next in a long-lived worker process, even if a prior request never
     * reached its terminate phase.
     */
    public function arm(): void
    {
        $this->armed = true;
        $this->open = false;
        $this->reason = null;
        $this->skipped = 0;
    }

    /**
     * Fully disarm — clears armed too. Not called on the request path; exists
     * for tests and any future console entry point that wants to guarantee
     * the breaker is inert.
     */
    public function disarm(): void
    {
        $this->armed = false;
        $this->open = false;
        $this->reason = null;
        $this->skipped = 0;
    }

    public function isArmed(): bool
    {
        return $this->armed;
    }

    /**
     * Whether the guarded connection/connector should short-circuit instead
     * of touching Redis. False whenever the breaker isn't armed, regardless
     * of $open — an un-armed breaker must never affect a console/queue run.
     */
    public function isOpen(): bool
    {
        return $this->armed && $this->open;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function skippedCommands(): int
    {
        return $this->skipped;
    }

    /**
     * Count a command that was skipped because the breaker was already open.
     * No log line here on purpose — a degraded request can skip five or more
     * commands, and one breadcrumb per skip would multiply the very noise
     * this design exists to avoid. skippedCommands() is the observable if a
     * caller wants the count.
     */
    public function recordSkip(): void
    {
        $this->skipped++;
    }

    /**
     * Trip the breaker after a transport failure. No-op unless armed — an
     * un-armed breaker (console/queue) has no isOpen() consumer anyway, but
     * staying inert here too keeps the invariant obvious from this method
     * alone rather than depending on isOpen()'s armed check elsewhere.
     *
     * Idempotent: only the FIRST trip in a request sets the reason. A second,
     * unrelated failure five commands later is very likely a symptom of the
     * first (every subsequent op on a hung server also fails), and overwriting
     * the reason would erase the diagnostic value of knowing what failed
     * *first*.
     */
    public function trip(Throwable $e, string $connection, string $command): void
    {
        if (! $this->armed) {
            return;
        }

        if ($this->open) {
            return;
        }

        $this->open = true;
        $this->reason = sprintf(
            '%s on connection [%s] via %s: %s',
            $e::class,
            $connection,
            $command,
            $e->getMessage(),
        );

        // Wrapped in its own try/catch, matching CacheLockService::recordStoreFault():
        // a broken logger must never be what turns a fast-fail into a failed request.
        try {
            Log::warning('redis.request_breaker.opened', [
                'connection' => $connection,
                'command' => $command,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable) {
            // Swallowed deliberately — see above.
        }
    }

    /**
     * True only for a \RedisException whose message names a transport-level
     * failure (server unreachable/hung), never for a command-shape error
     * (WRONGTYPE, NOSCRIPT, NOAUTH, OOM, MISCONF) or any non-RedisException
     * Throwable. Unmatched input does NOT trip — the fail-safe direction is
     * "worst case, behave like today", not "trip on anything surprising".
     */
    public static function isTransportFailure(Throwable $e): bool
    {
        if (! $e instanceof RedisException) {
            return false;
        }

        return Str::contains($e->getMessage(), self::TRANSPORT_FAILURE_FRAGMENTS, ignoreCase: true);
    }
}
