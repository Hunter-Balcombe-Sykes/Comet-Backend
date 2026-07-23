<?php

use App\Services\Cache\PlacesBudget;
use App\Services\Cache\PlacesClaim;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    config()->set('partna.limits.places.global_daily_cap', 100);
    config()->set('partna.limits.places.per_user_daily_cap', 100);
    config()->set('partna.limits.places.skus.details', 100);
    config()->set('partna.limits.places.skus.photos', 100);
});

it('grants claims up to the per-SKU cap then denies with PlatformCapReached', function () {
    config()->set('partna.limits.places.skus.details', 2);

    $budget = new PlacesBudget;
    expect($budget->claim('details', 'user-1'))->toBe(PlacesClaim::Granted)
        ->and($budget->claim('details', 'user-1'))->toBe(PlacesClaim::Granted)
        ->and($budget->claim('details', 'user-1'))->toBe(PlacesClaim::PlatformCapReached);
});

it('denies on the GLOBAL cap even when the SKU cap has headroom', function () {
    config()->set('partna.limits.places.global_daily_cap', 2);

    $budget = new PlacesBudget;
    expect($budget->claim('details', 'user-1'))->toBe(PlacesClaim::Granted)
        ->and($budget->claim('photos', 'user-2'))->toBe(PlacesClaim::Granted)
        // 3rd claim exceeds the global cap of 2, though both SKU caps (100) have headroom.
        ->and($budget->claim('details', 'user-3'))->toBe(PlacesClaim::PlatformCapReached);
});

it('denies on the PER-USER cap even when both global and SKU have headroom', function () {
    config()->set('partna.limits.places.per_user_daily_cap', 2);

    $budget = new PlacesBudget;
    expect($budget->claim('details', 'user-heavy'))->toBe(PlacesClaim::Granted)
        ->and($budget->claim('details', 'user-heavy'))->toBe(PlacesClaim::Granted)
        ->and($budget->claim('details', 'user-heavy'))->toBe(PlacesClaim::UserCapReached);
});

it('one user exhausting their own cap leaves another user able to claim (the whole point of the unit)', function () {
    config()->set('partna.limits.places.per_user_daily_cap', 1);

    $budget = new PlacesBudget;
    expect($budget->claim('details', 'user-a'))->toBe(PlacesClaim::Granted)
        ->and($budget->claim('details', 'user-a'))->toBe(PlacesClaim::UserCapReached)
        ->and($budget->claim('details', 'user-b'))->toBe(PlacesClaim::Granted);
});

it('a denied claim consumes no budget — remaining() is untouched by the rejection', function () {
    config()->set('partna.limits.places.skus.details', 1);

    $budget = new PlacesBudget;
    $budget->claim('details', 'user-1'); // 1/1, granted
    expect($budget->remaining('details'))->toBe(0);

    $budget->claim('details', 'user-1'); // rejected — must release
    expect($budget->remaining('details'))->toBe(0); // still 0, not negative
});

it('counters are date-scoped: travelling past midnight grants again', function () {
    config()->set('partna.limits.places.per_user_daily_cap', 1);

    $budget = new PlacesBudget;
    expect($budget->claim('details', 'user-1'))->toBe(PlacesClaim::Granted)
        ->and($budget->claim('details', 'user-1'))->toBe(PlacesClaim::UserCapReached);

    $this->travel(1)->day();

    expect($budget->claim('details', 'user-1'))->toBe(PlacesClaim::Granted);
});

it('fails CLOSED (Unavailable, never Granted) when the cache layer throws', function () {
    Exceptions::fake();

    Cache::shouldReceive('add')
        ->once()
        ->andThrow(new RuntimeException('redis down'));

    $budget = new PlacesBudget;
    expect($budget->claim('details', 'user-1'))->toBe(PlacesClaim::Unavailable);
    Exceptions::assertReported(RuntimeException::class);
});

// The rollback decrements used to sit outside claim()'s try/catch: a cache
// Throwable there escaped uncaught instead of degrading, so a caller catching
// only PlacesBudgetExhaustedException (GoogleBusinessController) got a 500
// instead of the intended degrade. This proves the genuine verdict
// (PlatformCapReached) still returns even when the rollback itself fails.
it('degrades (does not throw) when the cache layer fails during the rollback decrement', function () {
    Exceptions::fake();
    config()->set('partna.limits.places.skus.details', 1);

    $budget = new PlacesBudget;
    $budget->claim('details', 'user-1'); // 1/1, granted — cap now full

    // Forward add/increment to the real (already-resolved) manager — only
    // decrement is faked — since Cache::partialMock() rebuilds a bare Mockery
    // double with no container state, and calling through on THAT breaks the
    // add/increment path too (masking this test behind an unrelated Unavailable).
    $real = Cache::getFacadeRoot();
    Cache::shouldReceive('add')->andReturnUsing(fn (...$args) => $real->add(...$args));
    Cache::shouldReceive('increment')->andReturnUsing(fn (...$args) => $real->increment(...$args));
    Cache::shouldReceive('decrement')->andThrow(new RuntimeException('redis down'));

    // 2nd claim exceeds the SKU cap of 1 -> rollback fires -> decrement throws.
    // Must still surface the genuine rejection reason, not an uncaught Throwable.
    expect($budget->claim('details', 'user-1'))->toBe(PlacesClaim::PlatformCapReached);
    Exceptions::assertReported(RuntimeException::class);
});
