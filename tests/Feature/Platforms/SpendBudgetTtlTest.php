<?php

// TTL coverage for the three PAID spend budgets, mirroring what
// ProbeBudgetConcurrencyTest pins for the (keyless) probe budget.
//
// All three carried the identical defect Unit 6 found in ProbeBudget: a
// two-round-trip Cache::add + Cache::increment pair. If the counter key
// expired between the two calls, INCRBY silently recreated it with NO TTL.
// Under this repo's instance-wide volatile-lru policy (CLAUDE.md) a TTL-less
// key is permanent, inevictable ballast — and cache shares the Valkey
// instance with the queue, so that ballast competes with Horizon job state.
// CacheKeyspaceConstraintsTest cannot catch it: it greps for the literal
// forever() form and concedes a raw write with no paired expiry is not
// statically checkable.
//
// Runs against REAL Redis — the bug only exists in Redis semantics, and both
// phpunit lanes otherwise pin CACHE_STORE=array, under which it cannot occur.
// Never calls Cache::flush() (a raw FLUSHDB per CLAUDE.md); cleanup forgets
// only the exact keys this suite touches, through the Cache facade so the
// prefixing path matches production's.

use App\Services\Cache\AiSpendBudget;
use App\Services\Cache\ApifyBudget;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\PlacesBudget;
use App\Services\Cache\PlacesClaim;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

function spendBudgetRedisReachable(): bool
{
    try {
        Redis::connection('cache')->ping();

        return true;
    } catch (Throwable) {
        return false;
    }
}

beforeEach(function () {
    if (! spendBudgetRedisReachable()) {
        $this->markTestSkipped('requires a reachable Redis (ping failed against the `cache` connection)');
    }

    config(['cache.default' => 'redis']);

    $date = now()->format('Y-m-d');
    $this->spendKeys = [
        CacheKeyGenerator::apifyGlobalDailyLimit($date),
        CacheKeyGenerator::apifyActorDailyLimit('instagram', $date),
        CacheKeyGenerator::aiSpendGlobalDailyLimit($date),
        CacheKeyGenerator::aiSpendActorDailyLimit('mistral_ocr', $date),
        CacheKeyGenerator::placesGlobalDailyLimit($date),
        CacheKeyGenerator::placesSkuDailyLimit('details', $date),
        CacheKeyGenerator::placesUserDailyLimit('budget-ttl-user', $date),
    ];

    // Date-scoped, not test-scoped — shared with any other run against this
    // Redis on the same calendar day, so start from a known-clean counter.
    foreach ($this->spendKeys as $key) {
        Cache::forget($key);
    }
});

afterEach(function () {
    foreach ($this->spendKeys ?? [] as $key) {
        Cache::forget($key);
    }
});

/**
 * Run $claim with the key expiring in the exact gap the old two-round-trip
 * form had between its add() and its increment(), then report the key's TTL.
 *
 * The decorator deletes $raceKey immediately before delegating to the real
 * increment(), which is the state old code's INCRBY saw. New code never calls
 * Cache::increment() on the Redis path at all — it does one EVAL — so the
 * decorator is inert against it. That asymmetry is the point: the new form
 * structurally has no gap to race, so the assertion below discriminates the
 * two implementations rather than merely passing for both.
 */
function ttlAfterRacedClaim(string $raceKey, callable $claim): int
{
    // Seed as already-established (mid-day, mid-window) so an add()-based
    // implementation's SETNX legitimately no-ops, exactly as it would have
    // mid-day in production. Without this the old code's add() would recreate
    // the key with a fresh TTL and the test would not discriminate.
    Cache::put($raceKey, 3, now()->addDay());

    $realCacheManager = Cache::getFacadeRoot();
    $raceInjectingCache = new class($realCacheManager, $raceKey)
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
        $claim();
    } finally {
        Cache::swap($realCacheManager);
    }

    $store = Cache::getStore();

    return (int) $store->connection()->ttl($store->getPrefix().$raceKey);
}

it('keeps a TTL on the Apify counter when the key expires mid-claim', function () {
    config([
        'partna.limits.apify.global_daily_cap' => 500,
        'partna.limits.apify.actors.instagram' => 500,
    ]);

    $key = CacheKeyGenerator::apifyGlobalDailyLimit(now()->format('Y-m-d'));

    $ttl = ttlAfterRacedClaim($key, fn () => (new ApifyBudget)->tryClaim('instagram'));

    expect($ttl)->toBeGreaterThan(0);
});

it('keeps a TTL on the AI-spend counter when the key expires mid-claim', function () {
    config([
        'partna.limits.ai_spend.global_daily_cap' => 500,
        'partna.limits.ai_spend.actors.mistral_ocr' => 500,
    ]);

    $key = CacheKeyGenerator::aiSpendGlobalDailyLimit(now()->format('Y-m-d'));

    $ttl = ttlAfterRacedClaim($key, fn () => (new AiSpendBudget)->tryClaim('mistral_ocr'));

    expect($ttl)->toBeGreaterThan(0);
});

it('keeps a TTL on the Places counter when the key expires mid-claim', function () {
    // PlacesBudget claims the USER dimension first, so that is the counter
    // whose own two round trips the race has to land between.
    config([
        'partna.limits.places.global_daily_cap' => 500,
        'partna.limits.places.skus.details' => 500,
        'partna.limits.places.per_user_daily_cap' => 500,
    ]);

    $key = CacheKeyGenerator::placesUserDailyLimit('budget-ttl-user', now()->format('Y-m-d'));

    $ttl = ttlAfterRacedClaim($key, fn () => (new PlacesBudget)->claim('details', 'budget-ttl-user'));

    expect($ttl)->toBeGreaterThan(0);
});

// ── Admission behaviour is unchanged by the rewrite ─────────────────────────

it('still denies an Apify claim over either ceiling and releases what it took', function () {
    config([
        'partna.limits.apify.global_daily_cap' => 50,
        'partna.limits.apify.actors.instagram' => 1,
    ]);

    $budget = new ApifyBudget;
    $globalKey = CacheKeyGenerator::apifyGlobalDailyLimit(now()->format('Y-m-d'));

    expect($budget->tryClaim('instagram'))->toBeTrue()
        ->and($budget->tryClaim('instagram'))->toBeFalse();

    // The denied claim must give the GLOBAL counter back — it only failed on
    // the actor ceiling, so leaving global incremented would bleed the shared
    // allowance away from every other actor, one denied claim at a time.
    expect((int) Cache::get($globalKey, 0))->toBe(1);
});

it('reports the user ceiling ahead of the platform ceiling, and releases nothing it did not take', function () {
    // Precedence the previous increment-all-three-then-check form gave by
    // testing $userCount first; the sequential form has to preserve it.
    config([
        'partna.limits.places.global_daily_cap' => 500,
        'partna.limits.places.skus.details' => 500,
        'partna.limits.places.per_user_daily_cap' => 1,
    ]);

    $budget = new PlacesBudget;
    $globalKey = CacheKeyGenerator::placesGlobalDailyLimit(now()->format('Y-m-d'));

    expect($budget->claim('details', 'budget-ttl-user'))->toBe(PlacesClaim::Granted)
        ->and($budget->claim('details', 'budget-ttl-user'))->toBe(PlacesClaim::UserCapReached);

    // The second claim died on the FIRST dimension, so it never touched
    // global — the count must reflect one granted claim, not a rollback that
    // decremented a counter this call never incremented.
    expect((int) Cache::get($globalKey, 0))->toBe(1);
});
