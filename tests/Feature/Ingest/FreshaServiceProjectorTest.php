<?php

use App\Ingest\Projection\FreshaServiceProjector;
use App\Ingest\Projection\ProjectionWriter;
use App\Ingest\Projection\RecordView;
use App\Models\Core\Site\IntegrationConnection;
use App\Services\Content\FreshaServiceItems;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Slice 3b Task 6: the projector turns Fresha's category into a
// content.collections row keyed on the VENDOR'S id, and the whole chain
// (record -> projector -> ProjectionWriter -> content.collections /
// content.collection_items -> FreshaServiceItems) is driven for real below.
//
// The end-to-end case is the load-bearing one. Every OTHER category assertion
// in this slice stands on hand-inserted content.collections rows that the
// pipeline had never actually produced — if any link were wrong, all of them
// would still pass. This file is the one place the links are joined up.
//
// The file lives flat in tests/Feature/Ingest/ rather than the plan's
// tests/Feature/Ingest/Projection/: a NEW directory under tests/Feature would
// have to be wired into scripts/audit's codebase_chunks() + a lens scope group
// (AuditPipelineIntegrityTest), and neither file is in this task's scope.

// ---------------------------------------------------------------------------
// Pure: vendor doc in, projection out. No DB, no network.
// ---------------------------------------------------------------------------

it('turns the vendor category into a collection keyed on its id', function () {
    $projection = (new FreshaServiceProjector)->project(new RecordView([
        'serviceId' => 's:1', 'name' => 'Standard Haircut', 'price' => 'from $48',
        'category' => 'Haircuts', 'categoryId' => '3282965',
    ]));

    expect($projection['collections'])->toBe([[
        'external_ref' => '3282965', 'label' => 'Haircuts',
        'kind' => 'service_category', 'position' => 0, 'item_position' => 0,
    ]]);

    // The venue's own order rides as seeds (F30): the category's rank and
    // the service's rank inside it.
    $projection = (new FreshaServiceProjector)->project(new RecordView([
        'serviceId' => 's:2', 'name' => 'Beard Trim', 'price' => 'from $20',
        'category' => 'Haircuts', 'categoryId' => '3282965', 'category_position' => 1, 'position' => 4,
    ]));
    expect($projection['collections'][0])->toMatchArray(['position' => 1, 'item_position' => 4]);
});

it('emits no collection when the category carries no id', function () {
    $projection = (new FreshaServiceProjector)->project(new RecordView([
        'serviceId' => 's:1', 'name' => 'Standard Haircut', 'price' => 'from $48',
        'category' => 'Haircuts',
    ]));

    // A category with no id cannot be reconciled across runs — a null
    // external_ref would insert a fresh row on every single run — so it stays
    // a tag only.
    expect($projection['collections'] ?? [])->toBe([])
        ->and($projection['tags'][0])->toBe(['tag' => 'Haircuts', 'tag_type' => 'category']);
});

// Regression guard for the whole slice's premise: these are the shapes the
// vendor emits. Green before this task's change — pinned so they stay green.
it('maps the real price shapes', function (string $price, string $qualifier, ?int $minor) {
    $projection = (new FreshaServiceProjector)->project(new RecordView(['name' => 'X', 'price' => $price]));

    expect($projection['offers'][0]['qualifier'])->toBe($qualifier)
        ->and($projection['offers'][0]['amount_minor'])->toBe($minor);
})->with([
    ['from $108', 'from', 10800],
    ['$120', 'exact', 12000],
    ['free', 'free', 0],
    // Cents must survive: a whole-dollar parse would bill $49.50 as $49.
    ['from $49.50', 'from', 4950],
]);

// ---------------------------------------------------------------------------
// End to end: the real chain, no hand-inserted collection rows.
// ---------------------------------------------------------------------------

/** A user + active Fresha connection + its provisioned ingest source and `services` stream. */
function freshaProjectableSource(): array
{
    // The eager on-connect run must not reach fresha.com from a test.
    Http::fake();
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();

    $userId = createTenant('fresha'.Str::lower(Str::random(6)))->id;

    $connection = IntegrationConnection::create([
        'user_id' => $userId,
        'platform' => 'fresha',
        'resource_id' => 'salon-'.substr(sha1(Str::random(8)), 0, 12),
        'payload' => [
            'url' => 'https://www.fresha.com/a/invented-salon-'.Str::lower(Str::random(6)),
            'selection' => ['mode' => 'storewide'],
        ],
        'is_active' => true,
    ]);

    // The ingest source is provisioned by IntegrationConnectionObserver ->
    // SourceProvisioner, not by hand: if that seam broke, this test should say
    // so rather than paper over it with an inserted row.
    $source = DB::table('ingest.sources')->where('connection_id', $connection->id)->first();
    expect($source)->not->toBeNull()
        ->and($source->source_key)->toBe('fresha');
    $source = (array) $source;

    // FreshaConnector runs eagerly on connect (W6), so the observer's inline
    // (sync-queue) run may already have minted the stream row — reuse it.
    $streamId = DB::table('ingest.streams')
        ->where('source_id', $source['id'])->where('stream_name', 'services')->value('id');
    if ($streamId === null) {
        $streamId = (string) Str::uuid();
        DB::table('ingest.streams')->insert([
            'id' => $streamId, 'source_id' => $source['id'], 'stream_name' => 'services',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $streamId = (string) $streamId;

    return [$userId, $connection, $source, $streamId];
}

/** The doc shape FreshaConnector::mapServiceItem() lands, verbatim keys. */
function freshaServiceDoc(string $serviceId, string $name, ?string $category, ?string $categoryId, string $price = 'from $48'): array
{
    return array_filter([
        'serviceId' => $serviceId,
        'name' => $name,
        'duration' => '30min',
        'price' => $price,
        'category' => $category,
        'categoryId' => $categoryId,
    ], static fn ($v) => $v !== null);
}

/** Lands $docs as the CURRENT record versions of $streamId (keyed on serviceId, as the connector does). */
function freshaLandRecords(string $streamId, array $docs): void
{
    foreach ($docs as $doc) {
        $key = (string) $doc['serviceId'];
        DB::table('ingest.record_versions')->insert([
            'stream_id' => $streamId, 'key' => $key, 'doc_hash' => sha1(json_encode($doc)),
            'doc' => json_encode($doc), 'first_seen_at' => now(), 'is_current' => 1,
        ]);
        $versionId = DB::table('ingest.record_versions')
            ->where('stream_id', $streamId)->where('key', $key)->orderByDesc('id')->value('id');

        DB::table('ingest.record_state')->updateOrInsert(
            ['stream_id' => $streamId, 'key' => $key],
            ['current_version_id' => $versionId, 'last_seen_at' => now()],
        );
    }
}

it('drives a Fresha category from a landed record to a rendered booking category', function () {
    [$userId, $connection, $source, $streamId] = freshaProjectableSource();

    freshaLandRecords($streamId, [
        freshaServiceDoc('s:101', 'Standard Haircut', 'Haircuts', '3282965'),
        freshaServiceDoc('s:102', 'Skin Fade', 'Haircuts', '3282965', '$60'),
        freshaServiceDoc('s:103', 'Beard Trim', 'Beards', '3282966', 'from $49.50'),
        // No categoryId: label only, so no collection — it must still project
        // as a service, just an uncategorised one.
        freshaServiceDoc('s:104', 'Hot Towel Shave', 'Extras', null),
    ]);

    $result = app(ProjectionWriter::class)->projectStream($source, $streamId, 'services');
    expect($result['status'])->toBe('ok')
        ->and($result['projected'])->toBe(4)
        ->and($result['items'])->toBe(4);

    // Hop 1: the writer's own content source for this connection.
    $contentSourceId = DB::table('content.sources')->where('connection_id', $connection->id)->value('id');
    expect($contentSourceId)->not->toBeNull();

    // Hop 2: content.collections — two rows, keyed on the vendor's ids, never
    // on the labels, and machine-derived (is_user_created false).
    $collections = DB::table('content.collections')->where('user_id', $userId)->orderBy('external_ref')->get();
    expect($collections)->toHaveCount(2)
        ->and($collections->pluck('external_ref')->all())->toBe(['3282965', '3282966'])
        ->and($collections->pluck('label')->all())->toBe(['Haircuts', 'Beards'])
        ->and($collections->pluck('kind')->unique()->all())->toBe(['service_category'])
        ->and(array_map(fn ($v) => (bool) $v, $collections->pluck('is_user_created')->all()))->toBe([false, false]);

    // Hop 3: content.collection_items — memberships under THIS source id, two
    // services in the first category, one in the second, none for the
    // id-less one.
    $haircuts = $collections->firstWhere('external_ref', '3282965');
    $beards = $collections->firstWhere('external_ref', '3282966');

    $memberships = DB::table('content.collection_items as ci')
        ->join('content.items as i', 'i.id', '=', 'ci.item_id')
        ->where('ci.source_id', $contentSourceId)
        ->get(['ci.collection_id', 'i.headline_cache']);

    expect($memberships)->toHaveCount(3)
        ->and($memberships->where('collection_id', $haircuts->id)->pluck('headline_cache')->sort()->values()->all())
        ->toBe(['Skin Fade', 'Standard Haircut'])
        ->and($memberships->where('collection_id', $beards->id)->pluck('headline_cache')->all())
        ->toBe(['Beard Trim']);

    // Hop 4: the booking surface renders those categories off the pool — no
    // hand-inserted collection row anywhere in this test.
    $services = collect(app(FreshaServiceItems::class)->selectionServices($userId))->keyBy('serviceId');

    expect($services)->toHaveCount(4)
        ->and($services['s:101']['category'])->toBe('Haircuts')
        ->and($services['s:102']['category'])->toBe('Haircuts')
        ->and($services['s:103']['category'])->toBe('Beards')
        ->and($services['s:104']['category'])->toBeNull()
        // The price round-trip travels the same chain: 'from $49.50' must not
        // come back as '$49'.
        ->and($services['s:103']['price'])->toBe('from $49.50')
        ->and($services['s:102']['price'])->toBe('$60');
});

it('follows a vendor-side category rename instead of minting a duplicate', function () {
    [$userId, , $source, $streamId] = freshaProjectableSource();

    freshaLandRecords($streamId, [freshaServiceDoc('s:101', 'Standard Haircut', 'Haircuts', '3282965')]);
    app(ProjectionWriter::class)->projectStream($source, $streamId, 'services');

    $before = DB::table('content.collections')->where('user_id', $userId)->get();
    expect($before)->toHaveCount(1);

    // Same vendor id, new label — what the legacy lane's title-matching
    // resolveCategoryIds() forked on.
    DB::table('ingest.record_versions')->where('stream_id', $streamId)->update(['is_current' => 0]);
    freshaLandRecords($streamId, [freshaServiceDoc('s:101', 'Standard Haircut', "Men's Haircuts", '3282965')]);
    app(ProjectionWriter::class)->projectStream($source, $streamId, 'services');

    $after = DB::table('content.collections')->where('user_id', $userId)->get();
    expect($after)->toHaveCount(1)
        ->and($after->first()->id)->toBe($before->first()->id)
        ->and($after->first()->label)->toBe("Men's Haircuts");

    expect(collect(app(FreshaServiceItems::class)->selectionServices($userId))->first()['category'])
        ->toBe("Men's Haircuts");
});
