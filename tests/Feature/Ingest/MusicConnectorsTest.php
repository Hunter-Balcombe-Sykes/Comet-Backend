<?php

use App\Ingest\Connectors\SoundcloudTracksConnector;
use App\Ingest\Connectors\SpotifyTracksConnector;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\RecordView;
use App\Ingest\Projection\SoundcloudTrackProjector;
use App\Ingest\Projection\SpotifyTrackProjector;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\EffectRefused;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

// Tier C for the two music connectors. Both replaced keyless oEmbed connectors
// that could only ever resolve ONE entity to the `channel` kind — oEmbed answers
// about the embed itself and has no list to page — which is why `channel`
// existed and why retiring it needed these first (convergence-log F29/F30).
//
// Fixtures mirror the normalized shape MusicActorDriver's adapters emit, NOT the
// raw vendor dataset: the adapter owns vendor field names, the connector never
// sees them.

/** A minimal Io whose effect() answers from a fixed verdict; HTTP is refused. */
function musicIo(array $effectResult): Io
{
    return new class($effectResult) implements Io
    {
        public array $effects = [];

        public function __construct(private array $effectResult) {}

        public function get(string $url, array $headers = []): array
        {
            throw new EffectRefused('music connectors must not fetch over HTTP');
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            throw new EffectRefused('music connectors must not fetch over HTTP');
        }

        public function getMany(array $urls, array $headers = []): array
        {
            throw new EffectRefused('music connectors must not fetch over HTTP');
        }

        public function effect(string $kind, string $name, array $input): array
        {
            $this->effects[] = ['kind' => $kind, 'name' => $name, 'input' => $input];

            return $this->effectResult;
        }
    };
}

function musicPull(Connector $connector, string $identifier): Pull
{
    return new Pull(identifier: $identifier, stream: $connector::manifest()->stream('tracks'));
}

function okEffect(array $tracks): array
{
    return ['status' => 'ok', 'cached' => false, 'data' => $tracks];
}

function spotifyTrackFixture(array $overrides = []): array
{
    return $overrides + [
        'external_id' => 't1',
        'title' => 'The Funeral',
        'url' => 'https://open.spotify.com/track/t1',
        'artist' => 'Band of Horses',
        'isrc' => 'USUM71234567',
        'duration_seconds' => 321,
        'published' => '2006-03-21',
        'artwork' => 'https://i.scdn.co/x.jpg',
    ];
}

// ── Manifests ───────────────────────────────────────────────────────────────

it('both music connectors declare an Actor-cost track stream, never a free channel one', function () {
    foreach ([SpotifyTracksConnector::manifest(), SoundcloudTracksConnector::manifest()] as $manifest) {
        expect($manifest->cost)->toBe(CostClass::Actor)
            ->and($manifest->streams)->toHaveKey('tracks')
            ->and($manifest->streams)->not->toHaveKey('listen')
            ->and($manifest->streams['tracks']->target)->toBe('track')
            ->and($manifest->streams['tracks']->profile)->toBe(SourceProfile::Catalogue);
    }
});

it('declares only the hosts it fetches directly: spotify the keyless oEmbed for covers, soundcloud none', function () {
    // The actor run happens inside the driver; the only direct fetch is
    // Spotify's oEmbed (track covers + artist name, 2026-08-18 restructure).
    expect(SpotifyTracksConnector::manifest()->hosts)->toBe(['open.spotify.com'])
        ->and(SoundcloudTracksConnector::manifest()->hosts)->toBe([]);
});

// ── Pull behaviour ──────────────────────────────────────────────────────────

it('spotify asks the music effect for its own platform and identifier', function () {
    $connector = new SpotifyTracksConnector;
    $io = musicIo(okEffect([spotifyTrackFixture()]));

    iterator_to_array($connector->pull(musicPull($connector, 'https://open.spotify.com/artist/abc'), $io));

    expect($io->effects)->toHaveCount(1)
        ->and($io->effects[0]['kind'])->toBe('actor')
        ->and($io->effects[0]['name'])->toBe('music')
        ->and($io->effects[0]['input']['platform'])->toBe('spotify')
        ->and($io->effects[0]['input']['identifier'])->toBe('https://open.spotify.com/artist/abc');
});

it('soundcloud asks the same effect under its own platform', function () {
    $connector = new SoundcloudTracksConnector;
    $io = musicIo(okEffect([]));

    iterator_to_array($connector->pull(musicPull($connector, 'https://soundcloud.com/flume'), $io));

    expect($io->effects[0]['input']['platform'])->toBe('soundcloud');
});

it('yields one record per track, keyed by the vendor external id', function () {
    $connector = new SpotifyTracksConnector;
    $messages = iterator_to_array($connector->pull(
        musicPull($connector, 'https://open.spotify.com/artist/abc'),
        musicIo(okEffect([
            spotifyTrackFixture(),
            spotifyTrackFixture(['external_id' => 't2', 'title' => 'Laredo', 'published' => '2010-05-18']),
        ])),
    ));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    expect($records)->toHaveCount(2)
        ->and($records[0]->key)->toBe('t1')
        ->and($records[1]->key)->toBe('t2');
});

it('claims prefix coverage from the oldest track it saw, never exhaustive', function () {
    $connector = new SpotifyTracksConnector;
    $messages = iterator_to_array($connector->pull(
        musicPull($connector, 'https://open.spotify.com/artist/abc'),
        musicIo(okEffect([
            spotifyTrackFixture(['published' => '2010-05-18']),
            spotifyTrackFixture(['external_id' => 't2', 'published' => '2006-03-21']),
        ])),
    ));

    // max_tracks caps the actor, so what came back is a PREFIX of the
    // catalogue. Claiming exhaustive would let absence-folding tombstone every
    // older release the cap excluded.
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));
    expect($covered)->toHaveCount(1);
});

it('emits a Note and claims NO coverage when the artist has no tracks', function () {
    $connector = new SpotifyTracksConnector;
    $messages = iterator_to_array($connector->pull(
        musicPull($connector, 'https://open.spotify.com/artist/abc'),
        musicIo(okEffect([])),
    ));

    // Same guard as YoutubeMusicConnector's empty feed: an artist the actor
    // could not see must never read as "they deleted their catalogue".
    expect(array_filter($messages, fn ($m) => $m instanceof Note))->not->toBeEmpty()
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty()
        ->and(array_filter($messages, fn ($m) => $m instanceof Record))->toBeEmpty();
});

it('degrades to Unavailable when the effect was refused, budgeted away or failed', function () {
    foreach (['refused', 'budget_skipped', 'failed'] as $status) {
        $connector = new SpotifyTracksConnector;
        $messages = iterator_to_array($connector->pull(
            musicPull($connector, 'https://open.spotify.com/artist/abc'),
            musicIo(['status' => $status, 'cached' => false, 'data' => null]),
        ));

        expect(array_filter($messages, fn ($m) => $m instanceof Unavailable))->not->toBeEmpty("status '{$status}'")
            ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty("status '{$status}'");
    }
});

it('honours a scope limit so a probe run cannot land a whole catalogue', function () {
    $connector = new SpotifyTracksConnector;
    $tracks = [];
    foreach (range(1, 10) as $i) {
        $tracks[] = spotifyTrackFixture(['external_id' => "t{$i}"]);
    }

    $pull = new Pull(
        identifier: 'https://open.spotify.com/artist/abc',
        stream: $connector::manifest()->stream('tracks'),
        config: ['scope' => 'latest_n', 'scope_n' => 3],
    );

    $records = array_filter(iterator_to_array($connector->pull($pull, musicIo(okEffect($tracks)))), fn ($m) => $m instanceof Record);
    expect($records)->toHaveCount(3);
});

// ── Projection ──────────────────────────────────────────────────────────────

it('projects a spotify track carrying the facets the identity keys read', function () {
    $projection = (new SpotifyTrackProjector)->project(new RecordView(spotifyTrackFixture(), 't1'));

    expect($projection['kind'])->toBe('track')
        ->and($projection['headline'])->toBe('The Funeral')
        // f_catalog.isrc feeds KeyClass::Isrc (joining); f_authored.creator feeds
        // TitleRelease (corroborating). Without these two the phase's dedup gate
        // has nothing stronger than a bare title to work with.
        ->and($projection['facets']['f_catalog']['isrc'])->toBe('USUM71234567')
        ->and($projection['facets']['f_authored']['creator'])->toBe('Band of Horses')
        ->and($projection['facets']['f_duration']['seconds'])->toBe(321)
        ->and($projection['facets']['f_published']['published_from'])->toBe('2006-03-21')
        ->and($projection['facets']['f_link']['url'])->toBe('https://open.spotify.com/track/t1')
        ->and($projection['media'][0]['url'])->toBe('https://i.scdn.co/x.jpg');
});

it('omits the facets a track simply does not carry, rather than emitting empty ones', function () {
    $projection = (new SpotifyTrackProjector)->project(new RecordView([
        'external_id' => 't9', 'title' => 'Untitled', 'url' => 'https://open.spotify.com/track/t9',
    ], 't9'));

    expect($projection['facets'])->not->toHaveKey('f_catalog')
        ->and($projection['facets'])->not->toHaveKey('f_authored')
        ->and($projection['facets'])->not->toHaveKey('f_duration')
        ->and($projection['media'])->toBe([]);
});

it('refuses to project a track with no title or no url', function () {
    $projector = new SpotifyTrackProjector;

    expect($projector->project(new RecordView(['url' => 'https://open.spotify.com/track/x'], 'x')))->toBeNull()
        ->and($projector->project(new RecordView(['title' => 'Titled'], 'y')))->toBeNull();
});

it('projects a soundcloud track with its own embed provider', function () {
    $projection = (new SoundcloudTrackProjector)->project(new RecordView([
        'external_id' => 's1',
        'title' => 'Never Be Like You',
        'url' => 'https://soundcloud.com/flume/never-be-like-you',
        'artist' => 'Flume',
        'isrc' => 'AUUM71600001',
        'duration_seconds' => 236,
    ], 's1'));

    expect($projection['kind'])->toBe('track')
        ->and($projection['facets']['f_authored']['creator'])->toBe('Flume')
        ->and($projection['facets']['f_catalog']['isrc'])->toBe('AUUM71600001')
        ->and($projection['facets']['f_embed']['provider'])->toBe('soundcloud');
});

it('keeps both track projectors on the same kind and version', function () {
    expect(SpotifyTrackProjector::kind())->toBe('track')
        ->and(SoundcloudTrackProjector::kind())->toBe('track')
        ->and(SpotifyTrackProjector::version())->toBe(SoundcloudTrackProjector::version())
        ->and(SoundcloudTrackProjector::version())->toBe(2);
});
