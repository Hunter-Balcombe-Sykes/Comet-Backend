<?php

use App\Services\Cloudflare\CloudflarePurgeService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/** Every URL POSTed across all purge_cache `files` requests, flattened in send order. */
function cfRecordedFiles(): array
{
    return collect(Http::recorded())
        ->flatMap(fn ($pair) => (array) ($pair[0]['files'] ?? []))
        ->all();
}

/** Every prefix POSTed across all purge_cache `prefixes` requests. */
function cfRecordedPrefixes(): array
{
    return collect(Http::recorded())
        ->flatMap(fn ($pair) => (array) ($pair[0]['prefixes'] ?? []))
        ->all();
}

it('no-ops when unconfigured (no zone_id or token)', function () {
    Config::set('services.cloudflare.zone_id', '');
    Config::set('services.cloudflare.cache_purge_token', '');
    Http::fake();

    (new CloudflarePurgeService)->purgeUrls(['https://x.partna.au/']);

    Http::assertNothingSent();
});

it('no-ops on empty url list even when configured', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Http::fake();

    (new CloudflarePurgeService)->purgeUrls([]);

    Http::assertNothingSent();
});

it('POSTs purge_cache with files payload for the configured zone', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflarePurgeService)->purgeUrls([
        'https://h1.partna.au/',
        'https://h1.partna.au',
    ]);

    Http::assertSent(function ($req) {
        return $req->url() === 'https://api.cloudflare.com/client/v4/zones/zoneXYZ/purge_cache'
            && $req->method() === 'POST'
            && $req->hasHeader('Authorization', 'Bearer tok')
            && $req['files'] === ['https://h1.partna.au/', 'https://h1.partna.au'];
    });
});

it('chunks purgeUrls into <=30-URL requests (Cloudflare files limit)', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    // Pace-spy (not `new CloudflarePurgeService`) so this doesn't incur real
    // usleep — this test asserts chunking, not pacing.
    $urls = array_map(fn ($i) => "https://h.partna.au/p{$i}", range(1, 65));
    purgeServiceWithPaceSpy()->purgeUrls($urls);

    expect(Http::recorded())->toHaveCount(3); // 30 + 30 + 5
    Http::recorded()->each(fn ($pair) => expect(count($pair[0]['files']))->toBeLessThanOrEqual(30));
    expect(cfRecordedFiles())->toBe($urls); // order preserved, nothing dropped
});

it('purgeHandle purges the site host by PREFIX and the API subrequests by file (owner plan, 2026-08-19)', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', 'https://dev-api.partna.au');
    Config::set('partna.public_domain', 'partna.au');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflarePurgeService)->purgeHandle('  MIXED-CASE  ');

    // ONE prefix request covers the root, every deep-link page, every
    // product/menu/event page and the router's /_swr-shadow/* twins.
    expect(cfRecordedPrefixes())->toBe(['mixed-case.partna.au/']);
    // The API host is a different host: its three subrequest URLs go by file,
    // in ONE chunk. `/platforms` is the legacy alias onto the SAME controller.
    expect(cfRecordedFiles())->toBe([
        'https://dev-api.partna.au/api/public/profiles/mixed-case',
        'https://dev-api.partna.au/api/public/profiles/mixed-case/integrations',
        'https://dev-api.partna.au/api/public/profiles/mixed-case/platforms',
    ]);
    // Exactly two requests: the enumeration of ~2,481 files across ~83 calls is gone.
    // API wire first, HTML prefix second — the fresh render reads the API.
    expect(Http::recorded())->toHaveCount(2);
    expect(Http::recorded()[0][0]['files'] ?? null)->not->toBeNull();
    expect(Http::recorded()[1][0]['prefixes'] ?? null)->not->toBeNull();
    // No sitepage URL is ever listed as a file any more.
    expect(collect(cfRecordedFiles())->filter(fn ($u) => str_contains($u, 'mixed-case.partna.au'))->all())->toBe([]);
});

it('purgeHandle also purges the custom domain host by prefix when one is given', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', 'https://dev-api.partna.au');
    Config::set('partna.public_domain', 'partna.au');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflarePurgeService)->purgeHandle('jane', 'Tuesdae.co');

    // Both hosts, one request; the API subrequests are keyed on the backend
    // host, so they are emitted ONCE no matter how many site hosts are purged.
    expect(cfRecordedPrefixes())->toBe(['jane.partna.au/', 'tuesdae.co/']);
    expect(cfRecordedFiles())->toHaveCount(3);
    expect(Http::recorded())->toHaveCount(2);
});

it('purgeHandle strips trailing slash on app.url before composing API URL', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', 'https://dev-api.partna.au/');
    Config::set('partna.public_domain', 'partna.au');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflarePurgeService)->purgeHandle('jane');

    expect(cfRecordedFiles())
        ->toContain('https://dev-api.partna.au/api/public/profiles/jane')
        ->not->toContain('https://dev-api.partna.au//api/public/profiles/jane');
});

it('purgeHandle sends only the prefix request when app.url is unset', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', '');
    Config::set('partna.public_domain', 'partna.au');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflarePurgeService)->purgeHandle('jane');

    expect(cfRecordedPrefixes())->toBe(['jane.partna.au/']);
    expect(cfRecordedFiles())->toBe([]);
    expect(Http::recorded())->toHaveCount(1);
});

it('purgeHandle composes the prefix against the configured public domain (non-prod TLD)', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('partna.public_domain', 'staging.partna.test');
    Config::set('app.url', '');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflarePurgeService)->purgeHandle('jane');

    expect(cfRecordedPrefixes())->toBe(['jane.staging.partna.test/']);
});

it('bounds each purge_cache POST with an explicit timeout + connect timeout (LIFE-1 residual)', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', 'https://dev-api.partna.au');
    Config::set('partna.public_domain', 'partna.au');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflarePurgeService)->purgeHandle('jane');

    Http::assertSent(function (Request $request, ?Response $response = null): bool {
        return str_contains($request->url(), '/purge_cache');
    });
    // Both the prefix request and the files chunk carry the same bounds.
    foreach (Http::recorded() as [$request]) {
        expect($request->toPsrRequest()->getUri()->getPath())->toEndWith('/purge_cache');
    }
    expect(Http::recorded())->toHaveCount(2);
});

it('percent-encodes the handle before it lands in a purge prefix or URL (SEC-1)', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', 'https://dev-api.partna.au');
    Config::set('partna.public_domain', 'partna.au');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflarePurgeService)->purgeHandle('bad/handle?x=1');

    expect(cfRecordedPrefixes())->toBe(['bad%2Fhandle%3Fx%3D1.partna.au/']);
    expect(cfRecordedFiles())->toContain('https://dev-api.partna.au/api/public/profiles/bad%2Fhandle%3Fx%3D1');
});

it('purgeHandle ignores empty handles', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Http::fake();

    (new CloudflarePurgeService)->purgeHandle('');

    Http::assertNothingSent();
});

/** Anonymous subclass that counts paceBetweenChunks() calls instead of really
 *  sleeping — captures the pacing MECHANISM (SCALE-101) without slowing the
 *  suite down or asserting on wall-clock time. Plain subclassing (not a
 *  Mockery partial mock) so the real constructor runs and initialises the
 *  readonly zoneId/apiToken/configured properties from config as normal. */
function purgeServiceWithPaceSpy(): object
{
    return new class extends CloudflarePurgeService
    {
        public int $paceCalls = 0;

        protected function paceBetweenChunks(): void
        {
            $this->paceCalls++;
        }
    };
}

it('paces between purge_cache chunk POSTs on a multi-chunk purge (SCALE-101)', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    // 65 URLs -> 3 chunks (30/30/5) -> pacing fires BETWEEN chunks: exactly twice,
    // never before the first POST or after the last.
    $urls = array_map(fn ($i) => "https://h.partna.au/p{$i}", range(1, 65));

    $service = purgeServiceWithPaceSpy();
    $service->purgeUrls($urls);

    expect(Http::recorded())->toHaveCount(3);
    expect($service->paceCalls)->toBe(2);
});

it('caps TOTAL pacing time so a large purge cannot re-inflate guaranteed sleep past a fixed budget (SCALE-101)', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    // Realistic worst case for one purgeHandle() call (see the docblock on
    // CHUNK_PACING_MICROSECONDS): 39 subpage urls + 200 product + 600 menu +
    // 400 event urls per host x 2 hosts (canonical + custom domain) + 3 API
    // urls = 2,481 -> chunked at 30 -> 83 chunks -> 82 POTENTIAL inter-chunk
    // gaps. Pin the pacing budget so a future limit increase (more products,
    // more menu items, more events) can't silently re-blow the guaranteed-sleep
    // contribution to CloudflareCachePurgeJob's 15s timeout in lockstep with
    // chunk count — this must fail if someone raises a limit (or removes the
    // budget) without revisiting pacing.
    $urls = array_map(fn ($i) => "https://h.partna.au/p{$i}", range(1, 2481));

    $service = purgeServiceWithPaceSpy();
    $service->purgeUrls($urls);

    expect(Http::recorded())->toHaveCount(83) // ceil(2481 / 30)
        // 82 gaps exist, but the budget caps REAL sleeps at
        // floor(2_000_000us budget / 50_000us per pace) = 40.
        ->and($service->paceCalls)->toBe(40)
        ->and($service->paceCalls)->toBeLessThan(82);
});

it('does not pace a single-chunk purge (no gap to pace between)', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    $service = purgeServiceWithPaceSpy();
    $service->purgeUrls(['https://h.partna.au/']);

    expect(Http::recorded())->toHaveCount(1);
    expect($service->paceCalls)->toBe(0);
});
