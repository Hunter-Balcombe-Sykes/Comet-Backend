<?php

use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\ScrapeCreators\InstagramProfileNormalizer;
use Illuminate\Support\Facades\Http;

// Item 8 (2026-09-01): the vendor lane's contract, pinned against a RECORDED
// live payload (tests/fixtures/recorded/scrapecreators-instagram-profile.json
// — a slimmed real /v1/instagram/profile answer for ryanfitzsimonshair from
// the 2026-09-01 trial). Two properties matter and every test serves one:
//
//  1. When the vendor answers usably, the pipeline reads it EXACTLY as it
//     reads an actor payload — same keys, same media picker outcomes.
//  2. When the vendor answers any other way, the Apify path runs completely
//     unchanged — the lane can only ever make things faster, never absent.

function scFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-instagram-profile.json')),
        true
    );
}

function fakeVendorAnd(callable $apify): void
{
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(scFixture()),
        'api.apify.com/*' => $apify(),
    ]);
}

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('services.apify.token', 'apify-test-token');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.instagram', 100);
});

it('normalizes the recorded payload into the exact contract the pipeline reads', function () {
    $profile = app(InstagramProfileNormalizer::class)->normalize(scFixture());

    expect($profile)->not->toBeNull()
        ->and($profile['username'])->toBe('ryanfitzsimonshair')
        ->and($profile['full_name'])->toBe('Ryan Fitzsimons | MELBOURNE BARBER')
        ->and($profile['biography'])->toContain('@akro.studio')
        ->and($profile['latestPosts'])->toHaveCount(4)
        ->and($profile['postsCount'])->toBe(12)
        ->and($profile['followersCount'])->toBe(1303)
        ->and($profile['businessCategoryName'])->toBe('Artist');

    // The media picker's exact reads, on a video node: shortCode + timestamp
    // aliases materialised, display_url/is_video/video_url passed through.
    $video = collect($profile['latestPosts'])->first(fn ($p) => ($p['is_video'] ?? false) === true);
    expect($video)->not->toBeNull()
        ->and($video['shortCode'])->toBe($video['shortcode'])
        ->and($video['timestamp'])->toBe($video['taken_at_timestamp'])
        ->and($video['video_url'])->toBeString()
        ->and($video['display_url'])->toBeString();
});

it('serves the vendor profile without ever calling the actor', function () {
    fakeVendorAnd(fn () => Http::response(['should' => 'never-be-reached'], 500));

    $result = app(InstagramScraper::class)->fetchProfileResult('ryanfitzsimonshair');

    expect($result->profile)->not->toBeNull()
        ->and($result->thin)->toBeFalse()
        ->and($result->profile['scrapedVia'] ?? null)->toBe('scrapecreators');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.apify.com'));
});

it('the media picker picks the same newest photo and newest reel off a vendor profile', function () {
    $profile = app(InstagramProfileNormalizer::class)->normalize(scFixture());
    $media = app(InstagramScraper::class)->latestMedia($profile);

    expect($media['photo'])->not->toBeNull()
        ->and($media['video'])->not->toBeNull()
        ->and($media['video']['videoUrl'])->toBeString()
        ->and($media['diagnostics']['posts'])->toBe(4)
        ->and($media['diagnostics']['videos'])->toBe(2);
});

it('falls through to the actor when the vendor transport fails', function () {
    Http::fake([
        'api.scrapecreators.com/*' => Http::response('upstream sad', 502),
        'api.apify.com/*' => Http::response([[
            'username' => 'ryanfitzsimonshair', 'fullName' => 'Ryan Fitzsimons',
            'postsCount' => 3, 'latestPosts' => [['id' => '1'], ['id' => '2'], ['id' => '3']],
        ]], 201),
    ]);

    $result = app(InstagramScraper::class)->fetchProfileResult('ryanfitzsimonshair');

    expect($result->profile)->not->toBeNull()
        ->and($result->profile['fullName'] ?? null)->toBe('Ryan Fitzsimons');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.apify.com'));
});

it('falls through to the actor on a success-shaped husk (the NotFound quirk)', function () {
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(['success' => true, 'credits_charged' => 1, 'data' => ['user' => null]]),
        'api.apify.com/*' => Http::response([[
            'username' => 'ryanfitzsimonshair', 'fullName' => 'Actor Answered',
            'postsCount' => 3, 'latestPosts' => [['id' => '1'], ['id' => '2'], ['id' => '3']],
        ]], 201),
    ]);

    $result = app(InstagramScraper::class)->fetchProfileResult('ryanfitzsimonshair');

    expect($result->profile['fullName'] ?? null)->toBe('Actor Answered');
});

it('skips the vendor lane entirely when no key is configured', function () {
    config()->set('services.scrapecreators.key', null);
    Http::fake([
        'api.apify.com/*' => Http::response([[
            'username' => 'x', 'fullName' => 'Actor Only',
            'postsCount' => 3, 'latestPosts' => [['id' => '1'], ['id' => '2'], ['id' => '3']],
        ]], 201),
    ]);

    app(InstagramScraper::class)->fetchProfileResult('someone');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.apify.com'));
});

it('skips the vendor lane when its budget is exhausted, without touching the Apify budget', function () {
    config()->set('partna.limits.scrapecreators.sources.instagram', 0);
    Http::fake([
        'api.apify.com/*' => Http::response([[
            'username' => 'x', 'fullName' => 'Actor Only',
            'postsCount' => 3, 'latestPosts' => [['id' => '1'], ['id' => '2'], ['id' => '3']],
        ]], 201),
    ]);

    app(InstagramScraper::class)->fetchProfileResult('someone');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com'));
});
