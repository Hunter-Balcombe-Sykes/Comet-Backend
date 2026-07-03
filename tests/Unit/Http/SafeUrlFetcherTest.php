<?php

use App\Services\Http\SafeUrlException;
use App\Services\Http\SafeUrlFetcher;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('rejects loopback IPv4', function () {
    expect(fn () => app(SafeUrlFetcher::class)->fetch('http://127.0.0.1/'))
        ->toThrow(SafeUrlException::class);
});

it('rejects the cloud metadata endpoint', function () {
    expect(fn () => app(SafeUrlFetcher::class)->fetch('http://169.254.169.254/latest/meta-data/'))
        ->toThrow(SafeUrlException::class);
});

it('rejects private RFC1918 ranges', function () {
    expect(fn () => app(SafeUrlFetcher::class)->fetch('http://10.0.0.5/x'))
        ->toThrow(SafeUrlException::class);
    expect(fn () => app(SafeUrlFetcher::class)->fetch('http://192.168.1.1/x'))
        ->toThrow(SafeUrlException::class);
});

it('rejects loopback IPv6', function () {
    expect(fn () => app(SafeUrlFetcher::class)->fetch('http://[::1]/'))
        ->toThrow(SafeUrlException::class);
});

it('rejects non-http(s) schemes', function () {
    expect(fn () => app(SafeUrlFetcher::class)->fetch('ftp://example.com/x'))
        ->toThrow(SafeUrlException::class);
    expect(fn () => app(SafeUrlFetcher::class)->fetch('file:///etc/passwd'))
        ->toThrow(SafeUrlException::class);
});

// ── assertSafe() is now public — callable without going through fetch() ───────

it('assertSafe() is public and rejects a loopback IP directly', function () {
    // Proves the method is accessible as a public API (used by InstagramConnectJob
    // as a defence-in-depth IP-resolution layer on top of the CDN host allowlist).
    expect(fn () => app(SafeUrlFetcher::class)->assertSafe('http://127.0.0.1/'))
        ->toThrow(SafeUrlException::class);
});

it('assertSafe() accepts a literal public IP without throwing', function () {
    // 1.1.1.1 is Cloudflare's globally-routed public DNS resolver — passes
    // FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE without a DNS lookup.
    expect(fn () => app(SafeUrlFetcher::class)->assertSafe('http://1.1.1.1/'))
        ->not->toThrow(SafeUrlException::class);
});
