<?php

use App\Enums\SitepageId;
use App\Services\Cloudflare\CloudflarePurgeService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/** Every URL POSTed across all purge_cache requests, flattened in send order. */
function cfRecordedFiles(): array
{
    return collect(Http::recorded())
        ->flatMap(fn ($pair) => (array) $pair[0]['files'])
        ->all();
}

/** The deep-link sub-pages the service purges: SitepageId taxonomy minus 'home'. */
function cfDeepLinkSubPages(): array
{
    return array_values(array_filter(
        SitepageId::canonicalOrder(),
        static fn (string $p): bool => $p !== 'home',
    ));
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

    $urls = array_map(fn ($i) => "https://h.partna.au/p{$i}", range(1, 65));
    (new CloudflarePurgeService)->purgeUrls($urls);

    expect(Http::recorded())->toHaveCount(3); // 30 + 30 + 5
    Http::recorded()->each(fn ($pair) => expect(count($pair[0]['files']))->toBeLessThanOrEqual(30));
    expect(cfRecordedFiles())->toBe($urls); // order preserved, nothing dropped
});

it('purgeHandle purges root + every deep-link sub-page + shadows + API', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', 'https://dev-api.partna.au');
    Config::set('partna.public_domain', 'partna.au');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflarePurgeService)->purgeHandle('  MIXED-CASE  ');

    $files = cfRecordedFiles();
    $base = 'https://mixed-case.partna.au';

    // root (slash + slash-less) + root shadow + the API subrequests
    expect($files)->toContain("{$base}/", $base, "{$base}/_swr-shadow/");
    expect($files)->toContain('https://dev-api.partna.au/api/public/profiles/mixed-case');
    expect($files)->toContain('https://dev-api.partna.au/api/public/profiles/mixed-case/integrations');
    // every sub-page + its shadow; 'home' is the root, never a sub-page
    foreach (cfDeepLinkSubPages() as $page) {
        expect($files)->toContain("{$base}/{$page}", "{$base}/_swr-shadow/{$page}");
    }
    expect($files)->not->toContain("{$base}/home", "{$base}/_swr-shadow/home");
    // exact size: 3 root + 2 per sub-page + 2 API (profile + integrations)
    expect($files)->toHaveCount(3 + 2 * count(cfDeepLinkSubPages()) + 2);
});

it('purgeHandle also busts the custom domain edge cache when one is given', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', 'https://dev-api.partna.au');
    Config::set('partna.public_domain', 'partna.au');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflarePurgeService)->purgeHandle('jane', 'Tuesdae.co');

    $files = cfRecordedFiles();
    // both hosts get root + shadow + a representative sub-page + its shadow
    expect($files)->toContain(
        'https://jane.partna.au/', 'https://jane.partna.au/_swr-shadow/',
        'https://jane.partna.au/shop', 'https://jane.partna.au/_swr-shadow/shop',
        'https://tuesdae.co/', 'https://tuesdae.co/_swr-shadow/',
        'https://tuesdae.co/shop', 'https://tuesdae.co/_swr-shadow/shop',
        'https://dev-api.partna.au/api/public/profiles/jane',
        'https://dev-api.partna.au/api/public/profiles/jane/integrations',
    );
    // two hosts -> 2 x (3 root + 2 per sub-page) + 2 API (profile + integrations)
    expect($files)->toHaveCount(2 * (3 + 2 * count(cfDeepLinkSubPages())) + 2);
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

it('purgeHandle skips the API URL when app.url is unset', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', '');
    Config::set('partna.public_domain', 'partna.au');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflarePurgeService)->purgeHandle('jane');

    $files = cfRecordedFiles();
    expect($files)->toContain('https://jane.partna.au/', 'https://jane.partna.au/shop');
    // no API entry -> exactly 3 root + 2 per sub-page
    expect($files)->toHaveCount(3 + 2 * count(cfDeepLinkSubPages()));
    expect(collect($files)->filter(fn ($u) => str_contains($u, '/api/public/profiles/'))->all())->toBe([]);
});

it('purgeHandle composes page URLs against the configured public domain (non-prod TLD)', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('partna.public_domain', 'staging.partna.test');
    Config::set('app.url', '');
    Http::fake(['*' => Http::response(['success' => true], 200)]);

    (new CloudflarePurgeService)->purgeHandle('jane');

    // targets follow public_domain, so a staging/non-prod TLD hits the right zone
    expect(cfRecordedFiles())
        ->toContain(
            'https://jane.staging.partna.test/',
            'https://jane.staging.partna.test/shop',
            'https://jane.staging.partna.test/_swr-shadow/shop',
        )
        ->not->toContain('https://jane.partna.au/');
});

it('purgeHandle ignores empty handles', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Http::fake();

    (new CloudflarePurgeService)->purgeHandle('');

    Http::assertNothingSent();
});
