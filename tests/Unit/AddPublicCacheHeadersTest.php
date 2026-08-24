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
    $request = Request::create('/api/public/site-by-slug', 'GET');

    $middleware = new AddPublicCacheHeaders;
    $response = $middleware->handle($request, fn () => new Response('ok', 200));

    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    expect($cacheControl)->toContain('public')
        ->toContain('max-age=900')
        ->toContain('s-maxage=900');
    expect((string) $response->headers->get('Vary', ''))->toContain('Accept-Encoding');
    expect($response->headers->get('X-Cache-Status'))->toBe('MISS');
});

it('adds Vary: X-Site-Subdomain to allow-listed public cacheable routes', function () {
    $cacheablePaths = [
        '/api/public/site-by-slug',
    ];

    $middleware = new AddPublicCacheHeaders;

    foreach ($cacheablePaths as $path) {
        $request = Request::create($path, 'GET');
        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $vary = (string) $response->headers->get('Vary', '');
        expect($vary)->toContain('X-Site-Subdomain');
    }
});

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
    $request = Request::create('/api/public/site-by-slug', 'GET');

    $middleware = new AddPublicCacheHeaders;
    $response = $middleware->handle($request, fn () => new Response('not found', 404));

    $cacheControl = (string) $response->headers->get('Cache-Control', '');
    expect($cacheControl)->not->toContain('public');
});

it('does not cache POST requests to public paths', function () {
    $request = Request::create('/api/public/site-by-slug', 'POST');

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

// CFG-3 / stale-while-revalidate. The default MUST be byte-identical to the
// pre-SWR header, so the code ships inert and enabling it is an env-var flip.
it('omits stale-while-revalidate when the config value is 0', function () {
    config(['partna.cache.public_max_age' => 30, 'partna.cache.public_swr' => 0]);

    // site-by-slug: the generic knob. (Profiles take their own TTL and never
    // carry SWR — pinned separately below.)
    $request = Request::create('/api/public/site-by-slug', 'GET');

    $middleware = new AddPublicCacheHeaders;
    $response = $middleware->handle($request, fn () => new Response('{}', 200));

    // Exact equality, not toContain: 'max-age=30' is a substring of 's-maxage=30',
    // so a toContain assertion cannot tell the two directives apart and would pass
    // on a header that is subtly wrong.
    expect($response->headers->get('Cache-Control'))
        ->toBe('max-age=30, public, s-maxage=30');
});

it('appends stale-while-revalidate when the config value is positive', function () {
    config(['partna.cache.public_max_age' => 30, 'partna.cache.public_swr' => 60]);

    $request = Request::create('/api/public/site-by-slug', 'GET');

    $middleware = new AddPublicCacheHeaders;
    $response = $middleware->handle($request, fn () => new Response('{}', 200));

    expect($response->headers->get('Cache-Control'))
        ->toBe('max-age=30, public, s-maxage=30, stale-while-revalidate=60');
});

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

it('applies stale-while-revalidate to site-by-slug as well as profiles', function () {
    config(['partna.cache.public_max_age' => 30, 'partna.cache.public_swr' => 60]);

    $request = Request::create('/api/public/site-by-slug', 'GET');

    $middleware = new AddPublicCacheHeaders;
    $response = $middleware->handle($request, fn () => new Response('{}', 200));

    expect((string) $response->headers->get('Cache-Control', ''))
        ->toContain('stale-while-revalidate=60');
});
