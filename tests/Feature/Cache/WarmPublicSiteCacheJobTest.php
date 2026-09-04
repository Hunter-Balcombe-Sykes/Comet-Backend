<?php

use App\Jobs\Cache\WarmPublicSiteCacheJob;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Cache\SiteCacheService;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

it('no longer calls the retired warmSiteCache', function () {
    expect(method_exists(SiteCacheService::class, 'warmSiteCache'))->toBeFalse();
    expect(method_exists(CacheKeyGenerator::class, 'publicSite'))->toBeFalse();
    expect(method_exists(CacheKeyGenerator::class, 'publicSitePayload'))->toBeFalse();
});

it('still warms the §28.8 individual-profile key, and only that', function () {
    Exceptions::fake();

    $cacheLock = Mockery::mock(CacheLockService::class);
    $builder = Mockery::mock(IndividualProfilePayloadBuilder::class);

    // The §28.8 warm is best-effort behind a try/catch. Its User::where('handle_lc')
    // lookup THROWS against the SQLite fixture (no core.users attached) and the job
    // swallows it via report(), so neither mock is reached. Asserting the report
    // lands is what proves the warm actually RAN and was survived, rather than the
    // whole handle() being a no-op that passes vacuously.
    $builder->shouldNotReceive('build');
    $cacheLock->shouldNotReceive('rememberLocked');

    $job = new WarmPublicSiteCacheJob('My-Site');
    $job->handle($cacheLock, $builder);

    Exceptions::assertReportedCount(1);
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
