<?php

namespace App\Services\Cache;

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One capped, TTL'd daily counter — the shared mechanism under every spend
 * ceiling in the system (ApifyBudget, AiSpendBudget, PlacesBudget,
 * ProbeBudget). Extracted from ProbeBudget, where it was proven first.
 *
 * WHY THIS IS ONE CLASS AND NOT FOUR COPIES. It was four copies, and all four
 * carried the same defect, because each new budget class was written by
 * mirroring the last one — PlacesBudget's own header still described the
 * broken `Cache::add + increment` form as the pattern to follow. A mechanism
 * duplicated by imitation propagates its bugs by imitation. The next budget
 * class should inherit the TTL guarantee, not the bug.
 *
 * THE DEFECT THIS CLOSES. `Cache::add()` then `Cache::increment()` are two
 * round trips. If the key expires between them, INCRBY recreates it with NO
 * TTL — Redis does not restore an expiry on an incremented key. Cache and
 * queue share one Valkey instance under instance-wide volatile-lru, where
 * carrying a TTL is precisely what makes a key evictable; a TTL-less key is
 * permanent, inevictable ballast, one per counter per day, unbounded.
 * CacheKeyspaceConstraintsTest cannot catch this: it greps for the literal
 * `forever()` call form, and concedes in its own docblock that a raw write
 * with no paired expiry is not statically checkable.
 *
 * ATOMICITY. A claim is one server-side step: INCRBY, TTL assert, and the
 * over-cap release together in a single EVAL, so two concurrent claims cannot
 * both pass a ceiling. The counter is the authority; the check is never a
 * separate read. A release is always the paired decrement of this call's own
 * increment, so the counter never drifts down. The error direction is
 * deliberately fail-closed: an in-flight rejected claim can briefly make a
 * concurrent legitimate claim look over-budget, denying one call. Over-
 * admitting is the failure these ceilings exist to prevent; over-denying is
 * not worth a distributed lock.
 */
final class DailyCounterClaim
{
    /** A date-keyed counter rotates at midnight; the TTL just has to outlive the day. */
    public const DAY_SECONDS = 86400;

    /**
     * KEYS[1] = counter key, ARGV[1] = cap, ARGV[2] = ttl seconds.
     *
     * Increments FIRST (not check-then-increment — that ordering is what makes
     * concurrent over-admission impossible), then guarantees the key carries a
     * TTL on every path: a fresh key, or one that expired between an earlier
     * call's steps. The over-cap release re-asserts the TTL too, so even a
     * rejected claim cannot leave the key without an expiry.
     */
    private const CLAIM_SCRIPT = <<<'LUA'
        local v = redis.call('INCRBY', KEYS[1], 1)
        if redis.call('TTL', KEYS[1]) < 0 then
            redis.call('EXPIRE', KEYS[1], ARGV[2])
        end
        if v > tonumber(ARGV[1]) then
            redis.call('DECRBY', KEYS[1], 1)
            if redis.call('TTL', KEYS[1]) < 0 then
                redis.call('EXPIRE', KEYS[1], ARGV[2])
            end
            return 0
        end
        return 1
        LUA;

    /**
     * Claim one unit of $key against $cap. True if the claim is within the
     * ceiling; false if it was over and has already been released.
     *
     * Gated on the resolved STORE INSTANCE (`Cache::getStore()`), not on
     * `config('cache.default') === 'redis'` — a failover store or an Octane
     * wrapper would lie about that — and not on `Cache::store('redis')`, which
     * resolves the named store regardless of the default and so would try to
     * EVAL against real Redis under the array-store test lane. `getStore()` is
     * the exact object the plain `Cache::get`/`add` calls in the caller's
     * `remaining()` resolve to, so the script provably hits the same
     * connection, database and key prefix those reads use. Getting that wrong
     * would split each counter in two and silently stop the budget enforcing.
     */
    public static function claim(string $key, int $cap, int $ttlSeconds = self::DAY_SECONDS): bool
    {
        $store = Cache::getStore();

        if (! $store instanceof RedisStore) {
            return self::claimFallback($key, $cap, $ttlSeconds);
        }

        // Redis Lua EVAL (the server-side atomicity primitive), not a PHP
        // eval — the script is the fixed literal above, and no interpolated or
        // user-controlled code ever reaches it.
        //
        // The arg order below is PhpRedisConnection::eval($script, $numberOfKeys,
        // ...$arguments), which is what this resolves to at runtime. PHPStan sees
        // the raw \Redis signature instead and flags three false positives here;
        // they are stood down in phpstan.neon's ignoreErrors, with the reasoning.
        // Do NOT reorder these arguments to satisfy an analyser.
        $result = $store->connection()->eval(
            self::CLAIM_SCRIPT, 1, $store->getPrefix().$key, $cap, $ttlSeconds,
        );

        return (int) $result === 1;
    }

    /**
     * Paired release for {@see claim()} — used when a LATER dimension of a
     * multi-counter claim rejects and the earlier ones must be given back.
     * Re-asserts the TTL for the same reason the claim script does.
     */
    public static function release(string $key, int $ttlSeconds = self::DAY_SECONDS): void
    {
        $store = Cache::getStore();

        if (! $store instanceof RedisStore) {
            Cache::decrement($key);

            return;
        }

        $prefixedKey = $store->getPrefix().$key;
        $connection = $store->connection();
        $connection->decrby($prefixedKey, 1);

        if ($connection->ttl($prefixedKey) < 0) {
            $connection->expire($prefixedKey, $ttlSeconds);
        }
    }

    /**
     * Two-round-trip fallback for stores without EVAL (array/file — test-only).
     * Shares the cap guarantee but not the TTL guarantee, which is acceptable
     * precisely because it never runs against the real Valkey instance whose
     * eviction policy the TTL guarantee exists to satisfy.
     */
    private static function claimFallback(string $key, int $cap, int $ttlSeconds): bool
    {
        Cache::add($key, 0, now()->addSeconds($ttlSeconds));

        if (Cache::increment($key) > $cap) {
            // A failure to hand the counter back must NOT change the verdict.
            // The claim was over the ceiling either way; all a failed release
            // costs is one unit of headroom until the key rotates at midnight.
            // Letting it propagate would turn a precise "cap reached" into
            // whatever the caller makes of an infrastructure error — for
            // PlacesBudget, a specific PlatformCapReached became a generic
            // Unavailable, losing the reason a caller surfaces to the user.
            try {
                Cache::decrement($key);
            } catch (Throwable $e) {
                report($e);
            }

            return false;
        }

        return true;
    }
}
