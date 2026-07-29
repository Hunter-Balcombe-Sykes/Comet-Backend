<?php

namespace App\Routing\Probes;

use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;

/**
 * Three-dimension spend ceiling for outbound link probes (plan §11).
 *
 * Probes are keyless, so there is no vendor invoice to cap — but they are
 * still outbound requests this backend makes on a user's say-so, and an
 * unbounded one is both a reliability risk to us (a slow host stalling a
 * queue) and an abuse vector aimed at someone else (a paste loop turning the
 * platform into a probe amplifier). The dimensions answer three different
 * failures:
 *
 *   global per-day  — one runaway import cannot exhaust the platform's
 *                     outbound capacity for everyone else
 *   user per-day    — one account cannot make us hammer a third party
 *   per-run         — one import cannot spend its whole daily allowance on a
 *                     single 200-link page
 *
 * A claim is one atomic server-side step (INCRBY, TTL assert, and the
 * over-cap release in a single EVAL), so two concurrent claims cannot both
 * pass a ceiling. The counter is the authority; the check is never a separate
 * read. A rejected claim releases every counter it touched, so it costs
 * nothing — and because a release is a paired decrement of this call's own
 * increment, the counter never drifts down. The error direction is
 * deliberately fail-closed: an in-flight rejected claim can briefly make a
 * concurrent legitimate claim look over-budget, which denies one probe.
 * Over-admitting one is the failure this is built to prevent; over-denying
 * one is not a failure worth a lock. Stores without EVAL (the array store, in
 * tests) fall back to add+increment, which has the same cap guarantee but two
 * round trips and cannot re-assert the TTL.
 */
class ProbeBudget
{
    /**
     * KEYS[1] = counter key, ARGV[1] = cap, ARGV[2] = ttl seconds.
     *
     * Increments first (the correct order — see class docblock), then
     * guarantees the key carries a TTL on every path (fresh key or one that
     * expired between a previous call's steps), then releases over-cap. No
     * path can leave the key without an expiry, satisfying the volatile-lru
     * TTL invariant by construction.
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

    /** Probes spent by the CURRENT run. Request/job-scoped, like FetchBudget. */
    private int $spentThisRun = 0;

    public function tryClaim(?string $userId): bool
    {
        if ($this->spentThisRun >= self::perRunCap()) {
            return false;
        }

        $globalCap = self::globalDailyCap();
        $userCap = self::userDailyCap();
        $date = now()->format('Y-m-d');
        $ttlSeconds = 86400;

        $globalKey = CacheKeyGenerator::routingProbeGlobalDaily($date);

        if (! $this->claimDaily($globalKey, $globalCap, $ttlSeconds)) {
            return false;
        }

        // Pre-account and staff builds have no user to charge; the global and
        // per-run ceilings still bound them.
        if ($userId !== null) {
            $userKey = CacheKeyGenerator::routingProbeUserDaily($userId, $date);

            if (! $this->claimDaily($userKey, $userCap, $ttlSeconds)) {
                $this->releaseDaily($globalKey, $ttlSeconds);

                return false;
            }
        }

        $this->spentThisRun++;

        return true;
    }

    /**
     * Claim one unit of a capped, TTL'd daily counter. Redis stores run the
     * whole thing as a single EVAL (atomic, single round trip, TTL
     * guaranteed on every path). Stores without EVAL (the array store, in
     * tests) fall back to the two-round-trip add+increment+rollback form,
     * which shares the cap guarantee but not the TTL guarantee — acceptable
     * because it never runs against real Redis.
     *
     * Gated on the resolved STORE INSTANCE (`Cache::getStore()`), not on
     * `config('cache.default') === 'redis'` — a failover store or an octane
     * wrapper would lie about that. `Cache::getStore()` is the exact store
     * object the bare `Cache::get`/`add`/`increment` calls elsewhere in this
     * class resolve to, so gating on it (rather than a separately-fetched
     * `Cache::store('redis')`) guarantees the EVAL runs against the identical
     * connection and key prefix `exhaustedDimension()` reads back through —
     * get this wrong and the counters silently split in two.
     */
    private function claimDaily(string $key, int $cap, int $ttlSeconds): bool
    {
        $store = Cache::getStore();

        if (! $store instanceof RedisStore) {
            return $this->claimDailyFallback($key, $cap, $ttlSeconds);
        }

        $prefixedKey = $store->getPrefix().$key;

        // Redis Lua EVAL (server-side atomicity primitive), not a PHP eval —
        // the script is the fixed literal in self::CLAIM_SCRIPT, no
        // interpolated or user-controlled code ever reaches it.
        $result = $store->connection()->eval(self::CLAIM_SCRIPT, 1, $prefixedKey, $cap, $ttlSeconds);

        return (int) $result === 1;
    }

    /** Paired release for {@see claimDaily()} — used when a later dimension rejects. */
    private function releaseDaily(string $key, int $ttlSeconds): void
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

    /** Two-round-trip fallback for stores without EVAL (array/file — test-only). */
    private function claimDailyFallback(string $key, int $cap, int $ttlSeconds): bool
    {
        Cache::add($key, 0, now()->addSeconds($ttlSeconds));
        $count = Cache::increment($key);

        if ($count > $cap) {
            Cache::decrement($key);

            return false;
        }

        return true;
    }

    /** Which ceiling stopped the claim — for the run card and the observation. */
    public function exhaustedDimension(?string $userId): string
    {
        if ($this->spentThisRun >= self::perRunCap()) {
            return 'per_run';
        }

        $date = now()->format('Y-m-d');

        if ((int) Cache::get(CacheKeyGenerator::routingProbeGlobalDaily($date), 0) >= self::globalDailyCap()) {
            return 'global_daily';
        }

        if ($userId !== null
            && (int) Cache::get(CacheKeyGenerator::routingProbeUserDaily($userId, $date), 0) >= self::userDailyCap()) {
            return 'user_daily';
        }

        return 'unknown';
    }

    public function spentThisRun(): int
    {
        return $this->spentThisRun;
    }

    /**
     * A batch importer routes many links through one worker; the per-run
     * dimension only means anything if the run says where it starts.
     */
    public function startRun(): void
    {
        $this->spentThisRun = 0;
    }

    public static function perRunCap(): int
    {
        return (int) config('partna.routing.probe.per_run_cap', 6);
    }

    public static function globalDailyCap(): int
    {
        return (int) config('partna.routing.probe.global_daily_cap', 2000);
    }

    public static function userDailyCap(): int
    {
        return (int) config('partna.routing.probe.user_daily_cap', 40);
    }
}
