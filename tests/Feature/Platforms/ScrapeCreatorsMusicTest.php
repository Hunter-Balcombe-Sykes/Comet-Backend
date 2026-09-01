<?php

use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\MusicActorDriver;
use App\Services\Platforms\ScrapeCreators\SoundcloudTracksNormalizer;
use App\Services\Platforms\ScrapeCreators\SpotifyArtistNormalizer;
use Illuminate\Support\Facades\Http;

// Item 8 (2026-09-01, G3: Spotify + SoundCloud = SC primary): the vendor
// lane's contract, pinned against RECORDED live payloads from the 2026-09-01
// trial (Ben Böhmer on both platforms — scrapecreators-spotify-artist.json,
// scrapecreators-soundcloud-tracks.json, and the NotFound husk that bills a
// credit as success:true). Two properties, every test serves one:
//
//  1. When the vendor answers usably, the driver answers rows in the EXACT
//     shape the Apify adapters pinned — downstream cannot tell the lanes apart.
//  2. Any other vendor outcome leaves the Apify path completely unchanged —
//     the lane can only ever make things faster, never absent.

function scMusicSpotifyFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-spotify-artist.json')),
        true
    );
}

function scMusicNotFoundFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-spotify-artist-notfound.json')),
        true
    );
}

function scMusicSoundcloudFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-soundcloud-tracks.json')),
        true
    );
}

function scMusicCtx(string $platform, string $identifier): BilledEffectContext
{
    return new BilledEffectContext('actor', 'music', [
        'platform' => $platform,
        'identifier' => $identifier,
    ], 'run-1', 'source-1', 'user-1');
}

function scMusicSpotifyCtx(string $platform = 'spotify'): BilledEffectContext
{
    return scMusicCtx($platform, 'https://open.spotify.com/artist/5tDjiBYUsTqzd0RkTZxK7u');
}

function scMusicSoundcloudCtx(): BilledEffectContext
{
    return scMusicCtx('soundcloud', 'https://soundcloud.com/ben-bohmer');
}

beforeEach(function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('services.apify.token', 'apify-test-token');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.spotify', 100);
    config()->set('partna.limits.scrapecreators.sources.soundcloud', 100);
    config()->set('partna.limits.apify.global_daily_cap', 100);
    config()->set('partna.limits.apify.actors.music-spotify', 100);
    config()->set('partna.limits.apify.actors.music-soundcloud', 100);
    config()->set('partna.limits.apify.run_sync_timeout_seconds', 110);
});

it('normalizes the recorded Spotify artist payload into the exact track-row contract', function () {
    $rows = app(SpotifyArtistNormalizer::class)->tracks(scMusicSpotifyFixture());

    expect($rows)->toHaveCount(3);

    $track = $rows[0];
    expect($track['external_id'])->toBe('1MvLmHeLkaNgUScgbUVnWJ')
        ->and($track['title'])->toBe('Breathing')
        // No top-level artist name on this payload — the credit comes from
        // the nested topTracks artists[].profile.name.
        ->and($track['artist'])->toBe('Ben Böhmer')
        ->and($track['url'])->toBe('https://open.spotify.com/track/1MvLmHeLkaNgUScgbUVnWJ')
        ->and($track['duration_seconds'])->toBe(223)
        // No ISRC on this surface either — Spotify dedup stays TitleRelease.
        ->and($track['isrc'])->toBeNull()
        ->and($track['published'])->toBeNull()
        // Unlike the Apify tracks actor, album art rides along — 640 variant.
        ->and($track['artwork'])->toContain('ab67616d0000b273');
});

it('normalizes the recorded discography plain lists into the exact release-row contract', function () {
    $rows = app(SpotifyArtistNormalizer::class)->releases(scMusicSpotifyFixture());

    // 2 albums + 2 singles, all distinct (title|format) — nothing deduped away.
    expect($rows)->toHaveCount(4);

    $byId = collect($rows)->keyBy('external_id');
    $album = $byId['57OLEpkhCXysV9FWrSbwid'];
    expect($album['title'])->toBe('Bloom')
        ->and($album['format'])->toBe('album')
        ->and($album['track_count'])->toBe(11)
        ->and($album['published'])->toBe('2024-09-27')
        // Derived, not sharingInfo.shareUrl — no per-request ?si= token.
        ->and($album['url'])->toBe('https://open.spotify.com/album/57OLEpkhCXysV9FWrSbwid')
        ->and($album['artwork'])->toContain('ab67616d0000b273');

    expect($byId['0Kylm5pCQ5cUlRb6EvHLEw']['format'])->toBe('single')
        ->and($byId['0Kylm5pCQ5cUlRb6EvHLEw']['published'])->toBe('2025-12-09');
});

it('normalizes the recorded SoundCloud tracks payload into the exact track-row contract', function () {
    $rows = app(SoundcloudTracksNormalizer::class)->tracks(scMusicSoundcloudFixture());

    expect($rows)->toHaveCount(4);

    $track = $rows[0];
    expect($track['external_id'])->toBe('2219840441')
        ->and($track['title'])->toBe('Caught Up In The Fire (An Apparition)')
        ->and($track['url'])->toBe('https://soundcloud.com/ben-bohmer/caught-up-in-the-fire-an-2')
        ->and($track['artist'])->toBe('Ben Böhmer')
        // Upper-cased: KeyClass::Isrc is a JOINING key.
        ->and($track['isrc'])->toBe('GBCFB2500726')
        ->and($track['duration_seconds'])->toBe(227)
        ->and($track['published'])->toBe('2025-12-09T00:00:00Z')
        ->and($track['artwork'])->toContain('sndcdn.com');

    // A self-upload with no label metadata: created_at keeps it orderable.
    $upload = $rows[1];
    expect($upload['isrc'])->toBeNull()
        ->and($upload['published'])->toBe('2025-12-03T10:39:51Z');
});

it('reads the NotFound husk and the empty tracks list as vendor misses, never as answers', function () {
    $normalizer = app(SpotifyArtistNormalizer::class);

    expect($normalizer->tracks(scMusicNotFoundFixture()))->toBeNull()
        ->and($normalizer->releases(scMusicNotFoundFixture()))->toBeNull()
        // Squatter/husk guard: exact-match handles answer "successfully" —
        // an empty list must fall through, only Apify settles empty as truth.
        ->and(app(SoundcloudTracksNormalizer::class)->tracks(['success' => true, 'tracks' => [], 'cursor' => null]))->toBeNull();
});

it('serves Spotify tracks vendor-first without ever calling the actor', function () {
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(scMusicSpotifyFixture()),
        'api.apify.com/*' => Http::response(['should' => 'never-be-reached'], 500),
    ]);

    $result = app(MusicActorDriver::class)->run(scMusicSpotifyCtx());

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data)->toHaveCount(3)
        ->and($result->data[0]['title'])->toBe('Breathing');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.apify.com'));
});

it('serves Spotify releases off the SAME artist endpoint, vendor-first', function () {
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(scMusicSpotifyFixture()),
        'api.apify.com/*' => Http::response(['should' => 'never-be-reached'], 500),
    ]);

    $result = app(MusicActorDriver::class)->run(scMusicSpotifyCtx('spotify_releases'));

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data)->toHaveCount(4)
        ->and(collect($result->data)->pluck('format')->unique()->sort()->values()->all())->toBe(['album', 'single']);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/spotify/artist')
        && $request['id'] === '5tDjiBYUsTqzd0RkTZxK7u');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.apify.com'));
});

it('serves SoundCloud tracks vendor-first, addressing the handle the connection already stores', function () {
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(scMusicSoundcloudFixture()),
        'api.apify.com/*' => Http::response(['should' => 'never-be-reached'], 500),
    ]);

    $result = app(MusicActorDriver::class)->run(scMusicSoundcloudCtx());

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data)->toHaveCount(4)
        ->and($result->data[0]['isrc'])->toBe('GBCFB2500726');
    // Exact-match handles: read from the stored identifier, never guessed.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/soundcloud/artist/tracks')
        && $request['handle'] === 'ben-bohmer');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.apify.com'));
});

it('falls through to the actor on a vendor 5xx, releasing the budget slot', function () {
    config()->set('partna.limits.scrapecreators.sources.soundcloud', 1);
    Http::fake([
        'api.scrapecreators.com/*' => Http::sequence()
            ->push('upstream sad', 502)
            ->push(scMusicSoundcloudFixture()),
        'api.apify.com/*' => Http::response([
            ['type' => 'user', 'id' => 1, 'username' => 'Ben Böhmer'],
            ['type' => 'track', 'id' => 99, 'title' => 'Actor Answered',
                'url' => 'https://soundcloud.com/ben-bohmer/actor-answered', 'userName' => 'Ben Böhmer'],
        ], 201),
    ]);

    $first = app(MusicActorDriver::class)->run(scMusicSoundcloudCtx());
    expect($first->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($first->data[0]['title'])->toBe('Actor Answered');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.apify.com'));

    // The transport miss RELEASED the only slot — the retry is vendor-served.
    $second = app(MusicActorDriver::class)->run(scMusicSoundcloudCtx());
    expect($second->data)->toHaveCount(4);
});

it('falls through to the actor on a success-shaped husk, keeping the billed slot spent', function () {
    config()->set('partna.limits.scrapecreators.sources.spotify', 1);
    Http::fake([
        'api.scrapecreators.com/*' => Http::sequence()
            ->push(scMusicNotFoundFixture())
            ->push(scMusicSpotifyFixture()),
        'api.apify.com/*' => Http::response([[
            'name' => 'Ben Böhmer',
            'topTracks' => [['trackId' => 't1', 'title' => 'Actor Answered', 'artists' => 'Ben Böhmer']],
        ]], 201),
    ]);

    $first = app(MusicActorDriver::class)->run(scMusicSpotifyCtx());
    expect($first->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($first->data[0]['title'])->toBe('Actor Answered');

    // NotFound billed a credit — the slot stays spent, so the second run
    // never reaches the vendor and the actor answers again.
    $second = app(MusicActorDriver::class)->run(scMusicSpotifyCtx());
    expect($second->data[0]['title'])->toBe('Actor Answered');
    Http::assertSentCount(3);
});

it('falls through to the actor on an empty SoundCloud tracks answer', function () {
    Http::fake([
        'api.scrapecreators.com/*' => Http::response(['success' => true, 'tracks' => [], 'cursor' => null]),
        'api.apify.com/*' => Http::response([
            ['type' => 'track', 'id' => 7, 'title' => 'Still Here',
                'url' => 'https://soundcloud.com/ben-bohmer/still-here', 'userName' => 'Ben Böhmer'],
        ], 201),
    ]);

    $result = app(MusicActorDriver::class)->run(scMusicSoundcloudCtx());

    expect($result->data)->toHaveCount(1)
        ->and($result->data[0]['title'])->toBe('Still Here');
});

it('skips the vendor lane entirely when no key is configured', function () {
    config()->set('services.scrapecreators.key', null);
    Http::fake(['api.apify.com/*' => Http::response([], 201)]);

    $result = app(MusicActorDriver::class)->run(scMusicSpotifyCtx());

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.apify.com'));
});

it('skips the vendor lane when its budget is exhausted, without touching the Apify budget', function () {
    config()->set('partna.limits.scrapecreators.sources.spotify', 0);
    Http::fake(['api.apify.com/*' => Http::response([], 201)]);

    app(MusicActorDriver::class)->run(scMusicSpotifyCtx());

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.scrapecreators.com'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.apify.com'));
});
