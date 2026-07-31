<?php

namespace App\Http\Middleware\Throttle;

use App\Exceptions\Http\RateLimiterUnavailableException;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A RateLimiter that survives its own backing store going away.
 *
 * Every counter operation delegates to the real limiter and catches a store
 * fault. What happens next depends on the mode the middleware set for this
 * request's limiter (see FailOpenThrottleRequests::FAIL_OPEN_LIMITERS):
 *
 *   OPEN   — the gate opens. The route is a DB-backed public read or a beacon
 *            that already fail-opens by design; a cache outage must not take
 *            the public site down with it.
 *   CLOSED — RateLimiterUnavailableException → a clean 503 with Retry-After,
 *            instead of today's leaked RedisException 500.
 *
 * WHY A DELEGATING WRAPPER, NOT A SUBCLASS THAT CALLS parent::
 * ------------------------------------------------------------
 * RateLimiter::limiter() reads $this->limiters, populated by the RateLimiter::for()
 * calls in AppServiceProvider::configureRateLimiting() against the container
 * SINGLETON. A fresh subclass instance would have an empty $limiters array, so
 * every `throttle:public-site` would miss its named limiter and blow up in
 * ThrottleRequests::resolveMaxAttempts() as a MissingRateLimiterException. The
 * type hint on ThrottleRequests::__construct() forces us to BE a RateLimiter;
 * correctness forces us to DELEGATE to the real one.
 *
 * WHY THIS IS NOT BOUND OVER THE CONTAINER SINGLETON
 * ---------------------------------------------------
 * App\Services\Analytics\Concerns\EscalatesRepeatedFaults Tier 2 only fires
 * BECAUSE RateLimiter::hit() throws when the cache is dead. Making the global
 * limiter resilient would let Tier 1 quietly "succeed" and leave Tier 2
 * unreachable — the same silent-alarm failure mode that got a failover cache
 * store rejected. This wrapper lives ONLY inside the middleware instance; the
 * RateLimiter facade keeps resolving the untouched singleton.
 */
class ResilientRateLimiter extends RateLimiter
{
    /**
     * Sampling rate for the Nightwatch report. 1-in-200 matches
     * EscalatesRepeatedFaults::FAULT_SAMPLE_RATE: a sustained outage surfaces
     * within the first few dozen faults, while the report stream stays bounded
     * at the ~1M-requests/day target.
     */
    public const FAULT_SAMPLE_RATE = 200;

    /** Fallback Retry-After when the real availableIn() cannot be read. */
    private const FALLBACK_AVAILABLE_IN = 60;

    private bool $failOpen = false;

    private string $limiterName = 'inline';

    public function __construct(private readonly RateLimiter $inner)
    {
        // $this->cache is never read — every method below delegates to $inner —
        // but the parent constructor demands a Repository. Reading $inner's own
        // protected property (legal: it is declared on our parent class) keeps
        // the two in lockstep rather than resolving a second, possibly different
        // store from the container.
        parent::__construct($inner->cache);
    }

    /**
     * Set the failure mode for the limiter about to be evaluated. Called by
     * FailOpenThrottleRequests once per request, before any counter operation.
     */
    public function useFailOpen(bool $failOpen, string $limiterName): void
    {
        $this->failOpen = $failOpen;
        $this->limiterName = $limiterName;
    }

    // ── Gate operations: mode-sensitive ──────────────────────────────────────

    /** @param  string  $key */
    public function tooManyAttempts($key, $maxAttempts): bool
    {
        try {
            return $this->inner->tooManyAttempts($key, $maxAttempts);
        } catch (Throwable $e) {
            $this->onStoreFault($e, 'tooManyAttempts');

            // Reached only in fail-open mode; onStoreFault() throws otherwise.
            return false;
        }
    }

    /** @param  string  $key */
    public function hit($key, $decaySeconds = 60): int
    {
        try {
            return $this->inner->hit($key, $decaySeconds);
        } catch (Throwable $e) {
            $this->onStoreFault($e, 'hit');

            return 0;
        }
    }

    /** @param  string  $key */
    public function increment($key, $decaySeconds = 60, $amount = 1): int
    {
        try {
            return $this->inner->increment($key, $decaySeconds, $amount);
        } catch (Throwable $e) {
            $this->onStoreFault($e, 'increment');

            return 0;
        }
    }

    /** @param  string  $key */
    public function decrement($key, $decaySeconds = 60, $amount = 1): int
    {
        return $this->increment($key, $decaySeconds, $amount * -1);
    }

    // ── Header / metadata reads: ALWAYS degrade, never throw ─────────────────
    //
    // ThrottleRequests::addHeaders() runs AFTER $next($request) has produced the
    // response (ThrottleRequests::handleRequest()). Throwing from here would
    // convert an already-served 200 into a 500 — turning the fix into the bug it
    // is meant to remove. These are advisory headers; a wrong number during an
    // outage is harmless, a 500 is not.

    /** @param  string  $key */
    public function attempts($key): int
    {
        try {
            return (int) $this->inner->attempts($key);
        } catch (Throwable $e) {
            $this->recordStoreFault($e, 'attempts');

            return 0;
        }
    }

    /** @param  string  $key */
    public function remaining($key, $maxAttempts): int
    {
        try {
            return $this->inner->remaining($key, $maxAttempts);
        } catch (Throwable $e) {
            $this->recordStoreFault($e, 'remaining');

            return (int) $maxAttempts;
        }
    }

    /**
     * Overridden as well as remaining(): ThrottleRequests reaches the header
     * value through retriesLeft(), and delegating THAT to $inner would run the
     * inner object's own unguarded remaining() instead of the guarded one above.
     *
     * @param  string  $key
     */
    public function retriesLeft($key, $maxAttempts): int
    {
        return $this->remaining($key, $maxAttempts);
    }

    /** @param  string  $key */
    public function availableIn($key): int
    {
        try {
            return $this->inner->availableIn($key);
        } catch (Throwable $e) {
            $this->recordStoreFault($e, 'availableIn');

            return self::FALLBACK_AVAILABLE_IN;
        }
    }

    /** @param  string  $key */
    public function resetAttempts($key): bool
    {
        try {
            return (bool) $this->inner->resetAttempts($key);
        } catch (Throwable $e) {
            $this->recordStoreFault($e, 'resetAttempts');

            return false;
        }
    }

    /** @param  string  $key */
    public function clear($key): void
    {
        try {
            $this->inner->clear($key);
        } catch (Throwable $e) {
            $this->recordStoreFault($e, 'clear');
        }
    }

    // ── Pure delegation: no store access of their own ────────────────────────

    /** @param  \UnitEnum|string  $name */
    public function for($name, Closure $callback): static
    {
        $this->inner->for($name, $callback);

        return $this;
    }

    /**
     * The whole reason this class delegates rather than inherits — see the
     * class docblock.
     *
     * @param  \UnitEnum|string  $name
     */
    public function limiter($name): ?Closure
    {
        return $this->inner->limiter($name);
    }

    /** @param  string  $key */
    public function attempt($key, $maxAttempts, Closure $callback, $decaySeconds = 60): mixed
    {
        if ($this->tooManyAttempts($key, $maxAttempts)) {
            return false;
        }

        if (is_null($result = $callback())) {
            $result = true;
        }

        return tap($result, fn () => $this->hit($key, $decaySeconds));
    }

    /** @param  string  $key */
    public function cleanRateLimiterKey($key): string
    {
        return $this->inner->cleanRateLimiterKey($key);
    }

    // ── Fault handling ──────────────────────────────────────────────────────

    /**
     * Record the fault, then decide the request's fate.
     *
     * @throws RateLimiterUnavailableException when this limiter fails closed
     */
    private function onStoreFault(Throwable $e, string $operation): void
    {
        $this->recordStoreFault($e, $operation);

        if (! $this->failOpen) {
            throw new RateLimiterUnavailableException($e);
        }
    }

    /**
     * Breadcrumb on EVERY fault plus a 1-in-200 sampled report.
     *
     * The 2026-07-31 Redis drill measured ZERO breadcrumbs during a full
     * outage. A fail-open change that preserved that property would make the
     * next outage silent — strictly worse than loud 500s, because nothing would
     * tell us the limiter had stopped limiting.
     *
     * NO TIER 1 COUNTER, deliberately. EscalatesRepeatedFaults' Tier 1 counts
     * faults in a CACHE-BACKED RateLimiter bucket. Here the fault IS the cache
     * being dead, so that counter would throw on its own first write 100% of
     * the time and fall through to Tier 2 anyway. Implementing only the sampled
     * tier is not a shortcut — it is that trait's own "you cannot count
     * failures of X using X" note applied honestly. (The trait itself is not
     * reused: it lives in App\Services\Analytics\Concerns and hardcodes an
     * `analytics:fault:` key prefix.)
     */
    private function recordStoreFault(Throwable $e, string $operation): void
    {
        try {
            Log::warning('throttle.store_unavailable', [
                'limiter' => $this->limiterName,
                'operation' => $operation,
                'mode' => $this->failOpen ? 'open' : 'closed',
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            // mt_rand (not random_int): a sampling decision, not a security
            // boundary, and mt_srand() makes it seedable from a test.
            if (mt_rand(1, self::FAULT_SAMPLE_RATE) === 1) {
                report($e);
            }
        } catch (Throwable) {
            // A broken logger or reporter must never be what turns a degraded
            // request into a failed one.
        }
    }
}
