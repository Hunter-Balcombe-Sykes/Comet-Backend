<?php

use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\ScrapeCreators\FindSocialProfilesClient;
use App\Services\Platforms\ScrapeCreators\FindSocialProfilesNormalizer;
use Illuminate\Support\Facades\Http;

// Item 11g (2026-09-01): the discovery lane's contract, pinned against a
// RECORDED live payload (tests/fixtures/recorded/
// scrapecreators-find-social-profiles.json — the real /v1/find-social-profiles
// answer for instagram/mkbhd, captured 2026-09-01 via the vendor's cache).
// Ground truth in that payload: 7 verified profiles rows — the instagram
// source itself, youtube @mkbhd at 0.95, twitter (x.com URL) at 0.9, and four
// more youtube channels at 0.9 — plus unverified same-handle and Google
// candidate piles that must never surface. Two properties, same as every
// ScrapeCreators suite:
//
//  1. The normalized contract is exact: a {platform => url} map in registry
//     vocabulary, one best URL per platform, source platform excluded,
//     nothing unverified and nothing billing-shaped surviving.
//  2. Every vendor outcome short of a usable map reads as null, with the
//     Item 8 budget mechanics (release on transport-null, slot stays spent
//     on billed husks).

function scFspFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-find-social-profiles.json')),
        true
    );
}

function scFspClient(): FindSocialProfilesClient
{
    return app(FindSocialProfilesClient::class);
}

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.find_social_profiles', 100);
});

// ── Normalizer: the recorded payload ────────────────────────────────────────

it('normalizes the recorded payload into the exact registry-keyed map', function () {
    $map = app(FindSocialProfilesNormalizer::class)
        ->normalize(scFspFixture(), ['instagram', 'tiktok', 'youtube', 'x', 'facebook', 'twitch']);

    // youtube: @mkbhd (0.95) beats the four 0.9 channels; twitter aliases to
    // x; the instagram row is the source and never appears.
    expect($map)->toBe([
        'youtube' => 'https://www.youtube.com/@mkbhd',
        'x' => 'https://x.com/mkbhd',
    ]);

    // Nothing unverified and nothing billing-shaped survives: the recorded
    // body's tiktok/facebook rows live ONLY in same_handle_candidate_urls and
    // google_candidate_urls, which the normalizer must never read.
    expect($map)->not->toHaveKey('tiktok')
        ->and($map)->not->toHaveKey('facebook')
        ->and(json_encode($map))->not->toContain('credits');
});

it('filters to the allow-list — an unknown platform never reaches the map', function () {
    $body = scFspFixture();
    $body['profiles'][] = [
        'platform' => 'myspace', 'handle' => 'mkbhd',
        'url' => 'https://myspace.com/mkbhd', 'confidence' => 0.9, 'evidence' => ['website_link'],
    ];

    $map = app(FindSocialProfilesNormalizer::class)->normalize($body, ['youtube', 'x']);
    expect($map)->toBe([
        'youtube' => 'https://www.youtube.com/@mkbhd',
        'x' => 'https://x.com/mkbhd',
    ]);

    // Only unknown platforms left after filtering = nothing usable.
    expect(app(FindSocialProfilesNormalizer::class)->normalize($body, ['tiktok']))->toBeNull();
});

it('keeps the first row on a confidence tie, mirroring vendor order', function () {
    $map = app(FindSocialProfilesNormalizer::class)->normalize([
        'source' => ['platform' => 'instagram', 'handle' => 'a', 'url' => 'https://www.instagram.com/a'],
        'profiles' => [
            ['platform' => 'youtube', 'url' => 'https://www.youtube.com/@first', 'confidence' => 0.9],
            ['platform' => 'youtube', 'url' => 'https://www.youtube.com/@second', 'confidence' => 0.9],
            ['platform' => 'youtube', 'url' => 'https://www.youtube.com/@third', 'confidence' => 0.95],
        ],
    ], ['youtube']);

    expect($map)->toBe(['youtube' => 'https://www.youtube.com/@third']);
});

it('excludes the source platform even when the vendor returns a second same-platform account', function () {
    $map = app(FindSocialProfilesNormalizer::class)->normalize([
        'source' => ['platform' => 'instagram', 'handle' => 'a', 'url' => 'https://www.instagram.com/a'],
        'profiles' => [
            ['platform' => 'instagram', 'url' => 'https://www.instagram.com/a', 'confidence' => 1],
            ['platform' => 'instagram', 'url' => 'https://www.instagram.com/alt_account', 'confidence' => 0.9],
            ['platform' => 'x', 'url' => 'https://x.com/a', 'confidence' => 0.9],
        ],
    ], ['instagram', 'x']);

    expect($map)->toBe(['x' => 'https://x.com/a']);
});

it('reads every husk and drift shape as a vendor miss', function () {
    $normalizer = app(FindSocialProfilesNormalizer::class);
    $known = ['youtube', 'x'];

    // The NotFound quirk: billed, success-shaped, no graph inside.
    expect($normalizer->normalize(['success' => true, 'credits_charged' => 10], $known))->toBeNull()
        ->and($normalizer->normalize(['source' => ['platform' => 'instagram'], 'profiles' => []], $known))->toBeNull()
        ->and($normalizer->normalize(['source' => [], 'profiles' => [['platform' => 'x', 'url' => 'https://x.com/a', 'confidence' => 1]]], $known))->toBeNull()
        // Drifted rows — no confidence, string confidence, junk URL — are
        // skipped, never guessed at.
        ->and($normalizer->normalize([
            'source' => ['platform' => 'instagram'],
            'profiles' => [
                ['platform' => 'x', 'url' => 'https://x.com/a'],
                ['platform' => 'x', 'url' => 'https://x.com/a', 'confidence' => 'high'],
                ['platform' => 'x', 'url' => 'notaurl', 'confidence' => 0.9],
            ],
        ], $known))->toBeNull();
});

// ── Client: lane + budget mechanics ─────────────────────────────────────────

it('discovers off the recorded payload and rides the free-cache window', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(scFspFixture())]);

    $map = scFspClient()->discover('instagram', '@mkbhd');

    expect($map)->toBe([
        'youtube' => 'https://www.youtube.com/@mkbhd',
        'x' => 'https://x.com/mkbhd',
    ]);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com/v1/find-social-profiles')
        && $request['platform'] === 'instagram'
        && $request['handle'] === 'mkbhd' // the @ never travels
        && $request['cache_max_age'] === '7d');
});

it('returns null on a vendor 5xx and releases the budget slot', function () {
    config()->set('partna.limits.scrapecreators.sources.find_social_profiles', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response('upstream sad', 502)]);

    expect(scFspClient()->discover('instagram', 'mkbhd'))->toBeNull();
    // Transport-level null handed the day's only slot back.
    expect(app(ScrapeCreatorsBudget::class)->tryClaim(FindSocialProfilesClient::SOURCE))->toBeTrue();
});

it('keeps the slot spent on a success-shaped husk (the NotFound quirk)', function () {
    config()->set('partna.limits.scrapecreators.sources.find_social_profiles', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response(['success' => true, 'credits_charged' => 10, 'credits_remaining' => 7000])]);

    expect(scFspClient()->discover('instagram', 'nobody_here'))->toBeNull();
    expect(app(ScrapeCreatorsBudget::class)->tryClaim(FindSocialProfilesClient::SOURCE))->toBeFalse();
});

it('skips the lane entirely when no key is configured', function () {
    config()->set('services.scrapecreators.key', null);
    Http::fake();

    expect(scFspClient()->discover('instagram', 'mkbhd'))->toBeNull();
    Http::assertNothingSent();
});

it('skips the lane when its budget is exhausted — dormant until the cap lands', function () {
    config()->set('partna.limits.scrapecreators.sources.find_social_profiles', 0);
    Http::fake();

    expect(scFspClient()->discover('instagram', 'mkbhd'))->toBeNull();
    Http::assertNothingSent();
});

it('refuses unsupported source platforms and non-handle inputs before any spend', function () {
    config()->set('partna.limits.scrapecreators.sources.find_social_profiles', 1);
    Http::fake();

    // threads is a registry platform, but not one the endpoint accepts as a
    // source; 'twitter' must arrive in OUR vocabulary ('x'), not the vendor's.
    expect(scFspClient()->discover('threads', 'someone'))->toBeNull()
        ->and(scFspClient()->discover('twitter', 'someone'))->toBeNull()
        // URLs are not handles (vendor rule), and a malformed request still
        // bills — both refuse locally.
        ->and(scFspClient()->discover('instagram', 'https://x.com/mkbhd'))->toBeNull()
        ->and(scFspClient()->discover('instagram', '  '))->toBeNull();

    Http::assertNothingSent();
    // No claim was burned by any refusal.
    expect(app(ScrapeCreatorsBudget::class)->tryClaim(FindSocialProfilesClient::SOURCE))->toBeTrue();
});

it('filters the live map to platforms the real registry knows', function () {
    // The recorded payload against the REAL registry (not a hand allow-list):
    // youtube and x are registered platforms, so both survive; nothing else
    // in the verified set is. Pins the client's registry->keys() wiring.
    Http::fake(['api.scrapecreators.com/*' => Http::response(scFspFixture())]);

    $map = scFspClient()->discover('instagram', 'mkbhd');

    expect(array_keys($map))->toBe(['youtube', 'x']);
});
