<?php

namespace App\Services\Redis;

use App\Services\Redis\Exceptions\RedisUnavailableException;
use Closure;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Throwable;

/**
 * PhpRedisConnection that consults a RedisRequestBreaker before every command
 * and trips it on a transport failure.
 *
 * `Illuminate\Redis\Connections\Connection::command()` is the universal
 * funnel: __call() → command(), and RedisStore, RedisLock, RedisQueue,
 * RateLimiter and every `Redis::connection(...)->x()` call in app/ all land
 * there. Overriding only command() therefore covers the whole request path
 * without touching any caller.
 *
 * THE EARLY THROW IS LOAD-BEARING — it must happen BEFORE parent::command()
 * runs, not from inside a catch around it. Quoting the vendor code this
 * guards against (`vendor/laravel/framework/src/Illuminate/Redis/Connections/
 * PhpRedisConnection.php`, `command()`):
 *
 *     } catch (RedisException $e) {
 *         if (Str::contains($e->getMessage(), ['went away', 'socket', …])) {
 *             $this->client = $this->connector ? call_user_func($this->connector) : $this->client;
 *         }
 *         throw $e;
 *     }
 *
 * On a transport-failure message, Laravel EAGERLY RECONNECTS by re-invoking
 * $this->connector — which re-runs a blocking connect() + select($db) against
 * the still-hung server (see GuardedPhpRedisConnector). If we let
 * parent::command() run and only intercepted the exception afterwards, we
 * would still pay that reconnect cost on every skipped command; the whole
 * point of the breaker is to avoid paying it more than once per request.
 * Throwing before parent::command() is called at all is the only way to
 * actually skip the cost.
 *
 * That leaves the FIRST failure, whose reconnect fires before the breaker has
 * tripped. command() below neutralises it by swapping $this->connector for the
 * duration of an armed call — see the comment there for why that is exact
 * rather than a trick, and worth ~3s on the headline number.
 *
 * THE TRADE, STATED PLAINLY. For a Redis that is genuinely unreachable or
 * hung, the breaker reaches the same fail-open decision every caller already
 * makes, only sooner. For a SINGLE TRANSIENT failure — a Valkey restart, a
 * managed failover, one legitimate op overrunning the 3.0s read_timeout during
 * a BGSAVE fork stall — it does something the un-guarded code does not: today
 * op 1 fails, Laravel reconnects, and ops 2..N succeed; here op 1 fails and
 * ops 2..N are skipped for the rest of the request. On an authenticated route
 * that can turn a 200 into a 503, because VerifySupabaseJwt is pinned ahead of
 * ThrottleRequests (bootstrap/app.php) and FailOpenThrottleRequests fails
 * CLOSED for every limiter outside its five-entry allow-list. That is the real
 * cost of a per-request breaker and it is accepted deliberately: the behaviour
 * it replaces is an 18s public sitepage read, and the band in which a single
 * op breaches a bound ~10x the worst legitimate op ever measured (314ms) is
 * narrow. It is NOT true that "nothing changes except the wait" — that claim
 * holds for a sustained outage, not for a blip.
 *
 * WHAT THIS DOES NOT COVER. PhpRedisConnection's scan()/hscan()/zscan()/
 * sscan(), pipeline(), transaction(), subscribe() and psubscribe() all call
 * $this->client directly and bypass command() entirely — see the vendor
 * class. None of those are on the request path in this repo (SCAN is
 * console/job-only here), so this is recorded as a known boundary, not
 * worked around.
 */
class GuardedPhpRedisConnection extends PhpRedisConnection
{
    /**
     * $breaker is a RESOLVER, not an instance. Resolving once at construction
     * and holding it readonly would silently desynchronise the moment anything
     * rebinds the singleton — `app()->instance(RedisRequestBreaker::class, …)`,
     * the obvious way a future test injects a fake, would leave the middleware
     * arming one breaker while every connection consulted a different one that
     * never opens. That test would pass and measure nothing. Resolving per call
     * costs a container array lookup and removes the trap.
     *
     * @param  Closure(): RedisRequestBreaker  $breaker
     */
    public function __construct($client, ?callable $connector, array $config, private readonly Closure $breaker)
    {
        parent::__construct($client, $connector, $config);
    }

    public function command($method, array $parameters = [])
    {
        $breaker = ($this->breaker)();

        // Un-armed (console, queue worker, scheduler): vendor behaviour, byte for
        // byte, including the eager reconnect. A long-lived Horizon worker whose
        // socket drops genuinely needs it to recover.
        if (! $breaker->isArmed()) {
            return parent::command($method, $parameters);
        }

        if ($breaker->isOpen()) {
            $breaker->recordSkip();

            throw RedisUnavailableException::forSkippedCommand($this->getName(), $method, $breaker->reason());
        }

        // Suppress the eager reconnect for the duration of this call. The vendor
        // line is `$this->client = $this->connector ? call_user_func($this->connector)
        // : $this->client;`, and $this->connector is used NOWHERE else in the
        // parent (verified against the vendor class) — so swapping in a
        // connector that hands back the client we already hold turns that line
        // into `$this->client = $this->client`, a no-op, without touching
        // Connection::command()'s event dispatch.
        //
        // (Assigning null would read more directly and works identically at
        // runtime, but PhpRedisConnection::$connector is documented `@var
        // callable`, not `?callable`, so PHPStan rejects it. Returning the live
        // client is the same behaviour with an honest type.)
        //
        // WHY IT MATTERS: the reconnect fires from INSIDE parent::command()'s
        // catch, before our catch below can trip the breaker, and it re-runs a
        // blocking connect() + select($db) against the server that just timed
        // out. Left alone, the FIRST failing op costs read_timeout twice (~6s,
        // not ~3s) and the breaker's headline number is halved for nothing. The
        // reconnect also buys this request nothing: the parent rethrows either
        // way, and the next command on this connection is about to be skipped.
        $connector = $this->connector;
        $this->connector = fn () => $this->client;

        try {
            return parent::command($method, $parameters);
        } catch (Throwable $e) {
            if (RedisRequestBreaker::isTransportFailure($e)) {
                $breaker->trip($e, (string) $this->getName(), $method);
            }

            throw $e;
        } finally {
            $this->connector = $connector;
        }
    }
}
