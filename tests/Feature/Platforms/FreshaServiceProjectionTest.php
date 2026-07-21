<?php

// Fresha → site.services projection (2026-07-21): the scraped booking menu
// becomes editable service rows with menu-style provenance. Covers the dedup
// (duplicate serviceIds collapse), detach-on-edit ("sync broken"), delete-
// suppression across syncs, departed/returned handling, visibility↔is_active,
// revert (single + bulk), the public-blob composition (selection stays the
// public transport; payload.raw stays private), and the public services
// section ignoring projections.

use App\Console\Commands\PurgeSoftDeleted;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\Service;
use App\Models\Core\User\User;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\FreshaServiceProjector;
use App\Services\Platforms\Strategies\Fetch\FetchNotModifiedException;
use App\Services\Platforms\Strategies\Fetch\FreshaFetch;
use App\Services\PublicSite\SitepageDataResolverService;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
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

/** Seed a projected Fresha connection: sync() + the stored payload, one step. */
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

it('projects a scrape into deduped service rows with parsed fields and fresha categories', function () {
    $user = createTenant('frproj1');

    // The SAME serviceId listed under two Fresha categories — the duplicate bug.
    $result = app(FreshaServiceProjector::class)->sync($user, [
        freshaRawService('s:1', 'Haircut', 'Hair', priceValue: 65, duration: '1h 15min'),
        freshaRawService('s:1', 'Haircut', 'Packages', priceValue: 65, duration: '1h 15min'),
        freshaRawService('s:2', 'Beard Trim', 'Hair', priceValue: 27.5, duration: '30min'),
    ]);

    $rows = Service::query()->with('category')->where('user_id', $user->id)->where('source', 'fresha')->orderBy('sort_order')->get();
    expect($rows)->toHaveCount(2);

    $haircut = $rows->firstWhere('external_id', 's:1');
    expect($haircut->title)->toBe('Haircut');
    expect($haircut->price_cents)->toBe(6500);
    expect($haircut->duration_minutes)->toBe(75);
    expect($haircut->is_manual)->toBeFalse();
    expect($haircut->is_active)->toBeTrue();
    // First occurrence's category label wins; auto-created with source=fresha.
    expect($haircut->category?->title)->toBe('Hair');
    expect($haircut->category?->source)->toBe('fresha');

    expect($rows->firstWhere('external_id', 's:2')->price_cents)->toBe(2750);

    // The effective blob is deduped too — one entry per serviceId.
    expect(array_column($result['services'], 'serviceId'))->toBe(['s:1', 's:2']);
    expect($result['hiddenServiceIds'])->toBe([]);
    expect(array_column($result['raw'], 'serviceId'))->toBe(['s:1', 's:2']);
});

it('detaches a projected service on content edit and serializes the owner version into the blob', function () {
    $user = createTenant('frproj2');
    seedFreshaProjection($user, [freshaRawService('s:1', 'Haircut', 'Hair', 65), freshaRawService('s:2', 'Trim', 'Hair', 20)]);
    $row = Service::query()->where('user_id', $user->id)->where('external_id', 's:1')->firstOrFail();

    actingAsUser($user)->patchJson("/api/services/{$row->id}", ['title' => 'Signature Haircut', 'price_cents' => 8000])
        ->assertOk()
        ->assertJsonPath('service.is_manual', true)
        ->assertJsonPath('service.source', 'fresha');

    // The public blob now carries the OWNER's version for s:1, verbatim raw for s:2.
    $stored = IntegrationConnection::query()->where('user_id', $user->id)->firstOrFail()->payload;
    $entries = collect($stored['selection']['services'])->keyBy('serviceId');
    expect($entries['s:1']['name'])->toBe('Signature Haircut');
    expect($entries['s:1']['priceValue'])->toEqual(80);
    expect($entries['s:1']['price'])->toBe('A$80');
    expect($entries['s:2']['name'])->toBe('Trim');

    // A later sync must NOT overwrite the detached row.
    app(FreshaServiceProjector::class)->sync($user, [freshaRawService('s:1', 'Haircut', 'Hair', 70), freshaRawService('s:2', 'Trim', 'Hair', 22)]);
    $row->refresh();
    expect($row->title)->toBe('Signature Haircut');
    expect($row->price_cents)->toBe(8000);
    // ...while the synced row refreshed to the new scrape price.
    expect(Service::query()->where('user_id', $user->id)->where('external_id', 's:2')->firstOrFail()->price_cents)->toBe(2200);
});

it('toggling is_active does not break sync', function () {
    $user = createTenant('frproj2b');
    seedFreshaProjection($user, [freshaRawService('s:1', 'Haircut', 'Hair', 65)]);
    $row = Service::query()->where('user_id', $user->id)->where('external_id', 's:1')->firstOrFail();

    actingAsUser($user)->patchJson("/api/services/{$row->id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('service.is_manual', false);

    // is_active=false surfaces as a hiddenServiceIds entry in the blob.
    $stored = IntegrationConnection::query()->where('user_id', $user->id)->firstOrFail()->payload;
    expect($stored['selection']['hiddenServiceIds'])->toBe(['s:1']);
});

it('deleting a projected service suppresses it: no resurrect on sync, excluded from purge', function () {
    $user = createTenant('frproj3');
    seedFreshaProjection($user, [freshaRawService('s:1', 'Haircut', 'Hair', 65), freshaRawService('s:2', 'Trim', 'Hair', 20)]);
    $row = Service::query()->where('user_id', $user->id)->where('external_id', 's:1')->firstOrFail();

    actingAsUser($user)->deleteJson("/api/services/{$row->id}")->assertOk();

    $trashed = Service::withTrashed()->findOrFail($row->id);
    expect($trashed->trashed())->toBeTrue();
    expect($trashed->deleted_origin)->toBe('user');

    // Blob dropped it immediately.
    $stored = IntegrationConnection::query()->where('user_id', $user->id)->firstOrFail()->payload;
    expect(array_column($stored['selection']['services'], 'serviceId'))->toBe(['s:2']);

    // The next sync re-offers s:1 — it must stay gone.
    $result = app(FreshaServiceProjector::class)->sync($user, [freshaRawService('s:1', 'Haircut', 'Hair', 65), freshaRawService('s:2', 'Trim', 'Hair', 20)]);
    expect(Service::query()->where('user_id', $user->id)->where('external_id', 's:1')->exists())->toBeFalse();
    expect(array_column($result['services'], 'serviceId'))->toBe(['s:2']);

    // The suppression record survives the retention purge (the command sweeps
    // every PURGE_HANDLED model, so their tables all need to exist here).
    setupCustomersTable();
    setupSiteMediaTable();
    setupBlocksTable();
    setupEnquiriesTable();
    setupFeedbackTable();
    Service::withTrashed()->whereKey($row->id)->update(['deleted_at' => now()->subDays(90)]);
    $this->artisan(PurgeSoftDeleted::class)->assertSuccessful();
    expect(Service::withTrashed()->whereKey($row->id)->exists())->toBeTrue();

    // ...while an ordinary trashed service DOES purge (the exclusion is narrow).
    $plain = createServiceFor($user, ['title' => 'Old Manual', 'deleted_at' => now()->subDays(90)->toDateTimeString()]);
    $this->artisan(PurgeSoftDeleted::class)->assertSuccessful();
    expect(Service::withTrashed()->whereKey($plain->id)->exists())->toBeFalse();
});

it('soft-deletes a departed service with sync origin and restores it when it returns', function () {
    $user = createTenant('frproj4');
    $projector = app(FreshaServiceProjector::class);
    $projector->sync($user, [freshaRawService('s:1', 'Haircut', 'Hair', 65), freshaRawService('s:2', 'Trim', 'Hair', 20)]);
    $trim = Service::query()->where('user_id', $user->id)->where('external_id', 's:2')->firstOrFail();

    // s:2 disappears from the scrape.
    $projector->sync($user, [freshaRawService('s:1', 'Haircut', 'Hair', 65)]);
    $trim = Service::withTrashed()->findOrFail($trim->id);
    expect($trim->trashed())->toBeTrue();
    expect($trim->deleted_origin)->toBe('sync');

    // ...and comes back.
    $projector->sync($user, [freshaRawService('s:1', 'Haircut', 'Hair', 65), freshaRawService('s:2', 'Trim', 'Hair', 25)]);
    $trim->refresh();
    expect($trim->trashed())->toBeFalse();
    expect($trim->deleted_origin)->toBeNull();
    expect($trim->price_cents)->toBe(2500);
});

it('service-visibility drives projection is_active on projection-aware payloads', function () {
    $user = createTenant('frproj5');
    seedFreshaProjection($user, [freshaRawService('s:1', 'Haircut', 'Hair', 65)]);

    actingAsUser($user)->postJson('/api/platforms/fresha/service-visibility', ['serviceId' => 's:1', 'hidden' => true])
        ->assertOk()
        ->assertJsonPath('hiddenServiceIds', ['s:1']);

    expect(Service::query()->where('user_id', $user->id)->where('external_id', 's:1')->firstOrFail()->is_active)->toBeFalse();

    actingAsUser($user)->postJson('/api/platforms/fresha/service-visibility', ['serviceId' => 's:1', 'hidden' => false])
        ->assertOk()
        ->assertJsonPath('hiddenServiceIds', []);
    expect(Service::query()->where('user_id', $user->id)->where('external_id', 's:1')->firstOrFail()->is_active)->toBeTrue();
});

it('resyncs a detached service back to the raw scrape version — single and bulk', function () {
    $user = createTenant('frproj6');
    seedFreshaProjection($user, [freshaRawService('s:1', 'Haircut', 'Hair', 65), freshaRawService('s:2', 'Trim', 'Hair', 20)]);
    $one = Service::query()->where('user_id', $user->id)->where('external_id', 's:1')->firstOrFail();
    $two = Service::query()->where('user_id', $user->id)->where('external_id', 's:2')->firstOrFail();

    actingAsUser($user)->patchJson("/api/services/{$one->id}", ['title' => 'Edited One'])->assertOk();
    actingAsUser($user)->patchJson("/api/services/{$two->id}", ['title' => 'Edited Two'])->assertOk();

    // Single revert.
    actingAsUser($user)->postJson("/api/services/{$one->id}/resync")
        ->assertOk()
        ->assertJsonPath('service.is_manual', false)
        ->assertJsonPath('service.title', 'Haircut');

    // Bulk revert (no ids = every remaining detached row).
    actingAsUser($user)->postJson('/api/services/resync')
        ->assertOk()
        ->assertJsonPath('resynced', 1)
        ->assertJsonPath('skipped', 0);
    expect($two->fresh()->title)->toBe('Trim');

    // The blob is back to verbatim raw entries.
    $stored = IntegrationConnection::query()->where('user_id', $user->id)->firstOrFail()->payload;
    expect(collect($stored['selection']['services'])->pluck('name')->all())->toBe(['Haircut', 'Trim']);

    // A resync on a manual (non-Fresha) service 422s.
    $manual = createServiceFor($user, ['title' => 'Hand-made']);
    actingAsUser($user)->postJson("/api/services/{$manual->id}/resync")->assertStatus(422);
});

it('keeps projections out of the public services section and its visibility gate', function () {
    $user = createTenant('frproj7');
    seedFreshaProjection($user, [freshaRawService('s:1', 'Haircut', 'Hair', 65)]);
    createServiceFor($user, ['title' => 'Manual Massage', 'price_cents' => 9000, 'is_active' => 1]);

    $data = app(SitepageDataResolverService::class)->buildServicesData($user->site, (string) $user->id);
    expect(array_column($data['services'], 'title'))->toBe(['Manual Massage']);
});

it('dedupes and projects through the FreshaFetch refresh, storing raw privately', function () {
    $user = createTenant('frproj8');
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

    // Effective selection deduped; the raw scrape (deduped) persists under `raw`.
    expect(array_column($next['selection']['services'], 'serviceId'))->toBe(['s:1']);
    expect(array_column($next['raw']['services'], 'serviceId'))->toBe(['s:1']);
    expect(Service::query()->where('user_id', $user->id)->where('source', 'fresha')->count())->toBe(1);

    // Second identical refresh — quiet not-modified.
    $connection->payload = $next;
    $connection->save();
    expect(fn () => app(FreshaFetch::class)->fetch($connection))
        ->toThrow(FetchNotModifiedException::class);
});
