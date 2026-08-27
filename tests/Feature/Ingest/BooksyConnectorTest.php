<?php

// T27b (2026-08-28): Booksy venue schema.org → services + reviews pools.
// Fixture mirrors the live finn-barber capture (2026-08-28).

use App\Ingest\Connectors\BooksyConnector;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\BooksyServiceProjector;
use App\Ingest\Projection\ProjectorRegistry;
use App\Ingest\Projection\RecordView;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

function booksyIo(array $response): Io
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

function booksyPage(): array
{
    $ld = json_encode([
        '@type' => 'HairSalon',
        'name' => 'Finn Barber',
        'makesOffer' => [
            ['@type' => 'Offer', 'name' => 'Haircut', 'priceCurrency' => 'AUD', 'price' => 40, 'image' => 'https://cdn.example/haircut.jpg'],
            ['@type' => 'Offer', 'name' => 'Beard Trim', 'priceCurrency' => 'AUD', 'price' => 20],
        ],
        'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => 5, 'reviewCount' => 25],
        'review' => [[
            '@type' => 'Review',
            'author' => ['@type' => 'Person', 'name' => 'Thinh D…'],
            'datePublished' => '2026-08-26',
            'reviewBody' => 'Really happy with my cut. Fin is the one to go to.',
            'reviewRating' => ['@type' => 'Rating', 'ratingValue' => 5],
        ]],
    ]);

    return ['status' => 200, 'headers' => [], 'body' => '<script type="application/ld+json">'.$ld.'</script>'];
}

function booksyPull(string $stream): Pull
{
    return new Pull(identifier: 'en-au/11338_finn-barber_barbers_40080_albion', stream: BooksyConnector::manifest()->stream($stream), config: []);
}

it('lands offers as services with exhaustive coverage', function () {
    $messages = iterator_to_array((new BooksyConnector)->pull(booksyPull('services'), booksyIo(booksyPage())), false);
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = collect($messages)->first(fn ($m) => $m instanceof Covered);

    expect($records)->toHaveCount(2)
        ->and($records[0]->doc['name'])->toBe('Haircut')
        ->and($records[0]->doc['price'])->toBe(40.0)
        ->and($records[0]->doc['currency'])->toBe('AUD')
        ->and($covered)->not->toBeNull();
});

it('lands reviews with venue stats in the shared doc shape', function () {
    $records = array_values(array_filter(
        iterator_to_array((new BooksyConnector)->pull(booksyPull('reviews'), booksyIo(booksyPage())), false),
        fn ($m) => $m instanceof Record,
    ));

    expect($records)->toHaveCount(1)
        ->and($records[0]->doc['rating'])->toBe(5.0)
        ->and($records[0]->doc['text'])->toContain('Really happy with my cut')
        ->and($records[0]->doc['author'])->toBe('Thinh D…')
        ->and($records[0]->doc['venue_rating'])->toBe(5.0)
        ->and($records[0]->doc['venue_rating_count'])->toBe(25);
});

it('reports Unavailable when the venue block is missing', function () {
    $io = booksyIo(['status' => 200, 'headers' => [], 'body' => '<html>no ld</html>']);
    $messages = iterator_to_array((new BooksyConnector)->pull(booksyPull('services'), $io), false);

    expect(collect($messages)->first(fn ($m) => $m instanceof Unavailable))->not->toBeNull();
});

it('projects a booksy service with structured price and cover', function () {
    expect(ProjectorRegistry::has('booksy', 'services'))->toBeTrue()
        ->and(ProjectorRegistry::has('booksy', 'reviews'))->toBeTrue();

    $projection = (new BooksyServiceProjector)->project(new RecordView([
        'name' => 'Haircut',
        'price' => 40.0,
        'currency' => 'AUD',
        'url' => 'https://booksy.com/en-au/11338_finn-barber_barbers_40080_albion',
        'image' => 'https://cdn.example/haircut.jpg',
    ]));

    expect($projection['headline'])->toBe('Haircut')
        ->and($projection['offers'][0])->toBe(['channel' => 'booksy', 'qualifier' => 'exact', 'amount_minor' => 4000, 'currency' => 'AUD'])
        ->and($projection['media'][0]['url'])->toContain('haircut.jpg');
});
