<?php

use App\Providers\AppServiceProvider;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as IlluminateRoute;
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
    // limiters (leads, early-access, public-subscribe); single-Limit limiters
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
})->with([
    'public-site', 'analytics', 'leads', 'public-subscribe',
    // SCALE-1/2/3 (scale-health audit): analytics-click, document-download, and
    // partna.moderation.report were still keying on $request->ip() alone.
    'analytics-click', 'document-download', 'partna.moderation.report',
]);

/**
 * SSR aggregation: the Astro renderer calls /api/public/profiles/{handle}
 * server-side, so the API sees Cloudflare egress IPs (measured 2026-08-10:
 * 172.69.186.143, 162.158.3.134), NOT visitor IPs. Keyed on IP alone, every
 * visitor to every sitepage collapses into one bucket per renderer IP, and the
 * ceiling scales with how many sitepages exist rather than with abuse.
 *
 * Keying on handle|ip gives each sitepage its own bucket per caller. The IP
 * stays in the key deliberately: keying on handle ALONE would put every
 * Cloudflare location's revalidation for a viral page into one bucket
 * (~0.2/min per location x ~300 locations ~= the 60/min limit).
 */
function publicProfileLimiterKey(Closure $limiter, string $handle, string $cfIp): string
{
    $request = Request::create("/api/public/profiles/{$handle}", 'GET', [], [], [], [
        'HTTP_CF_CONNECTING_IP' => $cfIp,
    ]);

    $route = new IlluminateRoute('GET', 'api/public/profiles/{handle}', []);
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);

    return (string) Arr::wrap($limiter($request))[0]->key;
}

it('gives each handle its own public-profile bucket so one renderer IP cannot exhaust every sitepage', function () {
    config(['partna.throttle.enabled' => true]);
    (new ReflectionMethod(AppServiceProvider::class, 'configureRateLimiting'))
        ->invoke(new AppServiceProvider(app()));

    $limiter = app(CacheRateLimiter::class)->limiter('public-profile');
    expect($limiter)->not->toBeNull();

    $jane = publicProfileLimiterKey($limiter, 'jane', '203.0.113.9');
    $bob = publicProfileLimiterKey($limiter, 'bob', '203.0.113.9');

    expect($jane)->not->toBe($bob);
});

it('keeps the caller IP in the public-profile key so one handle is still capped per caller', function () {
    config(['partna.throttle.enabled' => true]);
    (new ReflectionMethod(AppServiceProvider::class, 'configureRateLimiting'))
        ->invoke(new AppServiceProvider(app()));

    $limiter = app(CacheRateLimiter::class)->limiter('public-profile');

    $renderer = publicProfileLimiterKey($limiter, 'jane', '203.0.113.9');
    $attacker = publicProfileLimiterKey($limiter, 'jane', '198.51.100.7');

    expect($renderer)->toContain('203.0.113.9')
        ->and($renderer)->not->toBe($attacker);
});
