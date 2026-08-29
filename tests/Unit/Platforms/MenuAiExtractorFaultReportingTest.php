<?php

// B3 (#W1-OBS-1): MenuAiExtractor's ocr()/structure() vendor-fault escalation
// matrix — throttled report() for account faults and vendor 5xx, unthrottled
// for a canary 400/404/422, nothing extra for 429/other-4xx (routine
// backpressure), and always Log::warning + exception CLASS (never raw
// getMessage(), which can carry a signed hosted-media URL — #W1-SEC-4).

use App\Exceptions\Platforms\VendorAccountFaultException;
use App\Services\Platforms\MenuAiExtractor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    config(['services.mistral.key' => 'k1']);
    config(['services.deepseek.key' => 'k2']);
});

it('escalates a 401 from Mistral OCR as a throttled vendor account fault', function () {
    Exceptions::fake();
    Http::fake(['api.mistral.ai/v1/ocr' => Http::response(['error' => 'unauthorized'], 401)]);

    $result = app(MenuAiExtractor::class)->ocrImageUrl('https://venue.example/menu.jpg');

    expect($result)->toBeNull();
    Exceptions::assertReported(fn (VendorAccountFaultException $e) => $e->vendor === 'mistral' && $e->status === 401);
});

it('escalates a 402 from Mistral OCR as a throttled vendor account fault', function () {
    Exceptions::fake();
    Http::fake(['api.mistral.ai/v1/ocr' => Http::response(['error' => 'payment required'], 402)]);

    $result = app(MenuAiExtractor::class)->ocrImageUrl('https://venue.example/menu.jpg');

    expect($result)->toBeNull();
    Exceptions::assertReported(fn (VendorAccountFaultException $e) => $e->vendor === 'mistral' && $e->status === 402);
});

it('escalates a 403 from Mistral OCR as a throttled vendor account fault', function () {
    Exceptions::fake();
    Http::fake(['api.mistral.ai/v1/ocr' => Http::response(['error' => 'forbidden'], 403)]);

    $result = app(MenuAiExtractor::class)->ocrImageUrl('https://venue.example/menu.jpg');

    expect($result)->toBeNull();
    Exceptions::assertReported(fn (VendorAccountFaultException $e) => $e->vendor === 'mistral' && $e->status === 403);
});

it('escalates a 401 from DeepSeek structure() as a throttled vendor account fault', function () {
    Exceptions::fake();
    Http::fake(['api.deepseek.com/*' => Http::response(['error' => 'unauthorized'], 401)]);

    $result = app(MenuAiExtractor::class)->structure('some ocr text');

    expect($result)->toBeNull();
    Exceptions::assertReported(fn (VendorAccountFaultException $e) => $e->vendor === 'deepseek' && $e->status === 401);
});

it('escalates a 402 from DeepSeek structure() as a throttled vendor account fault', function () {
    Exceptions::fake();
    Http::fake(['api.deepseek.com/*' => Http::response(['error' => 'payment required'], 402)]);

    $result = app(MenuAiExtractor::class)->structure('some ocr text');

    expect($result)->toBeNull();
    Exceptions::assertReported(fn (VendorAccountFaultException $e) => $e->vendor === 'deepseek' && $e->status === 402);
});

it('escalates a 403 from DeepSeek structure() as a throttled vendor account fault', function () {
    Exceptions::fake();
    Http::fake(['api.deepseek.com/*' => Http::response(['error' => 'forbidden'], 403)]);

    $result = app(MenuAiExtractor::class)->structure('some ocr text');

    expect($result)->toBeNull();
    Exceptions::assertReported(fn (VendorAccountFaultException $e) => $e->vendor === 'deepseek' && $e->status === 403);
});

it('does not escalate a 429 — routine backpressure stays quiet', function () {
    Exceptions::fake();
    Http::fake(['api.mistral.ai/v1/ocr' => Http::response(['error' => 'rate limited'], 429)]);

    $result = app(MenuAiExtractor::class)->ocrImageUrl('https://venue.example/menu.jpg');

    expect($result)->toBeNull();
    Exceptions::assertNothingReported();
    Exceptions::assertReportedCount(0);
});

it('escalates a 404 from Mistral OCR unthrottled — a canary for a moved endpoint', function () {
    Exceptions::fake();
    Http::fake(['api.mistral.ai/v1/ocr' => Http::response(['error' => 'not found'], 404)]);

    app(MenuAiExtractor::class)->ocrImageUrl('https://venue.example/menu.jpg');
    app(MenuAiExtractor::class)->ocrImageUrl('https://venue.example/menu2.jpg');

    // Unthrottled: both calls report, unlike the 401/402/403 throttle.
    Exceptions::assertReportedCount(2);
});

it('reports a transport-level throw with the exception CLASS in the log, not a raw message', function () {
    Exceptions::fake();
    Http::fake(['api.mistral.ai/v1/ocr' => fn () => throw new ConnectionException('boom https://venue.example/menu.jpg?sig=SECRET')]);

    $result = app(MenuAiExtractor::class)->ocrImageUrl('https://venue.example/menu.jpg?sig=SECRET');

    expect($result)->toBeNull();
    Exceptions::assertReported(ConnectionException::class);
});

it('throttles repeated 401s to one Nightwatch report — the seam a future regression would drop', function () {
    Exceptions::fake();
    Http::fake(['api.mistral.ai/v1/ocr' => Http::response(['error' => 'unauthorized'], 401)]);

    // Two consecutive calls in the SAME test body — CACHE_STORE is 'array'
    // (per-process) in tests, so this is the only way to observe the throttle.
    app(MenuAiExtractor::class)->ocrImageUrl('https://venue.example/a.jpg');
    app(MenuAiExtractor::class)->ocrImageUrl('https://venue.example/b.jpg');

    Exceptions::assertReportedCount(1);
});
