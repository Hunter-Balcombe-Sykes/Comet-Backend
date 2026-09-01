<?php

use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\ScrapeCreators\ScrapeCreatorsClient;
use App\Services\Platforms\ScrapeCreators\TiktokLiveNormalizer;
use App\Services\Platforms\ScrapeCreators\TwitchProfileNormalizer;
use App\Services\Platforms\YoutubeScraper;
use App\Services\Platforms\YoutubeThumbnailResolver;
use App\Services\Streaming\ScrapeCreatorsLiveClient;
use Illuminate\Support\Facades\Http;
use Tests\Support\Fixtures\Recorded;

// Item 11d (2026-09-01): the vendor leg of the unified live-status poller.
// One ?bool per (platform, handle): true/false is a positively-supported
// status, null is a vendor miss and LiveStatusPoller owns what a miss
// degrades to. Pinned against RECORDED payloads:
//
//  - scrapecreators-twitch-profile-live.json / -profile.json (Item 10a's
//    captures — jynxzi live, pokimane offline).
//  - scrapecreators-tiktok-user-live.json (charlidamelio 2026-09-01, offline:
//    is_live false while the LAST room still ships verbatim with status 4 —
//    which is WHY the top-level bool, never liveRoom.status, is the read;
//    slimmed: the multi-KB streamData/hevcStreamData CDN-URL blobs dropped,
//    nothing reads them). The live case is this capture with the vendor's
//    documented flip (is_live true) applied in-test — no well-known account
//    was live at capture time (three attempted).
//  - scrapecreators-tiktok-user-live-notfound.json (nonexistent handle,
//    2026-09-01): {is_live: false, liveRoomUserInfo: {}} — BYTE-IDENTICAL to
//    a real never-streamed account's answer (shoplc, verified live), so this
//    shape must read "unknown", never "offline".
//  - scrapecreators-youtube-channel-lives.json (Item 11c's capture) for the
//    delegated YouTube leg.

function scLiveClient(): ScrapeCreatorsLiveClient
{
    // Fetcher/thumbnail mocks are strict — the live lane must never touch the RSS legs.
    return new ScrapeCreatorsLiveClient(
        app(ScrapeCreatorsClient::class),
        app(ScrapeCreatorsBudget::class),
        new TwitchProfileNormalizer,
        new TiktokLiveNormalizer,
        new YoutubeScraper(
            Mockery::mock(SafeUrlFetcher::class),
            Mockery::mock(YoutubeThumbnailResolver::class),
        ),
    );
}

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.twitch_live', 100);
    config()->set('partna.limits.scrapecreators.sources.tiktok_live', 100);
    config()->set('partna.limits.scrapecreators.sources.youtube_lives', 100);
});

it('reads a live Twitch channel as true off the recorded payload', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(Recorded::json('scrapecreators-twitch-profile-live.json'))]);

    expect(scLiveClient()->isLive('twitch', 'Jynxzi'))->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/twitch/profile')
        && $request['handle'] === 'jynxzi');
});

it('reads an offline Twitch channel as false off the recorded payload', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(Recorded::json('scrapecreators-twitch-profile.json'))]);

    expect(scLiveClient()->isLive('twitch', 'pokimane'))->toBeFalse();
});

it('reads a Twitch husk as unknown and keeps the billed twitch_live slot spent', function () {
    config()->set('partna.limits.scrapecreators.sources.twitch_live', 1);
    // The NotFound quirk: success:true, a credit billed, no channel inside.
    Http::fake(['api.scrapecreators.com/*' => Http::response(['success' => true, 'credits_charged' => 1])]);

    expect(scLiveClient()->isLive('twitch', 'ghost_channel'))->toBeNull();
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('twitch_live'))->toBeFalse();
});

it('releases the twitch_live slot on a vendor 5xx', function () {
    config()->set('partna.limits.scrapecreators.sources.twitch_live', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response('upstream sad', 502)]);

    expect(scLiveClient()->isLive('twitch', 'jynxzi'))->toBeNull();
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('twitch_live'))->toBeTrue();
});

it('reads an offline TikTok account as false off the recorded payload', function () {
    // charlidamelio offline: is_live false while the FINISHED room still
    // ships (status 4, real counts) — the top-level bool is the only read.
    Http::fake(['api.scrapecreators.com/*' => Http::response(Recorded::json('scrapecreators-tiktok-user-live.json'))]);

    expect(scLiveClient()->isLive('tiktok', '@charlidamelio'))->toBeFalse();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/tiktok/user/live')
        && $request['handle'] === 'charlidamelio');
});

it('reads a live TikTok account as true and normalizes the room extras', function () {
    $fixture = Recorded::json('scrapecreators-tiktok-user-live.json');
    $fixture['is_live'] = true;
    $fixture['liveRoom']['status'] = 2;

    Http::fake(['api.scrapecreators.com/*' => Http::response($fixture)]);

    expect(scLiveClient()->isLive('tiktok', 'charlidamelio'))->toBeTrue();

    // The full normalized contract, pinned at the normalizer seam: a blank
    // room title refuses to ''-pollute, watching comes from liveRoomStats.
    $status = (new TiktokLiveNormalizer)->normalize($fixture);
    expect($status)->toBe([
        'isLive' => true,
        'handle' => 'charlidamelio',
        'title' => null,
        'watching' => 1,
        'startedAt' => 1781762987,
    ]);
});

it('reads the TikTok NotFound husk as unknown and keeps the billed slot spent', function () {
    config()->set('partna.limits.scrapecreators.sources.tiktok_live', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response(Recorded::json('scrapecreators-tiktok-user-live-notfound.json'))]);

    expect(scLiveClient()->isLive('tiktok', 'anyhandle'))->toBeNull();
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('tiktok_live'))->toBeFalse();
});

it('releases the tiktok_live slot on a vendor 5xx', function () {
    config()->set('partna.limits.scrapecreators.sources.tiktok_live', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response('upstream sad', 502)]);

    expect(scLiveClient()->isLive('tiktok', 'charlidamelio'))->toBeNull();
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('tiktok_live'))->toBeTrue();
});

it('refuses junk handles before any claim is spent', function () {
    Http::fake();

    expect(scLiveClient()->isLive('twitch', 'not a login!'))->toBeNull()
        ->and(scLiveClient()->isLive('tiktok', ''))->toBeNull()
        ->and(scLiveClient()->isLive('kick', 'xqc'))->toBeNull();

    Http::assertNothingSent();
});

it('skips the lane entirely when no key is configured', function () {
    config()->set('services.scrapecreators.key', null);
    Http::fake();

    expect(scLiveClient()->isLive('twitch', 'jynxzi'))->toBeNull()
        ->and(scLiveClient()->isLive('tiktok', 'charlidamelio'))->toBeNull();

    Http::assertNothingSent();
});

it('skips a platform whose own budget is exhausted', function () {
    config()->set('partna.limits.scrapecreators.sources.tiktok_live', 0);
    Http::fake();

    expect(scLiveClient()->isLive('tiktok', 'charlidamelio'))->toBeNull();
    Http::assertNothingSent();
});

it('delegates YouTube to fetchLives and reads the recorded Live tab as true', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(Recorded::json('scrapecreators-youtube-channel-lives.json'))]);

    expect(scLiveClient()->isLive('youtube', 'LofiGirl'))->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/youtube/channel/lives')
        && $request['handle'] === 'LofiGirl');
});

it('reads a YouTube vendor miss as unknown, never offline', function () {
    // The husk a never-streamed channel shares with NotFound (Item 11c).
    Http::fake(['api.scrapecreators.com/*' => Http::response([
        'success' => true, 'credits_charged' => 1, 'lives' => [],
    ])]);

    expect(scLiveClient()->isLive('youtube', 'LofiGirl'))->toBeNull();
});
