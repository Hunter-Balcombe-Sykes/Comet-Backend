<?php

use App\Http\Middleware\Throttle\FailOpenThrottleRequests;

/**
 * Both allow-lists are security decisions, not configuration. This test exists
 * so that widening either one is a deliberate, reviewed act with a visible
 * diff — the same role the docblocks on both constants describe.
 */
function throttleLimiterList(string $constant): array
{
    $reflection = new ReflectionClass(FailOpenThrottleRequests::class);

    return $reflection->getConstant($constant);
}

it('opens exactly five limiters and no more', function () {
    // Opening a limiter means anyone who can kill the store can disable it.
    // All five guard idempotent reads or a beacon that already fail-opens by
    // design, so the abuse ceiling is DB read load — not data or account
    // compromise. Adding a WRITE surface here would break that reasoning.
    expect(throttleLimiterList('FAIL_OPEN_LIMITERS'))->toBe([
        'public-site',
        'public-profile',
        'analytics',
        'analytics-click',
        'health-check',
    ]);
});

it('falls back to Postgres for exactly one limiter', function () {
    // The entry bar: what Postgres table ALREADY counts this thing? `leads`
    // qualifies because both its controllers synchronously write
    // analytics.lead_submissions on every outcome. A counter written solely to
    // satisfy a fallback has no independent reason to be correct, so
    // `public-subscribe` does not qualify and must not be added here.
    expect(throttleLimiterList('FALLBACK_LIMITERS'))->toBe(['leads']);
});

it('keeps the two lists disjoint', function () {
    // A limiter in both would take whichever branch modeFor() checks first —
    // a silent, order-dependent security posture.
    expect(array_intersect(
        throttleLimiterList('FAIL_OPEN_LIMITERS'),
        throttleLimiterList('FALLBACK_LIMITERS'),
    ))->toBeEmpty();
});
