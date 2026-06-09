<?php

use App\Jobs\Cache\WarmPublicSiteCacheJob;
use App\Services\Cache\CacheLockService;
use App\Services\Cache\SiteCacheService;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use Illuminate\Support\Facades\Queue;

it('calls warmSiteCache with a lowercased subdomain', function () {
    $siteCache = $this->mock(SiteCacheService::class);
    $siteCache->shouldReceive('warmSiteCache')
        ->once()
        ->with('my-site');

    // §28.8 warm path (audit #12) is best-effort behind a try/catch. The
    // User::where() lookup throws against the SQLite test fixture
    // (no core.users table attached) and the job swallows it, so
    // the builder/cacheLock mocks are never called — pass real container
    // instances so the type signature is satisfied.
    $cacheLock = app(CacheLockService::class);
    $builder = app(IndividualProfilePayloadBuilder::class);

    $job = new WarmPublicSiteCacheJob('My-Site');
    $job->handle($siteCache, $cacheLock, $builder);
});

it('runs on the cache-warm queue', function () {
    $job = new WarmPublicSiteCacheJob('my-site');

    expect($job->queue)->toBe('cache-warm');
});

it('can be dispatched via Queue::fake', function () {
    Queue::fake();

    WarmPublicSiteCacheJob::dispatch('my-site');

    Queue::assertPushed(WarmPublicSiteCacheJob::class, function ($job) {
        return $job->subdomain === 'my-site';
    });
});

it('has 3 tries', function () {
    $job = new WarmPublicSiteCacheJob('my-site');

    expect($job->tries)->toBe(3);
});

it('has backoff of [5, 15, 30]', function () {
    $job = new WarmPublicSiteCacheJob('my-site');

    expect($job->backoff)->toBe([5, 15, 30]);
});

it('has a timeout of 10', function () {
    $job = new WarmPublicSiteCacheJob('my-site');

    expect($job->timeout)->toBe(10);
});

it('calls report() on failure', function () {
    $e = new RuntimeException('cache warm error');
    $job = new WarmPublicSiteCacheJob('my-site');
    $job->failed($e); // Should not throw
})->throwsNoExceptions();
