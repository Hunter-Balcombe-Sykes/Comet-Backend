<?php

use App\Models\Core\Site\Site;
use App\Services\Cache\SiteCacheInvalidator;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('calls touch() on a non-null site', function () {
    $site = Mockery::mock(Site::class);
    $site->shouldReceive('touch')->once();

    $invalidator = new SiteCacheInvalidator;
    $invalidator->touchSite($site, 'create', ['block_id' => 'abc']);

    // Mockery assertion above is the real check; this keeps Pest's pass/fail output clean.
    expect(true)->toBeTrue();
});

it('no-ops when site is null without throwing', function () {
    $invalidator = new SiteCacheInvalidator;

    // Must complete silently — no exception propagated.
    $invalidator->touchSite(null, 'create');

    expect(true)->toBeTrue();
});

it('swallows a touch() throwable without re-throwing', function () {
    $site = Mockery::mock(Site::class);
    $site->shouldReceive('touch')->once()->andThrow(new RuntimeException('db down'));
    // Make id accessible for the log context.
    $site->shouldReceive('__get')->with('id')->andReturn('site-uuid');

    $invalidator = new SiteCacheInvalidator;

    // Must NOT propagate — cache-bookkeeping failures must never crash the originating write.
    $invalidator->touchSite($site, 'update', ['service_id' => 'svc-uuid']);

    expect(true)->toBeTrue();
});
