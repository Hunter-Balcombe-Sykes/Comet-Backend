<?php

use App\Ingest\Connectors\GumroadConnector;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Runtime\EffectRefused;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;

// Tier C: the connector's real pull() against a recorded Inertia payload —
// the fixture mirrors the live shape captured from real Gumroad profiles
// (2026-07-28): div#app[data-page], component Users/Show, sections[] with
// SellerProfileFeaturedProductSection + SellerProfileProductsSection.

/** A minimal Io that answers from a fixed url => response map. */
function gumroadIo(array $responses): Io
{
    return new class($responses) implements Io
    {
        public function __construct(private array $responses) {}

        public function get(string $url, array $headers = []): array
        {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (! GumroadConnector::manifest()->mayContact($host)) {
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

function gumroadPull(): Pull
{
    return new Pull(
        identifier: 'easlo',
        stream: GumroadConnector::manifest()->stream('products'),
    );
}

/** One grid product in the live search_results shape. */
function gumroadGridProduct(string $permalink, string $name, int $priceCents): array
{
    return [
        'id' => base64_encode($permalink).'==',
        'permalink' => $permalink,
        'name' => $name,
        'seller' => null,
        'ratings' => ['count' => 134, 'average' => 4.9],
        'thumbnail_url' => "https://public-files.gumroad.com/{$permalink}-thumb",
        'native_type' => 'digital',
        'quantity_remaining' => null,
        'is_sales_limited' => false,
        'price_cents' => $priceCents,
        'currency_code' => 'usd',
        'is_pay_what_you_want' => false,
        'url' => "https://easlo.gumroad.com/l/{$permalink}?layout=profile",
        'duration_in_months' => null,
        'recurrence' => null,
    ];
}

function gumroadPage(array $sections): string
{
    $page = [
        'component' => 'Users/Show',
        'props' => [
            'errors' => [],
            'title' => 'Easlo',
            'currency_code' => 'usd',
            'creator_profile' => [
                'external_id' => '5775558153757',
                'avatar_url' => 'https://public-files.gumroad.com/avatar',
                'name' => 'Easlo',
                'subdomain' => 'easlo.gumroad.com',
            ],
            'sections' => $sections,
            'bio' => 'Notion templates.',
            'tabs' => [],
        ],
        'url' => '/',
        'version' => null,
    ];

    return '<html><head><title>Easlo</title></head><body>'
        .'<div id="app" data-page="'.htmlspecialchars(json_encode($page), ENT_QUOTES).'"></div>'
        .'</body></html>';
}

it('parses grid and featured sections, deduping the featured product by permalink', function () {
    $sections = [
        [
            'id' => 'sec1',
            'header' => null,
            'type' => 'SellerProfileFeaturedProductSection',
            'props' => ['product' => [
                'id' => 'VdlB==', 'permalink' => 'brain', 'name' => 'Second Brain',
                'price_cents' => 7900, 'currency_code' => 'usd',
                'long_url' => 'https://easlo.gumroad.com/l/brain',
                'thumbnail_url' => 'https://public-files.gumroad.com/brain-thumb',
            ]],
        ],
        [
            'id' => 'sec2',
            'header' => ['name' => 'Products'],
            'type' => 'SellerProfileProductsSection',
            'show_filters' => true,
            'default_product_sort' => 'page_layout',
            'search_results' => ['products' => [
                gumroadGridProduct('beygm', 'Finance Tracker', 3900),
                // The featured product re-listed in the grid — must not double.
                gumroadGridProduct('brain', 'Second Brain', 7900),
            ]],
        ],
    ];

    $io = gumroadIo(['https://easlo.gumroad.com/' => ['status' => 200, 'body' => gumroadPage($sections), 'headers' => []]]);
    $messages = iterator_to_array((new GumroadConnector)->pull(gumroadPull(), $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(2)
        ->and($records[0]->key)->toBe('brain')
        ->and($records[0]->doc['url'])->toBe('https://easlo.gumroad.com/l/brain')
        ->and($records[1]->key)->toBe('beygm')
        ->and($records[1]->doc['name'])->toBe('Finance Tracker')
        ->and($records[1]->doc['price_cents'])->toBe(3900)
        ->and($records[1]->doc['currency'])->toBe('USD')
        // The ?layout=profile suffix is chrome, not identity.
        ->and($records[1]->doc['url'])->toBe('https://easlo.gumroad.com/l/beygm')
        ->and($records[1]->doc['rating'])->toBe(4.9)
        // Pagination past the first page is unknowable, so never exhaustive.
        ->and($covered[0]->coverage->toArray()['type'])->toBe('unknown');
});

it('notes a storefront with no products and claims nothing', function () {
    $io = gumroadIo(['https://easlo.gumroad.com/' => ['status' => 200, 'body' => gumroadPage([]), 'headers' => []]]);
    $messages = iterator_to_array((new GumroadConnector)->pull(gumroadPull(), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Note::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('treats a missing inertia payload as a shape break, never an empty store', function () {
    $io = gumroadIo(['https://easlo.gumroad.com/' => ['status' => 200, 'body' => '<html><body>interstitial</body></html>', 'headers' => []]]);
    $messages = iterator_to_array((new GumroadConnector)->pull(gumroadPull(), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class);
});

it('reports a failed fetch as unavailable', function () {
    $io = gumroadIo(['https://easlo.gumroad.com/' => ['status' => 503, 'body' => '', 'headers' => []]]);

    expect(iterator_to_array((new GumroadConnector)->pull(gumroadPull(), $io))[0])->toBeInstanceOf(Unavailable::class);
});

it('refuses to fetch outside gumroad hosts', function () {
    $pull = new Pull(identifier: 'evil.com/?', stream: GumroadConnector::manifest()->stream('products'));

    expect(GumroadConnector::manifest()->mayContact('easlo.gumroad.com'))->toBeTrue()
        ->and(GumroadConnector::manifest()->mayContact('gumroad.com.evil.com'))->toBeFalse();
});

it('is a catalogue that can never delete (no order field)', function () {
    $spec = GumroadConnector::manifest()->stream('products');

    expect($spec->profile)->toBe(SourceProfile::Catalogue)
        ->and($spec->orderField)->toBeNull()
        ->and($spec->mayDelete())->toBeFalse();
});
