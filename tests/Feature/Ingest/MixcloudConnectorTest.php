<?php

// T27b (2026-08-28): Mixcloud shows → listen pool. Fixture rows mirror the
// live NTSRadio capture (2026-08-28).

use App\Ingest\Connectors\MixcloudConnector;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\MixcloudTrackProjector;
use App\Ingest\Projection\ProjectorRegistry;
use App\Ingest\Projection\RecordView;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

function mixcloudIo(array $response): Io
{
    return new class($response) implements Io
    {
        public function __construct(private array $response) {}

        public function get(string $url, array $headers = []): array
        {
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
            return ['status' => 'ok', 'cached' => false, 'data' => null];
        }
    };
}

function mixcloudPull(): Pull
{
    return new Pull(identifier: 'NTSRadio', stream: MixcloudConnector::manifest()->stream('tracks'), config: []);
}

function mixcloudCast(string $slug, string $name): array
{
    return [
        'key' => "/NTSRadio/{$slug}/",
        'url' => "https://www.mixcloud.com/NTSRadio/{$slug}/",
        'name' => $name,
        'created_time' => '2026-08-27T12:27:02Z',
        'audio_length' => 3560,
        'pictures' => ['extra_large' => 'https://thumbnailer.mixcloud.com/x/extra_large/a.jpg', 'large' => 'https://thumbnailer.mixcloud.com/x/large/a.jpg'],
        'user' => ['name' => 'Mixcloud NTS Radio', 'username' => 'NTSRadio'],
    ];
}

it('lands cloudcasts as track records with the full doc shape', function () {
    $io = mixcloudIo(['status' => 200, 'headers' => [], 'body' => json_encode([
        'data' => [mixcloudCast('show-one', 'Show One'), mixcloudCast('show-two', 'Show Two')],
        'paging' => [],
    ])]);

    $messages = iterator_to_array((new MixcloudConnector)->pull(mixcloudPull(), $io), false);
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = collect($messages)->first(fn ($m) => $m instanceof Covered);

    expect($records)->toHaveCount(2)
        ->and($records[0]->key)->toBe('/NTSRadio/show-one/')
        ->and($records[0]->doc['title'])->toBe('Show One')
        ->and($records[0]->doc['duration_seconds'])->toBe(3560)
        ->and($records[0]->doc['artist'])->toBe('Mixcloud NTS Radio')
        ->and($records[0]->doc['artwork'])->toContain('extra_large')
        ->and($covered)->not->toBeNull();
});

it('reports Unavailable on a non-200 rather than an empty feed', function () {
    $io = mixcloudIo(['status' => 404, 'headers' => [], 'body' => '']);

    $messages = iterator_to_array((new MixcloudConnector)->pull(mixcloudPull(), $io), false);

    expect(collect($messages)->first(fn ($m) => $m instanceof Unavailable))->not->toBeNull();
});

it('registers the projector and emits the shared track facets', function () {
    expect(ProjectorRegistry::has('mixcloud', 'tracks'))->toBeTrue();

    $projection = (new MixcloudTrackProjector)->project(new RecordView([
        'title' => 'Show One',
        'url' => 'https://www.mixcloud.com/NTSRadio/show-one/',
        'published' => '2026-08-27T12:27:02Z',
        'duration_seconds' => 3560,
        'artist' => 'Mixcloud NTS Radio',
        'artwork' => 'https://thumbnailer.mixcloud.com/x/extra_large/a.jpg',
    ]));

    expect($projection['kind'])->toBe('track')
        ->and($projection['headline'])->toBe('Show One')
        ->and($projection['facets']['f_embed'])->toBe(['provider' => 'mixcloud', 'embed_key' => 'https://www.mixcloud.com/NTSRadio/show-one/'])
        ->and($projection['facets']['f_duration']['seconds'])->toBe(3560)
        ->and($projection['media'][0]['url'])->toContain('extra_large');
});
