<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\BandcampScraper;
use App\Services\Platforms\VimeoApi;
use App\Services\Platforms\YoutubeScraper;
use Illuminate\Support\Str;

// Unit 11 W2 (LIFE-21..24) — the highlights picker used to live-scrape the
// vendor on EVERY GET /recent, and had no wall-clock budget bounding that
// call at all. These are the end-to-end (HTTP-layer) proofs that
// GenericPlatformController::recent() now routes through HighlightsPicker.
// Unit-level coverage of the picker's own branch logic + budget wrapping
// lives in tests/Unit/Platforms/HighlightsPickerTest.php.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function hsUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('serves the vimeo picker from the connect-time snapshot without re-scraping the vendor', function () {
    $user = hsUser('hs1');

    $videos = array_map(fn (int $i) => [
        'itemId' => "20{$i}", 'name' => "Video {$i}", 'thumbnail' => null,
        'link' => "https://vimeo.com/20{$i}", 'date' => null,
        'embedUrl' => "https://player.vimeo.com/video/20{$i}",
    ], range(1, 8));

    $this->mock(VimeoApi::class, function ($m) use ($videos) {
        $m->shouldReceive('parseSource')->andReturn(['apiPath' => 'mockuser', 'link' => 'https://vimeo.com/mockuser']);
        $m->shouldReceive('fetchProfile')->andReturn(['name' => 'Mock User', 'thumbnail' => null, 'link' => 'https://vimeo.com/mockuser', 'bio' => null]);
        // Exactly ONCE across the whole test — connect() calls it; neither GET
        // below may call it again. Against unfixed code (recent() calling
        // $strategy->recentItems() directly), this fires a second and third
        // time and Mockery fails the test at teardown ("expected exactly 1
        // call, got 2/3").
        $m->shouldReceive('fetchVideos')->once()->andReturn($videos);
    });

    actingAsUser($user)->postJson('/api/platforms/vimeo/connect', ['url' => 'https://vimeo.com/mockuser'])
        ->assertOk();

    actingAsUser($user)->getJson('/api/platforms/vimeo/recent')
        ->assertOk()
        ->assertJsonCount(8, 'videos')
        ->assertJsonPath('videos.0.itemId', '201');

    // Opening the picker a second time must ALSO stay off the snapshot
    // (LIFE-21..24's exact complaint: "open the modal twice, scrape twice").
    actingAsUser($user)->getJson('/api/platforms/vimeo/recent')
        ->assertOk()
        ->assertJsonCount(8, 'videos');
});

it('runs a youtube connect-then-picker round trip with exactly one vendor call total', function () {
    $user = hsUser('hs2');

    $this->mock(YoutubeScraper::class, function ($m) {
        $m->shouldReceive('normalizeHandle')->andReturn('mychannel');
        // ->once(): the connect call. GET /recent immediately after must add
        // ZERO further calls — i.e. connect ->once(), recent ->never() beyond
        // that. Against unfixed code, recent() re-scrapes and this
        // expectation fails at teardown.
        $m->shouldReceive('fetchRecentVideos')->once()->andReturn([
            ['videoId' => 'v1', 'name' => 'Vid', 'description' => 'd', 'link' => 'l', 'thumbnail' => 't'],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/youtube/connect', ['channel' => '@mychannel'])
        ->assertOk()
        ->assertJsonPath('handle', 'mychannel');

    actingAsUser($user)->getJson('/api/platforms/youtube/recent')
        ->assertOk()
        ->assertJsonCount(1, 'videos')
        ->assertJsonPath('videos.0.videoId', 'v1');
});

it('returns the identical "connect first" 404 whether the row is missing or its identity cannot be resolved', function () {
    $user = hsUser('hs3');

    // No row at all — requestedAccountRow() returns null.
    $noRow = actingAsUser($user)->getJson('/api/platforms/vimeo/recent')->assertStatus(404);

    // A row exists but carries no apiPath — identity() returns null.
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'vimeo', 'resource_id' => 'vimeo',
        'payload' => ['name' => 'no api path'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $badIdentity = actingAsUser($user)->getJson('/api/platforms/vimeo/recent')->assertStatus(404);

    // The restructured recent() adds an explicit null-row guard ahead of the
    // identity check; this pins that the guard didn't invent new wording.
    expect($noRow->json('message'))->toBe($badIdentity->json('message'));
});

it('youtube-music connect stores a 15-wide `recent` snapshot alongside the unwidened 12-wide public `items`', function () {
    // Review follow-up (W2): YoutubeMusicConnect used to fetch + store BOTH
    // `items` and `recent` from the same self::MAX_ITEMS=12 call — the public
    // render width and the picker width were one constant wearing two hats.
    // Every other writer (YoutubeMusicFetch, YoutubeMusicHighlights::apply(),
    // and the other three platforms' Connect strategies) uses 15 for the
    // picker snapshot. 15 distinct videos makes a width bug directly visible:
    // if `recent` were still built from the 12-item fetch, it would be
    // truncated to 12 and item #15 would never appear.
    $user = hsUser('hs5');

    $videos = array_map(fn (int $i) => [
        'videoId' => "v{$i}", 'name' => "Track {$i}", 'thumbnail' => "t{$i}", 'link' => "l{$i}", 'date' => null,
    ], range(1, 15));

    $this->mock(YoutubeScraper::class, function ($m) use ($videos) {
        $m->shouldReceive('channelIdFrom')->andReturn('UCWIDTHTEST00000000000');
        // The ->with(..., 15) constraint is itself part of the proof: unfixed
        // code calls fetchUploadsFeed($channelId, self::MAX_ITEMS) = 12, which
        // wouldn't match this expectation at all and would throw a
        // NoMatchingExpectationException before any assertion below runs.
        $m->shouldReceive('fetchUploadsFeed')->with('UCWIDTHTEST00000000000', 15)
            ->andReturn(['title' => 'Artist', 'videos' => $videos]);
    });

    actingAsUser($user)->postJson('/api/platforms/youtube-music/connect', ['url' => 'https://music.youtube.com/channel/UCWIDTHTEST00000000000'])
        ->assertOk()
        ->assertJsonCount(12, 'items'); // public render width is UNCHANGED by this fix

    $stored = IntegrationConnection::where('user_id', $user->id)->where('platform', 'youtube-music')->first()->payload;

    expect($stored['items'])->toHaveCount(12);
    // The width this test exists to pin: `recent` must carry the full 15, not
    // the public-render-width-capped 12 unfixed code would produce.
    expect($stored['recent'])->toHaveCount(15);
    expect($stored['recent'][14]['itemId'])->toBe('v15');
});

it('POST /highlights persists a warm `recent` snapshot end-to-end through writeConnection() (bandcamp)', function () {
    // Review follow-up (W2): apply()'s `recent` write was only verified at
    // source level. This proves it actually survives the real save path —
    // connect, then POST /highlights (which calls BandcampHighlights::apply()
    // and writes the result through the real writeConnection()/Eloquent
    // save) — not just that apply() RETURNS the right array in isolation.
    //
    // W3 / LIFE-21 update: highlights() now reads its item list through
    // HighlightsPicker::items() (same snapshot GET /recent serves), not a raw
    // live re-fetch — "picker and save now see the same list" is the whole
    // point of the lock-boundary fix. So the connect-time snapshot is aged
    // past the freshness TTL below to force the picker's live-fetch branch —
    // otherwise POST /highlights would serve the still-fresh 1-item
    // connect-time snapshot and never reach $saveProfile at all.
    //
    // Connect-time and save-time fetches deliberately return DIFFERENT
    // fixtures (Mockery's sequential andReturn: 1st call → $connectProfile,
    // 2nd call → $saveProfile). If they returned the same items, this test
    // would pass even with apply()'s `recent` write deleted — the untouched
    // connect-time snapshot would coincidentally match. Asserting the FINAL
    // stored `recent` equals the SAVE-time fetch is what proves apply() ran
    // and overwrote it, not that some `recent` merely survived from connect.
    $user = hsUser('hs6');

    $connectProfile = ['name' => 'Artist', 'thumbnail' => null, 'items' => [
        ['itemId' => 'album-1', 'name' => 'Album One (at connect)', 'thumbnail' => 't1', 'link' => 'https://artist.bandcamp.com/album/one'],
    ]];
    $saveProfile = ['name' => 'Artist', 'thumbnail' => null, 'items' => [
        ['itemId' => 'album-2', 'name' => 'Album Two (fresh at save time)', 'thumbnail' => 't2', 'link' => 'https://artist.bandcamp.com/album/two'],
        ['itemId' => 'album-1', 'name' => 'Album One (at connect)', 'thumbnail' => 't1', 'link' => 'https://artist.bandcamp.com/album/one'],
    ]];

    $this->mock(BandcampScraper::class, function ($m) use ($connectProfile, $saveProfile) {
        $m->shouldReceive('normalizeOrigin')->andReturn('https://artist.bandcamp.com');
        $m->shouldReceive('fetchProfile')->andReturn($connectProfile, $saveProfile);
        // enrichPrices echoes its argument (mirrors the existing precedent in
        // AccountCanonicalKeyDedupeTest / FeedFetchParityTest for this exact
        // apply() call) — pricing isn't what this test is checking.
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn (array $items) => $items);
    });

    actingAsUser($user)->postJson('/api/platforms/bandcamp/connect', ['url' => 'https://artist.bandcamp.com'])
        ->assertOk();

    // Age the snapshot past the freshness TTL — see the note above.
    IntegrationConnection::where('user_id', $user->id)->where('platform', 'bandcamp')
        ->update(['last_refreshed_at' => now()->subHours(25)]);

    actingAsUser($user)->postJson('/api/platforms/bandcamp/highlights', ['itemIds' => ['album-2']])
        ->assertOk()
        ->assertJsonCount(1, 'highlights')
        ->assertJsonPath('highlights.0.itemId', 'album-2');

    $stored = IntegrationConnection::where('user_id', $user->id)->where('platform', 'bandcamp')->first()->payload;

    expect($stored)->toHaveKey('recent');
    // Against code with apply()'s `$selection['recent'] = ...` line removed,
    // $selection carries forward whatever `recent` connect wrote (1 item,
    // $connectProfile) — this assertion fails because it expects the 2-item
    // save-time fetch instead.
    expect($stored['recent'])->toBe($saveProfile['items']);
});

it('never leaks the private `recent` snapshot key onto the public profile payload', function () {
    // DELIBERATELY VACUOUS today: PublicIntegrationConnectionResource::ALLOWLIST
    // already drops any key it doesn't name, so this passes with or without
    // HighlightsPicker existing. It's a cheap regression guard against a
    // future allowlist edit accidentally adding `recent` — not proof that
    // this change is correct on its own.
    $user = hsUser('hs4');

    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'youtube',
        'payload' => ['handle' => 'chan', 'name' => 'N', 'recent' => [['videoId' => 'secret']]],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'vimeo', 'resource_id' => 'vimeo',
        'payload' => ['url' => 'https://vimeo.com/x', 'apiPath' => 'x', 'recent' => [['itemId' => 'secret']]],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube-music', 'resource_id' => 'youtube-music',
        'payload' => ['url' => 'https://music.youtube.com/channel/UC1', 'channelId' => 'UC1', 'recent' => [['itemId' => 'secret']]],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'bandcamp', 'resource_id' => 'bandcamp',
        'payload' => ['url' => 'https://artist.bandcamp.com', 'artist' => 'A', 'recent' => [['itemId' => 'secret']]],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $platforms = $this->getJson('/api/public/profiles/hs4/integrations')
        ->assertOk()
        ->json('data.platforms');

    expect($platforms['youtube'][0]['payload'])->not->toHaveKey('recent');
    expect($platforms['vimeo'][0]['payload'])->not->toHaveKey('recent');
    expect($platforms['youtube-music'][0]['payload'])->not->toHaveKey('recent');
    expect($platforms['bandcamp'][0]['payload'])->not->toHaveKey('recent');
});
