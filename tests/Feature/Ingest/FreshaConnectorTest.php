<?php

use App\Ingest\Connectors\FreshaConnector;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Message\Bookmark;
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
        public array $posts = [];

        public function __construct(private array $response) {}

        public function get(string $url, array $headers = []): array
        {
            return ['status' => 404, 'body' => '', 'headers' => []];
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            $this->posts[] = ['url' => $url, 'body' => $body, 'headers' => $headers];

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

function freshaPull(string $streamName, string $slug = 'invented-salon', array $config = []): Pull
{
    return new Pull(
        identifier: $slug,
        stream: FreshaConnector::manifest()->stream($streamName),
        config: $config,
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

/** @param  list<array<string,mixed>>  $categories */
function freshaResponseWith(array $categories): array
{
    return ['status' => 200, 'body' => freshaBookingFlowBody($categories), 'headers' => []];
}

function normalItem(string $catalogId): array
{
    return [
        'name' => 'Standard Haircut',
        'caption' => '30min',
        'price' => ['formatted' => 'from $48'],
        'primaryAction' => ['id' => '[{"catalogId":"'.$catalogId.'"}]'],
    ];
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

    $pull = freshaPull('services', config: ['selection_ref' => 'storewide']);
    $messages = iterator_to_array((new FreshaConnector)->pull($pull, $io));

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(1)
        ->and($records[0]->key)->toBe('s:1')
        ->and($records[0]->doc['name'])->toBe('Fade')
        ->and($records[0]->doc['category'])->toBe('Cuts')
        ->and($covered)->toHaveCount(1)
        ->and($covered[0]->coverage->toArray()['type'])->toBe('exhaustive');
});

it('reports a non-200 booking-flow response as unavailable', function () {
    $io = freshaIo(['status' => 500, 'body' => '', 'headers' => []]);

    $pull = freshaPull('services', config: ['selection_ref' => 'storewide']);
    $messages = iterator_to_array((new FreshaConnector)->pull($pull, $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class);
});

it('treats a GraphQL errors key on a 200 as the pinned query being rejected, not a normal miss', function () {
    $io = freshaIo([
        'status' => 200,
        'body' => json_encode(['errors' => [['message' => 'PersistedQueryNotFound']]]),
        'headers' => [],
    ]);

    $pull = freshaPull('services', config: ['selection_ref' => 'storewide']);
    $messages = iterator_to_array((new FreshaConnector)->pull($pull, $io));

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

    $pull = freshaPull('services', config: ['selection_ref' => 'storewide']);
    $messages = iterator_to_array((new FreshaConnector)->pull($pull, $io));

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

    $pull = freshaPull('services', config: ['selection_ref' => 'storewide']);
    $messages = iterator_to_array((new FreshaConnector)->pull($pull, $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Note::class)
        ->and($messages[0]->code)->toBe('empty_menu')
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

it('refuses to post to a host outside its own manifest', function () {
    $io = freshaIo(['status' => 200, 'body' => freshaBookingFlowBody([]), 'headers' => []]);

    expect(fn () => $io->post('https://evil.com/graphql', []))
        ->toThrow(EffectRefused::class);
});

it('marks services exhaustive-or-nothing: an unordered Catalogue that deletes only under exhaustive coverage', function () {
    $spec = FreshaConnector::manifest()->stream('services');

    // W6 (2026-08-18): the one booking-flow call IS the whole menu, so a
    // service it no longer lists is gone — deletesOnExhaustive opts this
    // unordered stream back into absence folding.
    expect($spec->profile)->toBe(SourceProfile::Catalogue)
        ->and($spec->orderField)->toBeNull()
        ->and($spec->deletesOnExhaustive)->toBeTrue()
        ->and($spec->mayDelete())->toBeTrue();
});

it('lands nothing and makes no HTTP call when nothing has been chosen', function () {
    $io = freshaIo(freshaResponseWith([]));   // recorded posts must stay empty
    $pull = freshaPull('services', 'some-salon-abc123', config: ['selection_ref' => null]);

    $messages = iterator_to_array((new FreshaConnector)->pull($pull, $io));

    expect($io->posts)->toBeEmpty()
        ->and($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Note::class)
        ->and($messages[0]->code)->toBe('no_selection');
});

it('asks for one employee menu when an employee is chosen', function () {
    $io = freshaIo(freshaResponseWith([['id' => '1', 'name' => 'Cuts', 'items' => [normalItem('s:1')]]]));
    $pull = freshaPull('services', 'some-salon-abc123', config: ['selection_ref' => '4891132']);

    iterator_to_array((new FreshaConnector)->pull($pull, $io));

    $options = $io->posts[0]['body']['variables']['input']['options'];
    expect($options['employeeId'])->toBe('4891132')
        ->and($options['shouldShowAllEmployees'])->toBeFalse();
});

it('asks for the store menu when storewide is chosen', function () {
    $io = freshaIo(freshaResponseWith([['id' => '1', 'name' => 'Cuts', 'items' => [normalItem('s:1')]]]));
    $pull = freshaPull('services', 'some-salon-abc123', config: ['selection_ref' => 'storewide']);

    iterator_to_array((new FreshaConnector)->pull($pull, $io));

    $options = $io->posts[0]['body']['variables']['input']['options'];
    expect($options['employeeId'])->toBeNull()
        ->and($options['shouldShowAllEmployees'])->toBeFalse();
});

it('lands a package row whose catalog id is only on the secondary action', function () {
    // primaryAction carries bookableId and NO catalogId; secondaryAction has
    // "catalogId":"p:360081". Two defects lost this row: the regex was pinned
    // to `s:`, and `primaryAction.id ?? secondaryAction.id` is a NULL-coalesce
    // on a non-null string, so it never fell through.
    $item = [
        'name' => "'Father & Son' Haircuts (Standard)",
        'caption' => '25 mins - 30 mins  •  2 services',
        'price' => ['formatted' => 'from $87'],
        'primaryAction' => ['id' => '[{"type":"onScreenServicesModalPackageOpen","bookableId":"p:360081"}]'],
        'secondaryAction' => ['id' => '[{"type":"onScreenServicesPackageAdd","catalogId":"p:360081"}]'],
    ];
    $io = freshaIo(freshaResponseWith([['id' => '2590968', 'name' => 'Kids', 'items' => [$item]]]));
    $pull = freshaPull('services', 'edward', config: ['selection_ref' => 'storewide']);

    $records = array_values(array_filter(
        iterator_to_array((new FreshaConnector)->pull($pull, $io)),
        fn ($m) => $m instanceof Record,
    ));

    expect($records)->toHaveCount(1)
        ->and($records[0]->key)->toBe('p:360081')
        ->and($records[0]->doc['price'])->toBe('from $87');
});

it('carries the vendor category id alongside its name', function () {
    $io = freshaIo(freshaResponseWith([[
        'id' => '3282965', 'name' => 'Haircuts', 'items' => [normalItem('s:12107058')],
    ]]));
    $pull = freshaPull('services', 'edward', config: ['selection_ref' => 'storewide']);

    $records = array_values(array_filter(
        iterator_to_array((new FreshaConnector)->pull($pull, $io)),
        fn ($m) => $m instanceof Record,
    ));

    expect($records[0]->doc['categoryId'])->toBe('3282965')
        ->and($records[0]->doc['category'])->toBe('Haircuts');
});

it('counts rows it could not map instead of dropping them silently', function () {
    $unmappable = ['name' => 'Mystery', 'primaryAction' => ['id' => '[{"type":"whatever"}]']];
    $io = freshaIo(freshaResponseWith([[
        'id' => '1', 'name' => 'Cuts', 'items' => [normalItem('s:1'), $unmappable],
    ]]));
    $pull = freshaPull('services', 'edward', config: ['selection_ref' => 'storewide']);

    $notes = array_values(array_filter(
        iterator_to_array((new FreshaConnector)->pull($pull, $io)),
        fn ($m) => $m instanceof Note,
    ));

    expect($notes)->toHaveCount(1)
        ->and($notes[0]->code)->toBe('unmapped_rows');
});

// ── Slug rotation (live 2026-08-18) ─────────────────────────────────────────

/** An Io whose booking flow answers per locationSlug and whose GET follows Fresha's share-alias redirect. */
function freshaRotatingIo(string $old, string $new): Io
{
    return new class($old, $new) implements Io
    {
        public array $posts = [];

        public array $gets = [];

        public function __construct(private string $old, private string $new) {}

        public function get(string $url, array $headers = []): array
        {
            $this->gets[] = $url;
            if (str_contains($url, '/book-now/'.$this->old.'/')) {
                // Fresha keeps the share alias redirecting to the current slug.
                return ['status' => 200, 'body' => '<html></html>', 'headers' => ['final-url' => 'https://www.fresha.com/en-GB/a/'.$this->new.'/booking?menu=true']];
            }
            if (str_contains($url, '/a/'.$this->new)) {
                return ['status' => 200, 'body' => '{"currency":"AUD"}', 'headers' => ['final-url' => $url]];
            }

            return ['status' => 410, 'body' => '', 'headers' => []];
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            $this->posts[] = ['url' => $url, 'body' => $body];
            $slug = $body['variables']['input']['locationSlug'] ?? '';
            if ($slug === $this->new) {
                return freshaResponseWith([['id' => '1', 'name' => 'Cuts', 'items' => [normalItem('s:1')]]]);
            }

            // Retired slug: a 200 with no menu on it — the same shape as a
            // rotated persisted-query hash.
            return ['status' => 200, 'body' => json_encode(['data' => ['bookingFlowInitialize' => ['screenServices' => []]]]), 'headers' => []];
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

it('heals a rotated slug: retries the booking flow on the slug Fresha redirects to, and bookmarks it', function () {
    $io = freshaRotatingIo('anseo-studio-v0v92jna', 'anseo-studio-melbourne-140a-chapel-street-w8ajp04r');
    $pull = freshaPull('services', 'anseo-studio-v0v92jna', config: ['selection_ref' => 'storewide']);

    $messages = iterator_to_array((new FreshaConnector)->pull($pull, $io), false);

    $slugsAsked = array_map(fn ($p) => $p['body']['variables']['input']['locationSlug'], $io->posts);
    expect($slugsAsked)->toBe(['anseo-studio-v0v92jna', 'anseo-studio-melbourne-140a-chapel-street-w8ajp04r']);

    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    expect($records)->toHaveCount(1);

    $bookmark = collect($messages)->first(fn ($m) => $m instanceof Bookmark);
    expect($bookmark)->not->toBeNull()
        ->and($bookmark->cursor['slug'])->toBe('anseo-studio-melbourne-140a-chapel-street-w8ajp04r')
        ->and($bookmark->cursor['currency'])->toBe('AUD');
});

it('uses the bookmarked slug on later runs without probing again', function () {
    $io = freshaRotatingIo('anseo-studio-v0v92jna', 'anseo-studio-melbourne-140a-chapel-street-w8ajp04r');
    $pull = new Pull(
        identifier: 'anseo-studio-v0v92jna',
        stream: FreshaConnector::manifest()->stream('services'),
        config: ['selection_ref' => 'storewide'],
        cursor: ['slug' => 'anseo-studio-melbourne-140a-chapel-street-w8ajp04r', 'currency' => 'AUD'],
    );

    $messages = iterator_to_array((new FreshaConnector)->pull($pull, $io), false);

    expect(array_map(fn ($p) => $p['body']['variables']['input']['locationSlug'], $io->posts))
        ->toBe(['anseo-studio-melbourne-140a-chapel-street-w8ajp04r'])
        ->and($io->gets)->toBe([])
        ->and(array_filter($messages, fn ($m) => $m instanceof Record))->toHaveCount(1);
});

it('still reports unavailable when the slug is genuinely dead (no redirect answers)', function () {
    // The probe 410s too: not a rotation, so the original diagnosis stands.
    $io = freshaIo(['status' => 200, 'body' => json_encode(['data' => ['bookingFlowInitialize' => ['screenServices' => []]]]), 'headers' => []]);
    $pull = freshaPull('services', config: ['selection_ref' => 'storewide']);

    $messages = iterator_to_array((new FreshaConnector)->pull($pull, $io), false);

    expect($messages)->toHaveCount(1)->and($messages[0])->toBeInstanceOf(Unavailable::class);
});
