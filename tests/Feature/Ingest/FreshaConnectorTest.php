<?php

use App\Ingest\Connectors\FreshaConnector;
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

/** A minimal Io that answers POSTs to the pinned GraphQL endpoint from a fixed response. */
function freshaIo(array $response): Io
{
    return new class($response) implements Io
    {
        public function __construct(private array $response) {}

        public function get(string $url, array $headers = []): array
        {
            return ['status' => 404, 'body' => '', 'headers' => []];
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! FreshaConnector::manifest()->mayContact($host)) {
                throw new EffectRefused("off-manifest host {$host}");
            }

            return $this->response;
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

function freshaPull(string $streamName, string $slug = 'invented-salon'): Pull
{
    return new Pull(
        identifier: $slug,
        stream: FreshaConnector::manifest()->stream($streamName),
    );
}

/** @param  list<array<string,mixed>>  $categories */
function freshaBookingFlowBody(array $categories, ?array $location = null): string
{
    $inner = ['screenServices' => ['categories' => $categories]];
    if ($location !== null) {
        $inner['location'] = $location;
    }

    return json_encode(['data' => ['bookingFlowInitialize' => $inner]]);
}

it('declares only the hosts it actually needs', function () {
    $manifest = FreshaConnector::manifest();

    expect($manifest->mayContact('www.fresha.com'))->toBeTrue()
        ->and($manifest->mayContact('fresha.com'))->toBeTrue()
        ->and($manifest->mayContact('evil.com'))->toBeFalse()
        ->and($manifest->mayContact('fresha.com.evil.com'))->toBeFalse();
});

it('yields a record per service plus an exhaustive coverage claim, since the whole menu is returned at once', function () {
    $io = freshaIo([
        'status' => 200,
        'body' => freshaBookingFlowBody([
            [
                'name' => 'Cuts',
                'items' => [
                    [
                        'name' => 'Fade',
                        'caption' => '30min',
                        'description' => 'A clean fade',
                        'price' => ['formatted' => 'A$40'],
                        'primaryAction' => ['id' => '{"catalogId":"s:1"}'],
                    ],
                ],
            ],
        ]),
        'headers' => [],
    ]);

    $messages = iterator_to_array((new FreshaConnector)->pull(freshaPull('services'), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(1)
        ->and($records[0]->key)->toBe('s:1')
        ->and($records[0]->doc['name'])->toBe('Fade')
        ->and($records[0]->doc['category'])->toBe('Cuts')
        ->and($covered)->toHaveCount(1)
        ->and($covered[0]->coverage->toArray()['type'])->toBe('exhaustive');
});

it('yields one profile record from the same booking-flow response, also exhaustive', function () {
    $io = freshaIo([
        'status' => 200,
        'body' => freshaBookingFlowBody([], [
            'name' => 'Invented Salon',
            'formattedAddress' => '123 Invented St, Sydney NSW',
            'phoneNumber' => '+61 2 0000 0000',
        ]),
        'headers' => [],
    ]);

    $messages = iterator_to_array((new FreshaConnector)->pull(freshaPull('profile', 'invented-salon'), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(1)
        ->and($records[0]->key)->toBe('invented-salon')
        ->and($records[0]->doc['display_name'])->toBe('Invented Salon')
        ->and($records[0]->doc['address'])->toBe('123 Invented St, Sydney NSW')
        ->and($records[0]->doc['phone'])->toBe('+61 2 0000 0000')
        ->and($covered)->toHaveCount(1)
        ->and($covered[0]->coverage->toArray()['type'])->toBe('exhaustive');
});

it('reports a non-200 booking-flow response as unavailable', function () {
    $io = freshaIo(['status' => 500, 'body' => '', 'headers' => []]);

    $messages = iterator_to_array((new FreshaConnector)->pull(freshaPull('services'), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class);
});

it('treats a GraphQL errors key on a 200 as the pinned query being rejected, not a normal miss', function () {
    $io = freshaIo([
        'status' => 200,
        'body' => json_encode(['errors' => [['message' => 'PersistedQueryNotFound']]]),
        'headers' => [],
    ]);

    $messages = iterator_to_array((new FreshaConnector)->pull(freshaPull('services'), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class)
        ->and($messages[0]->reason)->toContain('re-pin');
});

it('treats missing categories as the pinned-hash rotation symptom, not an empty menu', function () {
    // FreshaScraper's own comment: missing categories on an otherwise-200
    // response is the classic hash/version-rotation symptom, so this must
    // read as Unavailable, never as "this salon has no services".
    $io = freshaIo([
        'status' => 200,
        'body' => json_encode(['data' => ['bookingFlowInitialize' => ['screenServices' => []]]]),
        'headers' => [],
    ]);

    $messages = iterator_to_array((new FreshaConnector)->pull(freshaPull('services'), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class);
});

it('emits no coverage when categories parse but nothing maps to a real service', function () {
    $io = freshaIo([
        'status' => 200,
        'body' => freshaBookingFlowBody([
            ['name' => 'Empty Category', 'items' => [
                ['name' => 'Malformed Item', 'primaryAction' => ['id' => 'not-a-catalog-id']],
            ]],
        ]),
        'headers' => [],
    ]);

    $messages = iterator_to_array((new FreshaConnector)->pull(freshaPull('services'), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Note::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('emits a note with no coverage when the response carries no location profile data', function () {
    $io = freshaIo([
        'status' => 200,
        'body' => freshaBookingFlowBody([]),
        'headers' => [],
    ]);

    $messages = iterator_to_array((new FreshaConnector)->pull(freshaPull('profile'), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Note::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('refuses to post to a host outside its own manifest', function () {
    $io = freshaIo(['status' => 200, 'body' => freshaBookingFlowBody([]), 'headers' => []]);

    expect(fn () => $io->post('https://evil.com/graphql', []))
        ->toThrow(EffectRefused::class);
});

it('marks services exhaustive-or-nothing: a Catalogue profile with no order field can never delete', function () {
    $spec = FreshaConnector::manifest()->stream('services');

    expect($spec->profile)->toBe(SourceProfile::Catalogue)
        ->and($spec->orderField)->toBeNull()
        ->and($spec->mayDelete())->toBeFalse();
});

it('marks profile an Identity stream with no order field, so it can never delete either', function () {
    $spec = FreshaConnector::manifest()->stream('profile');

    expect($spec->profile)->toBe(SourceProfile::Identity)
        ->and($spec->orderField)->toBeNull()
        ->and($spec->mayDelete())->toBeFalse();
});
