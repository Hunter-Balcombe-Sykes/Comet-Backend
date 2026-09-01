<?php

use App\Services\Cache\ScrapeCreatorsBudget;
use App\Services\Platforms\ScrapeCreators\TwitchProfileNormalizer;
use App\Services\Platforms\ScrapeCreators\TwitchVideosNormalizer;
use App\Services\Platforms\TwitchScraper;
use Illuminate\Support\Facades\Http;

// Item 10a (2026-09-01): Twitch upgraded from link-only to data source —
// identity card + watch-pool VOD rows + the isLive read, all vendor-only
// (the old scraper died with the link-only demotion; there is no Apify
// fallback lane to protect, so "fall through" here means null → the caller's
// Unavailable). Pinned against RECORDED payloads from the 2026-09-01 trial:
//   scrapecreators-twitch-profile.json        pokimane — offline, all five
//                                             sibling social links present
//   scrapecreators-twitch-profile-live.json   jynxzi — live, stream block,
//                                             no social links (omitted keys),
//                                             404_processing VOD thumbnail
//   scrapecreators-twitch-user-videos.json    jynxzi — 5 VODs, TIME-sorted
// Same two properties every ScrapeCreators suite pins: the normalized
// contract is exact (no credits_*/__typename leak into anything persisted),
// and every vendor outcome short of usable shape reads as a miss with the
// budget mechanics of the Item 8 adapter notes (release on transport-null,
// slot stays spent on billed husks).

function scTwitchFixture(string $name): array
{
    return json_decode(
        file_get_contents(base_path("tests/fixtures/recorded/scrapecreators-{$name}.json")),
        true
    );
}

function scTwitchScraper(): TwitchScraper
{
    return app(TwitchScraper::class);
}

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.twitch', 100);
});

// ── Profile: the identity card ──────────────────────────────────────────────

it('maps the recorded offline profile into the exact identity-card contract', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(scTwitchFixture('twitch-profile'))]);

    $profile = scTwitchScraper()->fetchProfile('pokimane');

    expect($profile)->not->toBeNull()
        ->and($profile['login'])->toBe('pokimane')
        ->and($profile['displayName'])->toBe('pokimane')
        ->and($profile['url'])->toBe('https://www.twitch.tv/pokimane')
        ->and($profile['avatar'])->toContain('profile_image-150x150')
        ->and($profile['banner'])->toContain('profile_banner')
        ->and($profile['bio'])->toContain('i stream sometimes')
        ->and($profile['followers'])->toBe(9450698)
        ->and($profile['isPartner'])->toBeTrue()
        ->and($profile['isLive'])->toBeFalse()
        ->and($profile['liveViewers'])->toBeNull()
        ->and($profile['liveGame'])->toBeNull()
        ->and($profile['liveStartedAt'])->toBeNull();

    // The sibling links are the detection input Item 10a promises — the
    // vendor carries them as OPTIONAL top-level keys, full URLs.
    expect($profile['socialLinks'])->toBe([
        'instagram' => 'https://www.instagram.com/imane',
        'youtube' => 'https://www.youtube.com/pokimane',
        'tiktok' => 'https://www.tiktok.com/@poki',
        'twitter' => 'https://twitter.com/pokimanelol',
        'facebook' => 'https://www.facebook.com/pokimane',
    ]);

    // The card is synthesized, never spread: exactly these keys, so
    // credits_*, __typename, allVideos and featuredClips can never ride
    // into a persisted payload.
    expect(array_keys($profile))->toBe([
        'login', 'displayName', 'url', 'avatar', 'banner', 'bio', 'followers',
        'isPartner', 'isLive', 'liveViewers', 'liveGame', 'liveStartedAt', 'socialLinks',
    ]);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/twitch/profile')
        && $request['handle'] === 'pokimane');
});

it('reads the live block from the recorded live profile — the isLive read for the live badge', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(scTwitchFixture('twitch-profile-live'))]);

    $profile = scTwitchScraper()->fetchProfile('jynxzi');

    expect($profile['isLive'])->toBeTrue()
        ->and($profile['liveViewers'])->toBe(38707)
        ->and($profile['liveGame'])->toBe('Just Chatting')
        ->and($profile['liveStartedAt'])->toBe('2026-08-31T22:21:33Z')
        // Sibling-link keys OMITTED (not null) on channels that link nothing.
        ->and($profile['socialLinks'])->toBe([]);
});

it('never claims a live block when isLive is false, whatever the stream key holds', function () {
    // Offline answers carry stream: {} — the gate is isLive === true, never
    // the stream key existing. A stale stream object must not badge a page.
    $profile = (new TwitchProfileNormalizer)->normalize([
        'id' => '1', 'handle' => 'someone', 'isLive' => false,
        'currentViewersCount' => 12,
        'stream' => ['createdAt' => '2026-08-31T22:21:33Z', 'viewersCount' => 12, 'game' => ['displayName' => 'Chess']],
    ]);

    expect($profile['isLive'])->toBeFalse()
        ->and($profile['liveViewers'])->toBeNull()
        ->and($profile['liveGame'])->toBeNull()
        ->and($profile['liveStartedAt'])->toBeNull();
});

it('refuses a profile husk as a vendor miss and keeps the billed slot spent', function () {
    config()->set('partna.limits.scrapecreators.sources.twitch', 1);
    // The NotFound quirk: success:true, a credit charged, no channel.
    Http::fake(['api.scrapecreators.com/*' => Http::response([
        'success' => true, 'credits_remaining' => 9999, 'credits_charged' => 1,
    ])]);

    expect(scTwitchScraper()->fetchProfile('nobody_here'))->toBeNull();
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('twitch'))->toBeFalse();
});

// ── Videos: watch-pool rows ─────────────────────────────────────────────────

it('maps the recorded VOD page into watch-pool rows, newest first', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(scTwitchFixture('twitch-user-videos'))]);

    $rows = scTwitchScraper()->fetchRecentVideos('jynxzi');

    expect($rows)->toHaveCount(5)
        ->and(array_column($rows, 'id'))->toBe(['2861930683', '2860751804', '2858128913', '2858042034', '2857955660']);

    $second = $rows[1];
    expect($second['title'])->toContain('PLAYING CHAINED TOGETHER')
        ->and($second['url'])->toBe('https://www.twitch.tv/videos/2860751804')
        ->and($second['published'])->toBe('2026-08-30T16:04:01Z')
        ->and($second['thumbnail'])->toStartWith('https://static-cdn.jtvnw.net/cf_vods/')
        ->and($second['duration'])->toBe(42552)
        ->and($second['views'])->toBe(2985639)
        ->and($second['game'])->toBe('Just Chatting');

    // Synthesized rows: exactly these keys — owner/self/broadcastIdentifier
    // and the ~10 __typename-bearing vendor blocks per item never land.
    expect(array_keys($second))->toBe(['id', 'title', 'url', 'published', 'thumbnail', 'duration', 'views', 'game']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/twitch/user/videos')
        && $request['handle'] === 'jynxzi'
        && $request['sort_by'] === 'TIME');
});

it('caps the rows at the requested limit', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(scTwitchFixture('twitch-user-videos'))]);

    expect(scTwitchScraper()->fetchRecentVideos('jynxzi', 2))->toHaveCount(2);
});

it('absorbs the trial-verified row quirks: processing thumbnail, null game, junk rows', function () {
    $rows = (new TwitchVideosNormalizer)->rows(['videos' => [
        [
            // The VOD of a currently-live stream: its thumbnail is the
            // 404_processing placeholder and must map to null, not a 404 img.
            'id' => '111', 'title' => 'Live right now',
            'previewThumbnailURL' => 'https://vod-secure.twitch.tv/_404/404_processing_320x180.png',
            'publishedAt' => '2026-08-31T22:21:38Z', 'lengthSeconds' => 90, 'viewCount' => 3,
            'game' => ['displayName' => 'Just Chatting'],
        ],
        ['id' => '222', 'title' => 'No category VOD', 'publishedAt' => '2026-08-30T00:00:00Z', 'game' => null],
        ['id' => 'not-numeric', 'title' => 'Junk'],
        ['id' => '333', 'title' => '   '],
        'not-an-array',
    ]]);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['thumbnail'])->toBeNull()
        ->and($rows[0]['game'])->toBe('Just Chatting')
        ->and($rows[1]['game'])->toBeNull()
        ->and($rows[1]['views'])->toBeNull()
        ->and($rows[1]['duration'])->toBeNull();
});

it('refuses an empty VOD page as a vendor miss, never an empty channel, keeping the slot spent', function () {
    config()->set('partna.limits.scrapecreators.sources.twitch', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response([
        'success' => true, 'credits_remaining' => 9999, 'credits_charged' => 1,
        'videos' => [], 'hasNextPage' => false, 'cursor' => null,
    ])]);

    expect(scTwitchScraper()->fetchRecentVideos('nobody_here'))->toBeNull();
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('twitch'))->toBeFalse();
});

// ── Budget + lane mechanics ─────────────────────────────────────────────────

it('returns null on a vendor 5xx and releases the budget slot', function () {
    config()->set('partna.limits.scrapecreators.sources.twitch', 1);
    Http::fake(['api.scrapecreators.com/*' => Http::response('upstream sad', 502)]);

    expect(scTwitchScraper()->fetchProfile('pokimane'))->toBeNull();
    // Transport-level null handed the day's only slot back.
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('twitch'))->toBeTrue();
});

it('skips the lane entirely when no key is configured', function () {
    config()->set('services.scrapecreators.key', null);
    Http::fake();

    expect(scTwitchScraper()->fetchProfile('pokimane'))->toBeNull()
        ->and(scTwitchScraper()->fetchRecentVideos('pokimane'))->toBeNull();
    Http::assertNothingSent();
});

it('skips the lane when the twitch budget is exhausted — dormant until the cap lands', function () {
    // An absent partna.limits.scrapecreators.sources.twitch entry reads 0:
    // this is also the shipped state until the central config pass.
    config()->set('partna.limits.scrapecreators.sources.twitch', 0);
    Http::fake();

    expect(scTwitchScraper()->fetchProfile('pokimane'))->toBeNull();
    Http::assertNothingSent();
});

it('refuses a malformed login before spending anything', function () {
    config()->set('partna.limits.scrapecreators.sources.twitch', 1);
    Http::fake();

    expect(scTwitchScraper()->fetchProfile('no'))->toBeNull()          // under 3 chars
        ->and(scTwitchScraper()->fetchProfile('has spaces'))->toBeNull()
        ->and(scTwitchScraper()->fetchRecentVideos('twitch.tv/x'))->toBeNull();
    Http::assertNothingSent();
    // No claim was burned on any refusal.
    expect(app(ScrapeCreatorsBudget::class)->tryClaim('twitch'))->toBeTrue();
});

it('normalizes an @-prefixed mixed-case login to the canonical form on the wire', function () {
    Http::fake(['api.scrapecreators.com/*' => Http::response(scTwitchFixture('twitch-profile'))]);

    scTwitchScraper()->fetchProfile('@Pokimane');

    Http::assertSent(fn ($request) => $request['handle'] === 'pokimane');
});
