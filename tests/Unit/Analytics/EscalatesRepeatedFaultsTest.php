<?php

// tests/Unit/Analytics/EscalatesRepeatedFaultsTest.php
//
// #OBS-3 — tests for the shared cache-backed escalation mechanism itself
// (EscalatesRepeatedFaults) that don't fit naturally inside any single call
// site's own test file:
//   - Tier 1 isolation: a fault run at claim() must not advance ingest()'s
//     counter, proving the per-call-site label actually partitions the
//     fleet-wide cache bucket.
//   - Tier 2: when the counter store itself is unreachable (not just the
//     "outer" operation each call site catches), the fallback sample never
//     throws and still eventually reports.
// Per-site Tier 1 threshold + single-blip fail-open coverage lives in
// QueuedIngestorTest, AnalyticsDedupGuardTest, and AnalyticsCacheServiceTest.

use App\Services\Analytics\AnalyticsDedupGuard;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Ingestors\QueuedIngestor;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(TestCase::class);

// Tier 1 lives behind RateLimiter::hit() now (see EscalatesRepeatedFaults), and
// RateLimiter is resolved (and its cache store captured) during app boot —
// AppServiceProvider::boot() registers a dozen RateLimiter::for(...) throttles
// — well before any per-test Cache::shouldReceive() mock is set up. So the
// escalation counter always runs against the REAL cache store regardless of
// what a test mocks on the Cache facade; only mocking RateLimiter itself can
// make Tier 1 fail. Laravel rebuilds the whole app (fresh RateLimiter, fresh
// array cache store) before every test, but clear explicitly anyway — cheap,
// and it stops any future change to that boot behavior from becoming a silent
// cross-test leak.
beforeEach(function () {
    RateLimiter::clear('analytics:fault:dedup');
    RateLimiter::clear('analytics:fault:ingest');
});

function escalationIsolationEvent(): AnalyticsEvent
{
    return AnalyticsEvent::fromArray([
        'id' => 'i', 'type' => AnalyticsEvent::TYPE_CLICK, 'occurred_at' => 'now',
        'user_id' => 'u', 'site_id' => 's', 'block_id' => 'b',
    ]);
}

it('keeps claim() and ingest() fault counters independent — a burst at one never advances the other', function () {
    Exceptions::fake();

    // AnalyticsDedupGuard and QueuedIngestor pass distinct labels ('dedup' and
    // 'ingest') into the shared trait, so each gets its own
    // `analytics:fault:<label>` RateLimiter bucket. If they collided onto one
    // bucket, (threshold - 1) faults at EACH site would sum past the
    // threshold and this test would see a spurious report.
    $threshold = AnalyticsDedupGuard::FAULT_THRESHOLD;

    // Only the guard's OWN dedup key needs mocking — it's what forces claim()
    // into its catch block. Tier 1's RateLimiter::hit() call runs against the
    // real store either way (see comment above beforeEach).
    Cache::shouldReceive('add')
        ->withArgs(fn (string $key) => $key === 'dedup:test:isolation')
        ->times($threshold - 1)
        ->andThrow(new RuntimeException('redis down'));

    $guard = new AnalyticsDedupGuard;
    for ($i = 0; $i < $threshold - 1; $i++) {
        $guard->claim('dedup:test:isolation', 'uuid-Z', 3);
    }
    Exceptions::assertNothingReported();

    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->times($threshold - 1)->andThrow(new RuntimeException('redis down'));
    $ingestor = new QueuedIngestor($bus);
    for ($i = 0; $i < $threshold - 1; $i++) {
        $ingestor->ingest(escalationIsolationEvent());
    }

    // If 'dedup' and 'ingest' shared a bucket, 2 * (threshold - 1) faults
    // would already have crossed the threshold by now.
    Exceptions::assertNothingReported();
});

it('falls back to a sampled report without ever throwing when the cache store itself is unreachable', function () {
    Exceptions::fake();

    // Seeded so the sampled draw is reproducible (see EscalatesRepeatedFaults'
    // mt_rand docblock — chosen over random_int() specifically because it's
    // seedable). Verified offline: mt_srand(42) draws at least one 1-in-200
    // hit well inside 2000 iterations, so this can't flake on this seed.
    mt_srand(42);

    // claim()'s OWN dedup write throws every time — this is what puts claim()
    // into its catch block on every iteration.
    Cache::shouldReceive('add')->andThrow(new RuntimeException('redis down'));
    Cache::shouldReceive('increment')->andThrow(new RuntimeException('redis down'));

    // And Tier 1 itself — RateLimiter::hit(), which the trait calls instead of
    // touching Cache:: directly — ALSO fails. This is what "the cache is
    // itself the casualty" means (see EscalatesRepeatedFaults docblock: you
    // cannot count failures of X using X), forcing every fault down the
    // Tier 2 sampled path. Mocking Cache:: alone would NOT do this: RateLimiter
    // holds its own captured store reference from boot (see comment above
    // beforeEach) and ignores later Cache:: mocks entirely.
    RateLimiter::shouldReceive('hit')->andThrow(new RuntimeException('redis down'));

    $guard = new AnalyticsDedupGuard;

    // Driven many times rather than pinned to an exact random draw — the hard
    // requirement is that a total cache outage can never escape claim()'s
    // fail-open contract, no matter how many consecutive faults land.
    expect(function () use ($guard) {
        for ($i = 0; $i < 2000; $i++) {
            $guard->claim('dedup:test:tier2', 'uuid-Z', 3);
        }
    })->not->toThrow(Throwable::class);

    // 2000 draws at 1-in-FAULT_SAMPLE_RATE (200) makes a zero-report outcome
    // astronomically unlikely (~4.4e-5, i.e. ~1-in-22,500) — this also proves
    // Tier 2 actually fires rather than being dead code, without asserting
    // which specific draw did it. mt_srand(42) above removes the residual
    // flake entirely.
    Exceptions::assertReported(RuntimeException::class);
});
