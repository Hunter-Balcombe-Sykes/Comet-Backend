<?php

use App\Ingest\Connectors\ApplePodcastsConnector;
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
function applePodcastsIo(array $responses): Io
{
    return new class($responses) implements Io
    {
        public function __construct(private array $responses) {}

        public function get(string $url, array $headers = []): array
        {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! ApplePodcastsConnector::manifest()->mayContact($host)) {
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

function applePodcastsPull(string $podcastId = 'INVENTEDSHOW0001'): Pull
{
    return new Pull(
        identifier: $podcastId,
        stream: ApplePodcastsConnector::manifest()->stream('listen'),
    );
}

function applePodcastsLookupUrl(string $podcastId, int $limit = 200): string
{
    return 'https://itunes.apple.com/lookup?'.http_build_query(['id' => $podcastId, 'entity' => 'podcastEpisode', 'limit' => $limit]);
}

/** The wrapper row every real lookup returns at least once — deliberately shaped so it WOULD pass mapEpisode()'s requires check if the kind filter were ever removed, so tests asserting it is skipped are a real regression guard. */
function applePodcastWrapper(): array
{
    return [
        'wrapperType' => 'track',
        'kind' => 'podcast',
        'collectionId' => 888,
        'collectionName' => 'Invented Show',
        'artistName' => 'Invented Host',
        'trackId' => 888,
        'trackName' => 'Invented Show (should never land)',
    ];
}

it('declares only the hosts it actually needs', function () {
    $manifest = ApplePodcastsConnector::manifest();

    expect($manifest->mayContact('itunes.apple.com'))->toBeTrue()
        ->and($manifest->mayContact('a1.mzstatic.com'))->toBeTrue()
        ->and($manifest->mayContact('evil.com'))->toBeFalse()
        ->and($manifest->mayContact('itunes.apple.com.evil.com'))->toBeFalse();
});

it('yields a record per episode plus an exhaustive coverage claim, skipping the podcast wrapper row', function () {
    $podcastId = 'INVENTEDSHOW0001';
    $io = applePodcastsIo([applePodcastsLookupUrl($podcastId) => [
        'status' => 200,
        'body' => json_encode([
            'resultCount' => 3,
            'results' => [
                applePodcastWrapper(),
                ['wrapperType' => 'podcastEpisode', 'kind' => 'podcast-episode', 'trackId' => 1111, 'trackName' => 'First Episode', 'collectionName' => 'Invented Show', 'releaseDate' => '2024-03-01T00:00:00Z', 'artworkUrl600' => 'https://a1.mzstatic.com/600x600bb.jpg?x=1'],
                ['wrapperType' => 'podcastEpisode', 'kind' => 'podcast-episode', 'trackId' => 2222, 'trackName' => 'Second Episode', 'collectionName' => 'Invented Show', 'releaseDate' => '2025-02-02T00:00:00Z', 'artworkUrl600' => 'https://a1.mzstatic.com/600x600bb.jpg?x=2'],
            ],
        ]),
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new ApplePodcastsConnector)->pull(applePodcastsPull($podcastId), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(2)
        ->and($records[0]->key)->toBe('1111')
        ->and($records[0]->doc['trackName'])->toBe('First Episode')
        // The podcast wrapper row must never surface as a record of its own.
        ->and(array_column($records, 'key'))->not->toContain('888')
        ->and($covered)->toHaveCount(1)
        ->and($covered[0]->coverage->toArray()['type'])->toBe('exhaustive');
});

it('reports a failed lookup as unavailable rather than as a show with no episodes', function () {
    $podcastId = 'INVENTEDSHOW0002';
    $io = applePodcastsIo([applePodcastsLookupUrl($podcastId) => ['status' => 503, 'body' => '', 'headers' => []]]);

    $messages = iterator_to_array((new ApplePodcastsConnector)->pull(applePodcastsPull($podcastId), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class)
        ->and($messages[0]->status)->toBe(503);
});

it('reports resultCount=0 as unavailable, since the podcast id no longer resolves', function () {
    $podcastId = 'INVENTEDSHOW0003';
    $io = applePodcastsIo([applePodcastsLookupUrl($podcastId) => [
        'status' => 200,
        'body' => json_encode(['resultCount' => 0, 'results' => []]),
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new ApplePodcastsConnector)->pull(applePodcastsPull($podcastId), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('reports an unparseable body as unavailable rather than as an empty feed', function () {
    $podcastId = 'INVENTEDSHOW0004';
    $io = applePodcastsIo([applePodcastsLookupUrl($podcastId) => [
        'status' => 200,
        'body' => '<html>not json</html>',
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new ApplePodcastsConnector)->pull(applePodcastsPull($podcastId), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('emits no coverage when it parses zero episodes, so absence can never delete', function () {
    $podcastId = 'INVENTEDSHOW0005';
    $io = applePodcastsIo([applePodcastsLookupUrl($podcastId) => [
        'status' => 200,
        'body' => json_encode(['resultCount' => 1, 'results' => [applePodcastWrapper()]]),
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new ApplePodcastsConnector)->pull(applePodcastsPull($podcastId), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Note::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('claims only a prefix when the lookup hits the 200-row cap, never exhaustive', function () {
    $podcastId = 'INVENTEDSHOW0006';
    $io = applePodcastsIo([applePodcastsLookupUrl($podcastId) => [
        'status' => 200,
        'body' => json_encode([
            'resultCount' => 200,
            'results' => [
                applePodcastWrapper(),
                ['wrapperType' => 'podcastEpisode', 'kind' => 'podcast-episode', 'trackId' => 3333, 'trackName' => 'Newest Episode', 'releaseDate' => '2025-06-01T00:00:00Z'],
                ['wrapperType' => 'podcastEpisode', 'kind' => 'podcast-episode', 'trackId' => 4444, 'trackName' => 'Older Episode', 'releaseDate' => '2020-01-01T00:00:00Z'],
            ],
        ]),
        'headers' => [],
    ]]);

    $messages = iterator_to_array((new ApplePodcastsConnector)->pull(applePodcastsPull($podcastId), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered))[0];

    expect($records)->toHaveCount(2)
        ->and($covered->coverage->toArray())->toMatchArray(['type' => 'prefix', 'from' => '2020-01-01T00:00:00Z']);
});

it('refuses to fetch a host outside its own manifest', function () {
    $io = applePodcastsIo([]);

    expect(fn () => $io->get('https://evil.com/lookup?id=whatever'))
        ->toThrow(EffectRefused::class);
});

it('uses a Feed profile with an order field, so its absences can mean deletion', function () {
    $spec = ApplePodcastsConnector::manifest()->stream('listen');

    expect($spec->profile)->toBe(SourceProfile::Feed)
        ->and($spec->orderField)->toBe('releaseDate')
        ->and($spec->mayDelete())->toBeTrue();
});
