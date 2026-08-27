<?php

// Wave 2 (2026-08-28): Deezer artist top tracks → listen pool. Fixture
// mirrors the live api.deezer.com capture (2026-08-28).

use App\Ingest\Connectors\DeezerTracksConnector;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\DeezerTrackProjector;
use App\Ingest\Projection\ProjectorRegistry;
use App\Ingest\Projection\RecordView;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

function dzIo(array $response): Io
{
    return new class($response) implements Io
    {
        public array $urls = [];

        public function __construct(private array $response) {}

        public function get(string $url, array $headers = []): array
        {
            $this->urls[] = $url;

            return $this->response;
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            throw new RuntimeException('unexpected POST');
        }

        public function getMany(array $urls, array $headers = []): array
        {
            return array_map(fn ($u) => $this->get($u), array_combine($urls, $urls));
        }

        public function effect(string $kind, string $name, array $input): array
        {
            throw new RuntimeException('unexpected effect');
        }
    };
}

function dzBody(): string
{
    return json_encode(['data' => [[
        'id' => 1109731,
        'title' => 'Lose Yourself',
        'link' => 'https://www.deezer.com/track/1109731',
        'duration' => 326,
        'album' => ['title' => '8 Mile', 'cover_xl' => 'https://cdn-images.dzcdn.net/images/cover/x/1000x1000.jpg'],
        'contributors' => [['name' => 'Eminem']],
    ]]]);
}

function dzPull(): Pull
{
    return new Pull(identifier: '13', stream: DeezerTracksConnector::manifest()->stream('tracks'), config: []);
}

it('lands top tracks with exhaustive coverage off the keyless api', function () {
    $io = dzIo(['status' => 200, 'headers' => [], 'body' => dzBody()]);
    $messages = iterator_to_array((new DeezerTracksConnector)->pull(dzPull(), $io), false);
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));

    expect($io->urls)->toBe(['https://api.deezer.com/artist/13/top?limit=50'])
        ->and($records)->toHaveCount(1)
        ->and($records[0]->doc['title'])->toBe('Lose Yourself')
        ->and($records[0]->doc['artist'])->toBe('Eminem')
        ->and($records[0]->doc['album'])->toBe('8 Mile')
        ->and($records[0]->doc['duration_seconds'])->toBe(326)
        ->and(collect($messages)->first(fn ($m) => $m instanceof Covered))->not->toBeNull();
});

it('reads the 200-with-error body as Unavailable, never an emptied catalogue', function () {
    $io = dzIo(['status' => 200, 'headers' => [], 'body' => json_encode(['error' => ['type' => 'DataException', 'code' => 800]])]);
    $messages = iterator_to_array((new DeezerTracksConnector)->pull(dzPull(), $io), false);

    expect(collect($messages)->first(fn ($m) => $m instanceof Unavailable))->not->toBeNull()
        ->and(collect($messages)->first(fn ($m) => $m instanceof Covered))->toBeNull();
});

it('refuses a non-numeric identifier without fetching', function () {
    $io = dzIo(['status' => 200, 'headers' => [], 'body' => dzBody()]);
    $pull = new Pull(identifier: 'eminem', stream: DeezerTracksConnector::manifest()->stream('tracks'), config: []);
    $messages = iterator_to_array((new DeezerTracksConnector)->pull($pull, $io), false);

    expect(collect($messages)->first(fn ($m) => $m instanceof Unavailable))->not->toBeNull()
        ->and($io->urls)->toBe([]);
});

it('projects through the shared music shape with the numeric embed key', function () {
    expect(ProjectorRegistry::has('deezer', 'tracks'))->toBeTrue();

    $p = (new DeezerTrackProjector)->project(new RecordView([
        'title' => 'Lose Yourself',
        'url' => 'https://www.deezer.com/track/1109731',
        'duration_seconds' => 326,
        'artist' => 'Eminem',
        'album' => '8 Mile',
        'artwork' => 'https://cdn-images.dzcdn.net/images/cover/x/1000x1000.jpg',
    ]));

    expect($p['facets']['f_embed'])->toBe(['provider' => 'deezer', 'embed_key' => '1109731'])
        ->and($p['facets']['f_catalog']['collection_title'])->toBe('8 Mile')
        ->and($p['media'][0]['url'])->toContain('1000x1000');
});
