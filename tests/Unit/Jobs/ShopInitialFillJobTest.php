<?php

use App\Jobs\Platforms\ShopInitialFillJob;
use App\Services\Platforms\ShopCatalog;
use App\Services\Shop\ShopAutoSelector;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\StoreRecord;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// #JOB-1 — the fill/auto-select tail ShopBrandConnectJob dispatches this job
// FOR (SCALE-4). No DB setup: all three handle() dependencies are
// container-injected mocks, and StoreRecord is a plain readonly value object
// — nothing here needs setupContentTables().

function sifjStore(array $overrides = []): StoreRecord
{
    return (new StoreRecord(
        externalRef: 'brand-1',
        provider: 'shopify',
        collectionId: 'col-1',
        userId: 'user-1',
    ))->with($overrides);
}

it('defines the required queue-hygiene properties', function () {
    $job = new ShopInitialFillJob('col-1');

    expect($job->tries)->toBe(2)
        ->and($job->backoff)->toBe([10])
        ->and($job->timeout)->toBe(240)
        ->and($job->uniqueFor)->toBe(300)
        // Must exceed the job's own $timeout — same HorizonQueueCoverageTest
        // invariant every unique job in this codebase pins. Stops a future
        // "the fill is fast now, drop the timeout" from silently reintroducing
        // SCALE-4 (the 75s ceiling this job exists to escape).
        ->and($job->uniqueFor)->toBeGreaterThan($job->timeout)
        ->and($job->uniqueId())->toBe('shop-store-fill:col-1')
        ->and($job->queue)->toBe(config('partna.queues.platform_connect'));
});

it('reports AND logs both the fill failure and the auto-select failure independently', function () {
    Exceptions::fake();
    Log::spy();

    $this->mock(ShopConnections::class, fn ($m) => $m->shouldReceive('storeByCollection')
        ->with('col-1')->once()->andReturn(sifjStore()));
    $this->mock(ShopCatalog::class, fn ($m) => $m->shouldReceive('syncLatest')
        ->once()->andThrow(new RuntimeException('fill boom')));
    $this->mock(ShopAutoSelector::class, fn ($m) => $m->shouldReceive('selectInitial')
        ->once()->andThrow(new RuntimeException('select boom')));

    $job = new ShopInitialFillJob('col-1');
    app()->call([$job, 'handle']);

    // Load-bearing: also catches a "fix" that reports the same exception
    // twice, or that converts a catch into a rethrow (which would report via
    // failed() instead, and never reach this line at all under Bus/queue sync).
    Exceptions::assertReportedCount(2);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'fill boom');
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'select boom');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'shop.initial_fill_job.fill_failed'
            && ($context['collection_id'] ?? null) === 'col-1'
            && ($context['user_id'] ?? null) === 'user-1'
            && ($context['error'] ?? null) === 'fill boom')
        ->once();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => $message === 'shop.initial_fill_job.auto_select_failed'
            && ($context['collection_id'] ?? null) === 'col-1'
            && ($context['user_id'] ?? null) === 'user-1'
            && ($context['error'] ?? null) === 'select boom')
        ->once();
});

// Over-correction guard — a "fix" that converts either catch into a
// $this->fail()/rethrow makes this red: a fill failure would then skip the
// select entirely instead of still attempting it.
it('stays best-effort: a fill failure does not throw, and the auto-select still runs', function () {
    Exceptions::fake();

    $this->mock(ShopConnections::class, fn ($m) => $m->shouldReceive('storeByCollection')
        ->with('col-1')->once()->andReturn(sifjStore()));
    $this->mock(ShopCatalog::class, fn ($m) => $m->shouldReceive('syncLatest')
        ->once()->andThrow(new RuntimeException('fill boom')));
    $this->mock(ShopAutoSelector::class, fn ($m) => $m->shouldReceive('selectInitial')
        ->once()->andReturn(0));

    $job = new ShopInitialFillJob('col-1');

    $threw = false;
    try {
        app()->call([$job, 'handle']);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
});
