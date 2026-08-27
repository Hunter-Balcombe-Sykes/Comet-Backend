<?php

// T27b (2026-08-28): Treatwell venue catalog → services pool. Fixture
// mirrors the live barber-shop-mayfair capture (2026-08-28).

use App\Ingest\Connectors\TreatwellConnector;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\ProjectorRegistry;
use App\Ingest\Projection\RecordView;
use App\Ingest\Projection\TreatwellServiceProjector;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

function twIo(array $response): Io
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

function twPage(): array
{
    $ld = json_encode(['@context' => 'https://schema.org', '@graph' => [[
        '@type' => 'HealthAndBeautyBusiness',
        'name' => 'The Barber Shop Mayfair',
        'hasOfferCatalog' => ['@type' => 'OfferCatalog', 'itemListElement' => [[
            '@type' => 'OfferCatalog',
            'name' => 'Men - Haircuts & Grooming',
            'itemListElement' => [[
                '@type' => 'Offer',
                'itemOffered' => [
                    '@type' => 'Service',
                    'name' => 'Men - Wash, Scissor Cut & Style',
                    'description' => 'Fades, fuseys, buzzcuts…',
                    'additionalProperty' => ['@type' => 'PropertyValue', 'name' => 'Duration', 'value' => 'PT30M'],
                ],
                'price' => '10.00',
                'priceCurrency' => 'GBP',
            ]],
        ]]],
    ]]]);

    return ['status' => 200, 'headers' => [], 'body' => '<script type="application/ld+json">'.$ld.'</script>'];
}

it('lands catalog offers as services with category, duration and price', function () {
    $pull = new Pull(identifier: 'https://www.treatwell.co.uk/place/the-barber-shop-mayfair/', stream: TreatwellConnector::manifest()->stream('services'), config: []);
    $records = array_values(array_filter(
        iterator_to_array((new TreatwellConnector)->pull($pull, twIo(twPage())), false),
        fn ($m) => $m instanceof Record,
    ));

    expect($records)->toHaveCount(1)
        ->and($records[0]->doc['name'])->toBe('Men - Wash, Scissor Cut & Style')
        ->and($records[0]->doc['price'])->toBe(10.0)
        ->and($records[0]->doc['currency'])->toBe('GBP')
        ->and($records[0]->doc['duration_seconds'])->toBe(1800)
        ->and($records[0]->doc['category'])->toBe('Men - Haircuts & Grooming');
});

it('reports Unavailable when no catalog exists', function () {
    $pull = new Pull(identifier: 'https://www.treatwell.co.uk/place/x/', stream: TreatwellConnector::manifest()->stream('services'), config: []);
    $messages = iterator_to_array((new TreatwellConnector)->pull($pull, twIo(['status' => 200, 'headers' => [], 'body' => '<html/>'])), false);

    expect(collect($messages)->first(fn ($m) => $m instanceof Unavailable))->not->toBeNull();
});

it('projects with duration facet, category tag and exact offer', function () {
    expect(ProjectorRegistry::has('treatwell', 'services'))->toBeTrue();

    $p = (new TreatwellServiceProjector)->project(new RecordView([
        'name' => 'Cut', 'price' => 10.0, 'currency' => 'GBP',
        'duration_seconds' => 1800, 'category' => 'Men', 'url' => 'https://www.treatwell.co.uk/place/x/',
        'description' => 'A cut.',
    ]));

    expect($p['facets']['f_duration']['seconds'])->toBe(1800)
        ->and($p['tags'][0]['tag'])->toBe('Men')
        ->and($p['offers'][0]['amount_minor'])->toBe(1000);
});
