<?php

use App\Services\Platforms\YoutubeScraper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// T2 addendum / D9 (2026-08-27): the official Data API resolves handles with
// no bot-walls; config-gated so it activates the moment the owner's key
// lands, with the page scrape as the keyless path and fallback.

it('resolves a handle through the Data API when the key is configured', function () {
    config(['services.youtube.data_api_key' => 'test-key']);
    Http::fake([
        'www.googleapis.com/youtube/v3/channels*' => Http::response([
            'items' => [['id' => 'UCZKedxD7PM4mL_nUjjD4NYg']],
        ]),
        // The scrape leg must never be reached.
        'www.youtube.com/*' => Http::response('', 500),
    ]);

    expect(app(YoutubeScraper::class)->channelIdFrom('@dvlpmnttv'))
        ->toBe('UCZKedxD7PM4mL_nUjjD4NYg');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'www.youtube.com'));
});

it('falls back to the page scrape when the API misses', function () {
    config(['services.youtube.data_api_key' => 'test-key']);
    Http::fake([
        'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => []]),
        'www.youtube.com/*' => Http::response('"externalId":"UCzHb4PHcEeWN8Fc6EsP0CPw"', 200),
    ]);

    expect(app(YoutubeScraper::class)->channelIdFrom('@StAliCoffeeRoasters'))
        ->toBe('UCzHb4PHcEeWN8Fc6EsP0CPw');
});

it('never calls the API without a key', function () {
    config(['services.youtube.data_api_key' => null]);
    Http::fake([
        'www.youtube.com/*' => Http::response('"externalId":"UCzHb4PHcEeWN8Fc6EsP0CPw"', 200),
    ]);

    expect(app(YoutubeScraper::class)->channelIdFrom('@x'))->toBe('UCzHb4PHcEeWN8Fc6EsP0CPw');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'googleapis.com'));
});
