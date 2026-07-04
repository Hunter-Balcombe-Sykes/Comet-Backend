<?php

/**
 * Unit tests for BrandScanClient::snapshot()'s own SSRF pre-check (SEC-3).
 * WebsiteStyleAnalyzer::analyze() already validates the URL via
 * SafeUrlFetcher::assertPublicUrl() before calling snapshot() — these tests
 * prove snapshot() enforces the SAME policy independently, so a future
 * direct caller (CLI command, staff endpoint) can't ship an unvalidated
 * URL to the Worker. Uses the real SafeUrlFetcher (app-resolved, no
 * required constructor args) with literal IPs, same pattern as
 * SafeUrlFetcherTest — no DNS mocking needed.
 */

use App\Services\Design\Scan\BrandScanClient;
use App\Services\Http\SafeUrlException;
use App\Services\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    config()->set('partna.brand_scan', [
        'enabled' => true, 'url' => 'https://scan.test', 'token' => 'test-secret', 'timeout' => 5,
    ]);
});

it('rejects a loopback URL before ever hitting the Worker', function () {
    Http::fake();

    $client = new BrandScanClient(app(SafeUrlFetcher::class));

    expect(fn () => $client->snapshot('http://127.0.0.1/'))
        ->toThrow(SafeUrlException::class);

    Http::assertNothingSent();
});

it('rejects the cloud metadata endpoint before ever hitting the Worker', function () {
    Http::fake();

    $client = new BrandScanClient(app(SafeUrlFetcher::class));

    expect(fn () => $client->snapshot('http://169.254.169.254/latest/meta-data/'))
        ->toThrow(SafeUrlException::class);

    Http::assertNothingSent();
});

it('rejects a private RFC1918 URL before ever hitting the Worker', function () {
    Http::fake();

    $client = new BrandScanClient(app(SafeUrlFetcher::class));

    expect(fn () => $client->snapshot('http://10.0.0.5/x'))
        ->toThrow(SafeUrlException::class);

    Http::assertNothingSent();
});

it('still proceeds to the Worker for a valid public URL', function () {
    // 1.1.1.1 is Cloudflare's globally-routed resolver — passes the public-IP
    // check without a DNS lookup (same literal SafeUrlFetcherTest uses).
    Http::fake([
        'scan.test/*' => Http::response([
            'success' => true,
            'result' => ['content' => '<html></html>', 'screenshot' => ''],
        ]),
    ]);

    $client = new BrandScanClient(app(SafeUrlFetcher::class));

    $snap = $client->snapshot('http://1.1.1.1/');

    expect($snap['content'])->toBe('<html></html>');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'scan.test'));
});
