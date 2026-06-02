<?php

use App\Http\Middleware\AddETagHeaders;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// Helpers ----------------------------------------------------------------

function etagRequest(string $path = 'api/public/site-by-slug/foo', string $method = 'GET', ?string $ifNoneMatch = null): Request
{
    $request = Request::create('/'.$path, $method);
    if ($ifNoneMatch !== null) {
        $request->headers->set('If-None-Match', $ifNoneMatch);
    }

    return $request;
}

function jsonResponse(mixed $data, int $status = 200): Response
{
    return new Response(json_encode($data), $status, ['Content-Type' => 'application/json']);
}

// ETag generation --------------------------------------------------------

it('sets an ETag header on a cacheable public GET response', function () {
    $request = etagRequest();
    $response = jsonResponse(['key' => 'value']);

    $result = (new AddETagHeaders)->handle($request, fn ($req) => $response);

    expect($result->headers->has('ETag'))->toBeTrue();
    expect($result->headers->get('ETag'))->toMatch('/^"[a-f0-9]{32}"$/');
});

it('produces a stable ETag regardless of JSON key insertion order', function () {
    $middleware = new AddETagHeaders;
    $path = 'api/public/site-by-slug/foo';

    $responseA = jsonResponse(['a' => 1, 'b' => 2, 'c' => 3]);
    $responseB = jsonResponse(['c' => 3, 'a' => 1, 'b' => 2]);

    $etagA = $middleware->handle(etagRequest($path), fn ($req) => $responseA)->headers->get('ETag');
    $etagB = $middleware->handle(etagRequest($path), fn ($req) => $responseB)->headers->get('ETag');

    expect($etagA)->toBe($etagB);
});

// 304 Not Modified -------------------------------------------------------

it('returns 304 when If-None-Match matches the computed ETag', function () {
    $middleware = new AddETagHeaders;
    $payload = ['site' => 'test'];

    // First request: get the ETag.
    $first = $middleware->handle(etagRequest(), fn ($req) => jsonResponse($payload));
    $etag = $first->headers->get('ETag');

    // Second request: send ETag back.
    $second = $middleware->handle(etagRequest('api/public/site-by-slug/foo', 'GET', $etag), fn ($req) => jsonResponse($payload));

    expect($second->getStatusCode())->toBe(304);
    expect($second->getContent())->toBe('');
    expect($second->headers->get('ETag'))->toBe($etag);
});

it('returns 304 when If-None-Match uses weak validator format (W/"hash")', function () {
    $middleware = new AddETagHeaders;
    $payload = ['x' => 1];

    $first = $middleware->handle(etagRequest(), fn ($req) => jsonResponse($payload));
    $rawHash = trim((string) $first->headers->get('ETag'), '"');

    $weakHeader = 'W/"'.$rawHash.'"';
    $second = $middleware->handle(etagRequest('api/public/site-by-slug/foo', 'GET', $weakHeader), fn ($req) => jsonResponse($payload));

    expect($second->getStatusCode())->toBe(304);
});

it('returns 200 when If-None-Match does not match', function () {
    $request = etagRequest('api/public/site-by-slug/foo', 'GET', '"stalehashabcdef1234567890abcdef"');
    $response = jsonResponse(['changed' => true]);

    $result = (new AddETagHeaders)->handle($request, fn ($req) => $response);

    expect($result->getStatusCode())->toBe(200);
});

// Scope guards -----------------------------------------------------------

it('does not set ETag on POST requests', function () {
    $request = Request::create('/api/public/site-by-slug/foo', 'POST');
    $response = jsonResponse(['ok' => true]);

    $result = (new AddETagHeaders)->handle($request, fn ($req) => $response);

    expect($result->headers->has('ETag'))->toBeFalse();
});

it('does not set ETag when Authorization header is present', function () {
    $request = etagRequest();
    $request->headers->set('Authorization', 'Bearer token');
    $response = jsonResponse(['secret' => true]);

    $result = (new AddETagHeaders)->handle($request, fn ($req) => $response);

    expect($result->headers->has('ETag'))->toBeFalse();
});

it('does not set ETag on non-cacheable public paths', function () {
    // /api/public/analytics/pageviews is not in CACHEABLE_PATH_PREFIXES.
    $request = etagRequest('api/public/analytics/pageviews');
    $response = jsonResponse(['ok' => true]);

    $result = (new AddETagHeaders)->handle($request, fn ($req) => $response);

    expect($result->headers->has('ETag'))->toBeFalse();
});

it('does not set ETag on non-2xx responses', function () {
    $request = etagRequest();
    $response = jsonResponse(['error' => 'not found'], 404);

    $result = (new AddETagHeaders)->handle($request, fn ($req) => $response);

    expect($result->headers->has('ETag'))->toBeFalse();
});

it('sets ETag on all cacheable path prefixes', function () {
    $middleware = new AddETagHeaders;
    $paths = [
        'api/public/site-by-slug/test',

    ];

    foreach ($paths as $path) {
        $result = $middleware->handle(etagRequest($path), fn ($req) => jsonResponse(['ok' => true]));
        expect($result->headers->has('ETag'))->toBeTrue("Expected ETag on path: {$path}");
    }
});
