<?php

use App\Http\Resources\Platforms\FreshaSelectionResource;
use App\Models\Core\User\User;
use App\Services\Content\FreshaServiceItems;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Slice 3b Task 12: FreshaSelectionResource's services[] stops coming from
// the connection's stored selection blob and starts coming from content.* —
// with its wire shape preserved exactly (spec §3.7). The bug this guards
// against: a silent wire change on the booking surface (partna-pages'
// service picker + the public booking deep links both consume this shape
// verbatim), or a price string that lies about the vendor's own display.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

/**
 * The user's single content.sources row for a Fresha connection
 * (kind = 'connection') — the ONE thing that keeps a landed item off the
 * public services section (that reads kind = 'manual' only).
 */
function freshaContentSourceFor(string $userId): string
{
    $id = (string) Str::uuid();
    $now = now();

    DB::table('content.sources')->insert([
        'id' => $id,
        'user_id' => $userId,
        'kind' => 'connection',
        'connection_id' => null,
        'label' => 'Fresha',
        'priority' => 100,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

/**
 * Lands one Fresha-shaped service item directly into content.* — the shape
 * App\Ingest\Projection\FreshaServiceProjector + ProjectionWriter would
 * produce, built by hand so this test stays independent of Task 5's
 * concurrent edits to ProjectionWriter and Task 6's (not yet landed) change
 * to the Fresha projector. `record_key` carries the raw vendor serviceId
 * ("s:123") — ProjectionWriter::projectStream() sets it verbatim from the
 * ingest Record's key (:191), which is exactly what FreshaConnector emits.
 *
 * @param  array<string, mixed>  $overrides  serviceId, name, qualifier,
 *                                           amountMinor, currency,
 *                                           description, durationSeconds,
 *                                           category
 * @return array{itemId: string, serviceId: string}
 */
function landFreshaService(string $userId, string $sourceId, array $overrides = []): array
{
    $serviceId = $overrides['serviceId'] ?? 's:'.random_int(100000, 999999);
    $now = now();

    $itemId = addItem($userId, 'service', $overrides['name'] ?? 'Standard Haircut');

    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(),
        'source_id' => $sourceId,
        'coord' => "fresha:store:{$serviceId}",
        'record_key' => $serviceId,
        'item_id' => $itemId,
        'kind' => 'service',
        'projector_version' => 1,
        'first_seen_at' => $now,
        'last_seen_at' => $now,
    ]);

    if (array_key_exists('qualifier', $overrides)) {
        DB::table('content.offers')->insert([
            'id' => (string) Str::uuid(),
            'item_id' => $itemId,
            'source_id' => $sourceId,
            'channel' => 'fresha',
            'qualifier' => $overrides['qualifier'],
            'amount_minor' => $overrides['amountMinor'] ?? null,
            // Bare '$' is what Fresha emits — never 'AUD' unless the fixture
            // says so explicitly (mirrors the ingest projector's own rule).
            'currency' => $overrides['currency'] ?? null,
            'updated_at' => $now,
        ]);
    }

    if (array_key_exists('description', $overrides) && $overrides['description'] !== null) {
        DB::table('content.f_text')->insert([
            'item_id' => $itemId, 'source_id' => $sourceId,
            'body' => $overrides['description'], 'updated_at' => $now,
        ]);
    }

    if (array_key_exists('durationSeconds', $overrides) && $overrides['durationSeconds'] !== null) {
        DB::table('content.f_duration')->insert([
            'item_id' => $itemId, 'source_id' => $sourceId,
            'seconds' => $overrides['durationSeconds'], 'updated_at' => $now,
        ]);
    }

    if (array_key_exists('category', $overrides) && $overrides['category'] !== null) {
        $collectionId = (string) Str::uuid();
        DB::table('content.collections')->insert([
            'id' => $collectionId, 'user_id' => $userId, 'parent_id' => null,
            'label' => $overrides['category'], 'kind' => 'service_category',
            'external_ref' => $overrides['categoryRef'] ?? $serviceId.'-cat',
            'position' => 0, 'is_user_created' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('content.collection_items')->insert([
            'collection_id' => $collectionId, 'item_id' => $itemId,
            'source_id' => $sourceId, 'position' => 0,
        ]);
    }

    return ['itemId' => $itemId, 'serviceId' => $serviceId];
}

/**
 * A tenant with a Fresha connection source carrying two landed services,
 * the second of which is the caller's "hidden" one — hiding is expressed on
 * the STORED SELECTION (hiddenServiceIds), never on the pool row itself, so
 * this helper hands back the id the test wires into that stored blob.
 *
 * @return array{0: User, 1: string} [$user, $hiddenServiceId]
 */
function freshaConnectionWithLandedItems(): array
{
    $user = createTenant('fresha-'.Str::lower(Str::random(6)));
    $sourceId = freshaContentSourceFor($user->id);

    landFreshaService($user->id, $sourceId, [
        'serviceId' => 's:1001', 'name' => 'Standard Haircut',
        'qualifier' => 'from', 'amountMinor' => 4800,
        'description' => 'A classic cut, wash and style.',
        'durationSeconds' => 1800, 'category' => 'Haircuts',
    ]);

    $hidden = landFreshaService($user->id, $sourceId, [
        'serviceId' => 's:1002', 'name' => 'Root Colour',
        'qualifier' => 'exact', 'amountMinor' => 12000,
        'description' => null,
        'durationSeconds' => 5400, 'category' => 'Colour',
    ]);

    return [$user, $hidden['serviceId']];
}

/** A tenant with exactly one landed Fresha service carrying the given price shape. */
function userWithFreshaOffer(string $qualifier, ?int $minor): User
{
    $user = createTenant('fresha-price-'.Str::lower(Str::random(6)));
    $sourceId = freshaContentSourceFor($user->id);

    landFreshaService($user->id, $sourceId, [
        'serviceId' => 's:2001', 'name' => 'Priced Service',
        'qualifier' => $qualifier, 'amountMinor' => $minor,
    ]);

    return $user;
}

it('reproduces the stored blob\'s service shape exactly', function () {
    [$user] = freshaConnectionWithLandedItems();

    $services = app(FreshaServiceItems::class)->selectionServices($user->id);

    expect($services)->not->toBeEmpty();
    expect(array_keys($services[0]))->toEqualCanonicalizing([
        'name', 'price', 'category', 'currency', 'duration', 'serviceId',
        'priceValue', 'description', 'hasVariants',
    ]);
});

it('orders services deterministically when several rows share one first_seen_at', function () {
    $user = createTenant('fresha-order-'.Str::lower(Str::random(6)));
    $sourceId = freshaContentSourceFor($user->id);
    $now = now();

    // Fix round 4, Finding 6: a single ingest batch writes ONE timestamp
    // across every row it lands (I1 hazard, ProjectionWriter.php:118-125),
    // so first_seen_at ties are the normal case here, not an edge case.
    // Landed deliberately OUT of si.id order, sharing one first_seen_at:
    // a test using distinct timestamps, or already-in-id-order rows, would
    // pass whether or not a tiebreak exists and would prove nothing.
    $plan = [
        ['si' => '00000000-0000-0000-0000-000000000003', 'serviceId' => 's:3', 'name' => 'Third'],
        ['si' => '00000000-0000-0000-0000-000000000001', 'serviceId' => 's:1', 'name' => 'First'],
        ['si' => '00000000-0000-0000-0000-000000000002', 'serviceId' => 's:2', 'name' => 'Second'],
    ];

    foreach ($plan as $entry) {
        $itemId = (string) Str::uuid();
        DB::table('content.items')->insert([
            'id' => $itemId, 'user_id' => $user->id, 'kind' => 'service',
            'headline_cache' => $entry['name'], 'facets_cache' => '[]', 'eligible_cache' => '[]',
            'first_seen_at' => $now, 'last_seen_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('content.source_items')->insert([
            'id' => $entry['si'], 'source_id' => $sourceId,
            'coord' => "fresha:store:{$entry['serviceId']}", 'record_key' => $entry['serviceId'],
            'item_id' => $itemId, 'kind' => 'service', 'projector_version' => 1,
            'first_seen_at' => $now, 'last_seen_at' => $now,
        ]);
    }

    $first = array_column(app(FreshaServiceItems::class)->selectionServices($user->id), 'serviceId');
    $second = array_column(app(FreshaServiceItems::class)->selectionServices($user->id), 'serviceId');

    expect($first)->toBe(['s:1', 's:2', 's:3'])
        ->and($second)->toBe($first);
});

it('round-trips every price qualifier back to its display string', function (string $qualifier, ?int $minor, string $display) {
    $user = userWithFreshaOffer($qualifier, $minor);

    expect(app(FreshaServiceItems::class)->selectionServices($user->id)[0]['price'])->toBe($display);
})->with([
    ['from', 10800, 'from $108'],
    ['exact', 12000, '$120'],
    ['free', 0, 'free'],
    // Cents render only when there are cents -- "$120.00" would be a wire change.
    ['from', 4950, 'from $49.50'],
    ['exact', 3150, '$31.50'],
]);

// RULING (controller, 2026-08-13): services[] does NOT filter hidden rows.
// FreshaSelectionResource passes `services` through verbatim and carries
// `hiddenServiceIds` as a separate sibling key; filtering here would be the
// wire change spec §3.7 forbids, and would break the dashboard's un-hide
// affordance, which needs the hidden rows present in order to render them.
it('keeps a hidden service in services[] and leaves the hiding to hiddenServiceIds', function () {
    [$user, $hiddenId] = freshaConnectionWithLandedItems();

    $selection = [
        'url' => 'https://fresha.com/example',
        'storeName' => 'Example Salon',
        'mode' => 'employee',
        'employee' => null,
        // hiddenServiceIds is the owner's own curation, stored on the
        // connection payload -- untouched by this slice.
        'hiddenServiceIds' => [$hiddenId],
    ];

    // Fix round 1 (Finding 1): the user is now an explicit constructor
    // argument, not read off the ambient request.
    $payload = (new FreshaSelectionResource($selection, (string) $user->id))->toArray(Request::create('/api/platforms/fresha/selection'));

    expect(collect($payload['services'])->pluck('serviceId'))->toContain($hiddenId)
        ->and($payload['hiddenServiceIds'])->toContain($hiddenId);
});
