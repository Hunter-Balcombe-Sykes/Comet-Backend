<?php

// Slice 7 Task 11 (spec D3 + owner ruling D3a, 2026-08-16) — the Fresha
// `payload.selection` blob composed from content.* instead of site.services.
//
// D3 keeps the blob on the public wire and changes only where it is composed
// FROM, so this file's job is to pin the composed output EXACTLY — key order
// included, as encoded JSON, because the stored blob ships to the CDN as JSON
// and a reordered object is a diff to anyone byte-comparing it.
//
// Three of the four behaviours the legacy projection carried survive verbatim
// and are asserted below against content.* fixtures:
//   • `deleted_origin='user'` suppression → content.items.removed_at, which
//     ProjectionWriter never clears, so a later scrape cannot resurrect it;
//   • `deleted_origin='sync'` restore-on-return → content.source_items.removed_at,
//     which IS cleared on reappearance;
//   • first-occurrence dedup → the vendor's menu order, fixed in place.
//
// The fourth — `is_manual`, the owner edit that detached a service from the
// sync — is RETIRED by D3a. An owner's edited PRICE has no representation in
// content.* (content.offers is a set-union collection; FacetRegistry excludes
// collections from content.manual_overrides by design), all 61 live rows
// carried is_manual = false, and none carried an owner price. The
// 'a re-sync now moves the price' case below asserts the NEW behaviour rather
// than deleting the old one. Title/description/duration overrides are
// unaffected — those are singleton facets and keep working.
//
// The read is FreshaServiceItems (`kind = 'connection'`), NEVER
// ManualServiceItems (`kind = 'manual'`): the two-surface rule is pinned in
// both directions by tests/Feature/Content/ServiceTwoSurfaceTest.php, and the
// last case here is this file's own guard on the same wall.

use App\Models\Core\User\User;
use App\Services\Content\ManualServiceItems;
use App\Services\Platforms\FreshaServiceProjector;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

/**
 * A raw scrape entry in the exact shape FreshaScraper::extractServices emits.
 * File-local (prefix `fsfc`) — a cross-file helper of the same name is fatal
 * under --parallel.
 */
function fsfcRaw(string $id, string $name, ?string $category = null, mixed $priceValue = 50, ?string $duration = '45min'): array
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

/** The user's single `kind = 'connection'` content source — the Fresha lane. */
function fsfcSource(string $userId): string
{
    $id = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => 'connection', 'connection_id' => null,
        'label' => 'Fresha', 'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

/**
 * Land one Fresha service into content.* the way the ingest connector +
 * ProjectionWriter would. `record_key` carries the vendor serviceId verbatim,
 * which is what FreshaServiceItems reads back out as `serviceId`.
 */
function fsfcLand(string $userId, string $sourceId, string $serviceId, string $name, ?int $amountMinor = 5000, ?int $seconds = 2700, ?string $category = 'Hair'): string
{
    $itemId = addItem($userId, 'service', $name);
    $now = now();

    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => "fresha:store:{$serviceId}", 'record_key' => $serviceId,
        'item_id' => $itemId, 'kind' => 'service', 'projector_version' => 1,
        'first_seen_at' => $now, 'last_seen_at' => $now,
    ]);

    DB::table('content.offers')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
        'channel' => 'fresha', 'qualifier' => 'exact', 'amount_minor' => $amountMinor,
        // Bare '$' is what Fresha emits — `currency` stays null rather than
        // guessing AUD, matching the ingest projector's never-fabricate rule.
        'currency' => null, 'updated_at' => $now,
    ]);

    if ($seconds !== null) {
        DB::table('content.f_duration')->insert([
            'item_id' => $itemId, 'source_id' => $sourceId, 'seconds' => $seconds, 'updated_at' => $now,
        ]);
    }

    if ($category !== null) {
        $collectionId = (string) Str::uuid();
        DB::table('content.collections')->insert([
            'id' => $collectionId, 'user_id' => $userId, 'parent_id' => null,
            'label' => $category, 'kind' => 'service_category', 'external_ref' => $serviceId.'-cat',
            'position' => 0, 'is_user_created' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('content.collection_items')->insert([
            'collection_id' => $collectionId, 'item_id' => $itemId,
            'source_id' => $sourceId, 'position' => 0,
        ]);
    }

    return $itemId;
}

/** The connector's own record of "this service is no longer on the menu". */
function fsfcMarkAbsent(string $itemId): void
{
    DB::table('content.source_items')->where('item_id', $itemId)->update(['removed_at' => now()]);
}

/** The connector's record of "it came back" — cleared on reappearance, by design. */
function fsfcMarkPresent(string $itemId): void
{
    DB::table('content.source_items')->where('item_id', $itemId)->update(['removed_at' => null]);
}

it('composes the blob from content.*, in the vendor menu order the raw scrape fixes', function () {
    $user = createTenant('fsfc1');
    $source = fsfcSource($user->id);
    fsfcLand($user->id, $source, 's:1', 'Haircut', 6500, 4500, 'Hair');
    fsfcLand($user->id, $source, 's:2', 'Beard Trim', 2750, 1800, 'Hair');

    // The raw scrape supplies ORDER only — s:2 first here, and the composed
    // blob follows it rather than content.*'s own first_seen_at ordering.
    $raw = [fsfcRaw('s:2', 'Beard Trim'), fsfcRaw('s:1', 'Haircut')];

    expect(json_encode(app(FreshaServiceProjector::class)->compose($user, $raw)))->toBe(
        '{"services":['
        .'{"name":"Beard Trim","price":"$27.50","category":"Hair","currency":null,"duration":"30min","serviceId":"s:2","priceValue":27.5,"description":null,"hasVariants":false},'
        .'{"name":"Haircut","price":"$65","category":"Hair","currency":null,"duration":"1h 15min","serviceId":"s:1","priceValue":65,"description":null,"hasVariants":false}'
        .'],"hiddenServiceIds":[]}'
    );
});

it('composes nothing when content.* holds no services, whatever the stored scrape says', function () {
    $user = createTenant('fsfc2');
    fsfcSource($user->id);

    // The blob is no longer self-sustaining: a populated payload.raw with an
    // empty pool renders an empty menu. That is 3b's documented deploy window
    // (an existing connection shows nothing until its connector has run),
    // reaching the public blob now that it reads the same lane.
    expect(app(FreshaServiceProjector::class)->compose($user, [fsfcRaw('s:1', 'Haircut')]))
        ->toBe(['services' => [], 'hiddenServiceIds' => []]);
});

it('sync writes nothing and returns the deduped raw beside the composed blob', function () {
    $user = createTenant('fsfc3');
    $source = fsfcSource($user->id);
    fsfcLand($user->id, $source, 's:1', 'Haircut', 6500);

    $before = DB::table('content.items')->count();

    $result = app(FreshaServiceProjector::class)->sync($user, [
        fsfcRaw('s:1', 'Haircut', 'Hair'),
        fsfcRaw('s:1', 'Haircut', 'Packages'),
    ]);

    // D3a: no rows, no advisory lock, no transaction — sync() is a read that
    // happens to also hand back the deduped scrape for payload.raw.
    expect(DB::table('content.items')->count())->toBe($before);
    expect(array_column($result['raw'], 'serviceId'))->toBe(['s:1']);
    expect(array_column($result['services'], 'serviceId'))->toBe(['s:1']);
});

it('moves the price on a re-sync — owner price edits are retired (D3a)', function () {
    $user = createTenant('fsfc4');
    $source = fsfcSource($user->id);
    $itemId = fsfcLand($user->id, $source, 's:1', 'Haircut', 6500);
    $raw = [fsfcRaw('s:1', 'Haircut')];
    $projector = app(FreshaServiceProjector::class);

    expect($projector->compose($user, $raw)['services'][0]['price'])->toBe('$65');

    // The connector re-prices the service. Under the legacy projection an owner
    // edit (is_manual) would have frozen this at the owner's number; the blob
    // now always speaks the vendor's, which is what makes it agree with the
    // dashboard (FreshaSelectionResource has read this same lane since 3b).
    DB::table('content.offers')->where('item_id', $itemId)->update(['amount_minor' => 8000]);

    $composed = $projector->compose($user, $raw);
    expect($composed['services'][0]['price'])->toBe('$80');
    expect($composed['services'][0]['priceValue'])->toBe(80.0);
});

it('suppresses an owner-deleted service and a later scrape cannot bring it back', function () {
    $user = createTenant('fsfc5');
    $source = fsfcSource($user->id);
    $deleted = fsfcLand($user->id, $source, 's:1', 'Haircut', 6500);
    fsfcLand($user->id, $source, 's:2', 'Trim', 2000);
    $raw = [fsfcRaw('s:1', 'Haircut'), fsfcRaw('s:2', 'Trim')];
    $projector = app(FreshaServiceProjector::class);

    // The owner's delete — content.items.removed_at, NEVER
    // source_items.removed_at, which is cleared on reappearance and would
    // resurrect a service its owner deleted.
    DB::table('content.items')->where('id', $deleted)->update(['removed_at' => now()]);

    expect(array_column($projector->compose($user, $raw)['services'], 'serviceId'))->toBe(['s:2']);

    // The scrape still offers s:1, and the connector still sees it present —
    // the suppression holds because it is recorded on the item, not the
    // source_item.
    fsfcMarkPresent($deleted);
    expect(array_column($projector->sync($user, $raw)['services'], 'serviceId'))->toBe(['s:2']);
});

it('drops a departed service and restores it when it returns', function () {
    $user = createTenant('fsfc6');
    $source = fsfcSource($user->id);
    fsfcLand($user->id, $source, 's:1', 'Haircut', 6500);
    $departing = fsfcLand($user->id, $source, 's:2', 'Trim', 2000);
    $raw = [fsfcRaw('s:1', 'Haircut'), fsfcRaw('s:2', 'Trim')];
    $projector = app(FreshaServiceProjector::class);

    // s:2 vanishes from Fresha — connector ABSENCE, not an owner act.
    fsfcMarkAbsent($departing);
    expect(array_column($projector->compose($user, $raw)['services'], 'serviceId'))->toBe(['s:1']);

    // ...and comes back. Absence-driven removal is REVERSIBLE; that is the
    // whole difference from the owner-delete case above.
    fsfcMarkPresent($departing);
    DB::table('content.offers')->where('item_id', $departing)->update(['amount_minor' => 2500]);

    $composed = $projector->compose($user, $raw);
    expect(array_column($composed['services'], 'serviceId'))->toBe(['s:1', 's:2']);
    expect($composed['services'][1]['price'])->toBe('$25');
});

it('collapses a serviceId listed under several categories to its first occurrence', function () {
    $user = createTenant('fsfc7');
    $source = fsfcSource($user->id);
    fsfcLand($user->id, $source, 's:1', 'Haircut', 6500, 2700, 'Hair');
    fsfcLand($user->id, $source, 's:2', 'Trim', 2000, 2700, 'Hair');

    // Fresha lists a service once per category it appears in — the same
    // serviceId arrives several times in one scrape.
    $composed = app(FreshaServiceProjector::class)->compose($user, [
        fsfcRaw('s:1', 'Haircut', 'Hair'),
        fsfcRaw('s:1', 'Haircut', 'Packages'),
        fsfcRaw('s:2', 'Trim', 'Hair'),
    ]);

    expect(array_column($composed['services'], 'serviceId'))->toBe(['s:1', 's:2']);
});

it('appends a live service the stored scrape has not caught up with', function () {
    $user = createTenant('fsfc8');
    $source = fsfcSource($user->id);
    fsfcLand($user->id, $source, 's:1', 'Haircut', 6500);
    fsfcLand($user->id, $source, 's:2', 'New Service', 3000);

    // content.* is the authority on WHICH services exist; payload.raw only
    // orders the ones it knows. A service the connector landed since the last
    // refresh appears at the end rather than staying invisible until the next
    // scrape rewrites the blob.
    expect(array_column(
        app(FreshaServiceProjector::class)->compose($user, [fsfcRaw('s:1', 'Haircut')])['services'],
        'serviceId',
    ))->toBe(['s:1', 's:2']);
});

it('carries hiddenServiceIds on the blob and prunes ids that are no longer live', function () {
    $user = createTenant('fsfc9');
    $source = fsfcSource($user->id);
    fsfcLand($user->id, $source, 's:1', 'Haircut', 6500);
    $gone = fsfcLand($user->id, $source, 's:2', 'Trim', 2000);
    $raw = [fsfcRaw('s:1', 'Haircut'), fsfcRaw('s:2', 'Trim')];

    $composed = app(FreshaServiceProjector::class)->compose($user, $raw, ['s:2']);

    // A sibling key, not a filter: a hidden service still appears in services[]
    // and the consumer hides it. content.* has no is_active, so the list rides
    // on the blob — where FreshaSelectionResource already reads it from.
    expect($composed['hiddenServiceIds'])->toBe(['s:2']);
    expect(array_column($composed['services'], 'serviceId'))->toBe(['s:1', 's:2']);

    // Hide it, then delete it: the id must not linger as a dangling entry.
    DB::table('content.items')->where('id', $gone)->update(['removed_at' => now()]);
    expect(app(FreshaServiceProjector::class)->compose($user, $raw, ['s:2', 'never-existed'])['hiddenServiceIds'])->toBe([]);
});

it('keeps the booking blob and the public services section on opposite sides of the two-surface rule', function () {
    $user = createTenant('fsfc10');
    $source = fsfcSource($user->id);
    fsfcLand($user->id, $source, 's:1', 'Haircut', 6500);
    $raw = [fsfcRaw('s:1', 'Haircut')];

    // The Fresha service reaches the booking blob...
    expect(array_column(app(FreshaServiceProjector::class)->compose($user, $raw)['services'], 'serviceId'))->toBe(['s:1']);

    // ...and NOT the public services section, which reads kind = 'manual'.
    // Positive control on the other side: this class must not be able to pass
    // by returning an empty list for everything.
    expect(app(ManualServiceItems::class)->publicList($user->id, $user->site))->toBe([]);
    expect(app(SitepageDataResolverService::class)->buildServicesData($user->site, (string) $user->id)['services'])->toBe([]);
});
