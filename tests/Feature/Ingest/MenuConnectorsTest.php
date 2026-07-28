<?php

use App\Ingest\Connectors\DoordashMenuConnector;
use App\Ingest\Connectors\SquareMenuConnector;
use App\Ingest\Connectors\UberEatsMenuConnector;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\Connector;
use App\Ingest\Runtime\EffectRefused;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

// Tier C for the three Apify menu connectors. Fixtures mirror each actor's
// real dataset shape as documented + shipped in the legacy MenuPlatformDrivers
// (menus-r-us / memo23 / dz_omar).

/** A minimal Io whose effect() answers from a fixed verdict; HTTP is refused. */
function menuIo(array $effectResult): Io
{
    return new class($effectResult) implements Io
    {
        public array $effects = [];

        public function __construct(private array $effectResult) {}

        public function get(string $url, array $headers = []): array
        {
            throw new EffectRefused('menu connectors must not fetch over HTTP');
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            throw new EffectRefused('menu connectors must not fetch over HTTP');
        }

        public function getMany(array $urls, array $headers = []): array
        {
            throw new EffectRefused('menu connectors must not fetch over HTTP');
        }

        public function effect(string $kind, string $name, array $input): array
        {
            $this->effects[] = ['kind' => $kind, 'name' => $name, 'input' => $input];

            return $this->effectResult;
        }
    };
}

function menuPull(Connector $connector, string $storeUrl): Pull
{
    return new Pull(identifier: $storeUrl, stream: $connector::manifest()->stream('menu'));
}

it('square: flattens menu.categories into keyed records under the shared menu budget', function () {
    $dataset = [[
        'restaurantName' => 'Fat Tuna',
        'logo' => 'https://order.fat-tuna.com/logo.png',
        'menu' => ['categories' => [
            ['name' => 'Sushi Rolls', 'items' => [
                ['id' => 'sq-101', 'name' => 'Salmon Roll', 'description' => 'Eight pieces.', 'price' => '$14.50', 'image' => 'https://sq-cdn/salmon.jpg'],
                ['id' => 'sq-102', 'name' => 'Tuna Roll', 'price' => 1650],
            ]],
            ['name' => 'Drinks', 'items' => [
                ['name' => 'Green Tea', 'price' => 4.0],
            ]],
        ]],
    ]];

    $io = menuIo(['status' => 'ok', 'cached' => false, 'data' => $dataset]);
    $connector = new SquareMenuConnector;
    $messages = iterator_to_array($connector->pull(menuPull($connector, 'https://order.fat-tuna.com'), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered))[0];

    expect($io->effects[0]['name'])->toBe('menu')
        ->and($io->effects[0]['input']['actor'])->toBe('menus-r-us~restaurant-menu-scraper')
        ->and($records)->toHaveCount(3)
        ->and($records[0]->key)->toBe('sq-101')
        // "$14.50" string and 1650 cents both normalise to dollars.
        ->and($records[0]->doc['price'])->toBe(14.5)
        ->and($records[1]->doc['price'])->toBe(16.5)
        ->and($records[0]->doc['category'])->toBe('Sushi Rolls')
        ->and($records[2]->doc['category'])->toBe('Drinks')
        ->and($records[0]->doc['store_name'])->toBe('Fat Tuna')
        ->and($covered->coverage->toArray()['type'])->toBe('exhaustive');
});

it('uber eats: regroups the flattened menuItems by section with digest keys (no vendor ids)', function () {
    $dataset = [[
        'title' => 'Doc Pizza',
        'currencyCode' => 'AUD',
        'menuItems' => [
            ['name' => 'Margherita', 'section' => 'Pizze', 'price' => 21.0, 'description' => 'San Marzano, fior di latte.', 'imageUrl' => 'https://ue-cdn/marg.jpg'],
            ['name' => 'Tiramisu', 'section' => 'Dolci', 'price' => 12.5],
            ['name' => 'Diavola', 'section' => 'Pizze', 'price' => 24.0],
        ],
    ]];

    $io = menuIo(['status' => 'ok', 'cached' => false, 'data' => $dataset]);
    $connector = new UberEatsMenuConnector;
    $messages = iterator_to_array($connector->pull(menuPull($connector, 'https://www.ubereats.com/au/store/doc-pizza/abc'), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));

    expect($io->effects[0]['input']['actor'])->toBe('memo23~uber-eats-scraper')
        ->and($records)->toHaveCount(3)
        // First-seen section order: Pizze, Pizze, then Dolci regrouped.
        ->and($records[0]->doc['category'])->toBe('Pizze')
        ->and($records[1]->doc['category'])->toBe('Pizze')
        ->and($records[1]->doc['name'])->toBe('Diavola')
        ->and($records[2]->doc['category'])->toBe('Dolci')
        ->and($records[0]->doc['currency'])->toBe('AUD')
        // No vendor id: the key is a category+name digest, stable across runs.
        ->and($records[0]->key)->toBe(substr(sha1('pizze|margherita'), 0, 16));
});

it('doordash: reads price_cents with the display-string fallback and skips featured re-lists', function () {
    $dataset = [[
        'name' => 'Burger Republic',
        'currency' => 'AUD',
        'featured_items' => [['name' => 'Cheeseburger', 'item_id' => 'dd-1']],
        'menu_categories' => [
            ['category_name' => 'Burgers', 'items' => [
                ['item_id' => 'dd-1', 'name' => 'Cheeseburger', 'price_cents' => 1590, 'image_url' => 'https://dd-cdn/cheese.jpg'],
                ['item_id' => 'dd-2', 'name' => 'Double Stack', 'price_display' => 'A$21.90'],
            ]],
        ],
    ]];

    $io = menuIo(['status' => 'ok', 'cached' => false, 'data' => $dataset]);
    $connector = new DoordashMenuConnector;
    $messages = iterator_to_array($connector->pull(menuPull($connector, 'https://www.doordash.com/store/burger-republic-123'), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));

    expect($io->effects[0]['input']['actor'])->toBe('dz_omar~doordash-scraper')
        // The AU locale keeps results out of the actor's Chicago default.
        ->and($io->effects[0]['input']['input']['address'])->toBe('Melbourne VIC, Australia')
        ->and($records)->toHaveCount(2)
        ->and($records[0]->key)->toBe('dd-1')
        ->and($records[0]->doc['price'])->toBe(15.9)
        ->and($records[1]->doc['price'])->toBe(21.9);
});

it('folds a refused effect into unavailable and an empty menu into a note, for all three', function () {
    $connectors = [new SquareMenuConnector, new UberEatsMenuConnector, new DoordashMenuConnector];

    foreach ($connectors as $connector) {
        $refused = iterator_to_array($connector->pull(
            menuPull($connector, 'https://example-store.test'),
            menuIo(['status' => 'refused', 'cached' => true, 'data' => null]),
        ));
        expect($refused)->toHaveCount(1)->and($refused[0])->toBeInstanceOf(Unavailable::class);

        $empty = iterator_to_array($connector->pull(
            menuPull($connector, 'https://example-store.test'),
            menuIo(['status' => 'ok', 'cached' => false, 'data' => [['name' => 'Store', 'menu' => ['categories' => []], 'menuItems' => [], 'menu_categories' => []]]]),
        ));
        expect($empty)->toHaveCount(1)
            ->and($empty[0])->toBeInstanceOf(Note::class)
            ->and(array_filter($empty, fn ($m) => $m instanceof Covered))->toBeEmpty();
    }
});

it('is actor-billed with no http path and a catalogue that never deletes, for all three', function () {
    foreach ([SquareMenuConnector::class, UberEatsMenuConnector::class, DoordashMenuConnector::class] as $class) {
        $manifest = $class::manifest();
        $spec = $manifest->stream('menu');

        expect($manifest->cost)->toBe(CostClass::Actor)
            ->and($manifest->hosts)->toBe([])
            ->and($spec->profile)->toBe(SourceProfile::Catalogue)
            ->and($spec->target)->toBe('menu_item')
            ->and($spec->mayDelete())->toBeFalse();
    }
});
