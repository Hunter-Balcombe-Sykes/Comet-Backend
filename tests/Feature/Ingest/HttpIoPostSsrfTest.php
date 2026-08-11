<?php

use App\Ingest\Manifest\Manifest;
use App\Ingest\Manifest\SourceKey;
use App\Ingest\Runtime\EffectLedger;
use App\Ingest\Runtime\EffectRefused;
use App\Ingest\Runtime\Effects\BilledEffectDriverRegistry;
use App\Ingest\Runtime\HttpIo;
use App\Services\Http\SafeUrlException;
use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\Http;

// #SEC-5, LIFE-7: HttpIo::post() used to hand the (already-validated) URL to
// the raw Http facade, which then followed Guzzle's default redirects with
// NO per-hop re-validation and NO byte cap — get()/getMany() never had this
// gap. Since HttpIo::post() now routes through SafeUrlFetcher::post()
// (sharing the send() loop with fetch()), it gets the same guarantees.
// Literal public IPs as manifest hosts keep these hermetic — no DNS mocking.

/** Build a real HttpIo whose manifest admits exactly the given hosts. */
function httpIoAdmitting(array $hosts): HttpIo
{
    $manifest = new Manifest(
        source: SourceKey::of('test_source'),
        identifierKind: 'handle',
        hosts: $hosts,
        streams: [],
    );

    return new HttpIo($manifest, app(SafeUrlFetcher::class), new EffectLedger, new BilledEffectDriverRegistry);
}

it('rejects a redirect to a loopback IP mid-chain — THIS IS THE FINDING', function () {
    Http::fake([
        '1.1.1.1/graphql' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin']),
    ]);

    $io = httpIoAdmitting(['1.1.1.1']);

    expect(fn () => $io->post('http://1.1.1.1/graphql', ['q' => 1]))
        ->toThrow(SafeUrlException::class);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
});

it('rejects a redirect to the cloud metadata endpoint', function () {
    Http::fake([
        '1.1.1.1/graphql' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
    ]);

    $io = httpIoAdmitting(['1.1.1.1']);

    expect(fn () => $io->post('http://1.1.1.1/graphql', ['q' => 1]))
        ->toThrow(SafeUrlException::class);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
});

it('rejects a redirect to an RFC1918 private address', function () {
    Http::fake([
        '1.1.1.1/graphql' => Http::response('', 302, ['Location' => 'http://10.0.0.5/']),
    ]);

    $io = httpIoAdmitting(['1.1.1.1']);

    expect(fn () => $io->post('http://1.1.1.1/graphql', ['q' => 1]))
        ->toThrow(SafeUrlException::class);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '10.0.0.5'));
});

it('rejects an oversized response body', function () {
    config()->set('partna.http_fetch.max_bytes', 16);
    Http::fake(['1.1.1.1/graphql' => Http::response(str_repeat('x', 64), 200)]);

    $io = httpIoAdmitting(['1.1.1.1']);

    expect(fn () => $io->post('http://1.1.1.1/graphql', ['q' => 1]))
        ->toThrow(SafeUrlException::class);
});

it('rejects a response whose declared Content-Length lies about being under the cap', function () {
    config()->set('partna.http_fetch.max_bytes', 16);
    Http::fake(['1.1.1.1/graphql' => Http::response('small', 200, ['Content-Length' => '1048576'])]);

    $io = httpIoAdmitting(['1.1.1.1']);

    expect(fn () => $io->post('http://1.1.1.1/graphql', ['q' => 1]))
        ->toThrow(SafeUrlException::class);
});

it('rejects a redirect chain that exceeds the configured max-redirects', function () {
    config()->set('partna.http_fetch.max_redirects', 1);

    // A→B is within budget (1 hop); B→A would be a 2nd hop, exceeding it.
    Http::fake([
        '1.1.1.1/a' => Http::response('', 302, ['Location' => 'http://8.8.8.8/b']),
        '8.8.8.8/b' => Http::response('', 302, ['Location' => 'http://1.1.1.1/a']),
    ]);

    $io = httpIoAdmitting(['1.1.1.1', '8.8.8.8']);

    expect(fn () => $io->post('http://1.1.1.1/a', ['q' => 1]))
        ->toThrow(SafeUrlException::class, 'Too many redirects');
});

it('does not replay the POST body to the redirect target on a 302 (credential-leak guard)', function () {
    Http::fake([
        '1.1.1.1/a' => Http::response('', 302, ['Location' => 'http://8.8.8.8/b']),
        '8.8.8.8/b' => Http::response('ok', 200),
    ]);

    $io = httpIoAdmitting(['1.1.1.1', '8.8.8.8']);

    $io->post('http://1.1.1.1/a', ['secret' => 'do-not-leak']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '8.8.8.8/b')) {
            return false;
        }

        return $request->method() === 'GET' && $request->data() === [];
    });
});

it('preserves method and body across a 307 redirect', function () {
    Http::fake([
        '1.1.1.1/a' => Http::response('', 307, ['Location' => 'http://8.8.8.8/b']),
        '8.8.8.8/b' => Http::response('ok', 200),
    ]);

    $io = httpIoAdmitting(['1.1.1.1', '8.8.8.8']);

    $io->post('http://1.1.1.1/a', ['keep' => 'me']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '8.8.8.8/b')) {
            return false;
        }

        return $request->method() === 'POST' && $request->data() === ['keep' => 'me'];
    });
});

it('refuses an off-manifest POST before any socket opens', function () {
    Http::fake();

    // Manifest admits only 1.1.1.1 — the target host, 8.8.8.8, is off-manifest.
    $io = httpIoAdmitting(['1.1.1.1']);

    // Regression pin: this MUST be EffectRefused, not SafeUrlException —
    // RunExecutor.php converts EffectRefused into budget_skipped WITHOUT
    // report(), while a SafeUrlException reaches the \Throwable catch and
    // IS reported. Misclassifying admission refusals here would make them
    // silently disappear the same way SSRF refusals must NOT.
    expect(fn () => $io->post('http://8.8.8.8/graphql', ['q' => 1]))
        ->toThrow(EffectRefused::class);

    Http::assertNothingSent();
});

it('returns the happy-path response unchanged, pinning the Fresha/Twitch wire format', function () {
    Http::fake(['1.1.1.1/graphql' => Http::response('{"data":{}}', 200, ['Content-Type' => 'application/json'])]);

    $io = httpIoAdmitting(['1.1.1.1']);

    $response = $io->post('http://1.1.1.1/graphql', ['query' => 'x'], [
        'content-type' => 'application/json',
        'x-graphql-operation-name' => 'mutation BookingFlow_Initialize_Mutation',
    ]);

    expect($response['status'])->toBe(200)
        ->and($response['body'])->toBe('{"data":{}}')
        ->and($response['headers']['content-type'])->toBe('application/json');

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '1.1.1.1/graphql')
            && $request->header('x-graphql-operation-name')[0] === 'mutation BookingFlow_Initialize_Mutation'
            && $request->data() === ['query' => 'x'];
    });
});
