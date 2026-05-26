<?php

use App\Services\Cloudflare\CloudflarePurgeService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

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
    Http::fake([
        '*' => Http::response(['success' => true], 200),
    ]);

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

it('purgeHandle composes page URLs + the API subrequest URL', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', 'https://dev-api.partna.au');
    Http::fake();

    (new CloudflarePurgeService)->purgeHandle('  MIXED-CASE  ');

    Http::assertSent(fn ($req) => $req['files'] === [
        'https://mixed-case.partna.au/',
        'https://mixed-case.partna.au',
        'https://dev-api.partna.au/api/public/profiles/mixed-case',
    ]);
});

it('purgeHandle strips trailing slash on app.url before composing API URL', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', 'https://dev-api.partna.au/');
    Http::fake();

    (new CloudflarePurgeService)->purgeHandle('jane');

    Http::assertSent(fn ($req) => $req['files'] === [
        'https://jane.partna.au/',
        'https://jane.partna.au',
        'https://dev-api.partna.au/api/public/profiles/jane',
    ]);
});

it('purgeHandle skips the API URL when app.url is unset', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Config::set('app.url', '');
    Http::fake();

    (new CloudflarePurgeService)->purgeHandle('jane');

    Http::assertSent(fn ($req) => $req['files'] === [
        'https://jane.partna.au/',
        'https://jane.partna.au',
    ]);
});

it('purgeHandle ignores empty handles', function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.cache_purge_token', 'tok');
    Http::fake();

    (new CloudflarePurgeService)->purgeHandle('');

    Http::assertNothingSent();
});
