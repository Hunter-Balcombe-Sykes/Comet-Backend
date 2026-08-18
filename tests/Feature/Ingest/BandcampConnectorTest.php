<?php

use App\Ingest\Connectors\BandcampConnector;
use App\Ingest\Landing\Lander;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\BandcampReleaseProjector;
use App\Ingest\Projection\RecordView;
use App\Ingest\Runtime\EffectRefused;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Tier C: the connector's real pull() against recorded responses. No network,
// no database — a connector is a pure function from (Pull, Io) to Messages,
// and this is what proves it.

/** A minimal Io that answers from a fixed url => response map. */
function bandcampIo(array $responses): Io
{
    return new class($responses) implements Io
    {
        public function __construct(private array $responses) {}

        public function get(string $url, array $headers = []): array
        {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! BandcampConnector::manifest()->mayContact($host)) {
                throw new EffectRefused("off-manifest host {$host}");
            }

            return $this->responses[$url] ?? ['status' => 404, 'body' => '', 'headers' => []];
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            return ['status' => 405, 'body' => '', 'headers' => []];
        }

        public function getMany(array $urls, array $headers = []): array
        {
            return array_map(fn ($u) => $this->get($u), array_combine($urls, $urls));
        }

        public function effect(string $kind, string $name, array $input): array
        {
            return ['status' => 'ok', 'cached' => false, 'data' => null];
        }
    };
}

function bandcampPull(array $config = []): Pull
{
    return new Pull(
        identifier: 'https://someartist.bandcamp.com',
        stream: BandcampConnector::manifest()->stream('releases'),
        config: $config,
    );
}

function bandcampGridHtml(array $entries): string
{
    return '<html><ol data-client-items="'.htmlspecialchars(json_encode($entries), ENT_QUOTES).'"></ol></html>';
}

it('declares only the hosts it actually needs', function () {
    $manifest = BandcampConnector::manifest();

    expect($manifest->mayContact('someartist.bandcamp.com'))->toBeTrue()
        ->and($manifest->mayContact('f4.bcbits.com'))->toBeTrue()
        ->and($manifest->mayContact('evil.com'))->toBeFalse()
        ->and($manifest->mayContact('bandcamp.com.evil.com'))->toBeFalse();
});

it('yields a record per release plus an exhaustive coverage claim', function () {
    $io = bandcampIo(['https://someartist.bandcamp.com/music' => [
        'status' => 200,
        'body' => bandcampGridHtml([
            ['page_url' => '/album/first-record', 'title' => 'First Record', 'release_date' => '01 Mar 2024 00:00:00 GMT', 'art_id' => 111],
            ['page_url' => '/album/second-record', 'title' => 'Second Record', 'release_date' => '02 Feb 2025 00:00:00 GMT', 'art_id' => 222],
        ]),
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new BandcampConnector)->pull(bandcampPull(), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(2)
        ->and($records[0]->key)->toBe('album/first-record')
        ->and($records[0]->doc['title'])->toBe('First Record')
        ->and($records[0]->doc['release_date'])->toBe('2024-03-01')
        ->and($covered)->toHaveCount(1)
        ->and($covered[0]->coverage->toArray()['type'])->toBe('exhaustive');
});

it('reports a failed fetch as unavailable rather than as an empty discography', function () {
    // The distinction that keeps C5 honest: an unreachable page must never be
    // mistaken for "this artist deleted everything".
    $io = bandcampIo(['https://someartist.bandcamp.com/music' => ['status' => 503, 'body' => '', 'headers' => []]]);

    $messages = iterator_to_array((new BandcampConnector)->pull(bandcampPull(), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class)
        ->and($messages[0]->status)->toBe(503);
});

it('emits no coverage when it parses nothing, so absence can never delete', function () {
    $io = bandcampIo(['https://someartist.bandcamp.com/music' => ['status' => 200, 'body' => '<html></html>', 'headers' => []]]);

    $messages = iterator_to_array((new BandcampConnector)->pull(bandcampPull(), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Note::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('claims only a prefix when the run is scope-limited', function () {
    $io = bandcampIo(['https://someartist.bandcamp.com/music' => [
        'status' => 200,
        'body' => bandcampGridHtml([
            ['page_url' => '/album/a', 'title' => 'A', 'release_date' => '01 Mar 2025 00:00:00 GMT'],
            ['page_url' => '/album/b', 'title' => 'B', 'release_date' => '01 Feb 2025 00:00:00 GMT'],
            ['page_url' => '/album/c', 'title' => 'C', 'release_date' => '01 Jan 2025 00:00:00 GMT'],
        ]),
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new BandcampConnector)->pull(bandcampPull(['scope' => 'latest_n', 'scope_n' => 2]), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered))[0];

    expect($records)->toHaveCount(2)
        ->and($covered->coverage->toArray())->toMatchArray(['type' => 'prefix', 'from' => '2025-02-01']);
});

it('falls back to link parsing when the grid blob is unreadable', function () {
    // A layout change should degrade, not blank: half the fields is better
    // than a stream that looks deleted.
    $io = bandcampIo(['https://someartist.bandcamp.com/music' => [
        'status' => 200,
        'body' => '<a href="/album/fallback-one" class="x"><p class="title"> Fallback One </p></a>',
        'headers' => [],
    ]]);

    $records = array_values(array_filter(
        iterator_to_array((new BandcampConnector)->pull(bandcampPull(), $io)),
        fn ($m) => $m instanceof Record,
    ));

    expect($records)->toHaveCount(1)
        ->and($records[0]->key)->toBe('album/fallback-one')
        ->and($records[0]->doc['title'])->toBe('Fallback One');
});

it('refuses to fetch a host outside its own manifest', function () {
    $connector = new BandcampConnector;
    $io = bandcampIo([]);

    $pull = new Pull(
        identifier: 'https://evil.com',
        stream: BandcampConnector::manifest()->stream('releases'),
    );

    expect(fn () => iterator_to_array($connector->pull($pull, $io)))
        ->toThrow(EffectRefused::class);
});

// ── End to end: connector → landing ─────────────────────────────────────────

it('lands its records, and re-landing identical content writes nothing new', function () {
    setupIngestTables();

    $streamId = (string) Str::uuid();
    DB::table('ingest.sources')->insert([
        'id' => $sourceId = (string) Str::uuid(),
        'user_id' => (string) Str::uuid(),
        'source_key' => 'bandcamp',
        'surface_key' => 'bandcamp.artist',
        'identifier' => 'https://someartist.bandcamp.com',
        'created_at' => now(), 'updated_at' => now(), 'next_attempt_at' => now(),
    ]);
    DB::table('ingest.streams')->insert([
        'id' => $streamId, 'source_id' => $sourceId, 'stream_name' => 'releases',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $io = bandcampIo(['https://someartist.bandcamp.com/music' => [
        'status' => 200,
        'body' => bandcampGridHtml([
            ['page_url' => '/album/one', 'title' => 'One', 'release_date' => '01 Mar 2025 00:00:00 GMT', 'art_id' => 1],
        ]),
        'headers' => [],
    ]]);

    $spec = BandcampConnector::manifest()->stream('releases');
    $lander = new Lander;

    $run = fn () => (function () use ($io, $spec, $lander, $streamId) {
        $messages = iterator_to_array((new BandcampConnector)->pull(bandcampPull(), $io));
        $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
        $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered))[0] ?? null;

        return $lander->land($streamId, (string) Str::uuid(), $spec, $records, $covered);
    })();

    $first = $run();
    $second = $run();

    expect($first['seen'])->toBe(1)
        ->and($first['changed'])->toBe(1)
        ->and($second['seen'])->toBe(1)
        // The property the whole design rests on.
        ->and($second['changed'])->toBe(0)
        ->and(DB::table('ingest.record_versions')->where('stream_id', $streamId)->count())->toBe(1);
});

it('uses a Catalogue profile, so its absences can mean deletion', function () {
    $spec = BandcampConnector::manifest()->stream('releases');

    expect($spec->profile)->toBe(SourceProfile::Catalogue)
        ->and($spec->orderField)->toBe('release_date')
        ->and($spec->mayDelete())->toBeTrue();
});

it('reads the server-rendered grid AND the client blob, credits the band, and dates each release from its page (F24)', function () {
    // The real /music page (menitrust, 2026-08-18): the newest releases are
    // <li> markup, the older remainder is data-client-items, neither half has
    // an artist or a date, and data-band names the band. Reading only the blob
    // landed the ten oldest releases, undated and uncredited.
    $band = htmlspecialchars(json_encode(['id' => 1, 'name' => 'Some Artist']), ENT_QUOTES);
    $blob = htmlspecialchars(json_encode([
        ['art_id' => 333, 'band_id' => 1, 'id' => 3, 'page_url' => '/track/old-single', 'title' => 'Old Single', 'type' => 'track'],
    ]), ENT_QUOTES);
    $music = '<html><ol id="music-grid" data-band="'.$band.'" data-client-items="'.$blob.'">'
        .'<li class="music-grid-item"><a href="/album/new-album"><div class="art"><img src="https://f4.bcbits.com/img/a0111_2.jpg" alt="" /></div><p class="title">New Album</p></a></li>'
        .'<li class="music-grid-item"><a href="/album/short-one"><div class="art"><img src="https://f4.bcbits.com/img/a0222_2.jpg" alt="" /></div><p class="title">Short One</p></a></li>'
        .'</ol></html>';
    $ld = fn (array $d) => '<html><script type="application/ld+json">'.json_encode($d).'</script></html>';
    $io = bandcampIo([
        'https://someartist.bandcamp.com/music' => ['status' => 200, 'body' => $music, 'headers' => []],
        'https://someartist.bandcamp.com/album/new-album' => ['status' => 200, 'body' => $ld([
            '@type' => 'MusicAlbum', 'name' => 'New Album', 'datePublished' => '06 May 2025 00:00:00 GMT',
            'byArtist' => ['@type' => 'MusicGroup', 'name' => 'Some Artist'], 'numTracks' => 13,
            'image' => 'https://f4.bcbits.com/img/a0111_10.jpg',
        ]), 'headers' => []],
        'https://someartist.bandcamp.com/album/short-one' => ['status' => 200, 'body' => $ld([
            '@type' => 'MusicAlbum', 'name' => 'Short One', 'datePublished' => '10 Jun 2015 00:00:00 GMT',
            'byArtist' => ['@type' => 'MusicGroup', 'name' => 'Some Artist & A Friend'], 'numTracks' => 4,
        ]), 'headers' => []],
        // The blob item's page 404s: it still lands, undated, credited to the band.
    ]);

    $records = array_values(array_filter(
        iterator_to_array((new BandcampConnector)->pull(bandcampPull(), $io)),
        fn ($m) => $m instanceof Record,
    ));
    $byKey = collect($records)->keyBy('key');

    expect($byKey->keys()->all())->toBe(['album/new-album', 'album/short-one', 'track/old-single'])
        ->and($byKey['album/new-album']->doc)->toMatchArray([
            'title' => 'New Album', 'artist' => 'Some Artist', 'release_date' => '2025-05-06',
            'track_count' => 13, 'type' => 'album', 'art_url' => 'https://f4.bcbits.com/img/a0111_10.jpg',
        ])
        ->and($byKey['album/short-one']->doc)->toMatchArray([
            'artist' => 'Some Artist & A Friend', 'release_date' => '2015-06-10', 'track_count' => 4,
        ])
        ->and($byKey['track/old-single']->doc)->toMatchArray([
            'title' => 'Old Single', 'artist' => 'Some Artist', 'release_date' => null, 'type' => 'track',
            'art_url' => 'https://f4.bcbits.com/img/a333_10.jpg',
        ]);

    // And the projector turns numTracks into the listen format vocabulary.
    $projector = new BandcampReleaseProjector;
    $format = fn ($doc) => $projector->project(new RecordView($doc, 'k'))['facets']['f_catalog']['release_type'];
    expect($format($byKey['album/new-album']->doc))->toBe('album')
        ->and($format($byKey['album/short-one']->doc))->toBe('ep')
        ->and($format($byKey['track/old-single']->doc))->toBe('single');
});
