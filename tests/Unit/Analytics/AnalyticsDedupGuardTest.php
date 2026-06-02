<?php

// tests/Unit/Analytics/AnalyticsDedupGuardTest.php

use App\Services\Analytics\AnalyticsDedupGuard;
use Illuminate\Support\Facades\Cache;

// Uses the Cache facade (real array store + Cache::shouldReceive), so it needs a
// booted app. tests/Pest.php binds only Feature to Tests\TestCase — unit tests must
// opt in explicitly or the facade has no root.
uses(Tests\TestCase::class);

it('reports novel and stores the minted uuid on first claim', function () {
    $guard = new AnalyticsDedupGuard;
    $result = $guard->claim('dedup:test:1', 'uuid-A', 3);

    expect($result)->toBe(['novel' => true, 'id' => 'uuid-A']);
});

it('reports duplicate and echoes the original uuid on a second claim', function () {
    $guard = new AnalyticsDedupGuard;
    $guard->claim('dedup:test:2', 'uuid-A', 30);
    $result = $guard->claim('dedup:test:2', 'uuid-B', 30);

    expect($result)->toBe(['novel' => false, 'id' => 'uuid-A']);
});

it('falls back to the minted uuid when the key expired between setnx and get', function () {
    $guard = new AnalyticsDedupGuard;
    // Simulate a failed SETNX (key present) whose value vanished before get().
    Cache::shouldReceive('add')->once()->andReturnFalse();
    Cache::shouldReceive('get')->once()->andReturnNull();

    expect($guard->claim('dedup:test:3', 'uuid-B', 3))->toBe(['novel' => false, 'id' => 'uuid-B']);
});

it('fails open (novel) when the cache store throws', function () {
    $guard = new AnalyticsDedupGuard;
    Cache::shouldReceive('add')->once()->andThrow(new RuntimeException('redis down'));

    expect($guard->claim('dedup:test:4', 'uuid-B', 3))->toBe(['novel' => true, 'id' => 'uuid-B']);
});
