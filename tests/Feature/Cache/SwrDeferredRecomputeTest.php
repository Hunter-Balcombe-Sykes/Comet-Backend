<?php

use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Cache\SiteCacheService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Mockery as M;

// cache-gold-standard §2.3: the SWR lock-winner used to run the recompute
// in-request, so one visitor per refresh window absorbed the full rebuild
// latency while every other concurrent visitor was served stale instantly.
// It now hands the rebuild to defer() and returns stale like everyone else.
//
// The lock release moved INSIDE the deferred closure. That is the whole
// correctness story: release it at return instead and the next caller also
// wins the lock and defers its own rebuild, and single-flight is gone.

afterEach(function () {
    M::close();
});

describe('HTTP context', function () {
    beforeEach(function () {
        // runningInConsole() is computed once per Application instance, so the
        // container has to be rebuilt after the env var is set. Every Cache and
        // Mockery expectation must be registered AFTER this — refreshApplication
        // discards the previous container and its facade roots.
        putenv('APP_RUNNING_IN_CONSOLE=false');
        $this->refreshApplication();
        Cache::flush();
        $this->service = new CacheLockService;
    });

    afterEach(function () {
        putenv('APP_RUNNING_IN_CONSOLE');
    });

    it('returns stale immediately and does not run the callback in-request', function () {
        $lock = M::mock(Lock::class);
        $lock->shouldReceive('get')->withNoArgs()->once()->andReturn(true);
        $lock->shouldReceive('release')->never(); // held until the deferred callback runs

        Cache::shouldReceive('get')->with('swr:defer')->twice()->andReturn(null, null);
        Cache::shouldReceive('get')->with('swr:defer:stale')->once()->andReturn(['stale' => true]);
        Cache::shouldReceive('lock')->with('lock:swr:defer', 10)->once()->andReturn($lock);
        Cache::shouldReceive('put')->never();

        $ran = false;
        $result = $this->service->rememberLocked('swr:defer', 60, function () use (&$ran) {
            $ran = true;

            return ['fresh' => true];
        });

        expect($result)->toBe(['stale' => true]);
        expect($ran)->toBeFalse();
        expect(count(app(DeferredCallbackCollection::class)))->toBe(1);
    });

    it('writes both keys and releases the lock when the deferred callback runs', function () {
        $lock = M::mock(Lock::class);
        $lock->shouldReceive('get')->withNoArgs()->once()->andReturn(true);
        $lock->shouldReceive('release')->once()->andReturn(true);

        Cache::shouldReceive('get')->with('swr:run')->twice()->andReturn(null, null);
        Cache::shouldReceive('get')->with('swr:run:stale')->once()->andReturn(['stale' => true]);
        Cache::shouldReceive('lock')->with('lock:swr:run', 10)->once()->andReturn($lock);
        Cache::shouldReceive('put')->with('swr:run', ['fresh' => true], M::type('int'))->once();
        Cache::shouldReceive('put')->with('swr:run:stale', ['fresh' => true], M::type('int'))->once();

        $this->service->rememberLocked('swr:run', 60, fn () => ['fresh' => true]);

        app(DeferredCallbackCollection::class)->invoke();
    });

    it('marks the deferred recompute always, so a 4xx response still refreshes', function () {
        // Without ->always() a 404 (routine on the profile-alias paths) would skip
        // the refresh AND strand the lock for its full 10s, during which every
        // reader gets stale with nobody rebuilding.
        $lock = M::mock(Lock::class);
        $lock->shouldReceive('get')->withNoArgs()->once()->andReturn(true);
        $lock->shouldReceive('release')->never();

        Cache::shouldReceive('get')->with('swr:always')->twice()->andReturn(null, null);
        Cache::shouldReceive('get')->with('swr:always:stale')->once()->andReturn(['stale' => true]);
        Cache::shouldReceive('lock')->with('lock:swr:always', 10)->once()->andReturn($lock);
        Cache::shouldReceive('put')->never();

        $this->service->rememberLocked('swr:always', 60, fn () => ['fresh' => true]);

        expect(app(DeferredCallbackCollection::class)->first()->always)->toBeTrue();
    });

    it('holds the lock across the deferred window so a second caller does not also recompute', function () {
        // Real array-store cache and lock: a Mockery lock cannot prove
        // hold-across-return, which is the single property this fix turns on.
        Cache::put('swr:single:stale', ['stale' => true], 600);

        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;

            return ['fresh' => true];
        };

        $first = $this->service->rememberLocked('swr:single', 60, $callback);
        $second = $this->service->rememberLocked('swr:single', 60, $callback);

        expect($first)->toBe(['stale' => true]);
        expect($second)->toBe(['stale' => true]);
        expect($calls)->toBe(0);
        expect(count(app(DeferredCallbackCollection::class)))->toBe(1);

        app(DeferredCallbackCollection::class)->invoke();

        expect($calls)->toBe(1);
        expect(Cache::get('swr:single'))->toBe(['fresh' => true]);
    });

    it('reports and leaves the stale copy standing when the deferred recompute throws', function () {
        Exceptions::fake();
        Cache::put('swr:boom:stale', ['stale' => true], 600);

        $result = $this->service->rememberLocked(
            'swr:boom',
            60,
            fn () => throw new RuntimeException('boom'),
        );

        expect($result)->toBe(['stale' => true]);

        app(DeferredCallbackCollection::class)->invoke();

        Exceptions::assertReported(RuntimeException::class);
        expect(Cache::get('swr:boom'))->toBeNull();
        expect(Cache::get('swr:boom:stale'))->toBe(['stale' => true]);
        // Lock released by the closure's finally — the next caller can win it.
        expect(Cache::lock('lock:swr:boom', 10)->get())->toBeTrue();
    });

    it('recomputes synchronously when the kill-switch is off', function () {
        config(['partna.cache.swr_defer_recompute' => false]);
        Cache::put('swr:killswitch:stale', ['stale' => true], 600);

        $result = $this->service->rememberLocked('swr:killswitch', 60, fn () => ['fresh' => true]);

        expect($result)->toBe(['fresh' => true]);
        expect(count(app(DeferredCallbackCollection::class)))->toBe(0);
    });

    it('SiteCacheService defers the payload rebuild and serves the healed stale copy', function () {
        // cache_locks is a redis store in config/cache.php — swap it to array so
        // this exercises a real lock instead of a mock.
        config(['cache.stores.cache_locks' => ['driver' => 'array']]);

        $subdomain = 'swr-defer-site';
        $key = CacheKeyGenerator::publicSitePayload($subdomain);
        $stale = swrDeferStalePayload();
        Cache::put($key.':stale', $stale, 600);

        $service = new class(new CacheLockService) extends SiteCacheService
        {
            public int $built = 0;

            protected function buildPayloadFromDb(string $subdomain, string $key): ?array
            {
                $this->built++;

                return swrDeferStalePayload();
            }
        };

        $result = $service->getPublicSitePayload($subdomain);

        expect($service->built)->toBe(0);
        expect($result)->toEqual($stale);
        expect(count(app(DeferredCallbackCollection::class)))->toBe(1);

        app(DeferredCallbackCollection::class)->invoke();

        expect($service->built)->toBe(1);
        // Lock released by the closure's finally.
        expect(Cache::store('cache_locks')->lock('site:fill:'.$subdomain, 10)->get())->toBeTrue();
    });

    it('SiteCacheService rebuilds synchronously (not deferred) when the stale copy is legacy-shaped', function () {
        config(['cache.stores.cache_locks' => ['driver' => 'array']]);

        $subdomain = 'swr-defer-legacy-stale';
        $key = CacheKeyGenerator::publicSitePayload($subdomain);
        // Legacy shape — no `services` key, so it can't be served as the SWR fast
        // path and must not be left standing behind a deferred rebuild.
        Cache::put($key.':stale', ['site' => null], 600);

        $service = new class(new CacheLockService) extends SiteCacheService
        {
            public int $built = 0;

            protected function buildPayloadFromDb(string $subdomain, string $key): ?array
            {
                $this->built++;

                return swrDeferStalePayload();
            }
        };

        $result = $service->getPublicSitePayload($subdomain);

        // Single synchronous rebuild, not a deferred one.
        expect($service->built)->toBe(1);
        expect($result)->toEqual(swrDeferStalePayload());
        expect(count(app(DeferredCallbackCollection::class)))->toBe(0);
        // Lock released synchronously — no deferred closure is holding it.
        expect(Cache::store('cache_locks')->lock('site:fill:'.$subdomain, 10)->get())->toBeTrue();
    });

    it('SiteCacheService does not let a second caller rebuild while one is deferred', function () {
        config(['cache.stores.cache_locks' => ['driver' => 'array']]);

        $subdomain = 'swr-defer-single';
        $key = CacheKeyGenerator::publicSitePayload($subdomain);
        Cache::put($key.':stale', swrDeferStalePayload(), 600);

        $service = new class(new CacheLockService) extends SiteCacheService
        {
            public int $built = 0;

            protected function buildPayloadFromDb(string $subdomain, string $key): ?array
            {
                $this->built++;

                return swrDeferStalePayload();
            }
        };

        $service->getPublicSitePayload($subdomain);
        $service->getPublicSitePayload($subdomain);

        expect(count(app(DeferredCallbackCollection::class)))->toBe(1);

        app(DeferredCallbackCollection::class)->invoke();

        expect($service->built)->toBe(1);
    });
});

describe('console context', function () {
    beforeEach(function () {
        Cache::flush();
        $this->service = new CacheLockService;
    });

    it('recomputes synchronously in a worker or command', function () {
        // WarmPublicSiteCacheJob's contract: the cache is warm the moment
        // handle() returns. defer() also flushes after queued jobs, so without
        // the console gate the job would report success before warming anything.
        Cache::put('swr:console:stale', ['stale' => true], 600);

        $result = $this->service->rememberLocked('swr:console', 60, fn () => ['fresh' => true]);

        expect($result)->toBe(['fresh' => true]);
        expect(Cache::get('swr:console'))->toBe(['fresh' => true]);
        expect(count(app(DeferredCallbackCollection::class)))->toBe(0);
    });

    it('SiteCacheService rebuilds synchronously in a worker or command', function () {
        config(['cache.stores.cache_locks' => ['driver' => 'array']]);

        $subdomain = 'swr-console-site';
        $key = CacheKeyGenerator::publicSitePayload($subdomain);
        Cache::put($key.':stale', swrDeferStalePayload(), 600);

        $service = new class(new CacheLockService) extends SiteCacheService
        {
            public int $built = 0;

            protected function buildPayloadFromDb(string $subdomain, string $key): ?array
            {
                $this->built++;

                return ['published' => true, 'services' => [], 'links' => [], 'sections' => [],
                    'blocks' => [], 'site' => null, 'professional' => null, 'legal' => null, 'fresh' => true];
            }
        };

        $result = $service->getPublicSitePayload($subdomain);

        expect($service->built)->toBe(1);
        expect($result['fresh'] ?? null)->toBeTrue();
        expect(count(app(DeferredCallbackCollection::class)))->toBe(0);
    });

    it('SiteCacheService still returns a legitimate null from a synchronous rebuild', function () {
        // Regression guard for the RECOMPUTE_FAILED sentinel: buildPayloadFromDb
        // returning null means "no such site" and must pass straight through,
        // NOT fall into the stale-healing ladder the way a thrown error does.
        config(['cache.stores.cache_locks' => ['driver' => 'array']]);

        $subdomain = 'swr-console-null';
        $key = CacheKeyGenerator::publicSitePayload($subdomain);
        Cache::put($key.':stale', swrDeferStalePayload(), 600);

        $service = new class(new CacheLockService) extends SiteCacheService
        {
            protected function buildPayloadFromDb(string $subdomain, string $key): ?array
            {
                return null;
            }
        };

        expect($service->getPublicSitePayload($subdomain))->toBeNull();
    });
});

/** Minimal payload shape that passes SiteCacheService's cache-healing checks. */
function swrDeferStalePayload(): array
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
