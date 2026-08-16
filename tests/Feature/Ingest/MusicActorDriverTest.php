<?php

use App\Ingest\Runtime\EffectNotAttempted;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\MusicActorDriver;
use App\Services\Cache\ApifyBudget;
use App\Services\Platforms\Actors\SoundcloudTracksAdapter;
use App\Services\Platforms\Actors\SpotifyTracksAdapter;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.apify.token', 'test-token');
    config()->set('partna.music.platforms', [
        'spotify' => ['actor' => 'automation-lab~spotify-scraper', 'adapter' => SpotifyTracksAdapter::class, 'max_tracks' => 50],
        'soundcloud' => ['actor' => 'automation-lab~soundcloud-scraper', 'adapter' => SoundcloudTracksAdapter::class, 'max_tracks' => 50],
    ]);
    config()->set('partna.limits.apify.global_daily_cap', 100);
    config()->set('partna.limits.apify.actors.music-spotify', 100);
    config()->set('partna.limits.apify.actors.music-soundcloud', 100);
    config()->set('partna.limits.apify.run_sync_timeout_seconds', 110);
});

function musicCtx(array $input = [], ?string $userId = 'user-1'): BilledEffectContext
{
    return new BilledEffectContext(
        'actor',
        'music',
        $input + ['platform' => 'spotify', 'identifier' => 'https://open.spotify.com/artist/abc'],
        'run-1',
        'source-1',
        $userId,
    );
}

it('claims only its own (kind, name), leaving the other actors unclaimed', function () {
    $driver = app(MusicActorDriver::class);

    expect($driver->supports('actor', 'music'))->toBeTrue()
        ->and($driver->supports('actor', 'instagram'))->toBeFalse()
        ->and($driver->supports('actor', 'menu'))->toBeFalse()
        ->and($driver->supports('api', 'music'))->toBeFalse();
});

it('claims an Apify budget slot before spending', function () {
    // The cap defaults to 0 for an unregistered actor tag, so this also pins
    // that music-* tags MUST exist in partna.limits.apify.actors — without
    // them tryClaim() denies every run and the connector silently lands nothing.
    config()->set('partna.limits.apify.actors.music-spotify', 0);
    Http::fake();

    expect(fn () => app(MusicActorDriver::class)->run(musicCtx()))
        ->toThrow(EffectNotAttempted::class);

    Http::assertNothingSent();
});

it('spends exactly one budget slot per run', function () {
    Http::fake(['api.apify.com/*' => Http::response([], 201)]);

    $before = app(ApifyBudget::class)->remaining('music-spotify');
    app(MusicActorDriver::class)->run(musicCtx());

    expect(app(ApifyBudget::class)->remaining('music-spotify'))->toBe($before - 1);
});

it('never spends when the token is missing', function () {
    config()->set('services.apify.token', null);
    Http::fake();

    expect(fn () => app(MusicActorDriver::class)->run(musicCtx()))
        ->toThrow(EffectNotAttempted::class);

    Http::assertNothingSent();
});

it('never spends on a platform it has no actor for', function () {
    Http::fake();

    expect(fn () => app(MusicActorDriver::class)->run(musicCtx(['platform' => 'bandcamp'])))
        ->toThrow(EffectNotAttempted::class);

    Http::assertNothingSent();
});

it('flattens a spotify artist object into its topTracks', function () {
    // The real URL-mode shape, pinned by live probe (F31): ONE artist object,
    // tracks hanging off topTracks — not a flat track list.
    Http::fake(['api.apify.com/*' => Http::response([[
        'name' => 'Band of Horses',
        'url' => 'https://open.spotify.com/artist/abc',
        'topTracks' => [
            ['trackId' => '5lRzWDEe7UuedU2QPsFg0K', 'title' => 'The Funeral',
                'artists' => 'Band of Horses', 'duration' => 322173],
            ['trackId' => '2xLMifQCjDGFmkHkpNLD9h', 'title' => 'Laredo',
                'artists' => 'Band of Horses', 'duration' => 200000],
        ],
    ]], 201)]);

    $result = app(MusicActorDriver::class)->run(musicCtx());

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data)->toHaveCount(2);

    $track = $result->data[0];
    expect($track['external_id'])->toBe('5lRzWDEe7UuedU2QPsFg0K')
        ->and($track['title'])->toBe('The Funeral')
        ->and($track['artist'])->toBe('Band of Horses')
        ->and($track['duration_seconds'])->toBe(322)
        // Derived: topTracks carries no per-track url.
        ->and($track['url'])->toBe('https://open.spotify.com/track/5lRzWDEe7UuedU2QPsFg0K');
});

it('emits no isrc for spotify, because neither actor returns one', function () {
    // Probed live 2026-08-16: the listing claims ISRC, the dataset has none.
    // Spotify dedup therefore rests on TitleRelease, per F10.
    Http::fake(['api.apify.com/*' => Http::response([[
        'name' => 'Band of Horses',
        'topTracks' => [['trackId' => 't1', 'title' => 'The Funeral', 'artists' => 'Band of Horses']],
    ]], 201)]);

    expect(app(MusicActorDriver::class)->run(musicCtx())->data[0]['isrc'])->toBeNull();
});

it('falls back to the artist-level name when a spotify track omits artists', function () {
    Http::fake(['api.apify.com/*' => Http::response([[
        'name' => 'Band of Horses',
        'topTracks' => [['trackId' => 't1', 'title' => 'The Funeral']],
    ]], 201)]);

    expect(app(MusicActorDriver::class)->run(musicCtx())->data[0]['artist'])->toBe('Band of Horses');
});

it('normalizes soundcloud tracks and skips the profile row that rides with them', function () {
    // The real userUrl shape (F31): a flat list whose FIRST row is the profile.
    // Projecting it would land the artist themselves as a track.
    Http::fake(['api.apify.com/*' => Http::response([
        ['type' => 'user', 'id' => 4803918, 'username' => 'Flume', 'url' => 'https://soundcloud.com/flume'],
        [
            'type' => 'track',
            'id' => 242136202,
            'title' => 'All Of The Worlds',
            'url' => 'https://soundcloud.com/flume/all-of-the-worlds',
            'userName' => 'Flume',
            'duration' => 125780,
            'isrc' => 'us38y2548239',
            'releaseDate' => '2025-08-22T00:00:00Z',
            'artworkUrl' => 'https://i1.sndcdn.com/x.jpg',
        ],
    ], 201)]);

    $result = app(MusicActorDriver::class)->run(musicCtx([
        'platform' => 'soundcloud',
        'identifier' => 'https://soundcloud.com/flume',
    ]));

    expect($result->data)->toHaveCount(1);

    $track = $result->data[0];
    expect($track['external_id'])->toBe('242136202')
        ->and($track['title'])->toBe('All Of The Worlds')
        // Flat userName, not a nested user object.
        ->and($track['artist'])->toBe('Flume')
        // Upper-cased: KeyClass::Isrc is a JOINING key, so two spellings of one
        // code would fail to union the very rows the key exists for.
        ->and($track['isrc'])->toBe('US38Y2548239')
        ->and($track['duration_seconds'])->toBe(126)
        ->and($track['published'])->toBe('2025-08-22T00:00:00Z');
});

it('sends soundcloud plain-string startUrls, because objects return zero rows', function () {
    Http::fake(['api.apify.com/*' => Http::response([], 201)]);

    app(MusicActorDriver::class)->run(musicCtx([
        'platform' => 'soundcloud',
        'identifier' => 'https://soundcloud.com/flume',
    ]));

    // Passing Apify's usual [{url: …}] objects here runs fine and lands NOTHING
    // — a silent empty rather than an error, so only a test keeps it honest.
    Http::assertSent(fn ($request) => $request['startUrls'] === ['https://soundcloud.com/flume']);
});

it('drops a dataset row with no usable title or url rather than landing it titleless', function () {
    Http::fake(['api.apify.com/*' => Http::response([[
        'name' => 'Band of Horses',
        'topTracks' => [
            ['trackId' => 'ok', 'title' => 'Real Track'],
            ['trackId' => 'no-title'],
            ['title' => 'Titled But Unkeyed'],
        ],
    ]], 201)]);

    $result = app(MusicActorDriver::class)->run(musicCtx());

    expect($result->data)->toHaveCount(1)
        ->and($result->data[0]['title'])->toBe('Real Track');
});

it('answers with an empty list when the artist has no tracks', function () {
    Http::fake(['api.apify.com/*' => Http::response([], 201)]);

    $result = app(MusicActorDriver::class)->run(musicCtx());

    // An artist with no public catalogue is an ANSWER. Returning NoAnswer here
    // would re-bill the same empty artist on every run in the freshness window.
    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data)->toBe([]);
});

it('reports no answer when the actor does not respond', function () {
    Http::fake(['api.apify.com/*' => Http::response('upstream exploded', 500)]);

    $result = app(MusicActorDriver::class)->run(musicCtx());

    // Rule 2 of BilledEffectDriver: a vendor outage must never be cached as
    // truth for the freshness window.
    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer);
});

it('reports no answer when the effect carries no identifier, without spending', function () {
    Http::fake();

    $result = app(MusicActorDriver::class)->run(musicCtx(['identifier' => '   ']));

    expect($result->outcome)->toBe(BilledEffectOutcome::NoAnswer);
    Http::assertNothingSent();
});

it('sends the artist URL to the actor in url mode, never a name search', function () {
    Http::fake(['api.apify.com/*' => Http::response([], 201)]);

    app(MusicActorDriver::class)->run(musicCtx());

    // Identity anchoring: a keyword search could resolve to a different artist
    // of the same name, which SourceProvisioner's own docblock rules is worse
    // than landing no row at all.
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'automation-lab~spotify-scraper')
            && $request['mode'] === 'urls'
            && $request['urls'] === ['https://open.spotify.com/artist/abc']
            // The token must NOT be in the body: Apify would treat it as actor
            // input and the run would be unauthenticated, which a pay-per-event
            // actor rejects as an x402 PAYMENT error rather than a 401 (F31).
            && ! isset($request['token'])
            && $request->hasHeader('Authorization');
    });
});
