<?php

use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Cache\SiteCacheService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Mockery as M;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// cache-invalidation/CCH-3: when a stale-while-revalidate lock-winner's recompute
// throws (a transient DB/Redis blip), the request must serve the known-good stale
// value instead of failing — every OTHER concurrent request in the same window
// already takes the "another worker is refreshing" branch and gets $stale, so the
// lock-winner deserves the same treatment rather than being the one unlucky 500.
// The negative case (no stale to fall back to) MUST still throw — this fix does
// not turn a broken cache into a silent "serve stale forever".

beforeEach(function () {
    Cache::flush();
});

afterEach(function () {
    M::close();
});

/** Stub Cache::store('cache_locks')->lock(...) — SiteCacheService's fill-lock hop. */
function stubSwrFillLockForCch3(string $subdomain, Lock $lock): void
{
    $store = M::mock();
    $store->shouldReceive('lock')->with('site:fill:'.$subdomain, 10)->once()->andReturn($lock);
    Cache::shouldReceive('store')->with('cache_locks')->andReturn($store);
}

/** Minimal payload shape that passes SiteCacheService's cache-healing checks. */
function cch3MinimalPayload(): array
{
    return [
        'published' => true,
        'services' => [],
        'links' => [],
        'sections' => [],
        'blocks' => [],
        'site' => null,
        'professional' => null,
        'legal' => null,
    ];
}

// --- CacheLockService::rememberLocked ---------------------------------------

it('CacheLockService returns the stale value when the lock-winners recompute throws (CCH-3)', function () {
    Exceptions::fake();

    $stalePayload = ['stale' => 'value'];
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('get')->withNoArgs()->once()->andReturn(true);
    $lock->shouldReceive('release')->once()->andReturn(true);

    // Fast-path primary miss, then the re-check inside the lock — both null.
    Cache::shouldReceive('get')->with('test:cch3')->twice()->andReturn(null, null);
    Cache::shouldReceive('get')->with('test:cch3:stale')->once()->andReturn($stalePayload);
    Cache::shouldReceive('lock')->with('lock:test:cch3', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->never(); // recompute never wrote — it threw before writeWithJitter

    $service = new CacheLockService;
    $result = $service->rememberLocked(
        'test:cch3',
        60,
        fn () => throw new RuntimeException('recompute blew up'),
    );

    expect($result)->toBe($stalePayload);
    Exceptions::assertReported(RuntimeException::class);
});

it('CacheLockService still throws when the recompute fails and there is no stale value (CCH-3 negative)', function () {
    // Cold miss: no stale copy exists at all, so this falls to the pre-existing
    // blocking-lock cold-miss path — untouched by the CCH-3 fix — where a
    // callback exception is NOT caught. Proves the fix does not mask a
    // genuinely broken cache/DB when there's nothing safe to fall back to.
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    Cache::shouldReceive('get')->with('test:cch3-negative')->twice()->andReturn(null, null);
    Cache::shouldReceive('get')->with('test:cch3-negative:stale')->once()->andReturn(null);
    Cache::shouldReceive('lock')->with('lock:test:cch3-negative', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->never();

    $service = new CacheLockService;
    $call = fn () => $service->rememberLocked(
        'test:cch3-negative',
        60,
        fn () => throw new RuntimeException('no stale to save us'),
    );

    expect($call)->toThrow(RuntimeException::class, 'no stale to save us');
});

// --- SiteCacheService::getPublicSitePayload ---------------------------------

it('SiteCacheService serves the healed stale payload when the lock-winners recompute throws (CCH-3)', function () {
    Exceptions::fake();

    $subdomain = 'test-cch3-site';
    $key = CacheKeyGenerator::publicSitePayload($subdomain);
    $stalePayload = cch3MinimalPayload();

    $lock = M::mock(Lock::class);
    $lock->shouldReceive('get')->withNoArgs()->once()->andReturn(true);
    $lock->shouldReceive('release')->once()->andReturn(true);

    Cache::shouldReceive('get')->with($key)->twice()->andReturn(null, null);
    Cache::shouldReceive('get')->with($key.':stale')->once()->andReturn($stalePayload);
    stubSwrFillLockForCch3($subdomain, $lock);

    // buildPayloadFromDb requires a real Postgres view — subclass to force the
    // recompute to throw without needing a live DB.
    $service = new class(new CacheLockService) extends SiteCacheService
    {
        protected function buildPayloadFromDb(string $subdomain, string $key): ?array
        {
            throw new RuntimeException('db blew up mid-recompute');
        }
    };

    $result = $service->getPublicSitePayload($subdomain);

    // Ran through the SAME healing ladder used by the "another worker refreshing"
    // branch — not a raw passthrough of $stale.
    expect($result)->toEqual($stalePayload);
    Exceptions::assertReported(RuntimeException::class);
});

it('SiteCacheService still throws when the recompute fails and there is no stale payload (CCH-3 negative)', function () {
    // Cold miss: no stale copy — falls to the pre-existing cold-miss path
    // (untouched by CCH-3), where buildPayloadFromDb's exception is NOT caught.
    $subdomain = 'test-cch3-site-negative';
    $key = CacheKeyGenerator::publicSitePayload($subdomain);

    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    Cache::shouldReceive('get')->with($key)->twice()->andReturn(null, null);
    Cache::shouldReceive('get')->with($key.':stale')->once()->andReturn(null);
    stubSwrFillLockForCch3($subdomain, $lock);

    $service = new class(new CacheLockService) extends SiteCacheService
    {
        protected function buildPayloadFromDb(string $subdomain, string $key): ?array
        {
            throw new RuntimeException('no stale to save us');
        }
    };

    $call = fn () => $service->getPublicSitePayload($subdomain);

    expect($call)->toThrow(RuntimeException::class, 'no stale to save us');
});
