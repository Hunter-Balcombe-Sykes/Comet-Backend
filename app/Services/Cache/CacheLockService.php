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
        // Fast path: primary key still warm.
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }

        // SWR fast path: primary expired but stale copy is still live — return
        // last-good immediately. The lock below will let one worker recompute.
        $staleKey = $key.':stale';
        $stale = Cache::get($staleKey);
        if ($stale !== null) {
            // Try a non-blocking lock attempt so a single worker recomputes in
            // this same request. If the lock is already held (another worker is
            // recomputing), skip and return the stale value without waiting.
            $lock = Cache::lock('lock:'.$key, $lockSeconds);
            if ($lock->get()) {
                // Re-check primary after acquiring — another process may have
                // just filled it while we were racing for the lock.
                $fresh = Cache::get($key);
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
        $lock = Cache::lock('lock:'.$key, $lockSeconds);

        try {
            $lock->block($blockSeconds);
        } catch (LockTimeoutException) {
            // Another process is filling the cache but took too long.
            // Return whatever is now cached, or fall through to compute as a last resort
            // so the user never gets nothing back. The stampede risk on this edge case
            // is bounded to requests that arrive in the timeout window.
            $warm = Cache::get($key);
            if ($warm !== null) {
                return $warm;
            }

            return $callback();
        }

        try {
            // Double-check: another process may have filled the cache while we waited.
            $rechecked = Cache::get($key);
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
     * Write $value to $key with jitter and to $staleKey with the stale extension TTL.
     *
     * Jitter is applied only to int TTLs (±20% uniform distribution). DateTimeInterface
     * TTLs represent a caller-specified deadline and must not be modified.
     */
    private function writeWithJitter(string $key, string $staleKey, mixed $value, DateTimeInterface|int $ttl): void
    {
        if ($ttl instanceof DateTimeInterface) {
            // Caller wants a specific expiry deadline — honour it exactly.
            Cache::put($key, $value, $ttl);
            // Stale extension: seconds from now to deadline, then ×10. Ensures
            // the stale copy outlives the primary by a meaningful window.
            $secondsUntilDeadline = max(1, $ttl->getTimestamp() - now()->timestamp);
            Cache::put($staleKey, $value, $secondsUntilDeadline * self::STALE_TTL_MULTIPLIER);

            return;
        }

        // Independent jitter draws so primary and stale copies expire at different seconds.
        $jitteredTtl = self::applyJitter($ttl);
        $staleTtl = self::applyJitter($ttl * self::STALE_TTL_MULTIPLIER);

        Cache::put($key, $value, $jitteredTtl);
        Cache::put($staleKey, $value, $staleTtl);
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
        $cached = Cache::get($key);
        if ($cached === self::NULL_SENTINEL) {
            return null;
        }
        if ($cached !== null) {
            return $cached;
        }

        $lock = Cache::lock('lock:'.$key, $lockSeconds);

        try {
            $lock->block($blockSeconds);
        } catch (LockTimeoutException) {
            $warm = Cache::get($key);
            if ($warm === self::NULL_SENTINEL) {
                return null;
            }
            if ($warm !== null) {
                return $warm;
            }

            return $callback();
        }

        try {
            $rechecked = Cache::get($key);
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
                Cache::put($key, self::NULL_SENTINEL, $nullTtl ?? $ttl);
            } else {
                Cache::put($key, $value, $ttl);
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
