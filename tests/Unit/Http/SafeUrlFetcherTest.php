<?php

use App\Services\Http\SafeUrlException;
use App\Services\Http\SafeUrlFetcher;
use Illuminate\Http\Client\ConnectionException;
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

// ── 403 honest-UA fallback retry (WS-B1.3) ────────────────────────────────────
//
// Some hosting WAFs (SiteGround et al. — bluelane.co / fearnoevil.com.au were
// the live repro) 403 any `Mozilla/…` UA whose TLS fingerprint isn't a real
// browser, while an honest non-Mozilla bot UA passes. fetch() retries such
// 403s once with FALLBACK_USER_AGENT. Literal public IPs keep these hermetic.

it('retries a 403 once with the honest bot UA and returns the passing response', function () {
    Http::fake(function ($request) {
        $ua = $request->header('User-Agent')[0] ?? '';

        return str_starts_with($ua, 'Mozilla/')
            ? Http::response('blocked', 403)
            : Http::response('[{"id":1}]', 200, ['Content-Type' => 'application/json']);
    });

    $out = app(SafeUrlFetcher::class)->fetch('https://1.1.1.1/wp-json/wc/store/v1/products', [
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    ]);

    expect($out['status'])->toBe(200);
    expect($out['body'])->toBe('[{"id":1}]');
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => ($request->header('User-Agent')[0] ?? '') === SafeUrlFetcher::FALLBACK_USER_AGENT);
});

it('keeps the original 403 when the honest-UA retry is also blocked', function () {
    Http::fake(['*' => Http::response('nope', 403)]);

    // No UA passed → the default (Mozilla-prefixed) UA applies, so the retry fires.
    $out = app(SafeUrlFetcher::class)->fetch('https://1.1.1.1/x');

    expect($out['status'])->toBe(403);
    Http::assertSentCount(2);
});

it('keeps the original 403 when the retry leg fails at transport level', function () {
    // The retry must never worsen the outcome: a ConnectionException on the
    // fallback-UA leg is swallowed, not propagated over the 403 we already have.
    Http::fake(function ($request) {
        $ua = $request->header('User-Agent')[0] ?? '';

        return str_starts_with($ua, 'Mozilla/')
            ? Http::response('blocked', 403)
            : throw new ConnectionException('timed out');
    });

    $out = app(SafeUrlFetcher::class)->fetch('https://1.1.1.1/x');

    expect($out['status'])->toBe(403);
    expect($out['body'])->toBe('blocked');
});

it('does not retry a 403 when the caller already sent a non-Mozilla UA', function () {
    Http::fake(['*' => Http::response('nope', 403)]);

    $out = app(SafeUrlFetcher::class)->fetch('https://1.1.1.1/x', ['User-Agent' => SafeUrlFetcher::FALLBACK_USER_AGENT]);

    expect($out['status'])->toBe(403);
    Http::assertSentCount(1);
});

it('does not retry non-403 error statuses', function () {
    Http::fake(['*' => Http::response('missing', 404)]);

    $out = app(SafeUrlFetcher::class)->fetch('https://1.1.1.1/x');

    expect($out['status'])->toBe(404);
    Http::assertSentCount(1);
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

// ── fetchMany() 403 honest-UA fallback (WS-B1) ────────────────────────────────
//
// The refresh fleets go through fetchMany(), which — before WS-B1 — lacked the
// one-shot honest-UA retry fetch() has, so bulk refreshes of WAF-guarded
// WooCommerce stores silently 403'd where a single fetch() would have recovered.

it('fetchMany retries a 403 once with the honest bot UA and returns the passing response', function () {
    Http::fake(function ($request) {
        $ua = $request->header('User-Agent')[0] ?? '';

        return str_starts_with($ua, 'Mozilla/')
            ? Http::response('blocked', 403)
            : Http::response('[{"id":1}]', 200, ['Content-Type' => 'application/json']);
    });

    $url = 'https://1.1.1.1/wp-json/wc/store/v1/products';
    $out = app(SafeUrlFetcher::class)->fetchMany([$url]);

    expect($out[$url]['status'])->toBe(200);
    expect($out[$url]['body'])->toBe('[{"id":1}]');
    Http::assertSent(fn ($request) => ($request->header('User-Agent')[0] ?? '') === SafeUrlFetcher::FALLBACK_USER_AGENT);
});

it('fetchMany keeps the original 403 when the honest-UA retry is also blocked', function () {
    Http::fake(['*' => Http::response('nope', 403)]);

    // Default (Mozilla-prefixed) UA applies → the retry fires exactly once.
    $out = app(SafeUrlFetcher::class)->fetchMany(['https://1.1.1.1/x']);

    expect($out['https://1.1.1.1/x']['status'])->toBe(403);
    Http::assertSentCount(2);
});

it('fetchMany does not retry a 403 when the caller already sent a non-Mozilla UA', function () {
    Http::fake(['*' => Http::response('nope', 403)]);

    $out = app(SafeUrlFetcher::class)->fetchMany(['https://1.1.1.1/x'], ['User-Agent' => SafeUrlFetcher::FALLBACK_USER_AGENT]);

    expect($out['https://1.1.1.1/x']['status'])->toBe(403);
    Http::assertSentCount(1);
});

// ── response body byte cap (SafeUrlFetcher size cap) ──────────────────────────
//
// A terminal response whose actual body OR declared Content-Length exceeds
// config('partna.http_fetch.max_bytes') is rejected: fetch() throws
// SafeUrlException, fetchMany() drops it to null. Guards the link-preview and
// menu/shop scrapers from an oversized/hostile body.

it('throws when the response body exceeds the byte cap', function () {
    config()->set('partna.http_fetch.max_bytes', 16);
    Http::fake(['*' => Http::response(str_repeat('x', 64), 200)]);

    expect(fn () => app(SafeUrlFetcher::class)->fetch('https://1.1.1.1/big'))
        ->toThrow(SafeUrlException::class);
});

it('throws when the declared Content-Length exceeds the byte cap even if the body is small', function () {
    config()->set('partna.http_fetch.max_bytes', 16);
    Http::fake(['*' => Http::response('small', 200, ['Content-Length' => '1048576'])]);

    expect(fn () => app(SafeUrlFetcher::class)->fetch('https://1.1.1.1/liar'))
        ->toThrow(SafeUrlException::class);
});

it('allows a response within the byte cap', function () {
    config()->set('partna.http_fetch.max_bytes', 1024);
    Http::fake(['*' => Http::response('tiny', 200)]);

    $out = app(SafeUrlFetcher::class)->fetch('https://1.1.1.1/ok');

    expect($out['status'])->toBe(200)->and($out['body'])->toBe('tiny');
});

it('fetchMany drops a URL whose body exceeds the byte cap', function () {
    config()->set('partna.http_fetch.max_bytes', 16);
    Http::fake(['*' => Http::response(str_repeat('x', 64), 200)]);

    $out = app(SafeUrlFetcher::class)->fetchMany(['https://1.1.1.1/big']);

    expect($out['https://1.1.1.1/big'])->toBeNull();
});

// ── post() — mirrors the redirect-revalidation and byte-cap guarantees ────────
//
// post() shares fetch()'s send() loop (#SEC-5, LIFE-7), so it gets the same
// per-hop SSRF re-validation and byte cap. Unlike fetchMany(), it throws
// rather than silently dropping — same contract as fetch().

it('post() rejects a redirect to a private IP (SSRF re-validation)', function () {
    Http::fake([
        '1.1.1.1/start' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin']),
    ]);

    expect(fn () => app(SafeUrlFetcher::class)->post('http://1.1.1.1/start', ['a' => 1]))
        ->toThrow(SafeUrlException::class);

    // The private-IP redirect target must never actually be requested.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
});

it('post() throws when the response body exceeds the byte cap', function () {
    config()->set('partna.http_fetch.max_bytes', 16);
    Http::fake(['*' => Http::response(str_repeat('x', 64), 200)]);

    expect(fn () => app(SafeUrlFetcher::class)->post('https://1.1.1.1/big', ['a' => 1]))
        ->toThrow(SafeUrlException::class);
});

// ── tryFetchToFile(): the streaming path (#SCALE-3) ──────────────────────────
//
// The buffering path's byte cap is explicitly NOT a memory guard —
// assertWithinByteCap()'s own docblock says Guzzle has already buffered the
// body by the time it runs. tryFetchToFile() is the path that never lets the
// body become a PHP string, so what these pin is that the bytes reach the FILE
// and that every guarantee fetch() makes still holds on the way there.

it('tryFetchToFile() writes the body to the destination and returns no body key', function () {
    $destination = tempnam(sys_get_temp_dir(), 'sink-test-');
    Http::fake(['*' => Http::response('streamed-payload', 200, ['Content-Type' => 'video/mp4'])]);

    try {
        $result = app(SafeUrlFetcher::class)->tryFetchToFile('https://1.1.1.1/clip.mp4', $destination);

        expect($result)->not->toBeNull()
            ->and($result['status'])->toBe(200)
            ->and($result['contentType'])->toBe('video/mp4')
            // The absent key is the contract, not an empty one: a caller that
            // reaches for ->['body'] here must fail loudly rather than quietly
            // read "" and mirror an empty object.
            ->and(array_key_exists('body', $result))->toBeFalse()
            ->and(file_get_contents($destination))->toBe('streamed-payload');
    } finally {
        @unlink($destination);
    }
});

it('tryFetchToFile() enforces the byte cap on what actually landed on disk', function () {
    // Not just the declared Content-Length: the sink cap must measure the file,
    // because a chunked response declares nothing at all.
    config()->set('partna.http_fetch.max_bytes', 16);
    $destination = tempnam(sys_get_temp_dir(), 'sink-test-');
    Http::fake(['*' => Http::response(str_repeat('x', 64), 200)]);

    try {
        expect(app(SafeUrlFetcher::class)->tryFetchToFile('https://1.1.1.1/big', $destination))->toBeNull();
    } finally {
        @unlink($destination);
    }
});

it('tryFetchToFile() honours a raised per-call cap', function () {
    // The MediaMirror case: withMaxBytes() must still apply on the sink path,
    // or a reel would be rejected at the 10 MB page default.
    config()->set('partna.http_fetch.max_bytes', 16);
    $destination = tempnam(sys_get_temp_dir(), 'sink-test-');
    Http::fake(['*' => Http::response(str_repeat('x', 64), 200)]);

    try {
        $result = app(SafeUrlFetcher::class)->withMaxBytes(1024)->tryFetchToFile('https://1.1.1.1/big', $destination);

        expect($result)->not->toBeNull()
            ->and(strlen((string) file_get_contents($destination)))->toBe(64);
    } finally {
        @unlink($destination);
    }
});

it('tryFetchToFile() re-validates every redirect hop and never fetches a private target', function () {
    // The whole reason the sink was threaded through send() rather than given
    // its own loop: one redirect-following implementation, one set of SSRF
    // guarantees. A second copy is how a hole gets added later.
    $destination = tempnam(sys_get_temp_dir(), 'sink-test-');
    Http::fake([
        '1.1.1.1/start' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin']),
    ]);

    try {
        expect(app(SafeUrlFetcher::class)->tryFetchToFile('http://1.1.1.1/start', $destination))->toBeNull();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
    } finally {
        @unlink($destination);
    }
});

it('tryFetchToFile() truncates a stale destination rather than appending to it', function () {
    // Guzzle opens the sink with 'w'. If that ever changed, a retried mirror
    // would concatenate two downloads and store a corrupt object under a hash
    // of the concatenation — which would look like a successful mirror.
    $destination = tempnam(sys_get_temp_dir(), 'sink-test-');
    file_put_contents($destination, str_repeat('STALE', 100));
    Http::fake(['*' => Http::response('fresh', 200)]);

    try {
        app(SafeUrlFetcher::class)->tryFetchToFile('https://1.1.1.1/x', $destination);

        expect(file_get_contents($destination))->toBe('fresh');
    } finally {
        @unlink($destination);
    }
});

it('tryFetchToFile() keeps the original 403 when the honest-UA retry fails harder', function () {
    // Pins the acceptance condition at `< 400`, matching fetch(). An earlier
    // cut used `!== 403`, which let a 500 from the retry replace the original
    // 403 — discarding the real answer for a worse one, while the method's own
    // docblock promised the opposite. Inert for MediaMirror today (it collapses
    // every non-2xx to fetch_failed) and pinned so it stays that way.
    $destination = tempnam(sys_get_temp_dir(), 'sink-test-');
    $seen = [];
    Http::fake(function ($request) use (&$seen) {
        $seen[] = $request->header('User-Agent')[0] ?? '';

        return count($seen) === 1
            ? Http::response('denied', 403)
            : Http::response('exploded', 500);
    });

    try {
        $result = app(SafeUrlFetcher::class)->tryFetchToFile('https://1.1.1.1/x', $destination);

        // The retry DID happen (browser-ish UA first, honest bot UA second)...
        expect($seen)->toHaveCount(2)
            ->and($seen[1])->toBe(SafeUrlFetcher::FALLBACK_USER_AGENT)
            // ...and its 500 was discarded in favour of the original 403.
            ->and($result['status'])->toBe(403);
    } finally {
        @unlink($destination);
    }
});
