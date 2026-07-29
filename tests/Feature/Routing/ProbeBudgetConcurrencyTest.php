<?php

// #TEST-8 concurrency + TTL coverage for ProbeBudget::tryClaim(). The finding
// filed against this method described a check-then-increment race; it is
// actually increment-then-check-then-rollback, which is provably safe against
// over-admission (see the plan/adjudication notes). The REAL defect in the
// old code was a two-round-trip Cache::add + Cache::increment pair: if the
// counter key expired between the two calls, INCRBY silently recreated it
// with no TTL — permanent, inevictable ballast under this repo's
// instance-wide volatile-lru policy (CLAUDE.md), one key per user per day.
//
// This suite runs against REAL Redis (not the array store both test lanes
// otherwise pin) because the bug being tested only exists in Redis semantics.
// It never touches Cache::flush() (a raw FLUSHDB per CLAUDE.md) — cleanup
// forgets only the exact keys this suite reads and writes, through the Cache
// facade (never raw connection/prefix arithmetic — that's what caused this
// suite's own first draft to misfire, see below).

use App\Routing\Probes\ProbeBudget;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

function probeBudgetRedisReachable(): bool
{
    try {
        Redis::connection('cache')->ping();

        return true;
    } catch (Throwable) {
        return false;
    }
}

beforeEach(function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl_fork is not available in this runtime');
    }

    if (! probeBudgetRedisReachable()) {
        $this->markTestSkipped('requires a reachable Redis (ping failed against the `cache` connection)');
    }

    // Point the app at the real Redis store for the duration of this test —
    // both phpunit lanes pin CACHE_STORE=array, which is exactly the store
    // under which the regression this suite pins cannot occur.
    config(['cache.default' => 'redis']);

    // The global daily key is date-scoped, not test-scoped (ProbeBudget has
    // no namespacing hook), so it is shared across every run against this
    // Redis on the same calendar day. Reset it through the Cache facade
    // (same prefixing path production code uses) rather than raw KEYS/DEL,
    // which double-applies the phpredis client-level prefix on top of
    // RedisStore's own prefix and silently misses the real key.
    $this->probeBudgetGlobalKey = CacheKeyGenerator::routingProbeGlobalDaily(now()->format('Y-m-d'));
    Cache::forget($this->probeBudgetGlobalKey);
    $this->probeBudgetUserKeys = [];
});

afterEach(function () {
    Cache::forget($this->probeBudgetGlobalKey);

    foreach ($this->probeBudgetUserKeys as $userKey) {
        Cache::forget($userKey);
    }
});

it('never lets total successful claims exceed the global daily cap under real concurrency', function () {
    $cap = 20;
    $childCount = 8;
    $attemptsPerChild = 10; // 80 attempts total against a cap of 20

    config(['partna.routing.probe.global_daily_cap' => $cap]);
    config(['partna.routing.probe.per_run_cap' => $attemptsPerChild]);

    $resultKeyPrefix = 'test:probe-budget-concurrency:'.bin2hex(random_bytes(6)).':';
    $pids = [];

    for ($i = 0; $i < $childCount; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            // Child process — a forked phpredis socket shared with the
            // parent corrupts in a way that looks exactly like the bug
            // under test, so drop the inherited connection and let the
            // next Cache:: call lazily open a fresh one.
            Redis::purge('cache');

            $budget = new ProbeBudget;
            $budget->startRun();
            $successes = 0;

            for ($j = 0; $j < $attemptsPerChild; $j++) {
                if ($budget->tryClaim(null)) {
                    $successes++;
                }
            }

            Cache::put($resultKeyPrefix.$i, $successes, now()->addMinute());

            exit(0);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $totalSuccesses = 0;
    for ($i = 0; $i < $childCount; $i++) {
        $totalSuccesses += (int) Cache::pull($resultKeyPrefix.$i, 0);
    }

    // The over-admission assertion — the one that would have failed had the
    // finding's "check-then-increment race" actually described this code.
    expect($totalSuccesses)->toBe($cap);

    // No drift from an unpaired rollback.
    expect((int) Cache::get($this->probeBudgetGlobalKey, 0))->toBe($cap);

    // The assertion this test exists for: the counter key must carry a TTL.
    // Resolved the same way production code resolves it — through the store,
    // not a hand-built connection/prefix pair.
    $store = Cache::getStore();
    $ttl = $store->connection()->ttl($store->getPrefix().$this->probeBudgetGlobalKey);
    expect($ttl)->toBeGreaterThan(0);
});

it('releases the global counter when the user-daily cap rejects, under real concurrency', function () {
    $userCap = 5;
    $childCount = 4;
    $attemptsPerChild = 5; // 20 attempts total, one shared user, cap of 5

    config(['partna.routing.probe.user_daily_cap' => $userCap]);
    config(['partna.routing.probe.global_daily_cap' => 2000]);
    config(['partna.routing.probe.per_run_cap' => $attemptsPerChild]);

    $userId = 'probe-budget-concurrency-user-'.bin2hex(random_bytes(6));
    $userKey = CacheKeyGenerator::routingProbeUserDaily($userId, now()->format('Y-m-d'));
    $this->probeBudgetUserKeys[] = $userKey;

    $resultKeyPrefix = 'test:probe-budget-user-concurrency:'.bin2hex(random_bytes(6)).':';
    $pids = [];

    for ($i = 0; $i < $childCount; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            Redis::purge('cache');

            $budget = new ProbeBudget;
            $budget->startRun();
            $successes = 0;

            for ($j = 0; $j < $attemptsPerChild; $j++) {
                if ($budget->tryClaim($userId)) {
                    $successes++;
                }
            }

            Cache::put($resultKeyPrefix.$i, $successes, now()->addMinute());

            exit(0);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $totalSuccesses = 0;
    for ($i = 0; $i < $childCount; $i++) {
        $totalSuccesses += (int) Cache::pull($resultKeyPrefix.$i, 0);
    }

    // Exactly the user cap admitted.
    expect($totalSuccesses)->toBe($userCap);
    expect((int) Cache::get($userKey, 0))->toBe($userCap);

    // Every rejection by the user dimension must have released its paired
    // global increment — no drift, no leak. The global counter must equal
    // the user counter exactly (nothing else is claiming against it here).
    expect((int) Cache::get($this->probeBudgetGlobalKey, 0))->toBe($userCap);
});

it('recovers TTL when the key expires between a full DEL and the next claim (secondary coverage)', function () {
    config(['partna.routing.probe.global_daily_cap' => 2000]);
    config(['partna.routing.probe.per_run_cap' => 10]);

    $budget = new ProbeBudget;
    $budget->startRun();

    // First claim establishes the key with its TTL.
    expect($budget->tryClaim(null))->toBeTrue();

    $store = Cache::getStore();
    $prefixedKey = $store->getPrefix().$this->probeBudgetGlobalKey;
    $store->connection()->del($prefixedKey);

    // NOTE: this scenario alone does NOT discriminate old vs new code —
    // Cache::add() legitimately re-creates the key with a fresh TTL when it
    // finds the key fully absent, so the old two-round-trip form handles a
    // full prior DEL correctly too (verified empirically while writing this
    // suite). It's kept as secondary coverage of the "key vanished before
    // any part of this claim ran" case. The real regression — the key
    // vanishing IN BETWEEN the old code's own two round trips — is pinned by
    // the next test, which injects the race at that exact boundary.
    expect($budget->tryClaim(null))->toBeTrue();

    $ttl = $store->connection()->ttl($prefixedKey);
    expect($ttl)->toBeGreaterThan(0);
});

it('re-asserts the TTL when the key expires in the gap between the counter\'s own two round trips (the actual #TEST-8 regression)', function () {
    config(['partna.routing.probe.global_daily_cap' => 2000]);
    config(['partna.routing.probe.per_run_cap' => 10]);

    $globalKey = $this->probeBudgetGlobalKey;

    // Seed the counter as already-established (mid-day, mid-window) so that
    // an add()-based implementation's Cache::add() legitimately no-ops
    // (SETNX sees the key present) — exactly the state old ProbeBudget code
    // saw right before its increment() call.
    Cache::put($globalKey, 3, now()->addDay());

    // A decorator around the real Cache manager that injects the race
    // deterministically: whichever code path calls Cache::increment() next
    // finds the key already gone, simulating the key expiring in the narrow
    // window between an add() call (already run, above) and the increment()
    // call. New code's Redis path never calls Cache::increment() at all (it
    // does one EVAL instead), so this decorator is a no-op for new code —
    // which is the point: the new code structurally has no such gap to race.
    $realCacheManager = Cache::getFacadeRoot();
    $raceInjectingCache = new class($realCacheManager, $globalKey)
    {
        public function __construct(private $real, private string $raceKey) {}

        public function increment($key, $value = 1)
        {
            if ($key === $this->raceKey) {
                $this->real->forget($key);
            }

            return $this->real->increment($key, $value);
        }

        public function __call($method, $args)
        {
            return $this->real->{$method}(...$args);
        }
    };

    Cache::swap($raceInjectingCache);

    try {
        $budget = new ProbeBudget;
        $budget->startRun();

        expect($budget->tryClaim(null))->toBeTrue();
    } finally {
        Cache::swap($realCacheManager);
    }

    $store = Cache::getStore();
    $ttl = $store->connection()->ttl($store->getPrefix().$globalKey);

    // This is the assertion that fails against the old two-round-trip code:
    // its increment() call finds a gone key (because our decorator deleted
    // it right before delegating to the real increment), and a bare INCRBY
    // recreates the key with no TTL. New code's single EVAL asserts the TTL
    // on every path, so this passes for it regardless.
    expect($ttl)->toBeGreaterThan(0);
});
