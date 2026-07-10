<?php

// tests/Unit/Analytics/AnalyticsCacheServiceTest.php
//
// #OBS-2/#OBS-3 — bumpVersion()'s fail-open catch must stay silent on a single
// blip and only escalate to Nightwatch on a sustained run (EscalatesRepeatedFaults).

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Analytics\AnalyticsCacheService;
use App\Services\Analytics\AnalyticsQueryService;
use App\Services\Analytics\InsightEngine;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use Illuminate\Support\Carbon;
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

// #TEST-1 sub-item 1 — bumpVersion()'s Cache::add(...debounce...) gate: within the
// debounce window a second bump must NOT increment the version again, or every
// (range, granularity) cache variant would be busted on every single ingest instead
// of at most once per window.
it('debounces two immediate bumpVersion calls into a single version increment', function () {
    config(['partna.analytics.ingest_debounce_seconds' => 30]);

    $service = app(AnalyticsCacheService::class);
    $service->bumpVersion('user-debounce-1');
    $service->bumpVersion('user-debounce-1');

    expect((int) Cache::get(CacheKeyGenerator::analyticsSummaryVersion('user-debounce-1')))->toBe(1);
});

it('bumps the version again once the debounce window has fully elapsed', function () {
    config(['partna.analytics.ingest_debounce_seconds' => 10]);

    $service = app(AnalyticsCacheService::class);

    Carbon::setTestNow(Carbon::now());
    $service->bumpVersion('user-debounce-2');

    // Max jitter is +20% of the 10s base = 12s; travel well past that so the
    // debounce key has expired regardless of the jitter draw (see JitteredTtl).
    Carbon::setTestNow(Carbon::now()->addSeconds(15));
    $service->bumpVersion('user-debounce-2');

    expect((int) Cache::get(CacheKeyGenerator::analyticsSummaryVersion('user-debounce-2')))->toBe(2);
});

// #TEST-1 sub-item 1 — summary()'s cache orchestration. AnalyticsQueryService is
// mocked entirely: compose() fans out to a dozen query methods, several of which
// (referrers() in particular) use Postgres-only ILIKE and can't run on SQLite. This
// test is about the CACHE layer only — key construction, hit/miss, version-bust —
// not the underlying SQL, so mocking the whole query service is the right boundary.
it('summary() reuses the cached payload for a repeat call, and bumpVersion() between calls forces a recompose', function () {
    $professional = (new User)->forceFill(['id' => 'user-cache-1', 'handle' => 'cache-user', 'display_name' => 'Cache User']);
    $site = (new Site)->forceFill(['id' => 'site-cache-1', 'subdomain' => 'cache-user', 'is_published' => true]);

    $from = Carbon::parse('2026-01-01');
    $to = Carbon::parse('2026-01-07');

    $emptyClicks = (object) ['total_clicks' => 0, 'unique_clickers' => 0, 'last_click_at' => null];
    $emptySessions = (object) ['total_sessions' => 0, 'engaged_sessions' => 0, 'avg_duration_seconds' => 0];

    // visitsAggregate's total_visits increments on every REAL invocation — a
    // stronger signal than a call-count alone: if summary() ever recomposed on
    // the "cached" second call, $second would visibly diverge from $first.
    $callCount = 0;

    // shouldIgnoreMissing(): every compose() call this test doesn't care about
    // (deviceTotals/countries/referrers/topLinks/...) returns null harmlessly —
    // compose() only assigns each result into the response array, it never
    // chains a method call off any of these, so a null stub is safe.
    $queries = Mockery::mock(AnalyticsQueryService::class)->shouldIgnoreMissing();
    // Called exactly once per compose() — asserting the total call count across
    // three summary() calls proves compose() ran exactly twice (initial miss +
    // post-bump miss), not three times (which would mean no cache reuse at all).
    $queries->shouldReceive('visitsAggregate')
        ->twice()
        ->andReturnUsing(function () use (&$callCount) {
            $callCount++;

            return (object) ['total_visits' => $callCount, 'unique_visitors' => 0, 'last_visit_at' => null];
        });
    $queries->shouldReceive('clicksAggregate')->twice()->andReturn($emptyClicks);
    $queries->shouldReceive('sessionsAggregate')->twice()->andReturn($emptySessions);

    $service = new AnalyticsCacheService(new CacheLockService, $queries, new InsightEngine);

    $first = $service->summary($professional, $site, $from, $to, false, 'Australia/Sydney');
    $second = $service->summary($professional, $site, $from, $to, false, 'Australia/Sydney');

    // Same (user, range, granularity, version) cache key both times — the second
    // call is a pure cache hit: identical payload, no fresh compose() (visits stays 1).
    expect($first['totals']['visits'])->toBe(1)
        ->and($second)->toBe($first);

    $service->bumpVersion($professional->id);

    $third = $service->summary($professional, $site, $from, $to, false, 'Australia/Sydney');

    // bumpVersion() folds a new version token into the cache key, forcing a
    // fresh compose() even though (user, range, granularity) are unchanged —
    // visible here as visits ticking over to the second real invocation.
    expect($third['totals']['visits'])->toBe(2);
});
