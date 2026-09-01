<?php

use App\Ingest\Connectors\FacebookEventsConnector;
use App\Ingest\Manifest\CostClass;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Note;
use App\Ingest\Message\Record;
use App\Ingest\Message\Unavailable;
use App\Ingest\Projection\RecordView;
use App\Ingest\Projection\SchemaOrgEventProjector;
use App\Ingest\Runtime\Effects\BilledEffectContext;
use App\Ingest\Runtime\Effects\BilledEffectOutcome;
use App\Ingest\Runtime\Effects\FacebookEventsVendorDriver;
use App\Ingest\Runtime\Io;
use App\Ingest\Runtime\Pull;
use Illuminate\Support\Facades\Http;

// Item 11a (2026-09-01): the facebook_events connector — an existing FB page
// connection's events stream into the events pool. Tier C plus the lane
// proof: the REAL driver against the RECORDED payloads, drained through the
// REAL connector, projected through the REAL SchemaOrgEventProjector — one
// projector, third source of the `event` item kind, no new pool semantics.

function fbEventsIo(array $effect): Io
{
    return new class($effect) implements Io
    {
        public array $calls = [];

        public function __construct(private array $effect) {}

        public function get(string $url, array $headers = []): array
        {
            throw new RuntimeException('unexpected GET');
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            throw new RuntimeException('unexpected POST');
        }

        public function getMany(array $urls, array $headers = []): array
        {
            throw new RuntimeException('unexpected getMany');
        }

        public function effect(string $kind, string $name, array $input): array
        {
            $this->calls[] = [$kind, $name, $input];

            return $this->effect;
        }
    };
}

function fbEventsPull(array $config = []): Pull
{
    return new Pull(
        identifier: 'https://www.facebook.com/thetotehotel',
        stream: FacebookEventsConnector::manifest()->stream('events'),
        config: $config,
    );
}

function fbEventsDoc(string $name = 'SHONEN KNIFE AT THE TOTE w/ MOLER'): array
{
    return [
        'name' => $name,
        'url' => 'https://tickets.oztix.com.au/outlet/event/d2b024d0-2d5f-4ad0-9de7-9c84e68698d5',
        'start_date' => '2026-10-14T19:30:00+11:00',
        'venue' => 'The Tote',
        'locality' => 'Melbourne',
    ];
}

it('declares a paid calendar stream that lands the shared event doc shape', function () {
    $manifest = FacebookEventsConnector::manifest();
    $spec = $manifest->stream('events');

    expect((string) $manifest->source)->toBe('facebook_events')
        ->and($manifest->identifierKind)->toBe('url')
        // Vendor-only: nothing here may fetch Facebook over HTTP.
        ->and($manifest->hosts)->toBe([])
        ->and($manifest->cost)->toBe(CostClass::Actor)
        // The one trigger a paid connector has (the Instagram lesson).
        ->and($manifest->runsEagerlyOnConnect())->toBeTrue()
        ->and($spec->target)->toBe('event')
        ->and($spec->profile)->toBe(SourceProfile::Calendar)
        ->and($spec->requires)->toBe(['name', 'url'])
        ->and($spec->orderField)->toBe('start_date')
        ->and($spec->mayDelete())->toBeTrue();
});

it('yields records keyed by event id and exhaustive coverage for a complete walk', function () {
    $io = fbEventsIo(['status' => 'ok', 'cached' => false, 'data' => [
        'complete' => true,
        'events' => [
            ['key' => '1759413615194443', 'doc' => fbEventsDoc()],
            ['key' => '1053591417622856', 'doc' => fbEventsDoc('DRENCHER at The Tote')],
        ],
    ]]);

    $messages = iterator_to_array((new FacebookEventsConnector)->pull(fbEventsPull(), $io));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($io->calls)->toBe([['vendor', 'facebook_events', ['url' => 'https://www.facebook.com/thetotehotel']]])
        ->and(array_map(fn ($r) => $r->key, $records))->toBe(['1759413615194443', '1053591417622856'])
        ->and($records[0]->stream)->toBe('events')
        ->and($records[0]->doc['name'])->toBe('SHONEN KNIFE AT THE TOTE w/ MOLER')
        ->and($covered[0]->coverage->toArray()['type'])->toBe('exhaustive');
});

it('claims nothing for an incomplete walk', function () {
    $io = fbEventsIo(['status' => 'ok', 'cached' => false, 'data' => [
        'complete' => false,
        'events' => [['key' => '1', 'doc' => fbEventsDoc()]],
    ]]);

    $covered = array_values(array_filter(
        iterator_to_array((new FacebookEventsConnector)->pull(fbEventsPull(), $io)),
        fn ($m) => $m instanceof Covered
    ));

    expect($covered[0]->coverage->toArray()['type'])->toBe('unknown');
});

it('degrades a complete walk to unknown when the scope limit truncates it', function () {
    $io = fbEventsIo(['status' => 'ok', 'cached' => false, 'data' => [
        'complete' => true,
        'events' => [
            ['key' => '1', 'doc' => fbEventsDoc('One')],
            ['key' => '2', 'doc' => fbEventsDoc('Two')],
            ['key' => '3', 'doc' => fbEventsDoc('Three')],
        ],
    ]]);

    $messages = iterator_to_array((new FacebookEventsConnector)->pull(
        fbEventsPull(['scope' => 'latest_n', 'scope_n' => 2]),
        $io
    ));
    $records = array_values(array_filter($messages, fn ($m) => $m instanceof Record));
    $covered = array_values(array_filter($messages, fn ($m) => $m instanceof Covered));

    expect($records)->toHaveCount(2)
        // A truncated list can never claim the whole calendar — exhaustive
        // here would tombstone event 3.
        ->and($covered[0]->coverage->toArray()['type'])->toBe('unknown');
});

it('drops malformed envelope rows rather than landing them', function () {
    $io = fbEventsIo(['status' => 'ok', 'cached' => false, 'data' => [
        'complete' => true,
        'events' => [
            ['key' => '', 'doc' => fbEventsDoc()],
            ['key' => '2', 'doc' => ['name' => 'No url or start']],
            'not-a-row',
            ['key' => '4', 'doc' => fbEventsDoc('Keeper')],
        ],
    ]]);

    $records = array_values(array_filter(
        iterator_to_array((new FacebookEventsConnector)->pull(fbEventsPull(), $io)),
        fn ($m) => $m instanceof Record
    ));

    expect(array_map(fn ($r) => $r->key, $records))->toBe(['4']);
});

it('folds a refused effect into unavailable, never an empty calendar', function () {
    $io = fbEventsIo(['status' => 'refused', 'cached' => false, 'data' => null]);

    $messages = iterator_to_array((new FacebookEventsConnector)->pull(fbEventsPull(), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Unavailable::class);
});

it('emits a note and no coverage when the vendor answered with nothing usable', function () {
    $io = fbEventsIo(['status' => 'ok', 'cached' => false, 'data' => ['complete' => false, 'events' => []]]);

    $messages = iterator_to_array((new FacebookEventsConnector)->pull(fbEventsPull(), $io));

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(Note::class)
        ->and(array_filter($messages, fn ($m) => $m instanceof Covered))->toBeEmpty();
});

// ── The lane: recorded fixtures → driver → connector → events projector ─────

it('projects recorded fixture events through the real projector into the events pool shape', function () {
    config()->set('services.scrapecreators.key', 'test-key');
    config()->set('partna.limits.scrapecreators.global_daily_cap', 100);
    config()->set('partna.limits.scrapecreators.sources.facebook_events', 100);
    config()->set('partna.limits.scrapecreators.facebook_events.details_per_run', 8);

    Http::fake([
        'api.scrapecreators.com/v1/facebook/profile/events*' => Http::response(json_decode(
            file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-facebook-profile-events.json')), true
        )),
        'api.scrapecreators.com/v1/facebook/event/details*' => Http::response(json_decode(
            file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-facebook-event-details.json')), true
        )),
    ]);

    // The real driver behind the Io seam, folded to HttpIo's return shape.
    $io = new class implements Io
    {
        public function get(string $url, array $headers = []): array
        {
            throw new RuntimeException('unexpected GET');
        }

        public function post(string $url, array $body = [], array $headers = []): array
        {
            throw new RuntimeException('unexpected POST');
        }

        public function getMany(array $urls, array $headers = []): array
        {
            throw new RuntimeException('unexpected getMany');
        }

        public function effect(string $kind, string $name, array $input): array
        {
            $result = app(FacebookEventsVendorDriver::class)->run(
                new BilledEffectContext($kind, $name, $input, 'run-1', 'source-1', 'user-1')
            );

            return [
                'status' => $result->outcome === BilledEffectOutcome::Answered ? 'ok' : 'failed',
                'cached' => false,
                'data' => $result->data,
            ];
        }
    };

    $records = array_values(array_filter(
        iterator_to_array((new FacebookEventsConnector)->pull(fbEventsPull(), $io)),
        fn ($m) => $m instanceof Record
    ));

    expect($records)->toHaveCount(8);

    // Every landed doc satisfies the stream's requires and projects.
    $spec = FacebookEventsConnector::manifest()->stream('events');
    $projector = new SchemaOrgEventProjector;
    foreach ($records as $record) {
        foreach ($spec->requires as $path) {
            expect($record->doc)->toHaveKey($path);
        }

        $item = $projector->project(new RecordView($record->doc, $record->key));
        expect($item['kind'])->toBe('event');
    }

    $shonen = collect($records)->firstWhere('key', '1759413615194443');
    $item = $projector->project(new RecordView($shonen->doc, $shonen->key));

    expect($item['headline'])->toBe('SHONEN KNIFE AT THE TOTE w/ MOLER')
        ->and($item['facets']['f_link']['url'])->toStartWith('https://tickets.oztix.com.au/')
        ->and($item['facets']['f_occurrence']['starts_at_utc'])->toBe('2026-10-14T08:30:00Z')
        ->and($item['facets']['f_place']['locality'])->toBe('Melbourne');
});
