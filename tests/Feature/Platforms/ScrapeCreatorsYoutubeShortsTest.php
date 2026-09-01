<?php

use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\YoutubeScraper;
use App\Services\Platforms\YoutubeThumbnailResolver;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures\Recorded;

// Item 11c (2026-09-01): the Shorts shelf is a vendor-ONLY lane — no free
// feed exists to rescue, so every failure mode must collapse to null and the
// build simply has no shorts that pass. Pinned against a RECORDED payload
// (scrapecreators-youtube-channel-shorts.json — a slimmed real answer for
// MrBeast, 2026-09-01 capture). Two properties, every test serves one:
//
//  1. A shorts row is byte-compatible with vendorUploadsFeed's video rows
//     (the RSS six + lengthSeconds) — watch-pool readers cannot tell a short
//     from a video, and nothing beyond those seven keys ever rides through.
//  2. Budget discipline on the 'youtube_shorts' source: claim before the
//     call, release on transport-null, keep the slot spent on a billed husk,
//     spend nothing at all with no key or no budget.

const SC_YT_SHORTS_CHANNEL = 'UCX6OQ3DkcsbYNE6H8uQQuVA';

/** Fetcher/thumbnail mocks are strict — the shorts lane must never touch the RSS legs. */
function scYtShortsScraper(): YoutubeScraper
{
    return new YoutubeScraper(
        Mockery::mock(SafeUrlFetcher::class),
        Mockery::mock(YoutubeThumbnailResolver::class),
    );
}

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.youtube_shorts', 100);
});

it('maps the recorded payload into the exact video-row shape', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(Recorded::json('scrapecreators-youtube-channel-shorts.json'))]);

    $rows = scYtShortsScraper()->fetchShorts(SC_YT_SHORTS_CHANNEL);

    expect($rows)->toHaveCount(5);

    $first = $rows[0];
    expect($first['videoId'])->toBe('5mU6SRS2Bxo')
        ->and($first['name'])->toBe('World’s Largest Tennis Match')
        // The recorded payload's explicit null description maps to '' — the
        // same value the RSS lane yields for a description-less entry.
        ->and($first['description'])->toBe('')
        ->and($first['link'])->toBe('https://www.youtube.com/watch?v=5mU6SRS2Bxo')
        ->and($first['date'])->toBe('2026-08-23T09:00:04-07:00')
        ->and($first['thumbnail'])->toBe('https://img.youtube.com/vi/5mU6SRS2Bxo/maxresdefault.jpg')
        // durationMs is milliseconds — 36000 must become 36, not ten hours.
        ->and($first['lengthSeconds'])->toBe(36);

    expect($rows[1]['description'])->toBe('#oldnavypartner');

    // The RSS six + lengthSeconds, nothing else: credits_* and the vendor's
    // engagement fields can never ride into a persisted payload.
    expect(array_keys($first))->toBe(['videoId', 'name', 'description', 'link', 'date', 'thumbnail', 'lengthSeconds']);

    // A UC… identity rides the channelId param; sort is explicit so the lane
    // never depends on the vendor's default ordering.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/youtube/channel/shorts')
        && $request['channelId'] === SC_YT_SHORTS_CHANNEL
        && $request['sort'] === 'newest');
});

it('routes a bare handle onto the handle param, stripped of any @', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(Recorded::json('scrapecreators-youtube-channel-shorts.json'))]);

    scYtShortsScraper()->fetchShorts('@MrBeast');

    Http::assertSent(fn ($request) => $request['handle'] === 'MrBeast'
        && ! isset($request['channelId']));
});

it('caps the rows at the requested limit', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(Recorded::json('scrapecreators-youtube-channel-shorts.json'))]);

    expect(scYtShortsScraper()->fetchShorts(SC_YT_SHORTS_CHANNEL, 2))->toHaveCount(2);
});

it('returns null on a vendor 5xx, releasing the budget slot', function () {
    config()->set('partna.limits.scrapecreators.sources.youtube_shorts', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response('upstream sad', 502)]);

    expect(scYtShortsScraper()->fetchShorts(SC_YT_SHORTS_CHANNEL))->toBeNull();
    // Transport-level null released the day's only slot.
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('youtube_shorts'))->toBeTrue();
});

it('returns null on a success-shaped husk and keeps the billed slot spent', function () {
    config()->set('partna.limits.scrapecreators.sources.youtube_shorts', 1);
    // The NotFound quirk: success:true, a credit charged, nothing usable.
    Http::fake(['api.scrapecreators.com/*' => Http::response([
        'success' => true, 'credits_remaining' => 9999, 'credits_charged' => 1,
        'videos' => [], 'channels' => [], 'playlists' => [], 'shorts' => [], 'shelves' => [], 'lives' => [],
    ])]);

    expect(scYtShortsScraper()->fetchShorts(SC_YT_SHORTS_CHANNEL))->toBeNull();
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('youtube_shorts'))->toBeFalse();
});

it('skips the lane entirely when no key is configured', function () {
    config()->set('services.scrapecreators.key', null);
    Http::fake();

    expect(scYtShortsScraper()->fetchShorts(SC_YT_SHORTS_CHANNEL))->toBeNull();
    Http::assertNothingSent();
});

it('skips the lane when its own budget is exhausted', function () {
    config()->set('partna.limits.scrapecreators.sources.youtube_shorts', 0);
    Http::fake();

    expect(scYtShortsScraper()->fetchShorts(SC_YT_SHORTS_CHANNEL))->toBeNull();
    Http::assertNothingSent();
});
