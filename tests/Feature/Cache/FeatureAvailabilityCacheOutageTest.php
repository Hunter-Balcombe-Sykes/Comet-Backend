<?php

use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\FeatureAvailability\UserFeatureAvailability;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FeatureAvailability::for() when its cache store misbehaves.
 *
 * THREE requirements. The first two pull in opposite directions, and the third is
 * the trap that catches a fix satisfying both — which is why each has its own
 * test rather than being folded together:
 *
 *   1. It must not THROW. Drill 03 (2026-08-05) found `GET /api/site` returning a
 *      raw 500 during a Redis outage whenever the rate limiter did not preempt
 *      it. Root cause: `for()` wrapped its `rememberLocked` call in a try/catch,
 *      but the two `Cache::get()` calls ABOVE that try were unguarded, so a dead
 *      store threw straight past the fail-open handler.
 *
 *   2. It must not FAIL OPEN either. A dead cache is not a dead database.
 *      `CacheLockService::rememberLocked` absorbs store faults and falls through
 *      to `computeWithoutCache()`, which queries Postgres directly — so the real
 *      rule set is still reachable. Returning "everything available" here would
 *      silently lift every staff-disabled feature for the outage's duration.
 *
 *   3. It must not GUESS A NAMESPACE either. The obvious way to satisfy 1 and 2
 *      is `$version = 0` plus fall-through — but the catch fires on ANY throwable,
 *      including the transient shape where op 1 fails and ops 2..N succeed. v0 is
 *      a LIVE namespace, so that serves a pre-flush snapshot and lifts the very
 *      kill-switch requirement 2 protects. Both earlier drafts of this fix failed
 *      one of these three.
 *
 * Fail-open remains correct for a genuine DB fault, which `for()` handles in its
 * other catch — there we truly cannot know the answer.
 *
 * In production the limiter usually masks requirement 1 as a 503, which is why it
 * had never been seen as a 500. That masking is not a guarantee: the five
 * fail-open limiters (`public-site`, `public-profile`, `analytics`,
 * `analytics-click`, `health-check`) do NOT throw, and any route whose limiter
 * joins that list, or any request with throttling disabled, reaches this code
 * with a live exception.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    FeatureAvailability::flush();
});

// bindThrowingCacheStore() lives in tests/Pest.php — shared with StrictRevocationTest.

it('does not throw when the cache store is unreachable', function () {
    $pro = createTenant('feat-avail-outage');

    bindThrowingCacheStore();

    expect(FeatureAvailability::for($pro))->toBeInstanceOf(UserFeatureAvailability::class);
});

it('KEEPS a staff-disabled feature disabled when only the cache is dead', function () {
    // The finding that matters, and the reason this file exists in its current
    // shape. An earlier draft of the fix returned an empty override set the moment
    // the cache threw — which silently re-enables every staff-disabled feature and
    // integration (including kill-switches pulled for legal reasons) for the whole
    // outage.
    //
    // That draft justified itself with "rememberLocked would fault on the same
    // store anyway". It would not: CacheLockService::rememberLocked catches store
    // faults and falls through to computeWithoutCache(), which queries Postgres
    // directly. With a dead cache and a healthy DB the TRUE answer is one query
    // away, so failing open there was a self-inflicted security hole.
    //
    // Absence == enabled, so "disabled" is the only state a lost override set can
    // destroy — which makes it the only state worth asserting.
    setupFeatureAvailabilityTable();
    setupSegmentsTables();
    $pro = createTenant('feat-avail-killswitch');

    DB::connection('pgsql')->table('core.feature_availability')->insert([
        'id' => (string) Str::uuid(),
        'feature_key' => 'integration.fresha',
        'mode' => 'disabled',
        'segment_id' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    FeatureAvailability::flush();

    // Sanity: the rule bites with a healthy cache. Without this the assertion
    // below could pass because the rule never applied at all.
    expect(FeatureAvailability::for($pro)->allows('integration.fresha'))->toBeFalse();

    FeatureAvailability::flush();
    bindThrowingCacheStore();

    expect(FeatureAvailability::for($pro)->allows('integration.fresha'))->toBeFalse();
});

it('does not serve a stale namespace when only the VERSION read fails', function () {
    // The transient shape, which a dead-store fake cannot reproduce: op 1 throws,
    // Laravel reconnects, ops 2..N SUCCEED (GuardedPhpRedisConnection:52-54).
    //
    // A previous revision handled the version-read failure with `$version = 0` and
    // fell through to rememberLocked. v0 is a LIVE namespace, so that read the
    // `:v0` key while the real version was N — serving a pre-flush snapshot and
    // silently lifting a staff kill-switch. Reachable in queue workers in
    // particular, where ArmRedisRequestBreaker deliberately never arms, so nothing
    // stops op 2 succeeding; FeatureAvailability::for() runs in ConnectFetchJob,
    // ShopBrandConnectJob, FreshaConnectFetch and CustomLinkSeeder.
    //
    // The fix resolves directly instead of guessing a namespace, so this test
    // fails against `$version = 0` and passes against a direct resolve.
    setupFeatureAvailabilityTable();
    setupSegmentsTables();
    $pro = createTenant('feat-avail-stale-v0');

    // Truth in the DB: disabled.
    DB::connection('pgsql')->table('core.feature_availability')->insert([
        'id' => (string) Str::uuid(),
        'feature_key' => 'integration.fresha',
        'mode' => 'disabled',
        'segment_id' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // A stale pre-flush snapshot parked under v0 saying the opposite, and a live
    // version token well past it.
    Cache::put("feature-availability:user:{$pro->id}:v0", ['integration.fresha' => true], 600);
    Cache::put('feature-availability:version', 3, 600);

    // Fail ONLY the version read; everything else keeps working.
    $real = Cache::store();
    Cache::swap(new class($real) implements CacheRepository
    {
        public function __construct(private readonly CacheRepository $real) {}

        public function get($key, $default = null): mixed
        {
            if ($key === 'feature-availability:version') {
                throw new RedisException('read error on connection');
            }

            return $this->real->get($key, $default);
        }

        public function __call($method, $parameters)
        {
            return $this->real->{$method}(...$parameters);
        }

        public function put($key, $value, $ttl = null): bool
        {
            return $this->real->put($key, $value, $ttl);
        }

        public function add($key, $value, $ttl = null): bool
        {
            return $this->real->add($key, $value, $ttl);
        }

        public function increment($key, $value = 1)
        {
            return $this->real->increment($key, $value);
        }

        public function decrement($key, $value = 1)
        {
            return $this->real->decrement($key, $value);
        }

        public function forever($key, $value): bool
        {
            return $this->real->forever($key, $value);
        }

        public function forget($key): bool
        {
            return $this->real->forget($key);
        }

        public function has($key): bool
        {
            return $this->real->has($key);
        }

        public function many(array $keys): array
        {
            return $this->real->many($keys);
        }

        public function putMany(array $values, $ttl = null): bool
        {
            return $this->real->putMany($values, $ttl);
        }

        public function pull($key, $default = null): mixed
        {
            return $this->real->pull($key, $default);
        }

        public function remember($key, $ttl, Closure $callback): mixed
        {
            return $this->real->remember($key, $ttl, $callback);
        }

        public function sear($key, Closure $callback): mixed
        {
            return $this->real->sear($key, $callback);
        }

        public function rememberForever($key, Closure $callback): mixed
        {
            return $this->real->rememberForever($key, $callback);
        }

        public function missing($key): bool
        {
            return $this->real->missing($key);
        }

        public function clear(): bool
        {
            return $this->real->clear();
        }

        public function delete($key): bool
        {
            return $this->real->delete($key);
        }

        public function deleteMultiple($keys): bool
        {
            return $this->real->deleteMultiple($keys);
        }

        public function getMultiple($keys, $default = null): iterable
        {
            return $this->real->getMultiple($keys, $default);
        }

        public function setMultiple($values, $ttl = null): bool
        {
            return $this->real->setMultiple($values, $ttl);
        }

        public function set($key, $value, $ttl = null): bool
        {
            return $this->real->set($key, $value, $ttl);
        }

        public function getStore()
        {
            return $this->real->getStore();
        }
    });

    expect(FeatureAvailability::for($pro)->allows('integration.fresha'))->toBeFalse();
});

it('serves GET /api/site rather than 500ing when the cache store is unreachable', function () {
    // The route-level proof. This is the exact request drill 03 saw return 500.
    $pro = createTenant('feat-avail-site');   // creates the site.sites row too

    bindThrowingCacheStore();

    actingAsUser($pro)
        ->getJson('/api/site')
        ->assertOk();
});
