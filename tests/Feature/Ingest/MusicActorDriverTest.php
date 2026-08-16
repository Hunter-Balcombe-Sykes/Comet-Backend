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

it('normalizes a spotify dataset into tracks, carrying the isrc', function () {
    Http::fake(['api.apify.com/*' => Http::response([
        [
            'id' => 't1',
            'name' => 'The Funeral',
            'url' => 'https://open.spotify.com/track/t1',
            'artists' => ['Band of Horses'],
            'durationMs' => 321000,
            'isrc' => 'usum71234567',
            'releaseDate' => '2006-03-21',
            'coverUrl' => 'https://i.scdn.co/x.jpg',
        ],
    ], 201)]);

    $result = app(MusicActorDriver::class)->run(musicCtx());

    expect($result->outcome)->toBe(BilledEffectOutcome::Answered)
        ->and($result->data)->toHaveCount(1);

    $track = $result->data[0];
    expect($track['external_id'])->toBe('t1')
        ->and($track['title'])->toBe('The Funeral')
        ->and($track['artist'])->toBe('Band of Horses')
        // Upper-cased: f_catalog.isrc feeds KeyClass::Isrc, and a joining key
        // that disagrees on case would fail to union the very rows it exists for.
        ->and($track['isrc'])->toBe('USUM71234567')
        ->and($track['duration_seconds'])->toBe(321)
        ->and($track['published'])->toBe('2006-03-21');
});

it('reads a spotify isrc nested under external_ids too', function () {
    Http::fake(['api.apify.com/*' => Http::response([
        ['id' => 't2', 'name' => 'Laredo', 'url' => 'https://open.spotify.com/track/t2',
            'external_ids' => ['isrc' => 'USUM71600002']],
    ], 201)]);

    $result = app(MusicActorDriver::class)->run(musicCtx());

    expect($result->data[0]['isrc'])->toBe('USUM71600002');
});

it('normalizes a soundcloud dataset, taking the artist off the nested user', function () {
    Http::fake(['api.apify.com/*' => Http::response([
        [
            'id' => 's1',
            'title' => 'Never Be Like You',
            'url' => 'https://soundcloud.com/flume/never-be-like-you',
            'user' => ['username' => 'Flume'],
            'duration' => 236000,
            'isrc' => 'AUUM71600001',
            'releaseDate' => '2016-01-21',
            'artworkUrl' => 'https://i1.sndcdn.com/x.jpg',
        ],
    ], 201)]);

    $result = app(MusicActorDriver::class)->run(musicCtx([
        'platform' => 'soundcloud',
        'identifier' => 'https://soundcloud.com/flume',
    ]));

    $track = $result->data[0];
    expect($track['title'])->toBe('Never Be Like You')
        ->and($track['artist'])->toBe('Flume')
        ->and($track['isrc'])->toBe('AUUM71600001')
        ->and($track['duration_seconds'])->toBe(236);
});

it('drops a dataset row with no usable title or url rather than landing it titleless', function () {
    Http::fake(['api.apify.com/*' => Http::response([
        ['id' => 'ok', 'name' => 'Real Track', 'url' => 'https://open.spotify.com/track/ok'],
        ['id' => 'no-title', 'url' => 'https://open.spotify.com/track/x'],
        ['id' => 'no-url', 'name' => 'Titled But Unreachable'],
    ], 201)]);

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
            && $request['urls'] === ['https://open.spotify.com/artist/abc'];
    });
});
