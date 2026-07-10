<?php

// tests/Unit/Analytics/AnalyticsCacheServiceTest.php
//
// #OBS-2/#OBS-3 — bumpVersion()'s fail-open catch must stay silent on a single
// blip and only escalate to Nightwatch on a sustained run (EscalatesRepeatedFaults).

use App\Services\Analytics\AnalyticsCacheService;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// EscalatesRepeatedFaults' Tier 1 counter runs via RateLimiter::hit() (a
// DIFFERENT facade from bumpVersion()'s own debounce key), and RateLimiter is
// resolved — capturing its own cache store reference — during app boot
// (AppServiceProvider::boot() registers a dozen RateLimiter::for(...)
// throttles), well before any Cache::shouldReceive() mock below is set up. So
// mocking the debounce key below throws only for bumpVersion()'s own call;
// the escalation counter keeps counting against the real store regardless.
// Laravel rebuilds the whole app before every test, but clear explicitly
// anyway — cheap, and it stops a future boot-order change from becoming a
// silent cross-test leak.
beforeEach(function () {
    RateLimiter::clear('analytics:fault:cache_bump');
});

it('fails open — a cache-store fault degrades to a breadcrumb and returns void, never throws', function () {
    Exceptions::fake();
    Log::spy();

    Cache::shouldReceive('add')
        ->withArgs(fn (string $key) => $key === 'analytics:ingest-debounce:user-1')
        ->once()
        ->andThrow(new RuntimeException('redis down'));

    $result = app(AnalyticsCacheService::class)->bumpVersion('user-1');

    expect($result)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $msg, array $ctx) => $msg === 'analytics.cache_bump_failed' && $ctx['user_id'] === 'user-1');

    // A single blip is a quiet breadcrumb — it must NOT reach Nightwatch.
    Exceptions::assertNothingReported();
});

it('escalates to Nightwatch once a sustained run of cache faults crosses the threshold', function () {
    Exceptions::fake();
    Log::spy();

    $threshold = AnalyticsCacheService::FAULT_THRESHOLD;
    Cache::shouldReceive('add')
        ->withArgs(fn (string $key) => $key === 'analytics:ingest-debounce:user-1')
        ->times($threshold)
        ->andThrow(new RuntimeException('redis down'));

    $service = app(AnalyticsCacheService::class);

    // Every failure short of the threshold stays a breadcrumb.
    for ($i = 1; $i < $threshold; $i++) {
        $service->bumpVersion('user-1');
        Exceptions::assertNothingReported();
    }

    // The threshold-th consecutive failure inside the window escalates.
    $service->bumpVersion('user-1');
    Exceptions::assertReported(RuntimeException::class);
});

// #CCH-1 — the 30s ingest-debounce TTL is jittered ±20% (JitteredTtl), so a burst of
// ingests across many users doesn't debounce (and re-bump) in lockstep.
it('jitters the ingest-debounce TTL within ±20% of the configured base', function () {
    config(['partna.analytics.ingest_debounce_seconds' => 30]);

    $capturedTtl = null;

    Cache::shouldReceive('add')
        ->withArgs(function (string $key, $value, $ttl) use (&$capturedTtl) {
            $capturedTtl = $ttl;

            return $key === CacheKeyGenerator::analyticsIngestDebounce('user-jitter');
        })
        ->once()
        // Return false so bumpVersion() skips Cache::increment() — only the TTL arg
        // passed to add() is under test here.
        ->andReturn(false);

    app(AnalyticsCacheService::class)->bumpVersion('user-jitter');

    expect($capturedTtl)->toBeGreaterThanOrEqual(24)->toBeLessThanOrEqual(36);
});
