<?php

use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Cache\SiteCacheService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Mockery as M;

beforeEach(function () {
    Cache::flush();
    $this->service = new SiteCacheService(new CacheLockService);
});

afterEach(function () {
    M::close();
});

// Helpers ─────────────────────────────────────────────────────────────────────

/**
 * Minimal payload array that passes the cache healing checks in getPublicSitePayload
 * (must contain a 'services' key so the backward-compat branch returns early).
 *
 * @return array<string, mixed>
 */
function minimalCachedPayload(): array
{
    return [
        'published' => true,
        'services' => [],
        'links' => [],
        'sections' => [],
        'blocks' => [],
        'site' => null,
        'professional' => null,
        'theme' => null,
        'legal' => null,
        'store' => null,
        'selected_products' => [],
        'default_commission_rate' => 15.0,
        'max_featured_products' => 12,
        'checkout_mode' => 'shopify',
    ];
}

/**
 * Stub the dedicated 'cache_locks' store so Cache::store('cache_locks')->lock(...)
 * resolves to the given Lock mock. SiteCacheService routes fill locks to that store
 * rather than the default cache store (commit e5f5fdcf), so tests must mock the
 * store hop — not Cache::lock() directly.
 */
function stubFillLock(string $subdomain, Lock $lock): void
{
    $store = M::mock();
    $store->shouldReceive('lock')->with('site:fill:'.$subdomain, 10)->once()->andReturn($lock);
    Cache::shouldReceive('store')->with('cache_locks')->andReturn($store);
}

// Tests ───────────────────────────────────────────────────────────────────────

it('returns null on lock timeout when MISS_SENTINEL is already cached', function () {
    $subdomain = 'test-sentinel';
    $key = CacheKeyGenerator::publicSitePayload($subdomain);

    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once()->andThrow(new LockTimeoutException);

    // Flow: primary miss → :stale miss (no SWR) → blocking lock → timeout → re-check returns sentinel.
    Cache::shouldReceive('get')->with($key)->twice()->andReturn(null, '__MISS__');
    Cache::shouldReceive('get')->with($key.':stale')->once()->andReturn(null);
    stubFillLock($subdomain, $lock);

    $result = $this->service->getPublicSitePayload($subdomain);

    expect($result)->toBeNull();
});

it('returns cached payload on lock timeout when cache fills during the wait', function () {
    $subdomain = 'test-warm';
    $key = CacheKeyGenerator::publicSitePayload($subdomain);
    $payload = minimalCachedPayload();

    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once()->andThrow(new LockTimeoutException);

    // Flow: primary miss → :stale miss (no SWR) → blocking lock → timeout → re-check finds payload.
    Cache::shouldReceive('get')->with($key)->twice()->andReturn(null, $payload);
    Cache::shouldReceive('get')->with($key.':stale')->once()->andReturn(null);
    stubFillLock($subdomain, $lock);

    $result = $this->service->getPublicSitePayload($subdomain);

    expect($result)->toBe($payload);
});

it('falls through to compute on lock timeout when cache is still empty (CACHE-4 fix)', function () {
    // Verifies the fix: rather than returning null immediately when the lock times
    // out and the cache re-check is still empty, the service now calls
    // buildPayloadFromDb() as a last resort — matching CacheLockService::rememberLocked.
    //
    // We subclass SiteCacheService to spy on buildPayloadFromDb (which would otherwise
    // require a real pgsql DB). The spy returns a valid payload so we can assert the
    // result was NOT null, proving the code did not short-circuit before reaching it.
    $subdomain = 'test-fallback';
    $key = CacheKeyGenerator::publicSitePayload($subdomain);

    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once()->andThrow(new LockTimeoutException);

    // Primary reads (twice) and stale read (once) all return null → cold-miss path → lock timeout → fallthrough compute.
    Cache::shouldReceive('get')->with($key)->twice()->andReturn(null, null);
    Cache::shouldReceive('get')->with($key.':stale')->once()->andReturn(null);
    stubFillLock($subdomain, $lock);

    $computeReached = false;
    $fakePayload = minimalCachedPayload();

    // Anonymous subclass stubs buildPayloadFromDb so the test does not need a real DB.
    $service = new class(new CacheLockService, $fakePayload, $computeReached) extends SiteCacheService
    {
        public function __construct(
            CacheLockService $lock,
            private array $fakePayload,
            public bool &$computeReached,
        ) {
            parent::__construct($lock);
        }

        protected function buildPayloadFromDb(string $subdomain, string $key): ?array
        {
            $this->computeReached = true;

            return $this->fakePayload;
        }
    };

    $result = $service->getPublicSitePayload($subdomain);

    expect($computeReached)->toBeTrue()
        ->and($result)->toBe($fakePayload);
});

it('returns stale payload immediately when primary expired and another worker is recomputing (CACHE-2 SWR)', function () {
    // CACHE-2: SWR fast path. Primary key is gone but :stale survives, AND another worker
    // already holds the fill lock — so this request must return the stale value without
    // blocking, never reaching buildPayloadFromDb.
    $subdomain = 'test-swr-stale';
    $key = CacheKeyGenerator::publicSitePayload($subdomain);
    $stalePayload = minimalCachedPayload();

    $lock = M::mock(Lock::class);
    // Non-blocking attempt fails → another worker is recomputing.
    $lock->shouldReceive('get')->withNoArgs()->once()->andReturn(false);
    $lock->shouldNotReceive('block');
    $lock->shouldNotReceive('release');

    Cache::shouldReceive('get')->with($key)->once()->andReturn(null);
    Cache::shouldReceive('get')->with($key.':stale')->once()->andReturn($stalePayload);
    stubFillLock($subdomain, $lock);

    $result = $this->service->getPublicSitePayload($subdomain);

    expect($result)->toBe($stalePayload);
});

it('writePayloadWithStale writes stale key with jittered TTL — not fixed at PAYLOAD_STALE_TTL_MULTIPLIER × base', function () {
    // Invoke the private writePayloadWithStale directly via reflection so we can run
    // 20 iterations without needing a real DB (buildPayloadFromDb requires PostgreSQL).
    $staleTtls = [];
    $service = new SiteCacheService(new CacheLockService);
    $method = new ReflectionMethod(SiteCacheService::class, 'writePayloadWithStale');

    for ($i = 0; $i < 20; $i++) {
        Cache::shouldReceive('put')
            ->with("site:stale-jitter:$i", 'v', M::type('int'))
            ->once();
        Cache::shouldReceive('put')
            ->with("site:stale-jitter:$i:stale", 'v', M::on(function ($ttl) use (&$staleTtls) {
                $staleTtls[] = $ttl;

                return true;
            }))
            ->once();

        $method->invoke($service, "site:stale-jitter:$i", 'v');
    }

    // base=900s; stale range = 900×10×[0.8,1.2] = [7200,10800].
    // Fixed stale (no jitter): all values identical → count(array_unique) = 1 → fails.
    expect(count(array_unique($staleTtls)))->toBeGreaterThan(1);

    $base = (int) config('partna.cache.ttls.public_payload');
    foreach ($staleTtls as $ttl) {
        expect($ttl)->toBeGreaterThanOrEqual((int) round($base * 10 * 0.8))
            ->and($ttl)->toBeLessThanOrEqual((int) round($base * 10 * 1.2));
    }
});

it('returns null from stale MISS_SENTINEL when primary expired and another worker is recomputing', function () {
    // Same SWR fast path but stale carries the negative-cache sentinel — public 404
    // must persist (don't expose a non-existent subdomain to the DB just because
    // primary expired).
    $subdomain = 'test-swr-miss';
    $key = CacheKeyGenerator::publicSitePayload($subdomain);

    $lock = M::mock(Lock::class);
    $lock->shouldReceive('get')->withNoArgs()->once()->andReturn(false);

    Cache::shouldReceive('get')->with($key)->once()->andReturn(null);
    Cache::shouldReceive('get')->with($key.':stale')->once()->andReturn('__MISS__');
    stubFillLock($subdomain, $lock);

    $result = $this->service->getPublicSitePayload($subdomain);

    expect($result)->toBeNull();
});

it('B14/P2-10: SWR no-lock branch heals a valid stale payload (missing legal backfilled)', function () {
    // CACHE-1 healing: stale payload has 'services' but is missing 'legal' (pre-V2-strip
    // shape). The SWR no-lock branch must apply the same healing as the primary-hit path:
    // backfill 'legal' and return the corrected payload — not the raw stale.
    $subdomain = 'test-swr-heal';
    $key = CacheKeyGenerator::publicSitePayload($subdomain);

    $staleWithoutLegal = minimalCachedPayload();
    unset($staleWithoutLegal['legal']); // simulate pre-V2 cached entry

    $lock = M::mock(Lock::class);
    $lock->shouldReceive('get')->withNoArgs()->once()->andReturn(false); // another worker holds lock

    Cache::shouldReceive('get')->with($key)->once()->andReturn(null);
    Cache::shouldReceive('get')->with($key.':stale')->once()->andReturn($staleWithoutLegal);
    stubFillLock($subdomain, $lock);

    $result = $this->service->getPublicSitePayload($subdomain);

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('legal')
        ->and($result['legal'])->toBeNull();
});

it('B14/P2-10: SWR no-lock branch forces rebuild when stale holds old payload shape (no services key)', function () {
    // Old stale (pre-V2-strip): missing 'services' entirely. Must forget both keys and
    // rebuild from DB rather than serve a broken response.
    $subdomain = 'test-swr-old-shape';
    $key = CacheKeyGenerator::publicSitePayload($subdomain);

    $oldShapeStale = ['published' => true, 'site' => null]; // no 'services' key
    $rebuiltPayload = minimalCachedPayload();
    $buildReached = false;

    // Subclass stubs buildPayloadFromDb — we verify it was called (no real DB needed).
    $service = new class(new CacheLockService, $rebuiltPayload, $buildReached) extends SiteCacheService
    {
        public function __construct(
            CacheLockService $lock,
            private array $rebuiltPayload,
            public bool &$buildReached,
        ) {
            parent::__construct($lock);
        }

        protected function buildPayloadFromDb(string $subdomain, string $key): ?array
        {
            $this->buildReached = true;

            return $this->rebuiltPayload;
        }
    };

    $lock = M::mock(Lock::class);
    $lock->shouldReceive('get')->withNoArgs()->once()->andReturn(false);

    Cache::shouldReceive('get')->with($key)->once()->andReturn(null);
    Cache::shouldReceive('get')->with($key.':stale')->once()->andReturn($oldShapeStale);
    stubFillLock($subdomain, $lock);
    // Must clear both keys before rebuilding.
    Cache::shouldReceive('forget')->with($key)->once();
    Cache::shouldReceive('forget')->with($key.':stale')->once();

    $result = $service->getPublicSitePayload($subdomain);

    expect($buildReached)->toBeTrue()
        ->and($result)->toBe($rebuiltPayload);
});
