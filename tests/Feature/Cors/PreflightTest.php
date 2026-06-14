<?php

/** @phpstan-ignore-all */

/**
 * Pins CORS preflight response shape. Without these headers the browser must
 * re-issue an OPTIONS round-trip on every fetch, which observably produced
 * ~140 redundant preflights per dashboard page load. See config/cors.php.
 */
it('returns Access-Control-Max-Age on api preflight responses', function () {
    $response = $this->call(
        method: 'OPTIONS',
        uri: '/api/me',
        server: [
            'HTTP_ORIGIN' => 'https://app.partna.au',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ],
    );

    $response->assertNoContent(); // 204
    expect($response->headers->get('Access-Control-Max-Age'))->toBe((string) config('cors.max_age'));
    expect((int) config('cors.max_age'))->toBeGreaterThanOrEqual(7200); // Chromium cap
});

it('keeps cors.max_age at the value the frontend dashboard relies on', function () {
    expect(config('cors.max_age'))->toBe(86400);
});

it('allows a preflight from the apex marketing origin (SEC-2)', function () {
    // The apex https://partna.au has no subdomain label; the prior regex required one
    // and silently denied it. The optional-label pattern must now allow the apex.
    $response = $this->call(
        method: 'OPTIONS',
        uri: '/api/me',
        server: [
            'HTTP_ORIGIN' => 'https://partna.au',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ],
    );

    $response->assertNoContent();
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://partna.au');
});

it('still rejects a look-alike origin that extends the apex host (SEC-2 anchor)', function () {
    $response = $this->call(
        method: 'OPTIONS',
        uri: '/api/me',
        server: [
            'HTTP_ORIGIN' => 'https://partna.au.attacker.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ],
    );

    // Not an allowed origin → no Allow-Origin echo for the attacker host.
    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://partna.au.attacker.com');
});
