<?php

// What survives of the Fresha → site.services projection after slice 7 D3a.
//
// This file used to characterise that projection end to end: deduped
// site.services rows with parsed fields and auto-created fresha categories,
// detach-on-edit ("sync broken"), delete-suppression across syncs,
// departed/returned, visibility ↔ is_active, and revert (single + bulk).
// FreshaServiceProjector no longer writes those rows — the Fresha service menu
// lives in content.* under a `kind = 'connection'` source, landed by the ingest
// connector, and the projector only composes the public blob from it.
//
// Where each retired case went:
//   • dedup, suppression (content.items.removed_at), departure/return
//     (content.source_items.removed_at) and the composed blob shape →
//     tests/Feature/Platforms/FreshaSelectionFromContentTest.php, re-expressed
//     against content.* fixtures;
//   • detach-on-edit / revert / is_active → RETIRED. An owner's edited PRICE
//     has no representation in content.* (offers are a set-union collection,
//     which FacetRegistry excludes from manual_overrides by design), all 61
//     live rows carried is_manual = false, and none carried an owner price.
//     Title/description/duration overrides are unaffected.
//
// What stays here is the behaviour that is still real and still this file's:
// the visibility endpoint, the FreshaFetch dedupe/raw round trip, and the
// two-surface guard.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\Service;
use App\Models\Core\User\User;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\FreshaServiceProjector;
use App\Services\Platforms\Strategies\Fetch\FetchNotModifiedException;
use App\Services\Platforms\Strategies\Fetch\FreshaFetch;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    setupIngestTables();
    setupContentTables();
    setupBlocksTable();
    shimPgAdvisoryLockForSqlite();
});

/** A raw scrape entry in the exact shape FreshaScraper::extractServices emits. */
function freshaRawService(string $id, string $name, ?string $category = null, mixed $priceValue = 50, ?string $duration = '45min'): array
{
    return [
        'serviceId' => $id,
        'name' => $name,
        'duration' => $duration,
        'description' => null,
        'price' => 'A$'.$priceValue,
        'priceValue' => $priceValue,
        'currency' => 'AUD',
        'category' => $category,
        'hasVariants' => false,
    ];
}

/** Land one Fresha service into content.* the way the ingest connector would. */
function freshaLandContentService(string $userId, string $serviceId, string $name, int $amountMinor = 5000): void
{
    $sourceId = DB::table('content.sources')
        ->where('user_id', $userId)->where('kind', 'connection')->value('id');

    if ($sourceId === null) {
        $sourceId = (string) Str::uuid();
        DB::table('content.sources')->insert([
            'id' => $sourceId, 'user_id' => $userId, 'kind' => 'connection', 'connection_id' => null,
            'label' => 'Fresha', 'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $itemId = addItem($userId, 'service', $name);

    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => "fresha:store:{$serviceId}", 'record_key' => $serviceId,
        'item_id' => $itemId, 'kind' => 'service', 'projector_version' => 1,
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('content.offers')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
        'channel' => 'fresha', 'qualifier' => 'exact', 'amount_minor' => $amountMinor,
        'currency' => null, 'updated_at' => now(),
    ]);
    DB::table('content.f_duration')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId, 'seconds' => 2700, 'updated_at' => now(),
    ]);
}

/** Seed a Fresha connection whose stored blob matches the landed pool. */
function seedFreshaProjection(User $user, array $rawServices): IntegrationConnection
{
    $projected = app(FreshaServiceProjector::class)->sync($user, $rawServices);

    return IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/acme',
            'selection' => [
                'url' => 'https://www.fresha.com/a/acme',
                'storeName' => 'Acme',
                'mode' => 'storewide',
                'employee' => null,
                'services' => $projected['services'],
                'hiddenServiceIds' => $projected['hiddenServiceIds'],
            ],
            'raw' => ['services' => $projected['raw']],
        ],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
}

it('writes no site.services rows for a scrape', function () {
    $user = createTenant('frproj1');
    freshaLandContentService($user->id, 's:1', 'Haircut', 6500);

    $result = app(FreshaServiceProjector::class)->sync($user, [
        freshaRawService('s:1', 'Haircut', 'Hair', priceValue: 65, duration: '1h 15min'),
        freshaRawService('s:1', 'Haircut', 'Packages', priceValue: 65, duration: '1h 15min'),
    ]);

    // D3a: pinned as a zero rather than deleted, so a reintroduced write to the
    // dropped-in-phase-6 table fails here instead of passing unnoticed.
    expect(Service::withTrashed()->where('user_id', $user->id)->where('source', 'fresha')->count())->toBe(0);
    // The blob and the stored raw are both deduped by serviceId.
    expect(array_column($result['services'], 'serviceId'))->toBe(['s:1']);
    expect(array_column($result['raw'], 'serviceId'))->toBe(['s:1']);
});

it('service-visibility toggles the hidden list on the stored blob', function () {
    $user = createTenant('frproj5');
    freshaLandContentService($user->id, 's:1', 'Haircut', 6500);
    seedFreshaProjection($user, [freshaRawService('s:1', 'Haircut', 'Hair', 65)]);

    actingAsUser($user)->postJson('/api/platforms/fresha/service-visibility', ['serviceId' => 's:1', 'hidden' => true])
        ->assertOk()
        ->assertJsonPath('hiddenServiceIds', ['s:1']);

    // D3a: the hidden list IS the record now — it lives on the blob, not on a
    // site.services.is_active column compose() re-derives it from. The hidden
    // service stays in services[]; hiding is the consumer's job, as always.
    $stored = IntegrationConnection::query()->where('user_id', $user->id)->firstOrFail()->payload;
    expect($stored['selection']['hiddenServiceIds'])->toBe(['s:1']);
    expect(array_column($stored['selection']['services'], 'serviceId'))->toBe(['s:1']);

    actingAsUser($user)->postJson('/api/platforms/fresha/service-visibility', ['serviceId' => 's:1', 'hidden' => false])
        ->assertOk()
        ->assertJsonPath('hiddenServiceIds', []);

    expect(IntegrationConnection::query()->where('user_id', $user->id)->firstOrFail()->payload['selection']['hiddenServiceIds'])
        ->toBe([]);
});

it('keeps the Fresha menu out of the public services section and its visibility gate', function () {
    $user = createTenant('frproj7');
    freshaLandContentService($user->id, 's:1', 'Haircut', 6500);
    seedFreshaProjection($user, [freshaRawService('s:1', 'Haircut', 'Hair', 65)]);

    // The public read serves owner-authored services from content.*'s MANUAL
    // lane; the Fresha item above sits on the connection lane and must not
    // cross over. Positive control: the owner's own service does appear.
    ownerServiceItem($user->id, ['title' => 'Manual Massage', 'price_cents' => 9000]);

    $data = app(SitepageDataResolverService::class)->buildServicesData($user->site, (string) $user->id);
    expect(array_column($data['services'], 'title'))->toBe(['Manual Massage']);
});

it('dedupes and composes through the FreshaFetch refresh, storing raw privately', function () {
    $user = createTenant('frproj8');
    freshaLandContentService($user->id, 's:1', 'Haircut', 6500);
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/acme',
            'selection' => [
                'url' => 'https://www.fresha.com/a/acme', 'storeName' => 'Acme', 'mode' => 'storewide',
                'employee' => null, 'services' => [], 'hiddenServiceIds' => [],
            ],
        ],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $this->mock(FreshaScraper::class, function ($m) {
        $m->shouldReceive('fetchLocation')->andReturn(['stub' => true]);
        $m->shouldReceive('extractStoreName')->andReturn('Acme');
        $m->shouldReceive('extractServices')->andReturn([
            freshaRawService('s:1', 'Haircut', 'Hair', 65),
            freshaRawService('s:1', 'Haircut', 'Packages', 65), // duplicate listing
        ]);
    });

    $next = app(FreshaFetch::class)->fetch($connection);

    // Effective selection deduped and composed from content.*; the raw scrape
    // (deduped) persists privately under `raw`, where its remaining job is the
    // vendor's menu order.
    expect(array_column($next['selection']['services'], 'serviceId'))->toBe(['s:1']);
    expect(array_column($next['raw']['services'], 'serviceId'))->toBe(['s:1']);
    expect(Service::query()->where('user_id', $user->id)->where('source', 'fresha')->count())->toBe(0);

    // Second identical refresh — quiet not-modified.
    $connection->payload = $next;
    $connection->save();
    expect(fn () => app(FreshaFetch::class)->fetch($connection))
        ->toThrow(FetchNotModifiedException::class);
});
