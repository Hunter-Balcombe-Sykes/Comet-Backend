<?php

use App\Services\Platforms\BandcampScraper;
use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Strategies\Connect\BandcampConnect;
use App\Services\Platforms\Strategies\Connect\NowBookitConnect;
use App\Services\Platforms\Strategies\Connect\OpenTableConnect;
use App\Services\Platforms\Strategies\Connect\ResDiaryConnect;
use App\Services\Platforms\Strategies\Connect\SoundcloudConnect;
use App\Services\Platforms\Strategies\Connect\SpotifyConnect;
use App\Services\Platforms\Strategies\Connect\StravaConnect;
use App\Services\Platforms\Strategies\Connect\TwitchConnect;
use App\Services\Platforms\Strategies\Connect\UrlConnect;
use App\Services\Platforms\Strategies\Connect\VimeoConnect;
use App\Services\Platforms\Strategies\Connect\YoutubeConnect;
use App\Services\Platforms\Strategies\Connect\YoutubeMusicConnect;
use App\Services\Platforms\Strategies\Contracts\DeferredConnect;
use App\Services\Platforms\StravaClubScraper;
use App\Services\Platforms\TwitchScraper;
use App\Services\Platforms\VimeoApi;
use App\Services\Platforms\YoutubeScraper;

// Unit 11 W4 — DeferredConnect contract. Two properties this file pins per
// docs/superpowers/plans/2026-07-20-platform-connect-async.md §2c:
//
// 1. SUCCESS PARITY: identify() and resolve() derive the SAME identity value
//    for the SAME input. This is the guard the plan calls for given the
//    deliberate choice NOT to reimplement resolve() in terms of identify()
//    (see DeferredConnect's docblock) — if the two ever compute the identity
//    key differently, this fails.
// 2. PARSE-FAIL PARITY: for an input that fails to parse, identify() and
//    resolve() return the identical ConnectResult::fail() shape (null error,
//    422 status) — both currently share the exact same underlying parse call
//    (a private method on the Connect class, or a pure method on the
//    scraper/API client), so this also proves neither path silently
//    swallows or reshapes a parse failure.
//
// Only the FETCH-stage collaborator is mocked (via partialMock, so the real
// parse method still runs) — resolve() needs it to reach success; identify()
// never touches it at all, which is exactly the seam this contract exists to
// prove.

it('spotify: identify() and resolve() select the same identity key on success, and share the parse-fail shape', function () {
    $link = 'https://open.spotify.com/artist/abc123';
    // parseEntity() is a private, pure method on SpotifyConnect itself — no
    // scraper/service mock needed for the parse stage in either path.
    $this->mock(OEmbedService::class, fn ($m) => $m->shouldReceive('resolve')->andReturn([
        'name' => 'Artist', 'thumbnail' => 't', 'embedUrl' => null,
    ]));

    $strategy = app(SpotifyConnect::class);
    $identify = $strategy->identify($link);
    $resolve = $strategy->resolve($link);

    expect($identify->failed())->toBeFalse();
    expect($resolve->failed())->toBeFalse();
    // OEmbedFetch reads link ?? url — either key proves the seam; both are
    // identical here since resolve() also derives them from the same parse.
    expect($identify->selection['link'])->toBe($link);
    expect($identify->selection['link'])->toBe($resolve->selection['link']);

    // Parse-fail: not a Spotify entity link at all.
    $badIdentify = $strategy->identify('https://example.com/not-spotify');
    $badResolve = $strategy->resolve('https://example.com/not-spotify');
    expect($badIdentify->error)->toBe($badResolve->error)->toBeNull();
    expect($badIdentify->status)->toBe($badResolve->status)->toBe(422);
});

it('bandcamp: identify() and resolve() select the same identity key on success, and share the parse-fail shape', function () {
    $this->partialMock(BandcampScraper::class, function ($m) {
        $m->shouldReceive('fetchProfile')->andReturn([
            'name' => 'Artist',
            'items' => [['itemId' => 'a1', 'name' => 'Album', 'thumbnail' => 't', 'link' => 'l']],
            'thumbnail' => 'pt',
        ]);
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn (array $items) => $items);
    });

    $strategy = app(BandcampConnect::class);
    $identify = $strategy->identify('someartist');
    $resolve = $strategy->resolve('someartist');

    expect($identify->failed())->toBeFalse();
    expect($resolve->failed())->toBeFalse();
    // BandcampFetch reads url.
    expect($identify->selection['url'])->toBe('https://someartist.bandcamp.com');
    expect($identify->selection['url'])->toBe($resolve->selection['url']);

    $badIdentify = $strategy->identify('https://example.com');
    $badResolve = $strategy->resolve('https://example.com');
    expect($badIdentify->error)->toBe($badResolve->error)->toBeNull();
    expect($badIdentify->status)->toBe($badResolve->status)->toBe(422);
});

it('twitch: identify() and resolve() select the same identity key on success, and share the parse-fail shape', function () {
    $this->partialMock(TwitchScraper::class, function ($m) {
        $m->shouldReceive('fetchChannel')->andReturn(['name' => 'Streamer', 'image' => 'i', 'description' => 'd']);
    });

    $strategy = app(TwitchConnect::class);
    $identify = $strategy->identify('validuser123');
    $resolve = $strategy->resolve('validuser123');

    expect($identify->failed())->toBeFalse();
    expect($resolve->failed())->toBeFalse();
    // TwitchFetch reads login.
    expect($identify->selection['login'])->toBe('validuser123');
    expect($identify->selection['login'])->toBe($resolve->selection['login']);

    // Too short for TwitchScraper::parseLogin's {3,25} pattern.
    $badIdentify = $strategy->identify('ab');
    $badResolve = $strategy->resolve('ab');
    expect($badIdentify->error)->toBe($badResolve->error)->toBeNull();
    expect($badIdentify->status)->toBe($badResolve->status)->toBe(422);
});

it('strava: identify() and resolve() select the same identity key on success, and share the parse-fail shape', function () {
    $this->partialMock(StravaClubScraper::class, function ($m) {
        $m->shouldReceive('fetchClub')->andReturn(['name' => 'Club', 'location' => null, 'image' => null, 'description' => null, 'members' => 5]);
    });

    $strategy = app(StravaConnect::class);
    $identify = $strategy->identify('myclub123');
    $resolve = $strategy->resolve('myclub123');

    expect($identify->failed())->toBeFalse();
    expect($resolve->failed())->toBeFalse();
    // StravaFetch reads url.
    expect($identify->selection['url'])->toBe('https://www.strava.com/clubs/myclub123');
    expect($identify->selection['url'])->toBe($resolve->selection['url']);

    // Too short for StravaClubScraper::normalizeUrl's {2,60} bare-token pattern.
    $badIdentify = $strategy->identify('a');
    $badResolve = $strategy->resolve('a');
    expect($badIdentify->error)->toBe($badResolve->error)->toBeNull();
    expect($badIdentify->status)->toBe($badResolve->status)->toBe(422);
});

it('vimeo: identify() and resolve() select the same identity key on success, and share the parse-fail shape', function () {
    $this->partialMock(VimeoApi::class, function ($m) {
        $m->shouldReceive('fetchProfile')->andReturn(['name' => 'Pat', 'thumbnail' => 't', 'link' => null, 'bio' => null]);
        $m->shouldReceive('fetchVideos')->andReturn([
            ['itemId' => '1', 'name' => 'Vid', 'thumbnail' => 't', 'link' => 'l', 'date' => null, 'embedUrl' => 'e'],
        ]);
    });

    $strategy = app(VimeoConnect::class);
    $identify = $strategy->identify('patuser');
    $resolve = $strategy->resolve('patuser');

    expect($identify->failed())->toBeFalse();
    expect($resolve->failed())->toBeFalse();
    // VimeoFetch reads apiPath.
    expect($identify->selection['apiPath'])->toBe('patuser');
    expect($identify->selection['apiPath'])->toBe($resolve->selection['apiPath']);
    expect($identify->accountKey)->toBe('patuser')->toBe($resolve->accountKey);

    // "watch" is a reserved Vimeo route segment, not a profile.
    $badIdentify = $strategy->identify('https://vimeo.com/watch');
    $badResolve = $strategy->resolve('https://vimeo.com/watch');
    expect($badIdentify->error)->toBe($badResolve->error)->toBeNull();
    expect($badIdentify->status)->toBe($badResolve->status)->toBe(422);
});

it('youtube-music: identify() and resolve() select the same identity key on success, and share the parse-fail shape', function () {
    // A direct /channel/UC… input keeps channelIdFrom() pure (no network) —
    // the @handle form's deliberate one-fetch exception is covered by the
    // dedicated identify() docblock note, not re-proven here.
    $channelUrl = 'https://music.youtube.com/channel/UC'.str_repeat('a', 22);
    $this->partialMock(YoutubeScraper::class, function ($m) {
        $m->shouldReceive('fetchUploadsFeed')->andReturn([
            'title' => 'Artist - Topic',
            'videos' => [['videoId' => 'v1', 'name' => 'Track', 'description' => '', 'link' => 'l', 'date' => null, 'thumbnail' => 't']],
        ]);
    });

    $strategy = app(YoutubeMusicConnect::class);
    $identify = $strategy->identify($channelUrl);
    $resolve = $strategy->resolve($channelUrl);

    expect($identify->failed())->toBeFalse();
    expect($resolve->failed())->toBeFalse();
    // YoutubeMusicFetch reads channelId.
    expect($identify->selection['channelId'])->toBe('UC'.str_repeat('a', 22));
    expect($identify->selection['channelId'])->toBe($resolve->selection['channelId']);

    // Empty input — channelIdFrom() short-circuits on the empty-handle check
    // before any network call, in both paths.
    $badIdentify = $strategy->identify('');
    $badResolve = $strategy->resolve('');
    expect($badIdentify->error)->toBe($badResolve->error)->toBeNull();
    expect($badIdentify->status)->toBe($badResolve->status)->toBe(422);
});

it('youtube: identify() and resolve() select the same identity key on success, and share the parse-fail shape', function () {
    $this->partialMock(YoutubeScraper::class, function ($m) {
        $m->shouldReceive('fetchRecentVideos')->andReturn([
            ['videoId' => 'v1', 'name' => 'n', 'description' => 'd', 'link' => 'l', 'date' => null, 'thumbnail' => 't'],
        ]);
    });

    $strategy = app(YoutubeConnect::class);
    $identify = $strategy->identify('@myhandle123');
    $resolve = $strategy->resolve('@myhandle123');

    expect($identify->failed())->toBeFalse();
    expect($resolve->failed())->toBeFalse();
    // YoutubeFetch reads handle.
    expect($identify->selection['handle'])->toBe('myhandle123');
    expect($identify->selection['handle'])->toBe($resolve->selection['handle']);

    // Empty input — normalizeHandle() returns '' for both paths, no network.
    $badIdentify = $strategy->identify('');
    $badResolve = $strategy->resolve('');
    expect($badIdentify->error)->toBe($badResolve->error)->toBeNull();
    expect($badIdentify->status)->toBe($badResolve->status)->toBe(422);
});

it('youtube: identify() rejects non-handle-shaped garbage inline, with the unchanged descriptor message — resolve() has no equivalent (pins NEW behaviour, not parity)', function () {
    // "hello world" is NOT parse-fail parity material: normalizeHandle()
    // reduces it to the non-empty string 'hello world' (PlatformInput::token()
    // is a permissive fallback), so resolve() would sail past its own
    // ($handle === '') check and only fail later at the network fetch — a
    // DIFFERENT error/status than identify()'s inline rejection. This is
    // exactly the YouTube weak-gate risk the plan calls out (§2b): under a
    // naive split this input would write a pending row. YoutubeConnect::
    // identify()'s IMPOSSIBLE_HANDLE_CHARS check (whitespace is one of the two
    // genuinely-impossible characters it gates on) is the fix, so this test is
    // deliberately asymmetric with resolve() rather than a parity check.
    $strategy = app(YoutubeConnect::class);

    $result = $strategy->identify('hello world');
    expect($result->failed())->toBeTrue();
    // identify() returns fail() with a NULL message — GenericPlatformController
    // resolves that to $descriptor->connectErrorMessage() (see the line at
    // GenericPlatformController::connect(), `$result->error ?? $descriptor->
    // connectErrorMessage() ?? 'Enter a valid link.'`), so the effective
    // message a future caller shows is unchanged. Confirmed here by asserting
    // BOTH: identify() adds no message of its own, AND the registered
    // descriptor message is still the frozen wording.
    expect($result->error)->toBeNull();
    $descriptor = app(PlatformRegistry::class)->get('youtube');
    expect($descriptor->connectErrorMessage())->toBe('Enter your YouTube channel.');

    // A real handle still passes the gate.
    $ok = $strategy->identify('@myhandle123');
    expect($ok->failed())->toBeFalse();
    expect($ok->selection['handle'])->toBe('myhandle123');
});

it('youtube: identify() accepts real handles that an ASCII charset allowlist would wrongly reject (middle dot + non-Latin script)', function () {
    // Regression guard for the FIRST cut of IMPOSSIBLE_HANDLE_CHARS, which was
    // an ASCII-only allowlist (`~^[A-Za-z0-9._-]{3,100}$~`). Per YouTube Help
    // ("Learn about YouTube handles"), handles admit letters/numbers from 75+
    // languages plus the Latin middle dot (U+00B7) as a separator — an ASCII
    // allowlist rejects both real cases below. Verified directly against that
    // old pattern before this fix: preg_match('~^[A-Za-z0-9._-]{3,100}$~',
    // 'test·case') === 0 and the same for the katakana handle — both would
    // have 422'd a real user the moment the deferred flag flips on. The
    // CURRENT gate (whitespace + "/" only) correctly lets both through.
    $strategy = app(YoutubeConnect::class);

    // Latin middle dot (U+00B7) — an explicitly-documented YouTube separator
    // character, absent from the old [A-Za-z0-9._-] class.
    $middleDot = $strategy->identify('test·case');
    expect($middleDot->failed())->toBeFalse();
    expect($middleDot->selection['handle'])->toBe('test·case');

    // Non-Latin script (Japanese katakana) — YouTube handles are explicitly
    // NOT limited to Latin script; the old class admitted none of these code
    // points at all.
    $nonLatin = $strategy->identify('チャンネル');
    expect($nonLatin->failed())->toBeFalse();
    expect($nonLatin->selection['handle'])->toBe('チャンネル');
});

it('the five non-deferred implementers do not implement DeferredConnect (blast-radius guard — deliberately vacuous)', function () {
    // Guards the plan's "zero diff" claim for the platforms that must NEVER
    // gain a queued-fetch path. Vacuous by nature — a passing suite proves
    // nothing on its own until a future change tries to add `identify()` to
    // one of these; this is the tripwire for that.
    expect(new UrlConnect(fn (string $s) => ['url' => $s]))
        ->not->toBeInstanceOf(DeferredConnect::class);
    expect(app(SoundcloudConnect::class))->not->toBeInstanceOf(DeferredConnect::class);
    expect(app(OpenTableConnect::class))->not->toBeInstanceOf(DeferredConnect::class);
    expect(app(NowBookitConnect::class))->not->toBeInstanceOf(DeferredConnect::class);
    expect(app(ResDiaryConnect::class))->not->toBeInstanceOf(DeferredConnect::class);
});
