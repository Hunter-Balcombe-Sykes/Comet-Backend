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

// ── TEST-106: Cloudflare's "HTTP 200 + success:false" soft-failure ─────────
// Cloudflare returns this shape for validation-style rejections (e.g. a
// hostname already in use elsewhere). ->throw() never fires on a 2xx status,
// so create()/get()/delete() must each independently detect success:false
// and throw — otherwise the caller treats a rejected request as accepted.

it('throws with the Cloudflare error detail when create() gets HTTP 200 + success:false', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response([
        'success' => false,
        'errors' => [['code' => 1004, 'message' => 'Hostname already exists.']],
    ], 200)]);

    expect(fn () => (new CloudflareCustomHostnameService)->create('bookwith.me'))
        ->toThrow(RuntimeException::class, '1004: Hostname already exists.');
});

it('returns the shaped result when create() succeeds', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response([
        'success' => true,
        'result' => ['id' => 'ch_123', 'status' => 'pending', 'ssl' => ['status' => 'pending_validation']],
    ], 200)]);

    $result = (new CloudflareCustomHostnameService)->create('bookwith.me');

    expect($result['id'])->toBe('ch_123');
    expect($result['status'])->toBe('pending');
});

it('throws when get() gets HTTP 200 + success:false (verify() shares the create() hole)', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response([
        'success' => false,
        'errors' => [['code' => 1003, 'message' => 'Hostname not found.']],
    ], 200)]);

    expect(fn () => (new CloudflareCustomHostnameService)->get('ch_1'))
        ->toThrow(RuntimeException::class, '1003: Hostname not found.');
});

it('throws when delete() gets HTTP 200 + success:false, not just on a real HTTP error', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['message' => 'still pending']]], 200)]);

    expect(fn () => (new CloudflareCustomHostnameService)->delete('ch_1'))
        ->toThrow(RuntimeException::class, '?: still pending');
});

// A success:true carrying no usable result id is the SAME corruption the
// success:false guard exists to stop — shape() returns all-nulls, the caller
// persists a null cf_id as 'pending', and verify() 404s on it forever. Real
// Cloudflare does not do this; the guard is cheap insurance against a
// permanently stuck domain.
it('refuses a success:true response that carries no result id, rather than persisting a null cf id', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => []], 200)]);

    expect(fn () => (new CloudflareCustomHostnameService)->create('bookwith.me'))
        ->toThrow(RuntimeException::class, 'returned no result id');
});

it('applies the result-id guard to get() as well as create()', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true], 200)]);

    expect(fn () => (new CloudflareCustomHostnameService)->get('ch_1'))
        ->toThrow(RuntimeException::class, 'returned no result id');
});

// delete() must NOT require a result id — a Cloudflare delete response carries
// no hostname id, so requiring one would reject every successful delete. This
// pins the asymmetry so a future "consistency" refactor doesn't reintroduce it.
it('does not require a result id on delete(), whose response never carries one', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => null], 200)]);

    (new CloudflareCustomHostnameService)->delete('ch_1');

    expect(true)->toBeTrue(); // reaching here without throwing is the assertion
});
