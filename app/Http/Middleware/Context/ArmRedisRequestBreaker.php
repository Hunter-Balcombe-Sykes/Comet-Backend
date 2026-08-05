<?php

namespace App\Http\Middleware\Context;

use App\Services\Redis\RedisRequestBreaker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ONLY thing that arms RedisRequestBreaker. Global middleware, prepended
 * in bootstrap/app.php so it is the first thing to run on every HTTP request.
 *
 * Deliberately does nothing else — no disarm() after $next(). See
 * RedisRequestBreaker::arm() for why the reset lives at the START of the
 * request: a tripped breaker must survive into terminate-phase work
 * (RecordCacheMetrics' terminating() flush, defer()ed SWR recomputes), and
 * the NEXT request's arm() is what resets it, not this one's return.
 *
 * SCOPE — WHY ONLY HTTP, NOT CONSOLE/QUEUE:
 * Console commands and queue workers never go through this middleware, so
 * they never arm the breaker, so isOpen() is always false for them and every
 * Redis command they issue behaves exactly as it does today. This is
 * deliberate, not an oversight:
 *
 *   - A worker has no user waiting on it. Skipping a Redis op inside a job
 *     doesn't make the job fast, it makes the job WRONG — a cache-warm job
 *     that skips its own write would report success without having warmed
 *     anything (the same failure shape DefersRecompute's runningInConsole()
 *     gate exists to prevent for SWR recomputes).
 *   - A worker process is long-lived across many jobs with no natural,
 *     testable "end of request" boundary to reset a trip at — arming per-job
 *     would need its own machinery this middleware doesn't have to invent.
 *   - The queue transport itself IS Redis. A breaker active on the worker
 *     path would, once tripped, skip the very BLPOP the worker exists to
 *     run — turning a degraded queue into a stopped one.
 *
 * A `runningInConsole()` gate here (mirroring DefersRecompute's) would have
 * been the wrong shape for the OPPOSITE reason: it would disable the breaker
 * across the ENTIRE test suite, since Pest boots through the console
 * kernel — exactly the false-PASS shape this repo has been bitten by before
 * (feature tests silently exercising code that never runs in production).
 * Arming explicitly, only from this one middleware, means feature tests that
 * go through the HTTP kernel arm it for real and unit tests arm it in one
 * line — there is no implicit "am I in a test" branch anywhere in this design.
 */
class ArmRedisRequestBreaker
{
    public function __construct(private readonly RedisRequestBreaker $breaker) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->breaker->arm();

        return $next($request);
    }
}
