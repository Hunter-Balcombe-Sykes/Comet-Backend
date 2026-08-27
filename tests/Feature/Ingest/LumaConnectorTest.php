<?php

// T27b (2026-08-28): Luma calendars → events pool via the shared schema.org
// projector. Fixture mirrors the live lu.ma/sf capture (2026-08-28).

use App\Ingest\Connectors\LumaConnector;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\ProjectorRegistry;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

function lumaIo(array $response): Io
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

function lumaPage(array $events): array
{
    $next = json_encode(['props' => ['pageProps' => ['initialData' => ['data' => ['events' => $events]]]]]);

    return ['status' => 200, 'headers' => [], 'body' => '<script id="__NEXT_DATA__" type="application/json">'.$next.'</script>'];
}

it('lands calendar events in the shared schema.org doc shape', function () {
    $io = lumaIo(lumaPage([[
        'api_id' => 'evt-abc',
        'event' => [
            'api_id' => 'evt-abc',
            'name' => 'Step SF 2026',
            'url' => 'StepSF26',
            'start_at' => '2026-08-27T16:00:00.000Z',
            'end_at' => '2026-08-28T03:00:00.000Z',
            'cover_url' => 'https://images.lumacdn.com/event-covers/x.png',
            'geo_address_info' => ['city' => 'San Francisco'],
        ],
    ]]));

    $pull = new Pull(identifier: 'sf', stream: LumaConnector::manifest()->stream('events'), config: []);
    $records = array_values(array_filter(
        iterator_to_array((new LumaConnector)->pull($pull, $io), false),
        fn ($m) => $m instanceof Record,
    ));

    expect($records)->toHaveCount(1)
        ->and($records[0]->key)->toBe('evt-abc')
        ->and($records[0]->doc['name'])->toBe('Step SF 2026')
        ->and($records[0]->doc['url'])->toBe('https://lu.ma/StepSF26')
        ->and($records[0]->doc['start_date'])->toBe('2026-08-27T16:00:00.000Z')
        ->and($records[0]->doc['locality'])->toBe('San Francisco')
        ->and(ProjectorRegistry::has('luma', 'events'))->toBeTrue();
});

it('reports Unavailable on a shell rev rather than an empty calendar', function () {
    $io = lumaIo(['status' => 200, 'headers' => [], 'body' => '<html>no next data</html>']);
    $pull = new Pull(identifier: 'sf', stream: LumaConnector::manifest()->stream('events'), config: []);

    $messages = iterator_to_array((new LumaConnector)->pull($pull, $io), false);

    expect(collect($messages)->first(fn ($m) => $m instanceof Unavailable))->not->toBeNull();
});
