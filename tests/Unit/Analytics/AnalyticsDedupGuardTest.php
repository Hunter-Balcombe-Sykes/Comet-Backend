<?php

// tests/Unit/Analytics/AnalyticsDedupGuardTest.php

use App\Services\Analytics\AnalyticsDedupGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

// Uses the Cache facade (real array store + Cache::shouldReceive), so it needs a
// booted app. tests/Pest.php binds only Feature to Tests\TestCase — unit tests must
// opt in explicitly or the facade has no root.
uses(TestCase::class);

// EscalatesRepeatedFaults' Tier 1 counter runs via RateLimiter::hit() (a
// DIFFERENT facade from anything claim() itself touches), and RateLimiter is
// resolved — capturing its own cache store reference — during app boot
// (AppServiceProvider::boot() registers a dozen RateLimiter::for(...)
// throttles), well before any Cache::shouldReceive() mock below is set up. So
// mocking claim()'s own key below throws only for claim()'s SETNX/get calls;
// the escalation counter keeps counting against the real store regardless.
// Laravel rebuilds the whole app before every test, but clear explicitly
// anyway — cheap, and it stops a future boot-order change from becoming a
// silent cross-test leak.
beforeEach(function () {
    RateLimiter::clear('analytics:fault:dedup');
});

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
    Exceptions::fake();
    $guard = new AnalyticsDedupGuard;

    Cache::shouldReceive('add')
        ->withArgs(fn (string $key) => $key === 'dedup:test:4')
        ->once()
        ->andThrow(new RuntimeException('redis down'));

    expect($guard->claim('dedup:test:4', 'uuid-B', 3))->toBe(['novel' => true, 'id' => 'uuid-B']);

    // A single blip is a quiet breadcrumb — it must NOT reach Nightwatch.
    Exceptions::assertNothingReported();
});

it('escalates to Nightwatch once a sustained run of dedup faults crosses the threshold', function () {
    Exceptions::fake();
    $guard = new AnalyticsDedupGuard;

    $threshold = AnalyticsDedupGuard::FAULT_THRESHOLD;
    Cache::shouldReceive('add')
        ->withArgs(fn (string $key) => $key === 'dedup:test:escalate')
        ->times($threshold)
        ->andThrow(new RuntimeException('redis down'));

    // Every failure short of the threshold stays a breadcrumb.
    for ($i = 1; $i < $threshold; $i++) {
        $guard->claim('dedup:test:escalate', 'uuid-B', 3);
        Exceptions::assertNothingReported();
    }

    // The threshold-th consecutive failure inside the window escalates.
    $guard->claim('dedup:test:escalate', 'uuid-B', 3);
    Exceptions::assertReported(RuntimeException::class);
});
