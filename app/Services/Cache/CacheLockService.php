<?php

namespace App\Services\Cache;

use App\Services\Cache\Concerns\DefersRecompute;
use App\Services\Cache\Concerns\JitteredTtl;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Single-flight cache regeneration helper with jitter and stale-while-revalidate.
 *
 * Wraps Laravel's Cache::remember with a Cache::lock so that under concurrent
 * load only one request rebuilds an expired value while others wait and read
 * the freshly-filled cache. Models the proven pattern in
 * SiteCacheService::getPublicSitePayload.
 *
 * Use this for any cached value that:
 *   - is hot (likely to be requested concurrently when it expires), AND
 *   - is expensive to regenerate (multiple DB queries, joins, external calls).
 *
 * Two methods, depending on whether the closure can return null:
 *   - rememberLocked()         — closures that always return a non-null value
 *   - rememberLockedNullable() — closures that can return null (e.g. lookups that
 *                                may not find a row); uses a sentinel so cached
 *                                nulls survive across requests instead of triggering
 *                                a fresh DB hit each time.
 *
 * Cache hardening features (rememberLocked only):
 *   - TTL jitter ±20% on every int TTL write, preventing thundering-herd expiry.
 *   - Stale-while-revalidate (SWR): stores a long-lived stale copy alongside the
 *     primary key. On stale (primary expired, stale present), returns last-good
 *     immediately without blocking. The next caller that acquires the lock will
 *     recompute and fill the primary key for all subsequent readers.
 *   - Lock keys live on the 'cache_locks' Redis DB (via lock_connection in
 *     config/cache.php) so Cache::flush() on the data DB never releases held locks.
 */
class CacheLockService
{
    use DefersRecompute;
    use JitteredTtl;

    /**
     * Sentinel cached by rememberLockedNullable when a closure returns null,
     * so the cache layer can distinguish "key absent" from "computed, was null".
     * Chosen as an unlikely-to-collide string rather than a constant object so
     * it survives Redis (de)serialisation cleanly.
     */
    private const NULL_SENTINEL = '__cache_lock_null_sentinel__';

    /**
     * Multiplier applied to int TTLs to derive the stale-extension TTL.
     * A primary TTL of 60s → stale TTL of 600s (10 min last-good window).
     */
    private const STALE_TTL_MULTIPLIER = 10;

    /**
     * Sampling rate for the Nightwatch report on a store fault. Matches
     * EscalatesRepeatedFaults::FAULT_SAMPLE_RATE — see recordStoreFault() for
     * why only the sampled tier exists here.
     */
    private const FAULT_SAMPLE_RATE = 200;

    /**
     * Get the value at $key, or compute it via $callback under a single-flight lock.
     *
     * TTL jitter (±20%) is applied to every int $ttl write to spread expiry across
     * the fleet. A stale-extension copy at "$key:stale" is written at 10× the base
     * TTL; when the primary expires but the stale copy is still live, the last-good
     * value is returned immediately and the lock-acquiring caller recomputes silently
     * on the next miss (stale-while-revalidate without a background job).
     *
     * @param  string  $key  Cache key (the lock key is auto-derived as 'lock:'.$key)
     * @param  DateTimeInterface|int  $ttl  Same TTL semantics as Cache::remember (DateTime or seconds).
     *                                      Defaults to 60s; callers passing explicit TTL still win.
     * @param  Closure(): mixed  $callback  Closure that produces the value on miss; must not return null
     * @param  int  $lockSeconds  How long the lock is held before auto-expiring (must exceed worst-case closure runtime)
     * @param  int  $blockSeconds  How long a waiting request blocks for the lock before falling through
     */
    public function rememberLocked(
        string $key,
        DateTimeInterface|int $ttl,
        Closure $callback,
        int $lockSeconds = 10,
        int $blockSeconds = 5,
    ): mixed {
        $staleKey = $key.':stale';

        try {
            // Fast path: primary key still warm.
            $cached = Cache::get($key);
            if ($cached !== null) {
                return $cached;
            }

            // SWR fast path: primary expired but stale copy is still live — return
            // last-good immediately. The lock below will let one worker recompute.
            $stale = Cache::get($staleKey);
        } catch (Throwable $e) {
            // The STORE is unreachable — distinct from the callback failing,
            // which has its own catch below and must still propagate. Compute
            // directly and hand back an uncached value rather than 500 a public
            // read that never needed the cache to be correct, only to be fast.
            return $this->computeWithoutCache($e, 'read', $callback);
        }

        if ($stale !== null) {
            // Try a non-blocking lock attempt so a single worker recomputes in
            // this same request. If the lock is already held (another worker is
            // recomputing), skip and return the stale value without waiting.
            try {
                $lock = Cache::lock('lock:'.$key, $lockSeconds);
                $acquired = $lock->get();
            } catch (Throwable $e) {
                // Lock store gone, but a known-good value is already in hand.
                // Serving it beats recomputing with no single-flight guard.
                $this->recordStoreFault($e, 'lock');

                return $stale;
            }

            if ($acquired) {
                // Re-check primary after acquiring — another process may have
                // just filled it while we were racing for the lock.
                $fresh = $this->readOrDegrade($key);
                if ($fresh !== null) {
                    $this->releaseQuietly($lock);

                    return $fresh;
                }

                // The lock release lives inside this closure, not in a finally at
                // the call site. Releasing at return would free the lock the moment
                // we hand back stale, so the next caller would also win it and
                // defer its own recompute — N rebuilds instead of one.
                $recompute = function () use ($lock, $key, $staleKey, $ttl, $callback, $stale): mixed {
                    try {
                        $value = $callback();
                    } catch (Throwable $e) {
                        // CCH-3: recompute failed but a known-good stale value is
                        // already in hand (SWR's whole purpose) — report and serve
                        // it instead of failing this one unlucky lock-winner's
                        // request. $stale is guaranteed non-null here; a genuinely
                        // cold miss with no stale copy is a different code path
                        // further down and still propagates.
                        report($e);

                        return $stale;
                    } finally {
                        // Released before the write on purpose: the lock decides
                        // who recomputes, and writeWithJitter is idempotent, so
                        // holding it across the write only widens the window a
                        // crashed process can strand it.
                        $this->releaseQuietly($lock);
                    }

                    $this->writeWithJitter($key, $staleKey, $value, $ttl);

                    return $value;
                };

                if ($this->shouldDeferRecompute()) {
                    // Unnamed on purpose: DeferredCallbackCollection keeps only the
                    // last callback per name, and a dropped closure would strand a
                    // held lock for its full $lockSeconds.
                    defer($recompute)->always();

                    return $stale;
                }

                // Console/worker: callers such as WarmPublicSiteCacheJob require
                // the value to be warm before this returns.
                return $recompute();
            }

            // Another worker is refreshing — return last-good without blocking.
            return $stale;
        }

        // Cold miss — acquire blocking lock so only one worker runs the callback.
        try {
            $lock = Cache::lock('lock:'.$key, $lockSeconds);
            $lock->block($blockSeconds);
        } catch (LockTimeoutException) {
            // Another process is filling the cache but took too long.
            // Return whatever is now cached, or fall through to compute as a last resort
            // so the user never gets nothing back. The stampede risk on this edge case
            // is bounded to requests that arrive in the timeout window.
            //
            // Listed BEFORE the Throwable arm below on purpose: LockTimeoutException
            // is a Throwable, and PHP matches catch arms in order — reversing them
            // would reclassify every lock timeout as a store outage.
            $warm = $this->readOrDegrade($key);
            if ($warm !== null) {
                return $warm;
            }

            return $callback();
        } catch (Throwable $e) {
            return $this->computeWithoutCache($e, 'lock', $callback);
        }

        try {
            // Double-check: another process may have filled the cache while we waited.
            $rechecked = $this->readOrDegrade($key);
            if ($rechecked !== null) {
                return $rechecked;
            }

            $value = $callback();
            $this->writeWithJitter($key, $staleKey, $value, $ttl);

            return $value;
        } finally {
            // Always release — even if the closure threw — so we don't hold the lock
            // for its full TTL after a failure.
            $this->releaseQuietly($lock);
        }
    }

    /**
     * Re-write an entry and its stale twin under a SHORTER TTL, after the build
     * that produced the value turned out to be degraded (CCH-5).
     *
     * rememberLocked() takes its TTL as an argument, so it is fixed before the
     * callback runs and cannot be lowered once the callback discovers it built
     * from a fault. Rather than widen that signature for one caller, this
     * re-writes the same value the caller already holds. Both keys are
     * rewritten: shortening only the primary would leave the ×10 stale twin
     * serving the degraded copy for the rest of its window, which is where
     * most of the observed staleness lived.
     *
     * Racing another worker is benign — a worker that also built degraded
     * shortens too, and one that built cleanly overwrites with good data.
     *
     * Deliberately NOT writeWithJitter(): that gives the stale twin a x10
     * extension, which is right for a good value (SWR wants a long last-known-
     * good) and exactly wrong for this one. Serving a stale DEGRADED copy is
     * the defect, so the twin gets the same short life as the primary. Jitter
     * is still drawn independently per key so a fleet-wide blip does not
     * expire every shortened entry on the same second.
     */
    public function shortenDegraded(string $key, mixed $value, int $ttl): void
    {
        $this->writeOrDegrade($key, $value, self::applyJitter($ttl));
        $this->writeOrDegrade($key.':stale', $value, self::applyJitter($ttl));
    }

    /**
     * Write $value to $key with jitter and to $staleKey with the stale extension TTL.
     *
     * Jitter is applied only to int TTLs (±20% uniform distribution). DateTimeInterface
     * TTLs represent a caller-specified deadline and must not be modified.
     */
    private function writeWithJitter(string $key, string $staleKey, mixed $value, DateTimeInterface|int $ttl): void
    {
        if ($ttl instanceof DateTimeInterface) {
            // Caller wants a specific expiry deadline — honour it exactly.
            $this->writeOrDegrade($key, $value, $ttl);
            // Stale extension: seconds from now to deadline, then ×10. Ensures
            // the stale copy outlives the primary by a meaningful window.
            $secondsUntilDeadline = max(1, $ttl->getTimestamp() - now()->timestamp);
            $this->writeOrDegrade($staleKey, $value, $secondsUntilDeadline * self::STALE_TTL_MULTIPLIER);

            return;
        }

        // Independent jitter draws so primary and stale copies expire at different seconds.
        $jitteredTtl = self::applyJitter($ttl);
        $staleTtl = self::applyJitter($ttl * self::STALE_TTL_MULTIPLIER);

        $this->writeOrDegrade($key, $value, $jitteredTtl);
        $this->writeOrDegrade($staleKey, $value, $staleTtl);
    }

    /**
     * Read a key, treating a store fault as a miss.
     *
     * Every caller already handles null as "not cached", so degrading to null
     * needs no extra branch at the call site — the request falls through to the
     * compute path it would have taken on a cold miss.
     */
    private function readOrDegrade(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (Throwable $e) {
            $this->recordStoreFault($e, 'read');

            return null;
        }
    }

    /**
     * Write a key, treating a store fault as "stays uncached".
     *
     * A failed write is not a failed request: the caller already holds the
     * computed value and is about to return it. The only cost is that the next
     * reader recomputes too — which is the correct behaviour when there is no
     * working cache to read from.
     */
    private function writeOrDegrade(string $key, mixed $value, DateTimeInterface|int $ttl): void
    {
        try {
            Cache::put($key, $value, $ttl);
        } catch (Throwable $e) {
            $this->recordStoreFault($e, 'write');
        }
    }

    /**
     * The store is gone: skip the lock, compute directly, return uncached.
     *
     * BLAST RADIUS, STATED HONESTLY. This class also backs
     * UserCacheService::getIdByAuthId() — the authenticated hot path — so
     * degradation there costs one extra DB query per authenticated request
     * during an outage, and with neither cache nor lock a cache-miss stampede
     * becomes a DB stampede. The trade is deliberate: Postgres survives an
     * unthrottled read burst at pilot scale, and the behaviour being replaced
     * is total unavailability. If it proves wrong, the narrowing is to restrict
     * degradation to the public read path.
     *
     * ONE CONSUMER IS NOT A DB READ, and it is the one to watch:
     * VerifySupabaseJwt::fetchJwksOrThrow() caches `supabase:jwks` through here.
     * Degrading it means every authenticated request re-fetches JWKS from
     * Supabase over HTTP for the duration of an outage — an EXTERNAL stampede,
     * not an internal one, with a 5s timeout attached. That is still better
     * than what it replaces (the RedisException became a JwksUnavailableException
     * and every authed request 503'd), but it is a different shape of risk from
     * the DB stampede above: if Supabase throttles the burst, authentication
     * degrades anyway, just more slowly. Worth revisiting with a process-local
     * JWKS memo before pilot traffic grows.
     *
     * CALLERS THAT CATCH. FeatureFlagService and FeatureAvailability both wrap
     * rememberLocked() in their own try/catch. A STORE fault no longer reaches
     * them — it is handled here — but a fault thrown by their CALLBACK still
     * does, which is what those catches are actually for (see
     * FeatureAvailability::resolveOverrides()'s docblock). Do not "clean up"
     * those catches as dead code.
     *
     * The callback's OWN exceptions are not caught here — a genuine downstream
     * failure must not be laundered into a cache-outage breadcrumb.
     */
    private function computeWithoutCache(Throwable $e, string $operation, Closure $callback): mixed
    {
        $this->recordStoreFault($e, $operation);

        return $callback();
    }

    /**
     * Breadcrumb on EVERY store fault plus a 1-in-200 sampled report.
     *
     * The 2026-07-31 Redis drill measured ZERO breadcrumbs during a full
     * outage. Degrading silently would be strictly worse than the 500s it
     * replaces — nothing would tell us the cache had stopped being a cache.
     *
     * NO TIER 1 COUNTER, deliberately. EscalatesRepeatedFaults' Tier 1 counts
     * faults in a CACHE-BACKED RateLimiter bucket; here the fault IS the cache
     * being dead, so that counter would throw on its own first write 100% of
     * the time and fall through to Tier 2 anyway. Only implementing the sampled
     * tier is that trait's own "you cannot count failures of X using X" note
     * applied honestly, not a shortcut. (The trait is not reused: it lives in
     * App\Services\Analytics\Concerns and hardcodes an `analytics:fault:` key
     * prefix, so borrowing it here would be namespace dishonesty for six lines.)
     */
    private function recordStoreFault(Throwable $e, string $operation): void
    {
        try {
            Log::warning('cache.store_unavailable', [
                'operation' => $operation,
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

    /**
     * Like rememberLocked, but the callback may return null. Null results are cached
     * as a sentinel so subsequent reads return null without re-running the callback.
     *
     * Note: no jitter or SWR here — this method is used for negative-cache lookups
     * (profile misses, etc.) where stale last-good semantics don't apply.
     *
     * @param  string  $key  Cache key
     * @param  DateTimeInterface|int  $ttl  TTL for non-null values
     * @param  Closure(): mixed  $callback  May return null. Must not return the sentinel string.
     * @param  DateTimeInterface|int|null  $nullTtl  TTL when caching a null result.
     *                                               Defaults to $ttl when null. For negative-cache use cases, pass an explicit
     *                                               shorter duration (e.g. 30s) so a "not found" lookup retries sooner once the
     *                                               underlying row may have appeared.
     */
    public function rememberLockedNullable(
        string $key,
        DateTimeInterface|int $ttl,
        Closure $callback,
        DateTimeInterface|int|null $nullTtl = null,
        int $lockSeconds = 10,
        int $blockSeconds = 5,
    ): mixed {
        try {
            $cached = Cache::get($key);
        } catch (Throwable $e) {
            return $this->computeWithoutCache($e, 'read', $callback);
        }

        if ($cached === self::NULL_SENTINEL) {
            return null;
        }
        if ($cached !== null) {
            return $cached;
        }

        try {
            $lock = Cache::lock('lock:'.$key, $lockSeconds);
            $lock->block($blockSeconds);
        } catch (LockTimeoutException) {
            // Ordered before the Throwable arm — see rememberLocked().
            $warm = $this->readOrDegrade($key);
            if ($warm === self::NULL_SENTINEL) {
                return null;
            }
            if ($warm !== null) {
                return $warm;
            }

            return $callback();
        } catch (Throwable $e) {
            return $this->computeWithoutCache($e, 'lock', $callback);
        }

        try {
            $rechecked = $this->readOrDegrade($key);
            if ($rechecked === self::NULL_SENTINEL) {
                return null;
            }
            if ($rechecked !== null) {
                return $rechecked;
            }

            $value = $callback();
            if ($value === self::NULL_SENTINEL) {
                throw new \LogicException('Closure returned the cache null sentinel; this value is reserved.');
            }
            if ($value === null) {
                $this->writeOrDegrade($key, self::NULL_SENTINEL, $nullTtl ?? $ttl);
            } else {
                $this->writeOrDegrade($key, $value, $ttl);
            }

            return $value;
        } finally {
            $this->releaseQuietly($lock);
        }
    }

    /**
     * Release a lock, counting rather than throwing if the driver refuses.
     *
     * A release can legitimately fail when the lock already auto-expired; a
     * failure to release must never surface as a request error.
     */
    private function releaseQuietly(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable) {
            $this->recordLockReleaseFailure();
        }
    }

    /**
     * Increment the lock-release failure counter in Redis.
     *
     * Key: cache:lock_release_failures (integer, 7-day rolling window — inspect
     * with: redis-cli GET cache:lock_release_failures). The window is deliberate:
     * this counter exists so ops can spot a driver problem, not for lifetime
     * accounting, and an untimed key is inevictable under the volatile-lru policy
     * this shared Valkey instance needs. EXPIRE is re-issued on every increment,
     * so the window rolls forward from the most recent failure.
     *
     * Swallows driver errors silently — a failure to count must not cascade.
     */
    private function recordLockReleaseFailure(): void
    {
        try {
            Redis::incr('cache:lock_release_failures');
            Redis::expire('cache:lock_release_failures', 7 * 86400);
        } catch (Throwable $e) {
            Log::warning('cache.lock_release_failure_counter_failed', ['error' => $e->getMessage()]);
        }
    }
}
