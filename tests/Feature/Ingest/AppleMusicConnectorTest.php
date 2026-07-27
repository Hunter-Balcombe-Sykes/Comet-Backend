<?php

use App\Ingest\Connectors\AppleMusicConnector;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\EffectRefused;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

// Tier C: the connector's real pull() against recorded responses. No network,
// no DB — a connector is a pure function from (Pull, Io) to Messages.

/** A minimal Io that answers from a fixed url => response map. */
function appleMusicIo(array $responses): Io
{
    return new class($responses) implements Io
    {
        public function __construct(private array $responses) {}

        public function get(string $url, array $headers = []): array
        {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! AppleMusicConnector::manifest()->mayContact($host)) {
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

function appleMusicPull(string $artistId = 'INVENTEDARTIST0001'): Pull
{
    return new Pull(
        identifier: $artistId,
        stream: AppleMusicConnector::manifest()->stream('listen'),
    );
}

function appleMusicLookupUrl(string $artistId, int $limit = 200): string
{
    return 'https://itunes.apple.com/lookup?'.http_build_query(['id' => $artistId, 'entity' => 'album', 'limit' => $limit]);
}

/** The wrapper row every real lookup returns at least once — deliberately shaped so it WOULD pass mapAlbum()'s requires check if the wrapperType filter were ever removed, so tests asserting it is skipped are a real regression guard. */
function appleArtistWrapper(): array
{
    return [
        'wrapperType' => 'artist',
        'artistId' => 999,
        'artistName' => 'Invented Artist',
        'primaryGenreName' => 'Electronic',
        'collectionId' => 999,
        'collectionName' => 'Invented Artist (should never land)',
    ];
}

it('declares only the hosts it actually needs', function () {
    $manifest = AppleMusicConnector::manifest();

    expect($manifest->mayContact('itunes.apple.com'))->toBeTrue()
        ->and($manifest->mayContact('a1.mzstatic.com'))->toBeTrue()
        ->and($manifest->mayContact('evil.com'))->toBeFalse()
        ->and($manifest->mayContact('itunes.apple.com.evil.com'))->toBeFalse();
});

it('yields a record per album plus an exhaustive coverage claim, skipping the artist wrapper row', function () {
    $artistId = 'INVENTEDARTIST0001';
    $io = appleMusicIo([appleMusicLookupUrl($artistId) => [
        'status' => 200,
        'body' => json_encode([
            'resultCount' => 3,
            'results' => [
                appleArtistWrapper(),
                ['wrapperType' => 'collection', 'collectionId' => 1111, 'collectionName' => 'First Record', 'artistName' => 'Invented Artist', 'releaseDate' => '2024-03-01T00:00:00Z', 'artworkUrl100' => 'https://a1.mzstatic.com/100x100bb.jpg?x=1'],
                ['wrapperType' => 'collection', 'collectionId' => 2222, 'collectionName' => 'Second Record', 'artistName' => 'Invented Artist', 'releaseDate' => '2025-02-02T00:00:00Z', 'artworkUrl100' => 'https://a1.mzstatic.com/100x100bb.jpg?x=2'],
            ],
        ]),
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new AppleMusicConnector)->pull(appleMusicPull($artistId), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(2)
        ->and($records[0]->key)->toBe('1111')
        ->and($records[0]->doc['collectionName'])->toBe('First Record')
        // The artist wrapper row must never surface as a record of its own.
        ->and(array_column($records, 'key'))->not->toContain('999')
        ->and($covered)->toHaveCount(1)
        ->and($covered[0]->coverage->toArray()['type'])->toBe('exhaustive');
});

it('reports a failed lookup as unavailable rather than as an empty catalogue', function () {
    $artistId = 'INVENTEDARTIST0002';
    $io = appleMusicIo([appleMusicLookupUrl($artistId) => ['status' => 503, 'body' => '', 'headers' => []]]);

    $messages = iterator_to_array((new AppleMusicConnector)->pull(appleMusicPull($artistId), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class)
        ->and($messages[0]->status)->toBe(503);
});

it('reports resultCount=0 as unavailable, since the artist id no longer resolves', function () {
    $artistId = 'INVENTEDARTIST0003';
    $io = appleMusicIo([appleMusicLookupUrl($artistId) => [
        'status' => 200,
        'body' => json_encode(['resultCount' => 0, 'results' => []]),
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new AppleMusicConnector)->pull(appleMusicPull($artistId), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('reports an unparseable body as unavailable rather than as an empty catalogue', function () {
    $artistId = 'INVENTEDARTIST0004';
    $io = appleMusicIo([appleMusicLookupUrl($artistId) => [
        'status' => 200,
        'body' => '<html>not json</html>',
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new AppleMusicConnector)->pull(appleMusicPull($artistId), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('emits no coverage when it parses zero albums, so absence can never delete', function () {
    $artistId = 'INVENTEDARTIST0005';
    $io = appleMusicIo([appleMusicLookupUrl($artistId) => [
        'status' => 200,
        'body' => json_encode(['resultCount' => 1, 'results' => [appleArtistWrapper()]]),
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new AppleMusicConnector)->pull(appleMusicPull($artistId), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Note::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('claims only a prefix when the lookup hits the 200-row cap, never exhaustive', function () {
    // The property this whole connector's coverage design rests on: `limit`
    // caps the TOTAL row count (wrapper included), so resultCount === 200
    // means at least one older album may be hiding beyond the cap — the same
    // class of boundary VimeoConnector's 20-video cap already proves, just
    // at 200 instead of 20.
    $artistId = 'INVENTEDARTIST0006';
    $io = appleMusicIo([appleMusicLookupUrl($artistId) => [
        'status' => 200,
        'body' => json_encode([
            'resultCount' => 200,
            'results' => [
                appleArtistWrapper(),
                ['wrapperType' => 'collection', 'collectionId' => 3333, 'collectionName' => 'Newest Record', 'releaseDate' => '2025-06-01T00:00:00Z'],
                ['wrapperType' => 'collection', 'collectionId' => 4444, 'collectionName' => 'Older Record', 'releaseDate' => '2020-01-01T00:00:00Z'],
            ],
        ]),
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new AppleMusicConnector)->pull(appleMusicPull($artistId), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered))[0];

    expect($records)->toHaveCount(2)
        ->and($covered->coverage->toArray())->toMatchArray(['type' => 'prefix', 'from' => '2020-01-01T00:00:00Z']);
});

it('refuses to fetch a host outside its own manifest', function () {
    $io = appleMusicIo([]);

    expect(fn () => $io->get('https://evil.com/lookup?id=whatever'))
        ->toThrow(EffectRefused::class);
});

it('uses a Catalogue profile with an order field, so its absences can mean deletion', function () {
    $spec = AppleMusicConnector::manifest()->stream('listen');

    expect($spec->profile)->toBe(SourceProfile::Catalogue)
        ->and($spec->orderField)->toBe('releaseDate')
        ->and($spec->mayDelete())->toBeTrue();
});
