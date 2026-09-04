<?php

use App\Http\Middleware\AddPublicCacheHeaders;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

// CFG-3: the cacheable branch now reads config('partna.cache.public_max_age'),
// which needs a booted app container — opt into the framework TestCase like
// sibling Unit tests that touch config() (e.g. Config/ModerationConfigTest).
uses(TestCase::class)->in(__FILE__);

it('marks authenticated api responses as private and non-cacheable', function () {
    $request = Request::create('/api/customers', 'GET');
    $request->headers->set('Authorization', 'Bearer test-token');

    $middleware = new AddPublicCacheHeaders;
    $response = $middleware->handle($request, fn () => new Response('ok', 200));

    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('private')
        ->toContain('no-store')
        ->toContain('max-age=0');
    expect($response->headers->get('Pragma'))->toBe('no-cache');

    $vary = (string) $response->headers->get('Vary', '');
    expect($vary)->toContain('Authorization')
        ->toContain('Cookie')
        ->toContain('Accept-Encoding');
});

it('adds cache headers to successful public get api responses', function () {
    // api/public/profiles is the sole surviving CACHEABLE_PATH_PREFIXES entry
    // since /public/site-by-slug was retired 2026-09-04 — it always takes the
    // profile-wire override branch, so max-age/s-maxage read 5 here, not the
    // generic 900 default (pinned separately below via the profile-specific test).
    $request = Request::create('/api/public/profiles/someone', 'GET');

    $middleware = new AddPublicCacheHeaders;
    $response = $middleware->handle($request, fn () => new Response('ok', 200));

    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('public')
        ->toContain('max-age=5')
        ->toContain('s-maxage=5');
    expect((string) $response->headers->get('Vary', ''))->toContain('Accept-Encoding');
    expect($response->headers->get('X-Cache-Status'))->toBe('MISS');
});

// The "adds Vary: X-Site-Subdomain to allow-listed public cacheable routes"
// test that stood here exercised /public/site-by-slug, the one prefix that
// resolved its tenant from a header instead of the URL. Retired 2026-09-04
// alongside the route itself (see VARY_BY_PREFIX's docblock in
// AddPublicCacheHeaders, and LegacyPayloadRouteRetiredTest).

it('returns no-store for tokenized unsubscribe endpoint', function () {
    $request = Request::create('/api/public/unsubscribe/abc123token', 'GET');

    $middleware = new AddPublicCacheHeaders;
    $response = $middleware->handle($request, fn () => new Response('ok', 200));

    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('no-store');
    expect($cacheControl)->not->toContain('public');
});

it('does not add public cache headers to non-allow-listed public paths', function () {
    $nonCacheablePaths = [
        '/api/public/subscribe',
        '/api/public/customers',
        '/api/public/signup/availability',
        '/api/public/analytics/pageviews',
    ];

    $middleware = new AddPublicCacheHeaders;

    foreach ($nonCacheablePaths as $path) {
        $request = Request::create($path, 'GET');
        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $cacheControl = (string) $response->headers->get('Cache-Control', '');
        expect($cacheControl)->not->toContain('public');
    }
});

it('does not cache failed responses', function () {
    // Must be a path that IS in CACHEABLE_PATH_PREFIXES, or this proves nothing
    // beyond the already-covered "non-allow-listed path" case above.
    $request = Request::create('/api/public/profiles/someone', 'GET');

    $middleware = new AddPublicCacheHeaders;
    $response = $middleware->handle($request, fn () => new Response('not found', 404));

    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    expect($cacheControl)->not->toContain('public');
});

it('does not cache POST requests to public paths', function () {
    // Same reasoning as above: needs an allow-listed path to be a real guard.
    $request = Request::create('/api/public/profiles/someone', 'POST');

    $middleware = new AddPublicCacheHeaders;
    $response = $middleware->handle($request, fn () => new Response('ok', 200));

    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    expect($cacheControl)->not->toContain('public');
});

// RFC 9111 lets a 304 update the stored entry's headers, so a revalidation that
// answers `no-cache, private` un-caches a route we mean to cache. Measured on dev
// as a poison-then-refetch cycle: 19x200 / 18x304 at the origin, where 304s should
// dominate. See docs/superpowers/specs/2026-08-09-public-cache-304-revalidation-design.md.
it('applies the public cache contract to a 304 revalidation on an allow-listed path', function () {
    $request = Request::create('/api/public/profiles/jane', 'GET');

    $middleware = new AddPublicCacheHeaders;
    $response = $middleware->handle($request, fn () => new Response('', 304));

    // The profile wire's OWN short TTL (public_profile_max_age, default 5 —
    // owner plan 2026-08-19), not the generic 900.
    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('public')
        ->toContain('max-age=5')
        ->toContain('s-maxage=5');
    expect((string) $response->headers->get('Vary', ''))->toContain('Accept-Encoding');
});

// The two below pin that widening the STATUS check did not widen the PATH scope.
// They pass before and after the change by design.
it('does not add public cache headers to a 304 on a non-allow-listed path', function () {
    $request = Request::create('/api/customers', 'GET');

    $middleware = new AddPublicCacheHeaders;
    $response = $middleware->handle($request, fn () => new Response('', 304));

    expect((string) $response->headers->get('Cache-Control', ''))->not->toContain('public');
});

it('never adds public cache headers to a 304 on a no-store path', function () {
    $request = Request::create('/api/public/unsubscribe/tok123', 'GET');

    $middleware = new AddPublicCacheHeaders;
    $response = $middleware->handle($request, fn () => new Response('', 304));

    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('no-store');
    expect($cacheControl)->not->toContain('public');
});

// CFG-3 / stale-while-revalidate — three tests stood here ("omits
// stale-while-revalidate when the config value is 0", "appends
// stale-while-revalidate when the config value is positive", and "applies
// stale-while-revalidate to site-by-slug as well as profiles") that all used
// /public/site-by-slug as "the generic knob" (their own prior comment's
// words) to prove config('partna.cache.public_max_age')/
// config('partna.cache.public_swr') flow through into the header. Removed
// 2026-09-04 alongside that route, and NOT repointable at api/public/profiles:
// read AddPublicCacheHeaders::handle() — the `if ($prefix ===
// 'api/public/profiles')` branch unconditionally overrides $maxAge to
// config('partna.cache.public_profile_max_age', 5) and forces $swr = 0, and
// since site-by-slug's retirement that branch fires for every request that
// reaches this loop (profiles is the only entry left in
// CACHEABLE_PATH_PREFIXES). So public_max_age/public_swr, and the
// `stale-while-revalidate` directive entirely, are now dead code with no live
// route able to reach them — there is nothing left for a test to assert
// against without inventing a route the app doesn't have. This is a bigger
// consequence than just losing these three tests: flagged in the task-1-report
// fix-round-1 section for the plan owner, since config/partna.php's
// `public_swr` key and this branch's generic (non-profile) path are candidates
// for their own follow-up cleanup, not something this task's scope covers.

it('gives the profile wire its own short TTL and never SWR (owner plan, 2026-08-19)', function () {
    config(['partna.cache.public_max_age' => 900, 'partna.cache.public_swr' => 60, 'partna.cache.public_profile_max_age' => 5]);

    $request = Request::create('/api/public/profiles/someone', 'GET');

    $middleware = new AddPublicCacheHeaders;
    $response = $middleware->handle($request, fn () => new Response('{}', 200));

    // The router's HTML cache is purged the instant an edit lands; the render
    // that follows reads this wire through an edge outside our purge reach, so
    // this TTL bounds how stale that render can be. Exact equality: no SWR.
    expect($response->headers->get('Cache-Control'))
        ->toBe('max-age=5, public, s-maxage=5');
});
