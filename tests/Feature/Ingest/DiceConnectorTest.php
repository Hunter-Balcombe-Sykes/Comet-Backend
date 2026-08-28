<?php

// Wave 2 (2026-08-28): DICE artist pages → events pool. Fixture mirrors the
// live dice.fm/artist/grouper-ebvw capture (2026-08-28).

use App\Ingest\Connectors\DiceConnector;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\ProjectorRegistry;
use App\Ingest\Projection\SchemaOrgEventProjector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

function diceIo(array $response): Io
{
    return new class($response) implements Io
    {
        public array $urls = [];

        public array $headers = [];

        public function __construct(private array $response) {}

        public function get(string $url, array $headers = []): array
        {
            $this->urls[] = $url;
            $this->headers = $headers;

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

function dicePage(array $events): array
{
    $ld = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'PerformingGroup',
        'url' => 'https://dice.fm/artist/grouper-ebvw',
        'name' => 'Grouper',
        'event' => $events,
    ]);

    return ['status' => 200, 'headers' => [], 'body' => '<script type="application/ld+json">'.$ld.'</script>'];
}

function diceEvent(string $id, string $name, string $start): array
{
    return [
        '@type' => 'Event',
        'url' => "https://dice.fm/event/{$id}",
        'name' => $name,
        'startDate' => $start,
        'endDate' => '2026-11-02T03:00:00+01:00',
        'location' => [
            '@type' => 'Place',
            'name' => 'Lingotto',
            'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Torino', 'addressCountry' => 'IT'],
        ],
        'image' => ['https://dice-media.imgix.net/attachments/x.jpg?rect=0%2C0%2C5000%2C5000'],
        'offers' => ['@type' => 'AggregateOffer', 'priceCurrency' => 'EUR', 'lowPrice' => '187.50'],
    ];
}

function dicePull(string $slug = 'grouper-ebvw'): Pull
{
    return new Pull(identifier: $slug, stream: DiceConnector::manifest()->stream('events'), config: []);
}

it('lands the artist tour from the PerformingGroup block', function () {
    $io = diceIo(dicePage([
        diceEvent('691caebfdb147a0001040d12', 'C2C FESTIVAL 2026 | PASSPORT', '2026-10-29T20:30:00+01:00'),
        diceEvent('691caebfdb147a0001040d99', 'Second Night', '2026-09-01T19:00:00+01:00'),
    ]));

    $messages = iterator_to_array((new DiceConnector)->pull(dicePull(), $io), false);
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));

    expect($io->urls)->toBe(['https://dice.fm/artist/grouper-ebvw'])
        // A browser-shaped UA, or dice.fm answers its bot wall.
        ->and($io->headers['User-Agent'] ?? '')->toContain('Mozilla/5.0')
        ->and($records)->toHaveCount(2)
        ->and($records[0]->key)->toBe('691caebfdb147a0001040d12')
        ->and($records[0]->doc['name'])->toBe('C2C FESTIVAL 2026 | PASSPORT')
        ->and($records[0]->doc['venue'])->toBe('Lingotto')
        ->and($records[0]->doc['locality'])->toBe('Torino')
        ->and($records[0]->doc['price_min'])->toBe(187.5)
        ->and($records[0]->doc['currency'])->toBe('EUR')
        ->and(collect($messages)->first(fn ($m) => $m instanceof Covered))->not->toBeNull();
});

it('emits a Note and no coverage for an artist between tours', function () {
    $messages = iterator_to_array((new DiceConnector)->pull(dicePull(), diceIo(dicePage([]))), false);

    expect(collect($messages)->first(fn ($m) => $m instanceof Note))->not->toBeNull()
        ->and(collect($messages)->first(fn ($m) => $m instanceof Covered))->toBeNull();
});

it('reports Unavailable when the PerformingGroup block is gone', function () {
    $io = diceIo(['status' => 200, 'headers' => [], 'body' => '<html>no ld+json</html>']);
    $messages = iterator_to_array((new DiceConnector)->pull(dicePull(), $io), false);

    expect(collect($messages)->first(fn ($m) => $m instanceof Unavailable))->not->toBeNull();
});

it('refuses a non-slug identifier without fetching', function () {
    $io = diceIo(dicePage([diceEvent('a1', 'X', '2026-01-01T00:00:00+00:00')]));
    $messages = iterator_to_array((new DiceConnector)->pull(dicePull('https://evil.example/x'), $io), false);

    expect(collect($messages)->first(fn ($m) => $m instanceof Unavailable))->not->toBeNull()
        ->and($io->urls)->toBe([]);
});

it('projects through the shared schema.org event projector', function () {
    expect(ProjectorRegistry::has('dice', 'events'))->toBeTrue()
        ->and(ProjectorRegistry::for('dice', 'events'))
        ->toBeInstanceOf(SchemaOrgEventProjector::class);
});
