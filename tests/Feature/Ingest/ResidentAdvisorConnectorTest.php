<?php

// T27b (2026-08-28): Resident Advisor artist events → events pool via the
// shared schema.org projector. Fixture mirrors the live GraphQL capture
// (benbohmer, 2026-08-28).

use App\Ingest\Connectors\ResidentAdvisorConnector;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\ProjectorRegistry;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

function raIo(array $postResponse): Io
{
    return new class($postResponse) implements Io
    {
        public function __construct(private array $postResponse) {}

        public function get(string $url, array $headers = []): array
        {
            throw new RuntimeException('unexpected GET — RA rides GraphQL POST');
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            return $this->postResponse;
        }

        public function getMany(array $urls, array $headers = []): array
        {
            return [];
        }

        public function effect(string $kind, string $name, array $input): array
        {
            return ['status' => 'ok', 'cached' => false, 'data' => null];
        }
    };
}

function raPull(): Pull
{
    return new Pull(identifier: 'benbohmer', stream: ResidentAdvisorConnector::manifest()->stream('events'), config: []);
}

it('lands artist events in the shared schema.org doc shape', function () {
    $io = raIo(['status' => 200, 'headers' => [], 'body' => json_encode(['data' => ['artist' => [
        'id' => '53404', 'name' => 'Ben Böhmer',
        'events' => [[
            'id' => '2476530',
            'title' => 'We Belong Here: Central Park',
            'contentUrl' => '/events/2476530',
            'startTime' => '2026-10-02T16:00:00.000',
            'endTime' => null,
            'venue' => ['name' => 'Wollman Rink', 'area' => ['name' => 'New York City']],
            'flyerFront' => null,
        ]],
    ]]])]);

    $records = array_values(array_filter(
        iterator_to_array((new ResidentAdvisorConnector)->pull(raPull(), $io), false),
        fn ($m) => $m instanceof Record,
    ));

    expect($records)->toHaveCount(1)
        ->and($records[0]->key)->toBe('2476530')
        ->and($records[0]->doc['name'])->toBe('We Belong Here: Central Park')
        ->and($records[0]->doc['url'])->toBe('https://ra.co/events/2476530')
        ->and($records[0]->doc['venue'])->toBe('Wollman Rink')
        ->and($records[0]->doc['locality'])->toBe('New York City')
        ->and(ProjectorRegistry::has('resident_advisor', 'events'))->toBeTrue();
});

it('treats graphql errors and unresolved slugs as Unavailable, never empty', function () {
    $errors = raIo(['status' => 200, 'headers' => [], 'body' => json_encode(['errors' => [['message' => 'rotated']]])]);
    $gone = raIo(['status' => 200, 'headers' => [], 'body' => json_encode(['data' => ['artist' => null]])]);

    foreach ([$errors, $gone] as $io) {
        $messages = iterator_to_array((new ResidentAdvisorConnector)->pull(raPull(), $io), false);
        expect(collect($messages)->first(fn ($m) => $m instanceof Unavailable))->not->toBeNull();
    }
});
