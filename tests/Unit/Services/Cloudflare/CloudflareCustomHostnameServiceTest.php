<?php

use App\Services\Cloudflare\CloudflareCustomHostnameService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// EDGE-101: delete() must throw on a Cloudflare error response, matching
// create()/get() in this same class — every call site wraps delete() in
// try/catch(Throwable){report($e)} EXPECTING it to throw.

beforeEach(function () {
    Config::set('services.cloudflare.zone_id', 'zoneXYZ');
    Config::set('services.cloudflare.saas_api_token', 'saas-tok');
});

it('deletes a custom hostname without throwing on a success response', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true], 200)]);

    (new CloudflareCustomHostnameService)->delete('ch_1');

    Http::assertSent(fn ($req) => $req->method() === 'DELETE'
        && str_contains($req->url(), 'custom_hostnames/ch_1'));
});

it('throws when Cloudflare returns an error response for delete (EDGE-101)', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['message' => 'not found']]], 404)]);

    expect(fn () => (new CloudflareCustomHostnameService)->delete('ch_missing'))
        ->toThrow(RequestException::class);
});

it('no-ops (does not call Cloudflare) for an empty id', function () {
    Http::fake();

    (new CloudflareCustomHostnameService)->delete('');

    Http::assertNothingSent();
});

it('no-ops when the service is not configured', function () {
    Config::set('services.cloudflare.zone_id', '');
    Config::set('services.cloudflare.saas_api_token', '');
    Config::set('services.cloudflare.api_token', '');
    Http::fake();

    (new CloudflareCustomHostnameService)->delete('ch_1');

    Http::assertNothingSent();
});
