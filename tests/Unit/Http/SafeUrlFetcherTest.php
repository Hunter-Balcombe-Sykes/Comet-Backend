<?php

use App\Services\Http\SafeUrlException;
use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\Http;
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

// ── fetchMany() — independent redirect-following + re-validation path ─────────
//
// fetchMany() re-implements redirect-following on top of Http::pool() rather
// than reusing fetch()'s loop, so its SSRF re-validation of redirect targets
// needs its own coverage. Literal public/private IPs (as above) keep these
// hermetic — no DNS mocking needed.

it('drops a URL whose redirect target resolves to a private IP (SSRF re-validation)', function () {
    Http::fake([
        '1.1.1.1/start' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin']),
    ]);

    $out = app(SafeUrlFetcher::class)->fetchMany(['http://1.1.1.1/start']);

    expect($out)->toHaveKey('http://1.1.1.1/start')
        ->and($out['http://1.1.1.1/start'])->toBeNull();

    // The private-IP redirect target must never actually be requested.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
});

it('drops a URL once its redirect chain exceeds the configured max-redirects', function () {
    config()->set('partna.http_fetch.max_redirects', 1);

    // A→B is within budget (1 hop); B→A would be a 2nd hop, which exceeds it.
    Http::fake([
        '1.1.1.1/a' => Http::response('', 302, ['Location' => 'http://8.8.8.8/b']),
        '8.8.8.8/b' => Http::response('', 302, ['Location' => 'http://1.1.1.1/a']),
    ]);

    $out = app(SafeUrlFetcher::class)->fetchMany(['http://1.1.1.1/a']);

    expect($out['http://1.1.1.1/a'])->toBeNull();
});

it('pools multiple URLs concurrently and keys results by the original URL', function () {
    Http::fake([
        '1.1.1.1/*' => Http::response('one', 200),
        '8.8.8.8/*' => Http::response('two', 200),
    ]);

    $urls = ['https://1.1.1.1/x', 'https://8.8.8.8/y'];
    $out = app(SafeUrlFetcher::class)->fetchMany($urls);

    expect(array_keys($out))->toEqualCanonicalizing($urls);
    expect($out['https://1.1.1.1/x']['status'])->toBe(200);
    expect($out['https://1.1.1.1/x']['body'])->toBe('one');
    expect($out['https://1.1.1.1/x']['finalUrl'])->toBe('https://1.1.1.1/x');
    expect($out['https://8.8.8.8/y']['body'])->toBe('two');
});
