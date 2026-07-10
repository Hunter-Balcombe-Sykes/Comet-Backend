<?php

use App\Providers\AppServiceProvider;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

// SEC-2: public-facing limiters must key on CF-Connecting-IP ahead of
// $request->ip(), matching the pattern already used by public-profile /
// signup-availability — a TrustProxies misconfig (or several edge nodes
// fronting the same apparent IP) must not collapse distinct clients onto one
// bucket. Exercises the registered closures directly (the KEY they derive)
// rather than firing enough real requests to trip a 429, which each
// endpoint's own feature tests already cover.
it('prefers CF-Connecting-IP over the resolved client IP for public limiters', function (string $name) {
    // The already-registered closures captured $throttleEnabled at the
    // application's original boot — BEFORE this config() override can run —
    // so setting config alone has no effect on them (local .env disables
    // throttling for dev convenience). Re-run the provider's rate-limiter
    // registration now that the override is live, same fix the codebase
    // already applies in PublicSignupAvailabilityControllerTest.
    config(['partna.throttle.enabled' => true]);
    $configureRateLimiting = new ReflectionMethod(AppServiceProvider::class, 'configureRateLimiting');
    $configureRateLimiting->invoke(new AppServiceProvider(app()));

    $limiter = app(CacheRateLimiter::class)->limiter($name);
    expect($limiter)->not->toBeNull();

    $withHeader = Request::create('/', 'POST', [], [], [], [
        'REMOTE_ADDR' => '10.0.0.1',
        'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
    ]);
    $withoutHeader = Request::create('/', 'POST', [], [], [], [
        'REMOTE_ADDR' => '203.0.113.9',
    ]);

    // First entry is always the IP-keyed bucket for the array-returning
    // limiters (leads, waitlist, public-subscribe); single-Limit limiters
    // (public-site, analytics) wrap cleanly via Arr::wrap.
    $keyed = (string) Arr::wrap($limiter($withHeader))[0]->key;
    $fallback = (string) Arr::wrap($limiter($withoutHeader))[0]->key;

    // The header-driven key must reflect the CF IP (203.0.113.9), never the
    // REMOTE_ADDR (10.0.0.1) — and must match the no-header case where
    // REMOTE_ADDR happens to equal that same CF IP value, proving the header
    // takes precedence rather than being merely appended.
    expect($keyed)->toContain('203.0.113.9')
        ->and($keyed)->not->toContain('10.0.0.1')
        ->and($keyed)->toBe($fallback);
})->with(['public-site', 'analytics', 'leads', 'waitlist', 'public-subscribe']);
