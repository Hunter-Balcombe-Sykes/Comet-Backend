<?php

use App\Http\Middleware\AddPublicCacheHeaders;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
        '/api/public/waitlist',
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
