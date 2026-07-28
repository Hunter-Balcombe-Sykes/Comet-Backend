<?php

use App\Services\Cache\CacheLockService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

it('applies jitter so all written TTLs fall within ±20% of the base TTL', function () {
    $service = new CacheLockService;
    $baseTtl = 100;
    $capturedTtls = [];

    // Spy on Cache::put by wrapping it: for each call we delete the key first
    // so the service always sees a cold miss, then capture what it wrote by
    // checking the remaining TTL via the array driver's internal store.
    //
    // The array driver does not expose getRemainingTtl publicly via the facade,
    // but we can capture the actual TTL by spying on the underlying store.
    // Simplest approach: use a partial mock of the Cache store that records TTLs.

    $store = Cache::store();

    // Use Cache::spy() to capture put calls on the default store.
    // Pest / Laravel test doesn't provide a built-in TTL reader for the array
    // driver, so we record calls by swapping in a spy.
    $ttlsFromSpy = [];

    // Swap the default cache store with a store that records put TTLs.
    // We achieve this by spying the Cache facade itself.
    Cache::spy();

    // Re-register our spy to capture arguments.
    Cache::shouldReceive('get')
        ->andReturn(null); // always a cold miss for primary key

    Cache::shouldReceive('lock')
        ->andReturnUsing(function () {
            // Return a real-ish lock stub that acquires immediately.
            $mock = Mockery::mock(Lock::class);
            $mock->shouldReceive('get')->andReturn(true);
            $mock->shouldReceive('block')->andReturn(null);
            $mock->shouldReceive('release')->andReturn(null);

            return $mock;
        });

    Cache::shouldReceive('put')
        ->andReturnUsing(function (string $key, mixed $value, ?int $ttl = null) use (&$ttlsFromSpy) {
            // Only record primary-key puts (not the stale-extension key).
            if (! str_ends_with($key, ':stale')) {
                $ttlsFromSpy[] = $ttl;
            }

            return true;
        });

    // Drive 50 cold-miss calls with different keys.
    for ($i = 0; $i < 50; $i++) {
        $service->rememberLocked("jitter:key:{$i}", $baseTtl, fn () => "value-{$i}");
    }

    expect($ttlsFromSpy)->toHaveCount(50);

    $min = (int) round($baseTtl * 0.8);
    $max = (int) round($baseTtl * 1.2);

    foreach ($ttlsFromSpy as $ttl) {
        expect($ttl)->toBeGreaterThanOrEqual($min);
        expect($ttl)->toBeLessThanOrEqual($max);
    }

    // Not all equal — coefficient of variation must be > 0.
    // (With 50 draws from a uniform [80, 120] dist this is virtually certain.)
    $unique = array_unique($ttlsFromSpy);
    expect(count($unique))->toBeGreaterThan(1, 'Jitter produced identical TTLs across 50 writes — not random');
});

it('does not apply jitter to DateTimeInterface TTLs', function () {
    $service = new CacheLockService;

    $deadline = now()->addMinutes(5);

    Cache::spy();

    Cache::shouldReceive('get')->andReturn(null);

    Cache::shouldReceive('lock')
        ->andReturnUsing(function () {
            $mock = Mockery::mock(Lock::class);
            $mock->shouldReceive('get')->andReturn(true);
            $mock->shouldReceive('block')->andReturn(null);
            $mock->shouldReceive('release')->andReturn(null);

            return $mock;
        });

    $capturedTtl = null;

    Cache::shouldReceive('put')
        ->andReturnUsing(function (string $key, mixed $value, mixed $ttl = null) use (&$capturedTtl) {
            if (! str_ends_with($key, ':stale')) {
                $capturedTtl = $ttl;
            }

            return true;
        });

    $service->rememberLocked('jitter:datetime', $deadline, fn () => 'result');

    // The captured TTL must be the exact DateTimeInterface instance, not an int.
    expect($capturedTtl)->toBeInstanceOf(DateTimeInterface::class);
});
