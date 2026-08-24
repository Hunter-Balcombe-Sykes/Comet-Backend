<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('trusts X-Forwarded-For from a genuine Cloudflare edge IP', function () {
    Route::get('/_test/proxy-ip', fn (Request $request) => ['ip' => $request->ip()]);

    $response = $this->call('GET', '/_test/proxy-ip', [], [], [], [
        // 173.245.48.0/20 — one of Cloudflare's published ranges.
        'REMOTE_ADDR' => '173.245.48.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.1',
    ]);

    $response->assertOk();
    expect($response->json('ip'))->toBe('203.0.113.1');
});

// Task 2: trustProxies(at: '*') trusted every immediate peer's
// X-Forwarded-For unconditionally, so an attacker reaching the origin
// directly (bypassing Cloudflare) could forge the header and every
// IP-keyed rate limiter would believe it. Restricting trust to Cloudflare's
// published ranges means a peer outside them is not trusted to set the
// client IP — the real (untrusted, but genuine) REMOTE_ADDR wins instead.
it('ignores a forged X-Forwarded-For from a peer that is not Cloudflare', function () {
    Route::get('/_test/proxy-ip', fn (Request $request) => ['ip' => $request->ip()]);

    $response = $this->call('GET', '/_test/proxy-ip', [], [], [], [
        'REMOTE_ADDR' => '198.51.100.7',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.1',
    ]);

    $response->assertOk();
    expect($response->json('ip'))->toBe('198.51.100.7');
});

it('resolves last hop IP when no forwarded header is present', function () {
    Route::get('/_test/proxy-ip', fn (Request $request) => ['ip' => $request->ip()]);

    $response = $this->call('GET', '/_test/proxy-ip', [], [], [], [
        'REMOTE_ADDR' => '127.0.0.1',
    ]);

    $response->assertOk();
    expect($response->json('ip'))->toBe('127.0.0.1');
});
