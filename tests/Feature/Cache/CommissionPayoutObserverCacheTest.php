<?php

use App\Models\Commerce\CommissionPayout;
use App\Observers\Core\CommissionPayoutObserver;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Mockery as M;

// CACHE-1: CommissionPayoutObserver must clear both the primary and :stale
// affiliatePayoutState cache keys on every status branch (completed / failed / pending).
// Tests drive the observer directly — no DB fixtures needed; the payout is a partial
// mock so isDirty() and getOriginal() return controllable values.

afterEach(fn () => M::close());

function makeObserver(): CommissionPayoutObserver
{
    $publisher = M::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->andReturnNull();

    return new CommissionPayoutObserver($publisher);
}

function makePayout(string $status, string $affiliateId, ?string $failureCode = null): CommissionPayout
{
    /** @var CommissionPayout&\Mockery\MockInterface $payout */
    $payout = M::mock(CommissionPayout::class)->makePartial();
    $payout->affiliate_professional_id = $affiliateId;
    $payout->brand_professional_id = (string) Str::uuid();
    $payout->status = $status;
    $payout->failure_code = $failureCode;
    $payout->net_payout_cents = 5000;
    $payout->currency_code = 'AUD';
    $payout->id = (string) Str::uuid();

    $payout->shouldReceive('isDirty')->with('status')->andReturn(true);
    $payout->shouldReceive('isDirty')->with('failure_code')->andReturn($failureCode !== null);
    $payout->shouldReceive('getOriginal')->with('failure_code')->andReturn(null);

    return $payout;
}

it('clears both cache keys when payout transitions to completed', function () {
    Cache::flush();

    $affiliateId = (string) Str::uuid();
    $key = CacheKeyGenerator::affiliatePayoutState($affiliateId);
    Cache::put($key, ['some' => 'state'], 600);
    Cache::put($key.':stale', ['some' => 'stale'], 6000);

    $observer = makeObserver();
    $observer->updated(makePayout('completed', $affiliateId));

    expect(Cache::has($key))->toBeFalse()
        ->and(Cache::has($key.':stale'))->toBeFalse();
});

it('clears both cache keys when payout transitions to failed', function () {
    Cache::flush();

    $affiliateId = (string) Str::uuid();
    $key = CacheKeyGenerator::affiliatePayoutState($affiliateId);
    Cache::put($key, ['some' => 'state'], 600);
    Cache::put($key.':stale', ['some' => 'stale'], 6000);

    $observer = makeObserver();
    $observer->updated(makePayout('failed', $affiliateId, 'transfer_failed'));

    expect(Cache::has($key))->toBeFalse()
        ->and(Cache::has($key.':stale'))->toBeFalse();
});

it('clears both cache keys when payout is pending with a newly-set failure code', function () {
    Cache::flush();

    $affiliateId = (string) Str::uuid();
    $key = CacheKeyGenerator::affiliatePayoutState($affiliateId);
    Cache::put($key, ['some' => 'state'], 600);
    Cache::put($key.':stale', ['some' => 'stale'], 6000);

    $observer = makeObserver();
    $observer->updated(makePayout('pending', $affiliateId, 'charge_failed'));

    expect(Cache::has($key))->toBeFalse()
        ->and(Cache::has($key.':stale'))->toBeFalse();
});

it('is a no-op when affiliate_professional_id is empty', function () {
    Cache::flush();

    // Seed a key that should NOT be deleted.
    Cache::put('payout:state:v1:', 'sentinel', 60);

    $observer = makeObserver();
    $observer->updated(makePayout('completed', ''));

    // Nothing crashed and sentinel key is untouched.
    expect(Cache::has('payout:state:v1:'))->toBeTrue();
})->throwsNoExceptions();
