<?php

use App\Support\ThrottledReport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// B3: the shared throttle seam behind #W1-OBS-1 / #W2-OBS-4 / #W2-OBS-5.
// Mirrors IdempotencyKey::logFailOpen's shape verbatim.

it('reports the first call and throttles a second within the TTL', function () {
    Exceptions::fake();

    // THROTTLE PROOF: both calls in the SAME test body — CACHE_STORE is
    // 'array' (per-process) in tests, so this is the only way to observe it.
    ThrottledReport::once('throttled_report_test:key', new RuntimeException('first'));
    ThrottledReport::once('throttled_report_test:key', new RuntimeException('second'));

    Exceptions::assertReportedCount(1);
});

it('keys the cooldown per vendor+status, so one fault never mutes a different one', function () {
    Exceptions::fake();

    ThrottledReport::once('throttled_report_test:key_a', new RuntimeException('a'));
    ThrottledReport::once('throttled_report_test:key_b', new RuntimeException('b'));

    Exceptions::assertReportedCount(2);
});

it('reports anyway when the lock store itself is unreachable — WHK-3 no-self-muting', function () {
    Exceptions::fake();
    Cache::shouldReceive('lock')->andThrow(new RuntimeException('cache_locks store down'));

    ThrottledReport::once('throttled_report_test:dead_store', new RuntimeException('boom'));

    // Not 0: a broken throttle layer must not silently swallow the fault.
    Exceptions::assertReportedCount(1);
});
