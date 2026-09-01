<?php

use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\YoutubeScraper;
use App\Services\Platforms\YoutubeThumbnailResolver;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures\Recorded;

// Item 11c (2026-09-01): the Live tab is a LIVE-STATUS input, never pool
// content — the isLive bool is the normalized read Item 11d's consolidation
// of CheckStreamingLiveStatusJob consumes. Pinned against a RECORDED payload
// (scrapecreators-youtube-channel-lives.json — a slimmed real answer for
// LofiGirl, 2026-09-01 capture, chosen because her channel carries several
// CONCURRENT live streams and finished ones in a single page). The
// properties every test serves:
//
//  1. Live discrimination is by entry SHAPE (lengthText "LIVE" / a
//     "watching" view count), never by trusting vendor ints — viewCountInt
//     arrives BROKEN for abbreviated live counts ("1.4K watching" → 1.4).
//  2. Null means "vendor miss / status unknown", never "offline" — false is
//     only asserted off a populated Live tab with nothing live.
//  3. Budget discipline on the 'youtube_lives' source, same ladder as every
//     vendor lane: release on transport-null, keep a billed husk spent.

const SC_YT_LIVES_HANDLE = 'LofiGirl';

function scYtLivesFixture(): array
{
    return Recorded::json('scrapecreators-youtube-channel-lives.json');
}

/** Fetcher/thumbnail mocks are strict — the lives lane must never touch the RSS legs. */
function scYtLivesScraper(): YoutubeScraper
{
    return new YoutubeScraper(
        Mockery::mock(SafeUrlFetcher::class),
        Mockery::mock(YoutubeThumbnailResolver::class),
    );
}

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.youtube_lives', 100);
});

it('reads the recorded payload as live and normalizes every entry', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(scYtLivesFixture())]);

    $result = scYtLivesScraper()->fetchLives(SC_YT_LIVES_HANDLE);

    expect($result['isLive'])->toBeTrue()
        ->and($result['lives'])->toHaveCount(5);

    $first = $result['lives'][0];
    expect($first['videoId'])->toBe('rFZHOHl-L8A')
        ->and($first['name'])->toBe('lofi hip hop radio 📚 beats to relax/study to')
        ->and($first['link'])->toBe('https://www.youtube.com/watch?v=rFZHOHl-L8A')
        ->and($first['isLive'])->toBeTrue()
        // "14K watching" — re-parsed from the text. The vendor's viewCountInt
        // says 14, which is exactly why it is never read.
        ->and($first['watching'])->toBe(14000)
        ->and($first['lengthSeconds'])->toBeNull();

    // An exact count parses exactly; the broken-float class ("1.4K watching"
    // arriving as viewCountInt 1.4) resolves to the real 1400.
    expect($result['lives'][1]['watching'])->toBe(921)
        ->and($result['lives'][2]['watching'])->toBe(1400);

    // Nothing beyond the pinned keys — and deliberately NO date key: lives
    // carry no publishDate, and publishedTime is synthesized at scrape time
    // (both recorded finished streams share one identical timestamp).
    expect(array_keys($first))->toBe(['videoId', 'name', 'link', 'thumbnail', 'isLive', 'watching', 'lengthSeconds']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/youtube/channel/lives')
        && $request['handle'] === SC_YT_LIVES_HANDLE);
});

it('marks finished streams offline and never invents a watcher count for them', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(scYtLivesFixture())]);

    $finished = scYtLivesScraper()->fetchLives(SC_YT_LIVES_HANDLE)['lives'][3];

    expect($finished['videoId'])->toBe('DWcJFNfaw9c')
        ->and($finished['isLive'])->toBeFalse()
        ->and($finished['watching'])->toBeNull();
});

it('answers isLive false off a populated Live tab with nothing live', function () {
    // The recorded page reduced to its finished streams only — the one state
    // that positively supports "offline" (an EMPTY lives array never does).
    $fixture = scYtLivesFixture();
    $fixture['lives'] = array_values(array_filter(
        $fixture['lives'],
        fn (array $entry) => $entry['lengthText'] !== 'LIVE',
    ));
    Http::fake(['api.scrapecreators.com/*' => Http::response($fixture)]);

    $result = scYtLivesScraper()->fetchLives(SC_YT_LIVES_HANDLE);

    expect($result['isLive'])->toBeFalse()
        ->and($result['lives'])->toHaveCount(2);
});

it('returns null on a vendor 5xx, releasing the budget slot', function () {
    config()->set('partna.limits.scrapecreators.sources.youtube_lives', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response('upstream sad', 502)]);

    expect(scYtLivesScraper()->fetchLives(SC_YT_LIVES_HANDLE))->toBeNull();
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('youtube_lives'))->toBeTrue();
});

it('returns null on a success-shaped husk and keeps the billed slot spent', function () {
    config()->set('partna.limits.scrapecreators.sources.youtube_lives', 1);
    // The NotFound quirk — and the shape a channel that has never streamed
    // shares with it, which is WHY empty must read "unknown", not "offline".
    Http::fake(['api.scrapecreators.com/*' => Http::response([
        'success' => true, 'credits_remaining' => 9999, 'credits_charged' => 1,
        'videos' => [], 'channels' => [], 'playlists' => [], 'shorts' => [], 'shelves' => [], 'lives' => [],
    ])]);

    expect(scYtLivesScraper()->fetchLives(SC_YT_LIVES_HANDLE))->toBeNull();
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('youtube_lives'))->toBeFalse();
});

it('skips the lane entirely when no key is configured', function () {
    config()->set('services.scrapecreators.key', null);
    Http::fake();

    expect(scYtLivesScraper()->fetchLives(SC_YT_LIVES_HANDLE))->toBeNull();
    Http::assertNothingSent();
});

it('skips the lane when its own budget is exhausted', function () {
    config()->set('partna.limits.scrapecreators.sources.youtube_lives', 0);
    Http::fake();

    expect(scYtLivesScraper()->fetchLives(SC_YT_LIVES_HANDLE))->toBeNull();
    Http::assertNothingSent();
});
