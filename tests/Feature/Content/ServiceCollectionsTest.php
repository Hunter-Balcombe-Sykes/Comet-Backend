<?php

use App\Services\Content\ServiceCollections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Task 8: the one read/write for content.collections (kind='service_category')
// and their content.collection_items memberships. Tasks 9/10/11 call this
// service exclusively — no controller or connector may touch these two
// tables directly.
beforeEach(function () {
    tenantHelpersEnsureTables();
    setupIngestTables();
    setupContentTables();
});

/** A fresh tenant, ready to own service_category collections. */
function userWithCollections(): string
{
    return createTenant('svc-coll-'.Str::random(8))->id;
}

/**
 * A machine-derived collection inserted directly (bypassing
 * ServiceCollections::create(), which always sets is_user_created=true and
 * external_ref=null) — mirrors what ProjectionWriter/Task 5's upsert lands
 * for a vendor-sourced category.
 */
function collectionFor(string $userId, string $external, string $label): string
{
    $id = (string) Str::uuid();
    $now = now();

    DB::connection('pgsql')->table('content.collections')->insert([
        'id' => $id,
        'user_id' => $userId,
        'parent_id' => null,
        'label' => $label,
        'kind' => 'service_category',
        'external_ref' => $external,
        'removed_at' => null,
        'position' => 0,
        'is_user_created' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

/** A bare content.items row of kind='service', owned by $userId — enough for assign()'s ownership guard. */
function serviceItemFor(string $userId): string
{
    $id = (string) Str::uuid();
    $now = now();

    DB::connection('pgsql')->table('content.items')->insert([
        'id' => $id,
        'user_id' => $userId,
        'kind' => 'service',
        'headline_cache' => 'Test Service',
        'facets_cache' => '[]',
        'eligible_cache' => '[]',
        'first_seen_at' => $now,
        'last_seen_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

it('hides machine-derived collections that have no items left', function () {
    $userId = userWithCollections();
    $empty = collectionFor($userId, external: '999', label: 'Departed');   // no memberships

    expect(app(ServiceCollections::class)->list($userId)->pluck('id'))->not->toContain($empty);
});

it('keeps a user-created collection with no items', function () {
    $userId = userWithCollections();
    $id = app(ServiceCollections::class)->create($userId, 'Empty But Mine');

    expect(app(ServiceCollections::class)->list($userId)->pluck('id'))->toContain($id);
});

it('excludes removed collections unless asked for them', function () {
    $userId = userWithCollections();
    $id = app(ServiceCollections::class)->create($userId, 'Gone');
    app(ServiceCollections::class)->remove($userId, $id);

    expect(app(ServiceCollections::class)->list($userId)->pluck('id'))->not->toContain($id)
        ->and(app(ServiceCollections::class)->list($userId, includeRemoved: true)->pluck('id'))->toContain($id);
});

it('restores a removed collection', function () {
    $userId = userWithCollections();
    $id = app(ServiceCollections::class)->create($userId, 'Back');
    app(ServiceCollections::class)->remove($userId, $id);
    app(ServiceCollections::class)->restore($userId, $id);

    expect(app(ServiceCollections::class)->find($userId, $id)->removed_at)->toBeNull();
});

it('never returns another user\'s collection', function () {
    $mine = userWithCollections();
    $theirs = userWithCollections();
    $id = app(ServiceCollections::class)->create($theirs, 'Theirs');

    expect(app(ServiceCollections::class)->find($mine, $id))->toBeNull();
});

it('moves an item between collections', function () {
    $userId = userWithCollections();
    $itemId = serviceItemFor($userId);
    $a = app(ServiceCollections::class)->create($userId, 'A');
    $b = app(ServiceCollections::class)->create($userId, 'B');

    app(ServiceCollections::class)->assign($userId, $itemId, $a, null);
    app(ServiceCollections::class)->assign($userId, $itemId, $b, null);

    expect(DB::table('content.collection_items')->where('item_id', $itemId)->pluck('collection_id')->all())
        ->toBe([$b]);
});

// C2 (final review): content.collection_items is a SHARED table. Slices
// 5a/5b file products into kind='storefront' collections with source_id NULL
// — identical, on the two columns assign()'s delete matched, to the
// owner-authored service lane. Unreachable today (a service item id is never
// a product item id), but one mis-resolved id would have silently unfiled a
// product from its storefront. The delete is scoped by kind now.
it('never touches a membership belonging to another collection kind', function () {
    $userId = userWithCollections();
    $itemId = serviceItemFor($userId);
    $serviceCategory = app(ServiceCollections::class)->create($userId, 'Colour');

    // A storefront collection + membership on the SAME item, source_id NULL —
    // exactly the shape ShopContentWriter lands.
    $storefrontId = (string) Str::uuid();
    DB::table('content.collections')->insert([
        'id' => $storefrontId, 'user_id' => $userId, 'parent_id' => null,
        'label' => 'My Shop', 'kind' => 'storefront', 'external_ref' => null,
        'removed_at' => null, 'position' => 0, 'is_user_created' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.collection_items')->insert([
        'collection_id' => $storefrontId, 'item_id' => $itemId,
        'source_id' => null, 'position' => 0,
    ]);

    app(ServiceCollections::class)->assign($userId, $itemId, $serviceCategory, null);
    // ...and again through the clearing spelling, which is the wider delete.
    app(ServiceCollections::class)->assign($userId, $itemId, null, null);

    expect(DB::table('content.collection_items')
        ->where('item_id', $itemId)->where('collection_id', $storefrontId)->count())->toBe(1);
    expect(DB::table('content.collection_items')
        ->where('item_id', $itemId)->where('collection_id', $serviceCategory)->count())->toBe(0);
});

it('clears an item\'s category when passed null', function () {
    $userId = userWithCollections();
    $itemId = serviceItemFor($userId);
    $a = app(ServiceCollections::class)->create($userId, 'A');
    app(ServiceCollections::class)->assign($userId, $itemId, $a, null);

    app(ServiceCollections::class)->assign($userId, $itemId, null, null);

    expect(DB::table('content.collection_items')->where('item_id', $itemId)->count())->toBe(0);
});

// Fix round 1, Finding 1: is_user_created must come back as a real PHP bool
// on the RETURNED row, not just be normalised for list()'s internal filter
// decision — Task 9 maps the wire's `source` field off `is_user_created ===
// false`, which silently breaks on Postgres's "t"/"f" string representation
// if the row itself still carries the raw value.
it('returns is_user_created as a real bool, not a driver-dependent string', function () {
    $userId = userWithCollections();
    $userCreatedId = app(ServiceCollections::class)->create($userId, 'Mine');
    $machineId = collectionFor($userId, external: '42', label: 'Vendor');
    // Give the machine-derived one a live member so list() doesn't filter it
    // out via rule 1 before the type assertion below even runs.
    app(ServiceCollections::class)->assign($userId, serviceItemFor($userId), $machineId, null);

    $rows = app(ServiceCollections::class)->list($userId)->keyBy('id');

    expect($rows[$userCreatedId]->is_user_created)->toBeBool()->toBeTrue()
        ->and($rows[$machineId]->is_user_created)->toBeBool()->toBeFalse();

    $found = app(ServiceCollections::class)->find($userId, $machineId);
    expect($found->is_user_created)->toBeBool()->toBeFalse();
});

// Fix round 1, Finding 3: item_count must only count LIVE members.
// content.collection_items itself has no removed_at — a membership row
// survives an item-level removal (content.items.removed_at) even though
// the service is gone, so item_count must join content.items and exclude
// it, or a machine-derived category with every member individually removed
// still renders as non-empty.
it('treats a machine-derived collection as empty once all its members are removed', function () {
    $userId = userWithCollections();
    $itemId = serviceItemFor($userId);
    $categoryId = collectionFor($userId, external: '111', label: 'Blowouts');
    app(ServiceCollections::class)->assign($userId, $itemId, $categoryId, null);

    // Sanity: with a live member, the category shows.
    expect(app(ServiceCollections::class)->list($userId)->pluck('id'))->toContain($categoryId);

    DB::table('content.items')->where('id', $itemId)->update(['removed_at' => now()]);

    expect(app(ServiceCollections::class)->list($userId)->pluck('id'))->not->toContain($categoryId);
});

// Fix round 1, Finding 2: reposition() with a partial list is authoritative
// for the ids it names — the ids it omits must not keep their stale
// positions (idx_collections_user is NOT unique on (user_id, position), so
// nothing would reject the resulting collision). Omitted ids are appended
// after the supplied ones, in their own current relative order.
it('renumbers collections omitted from a partial reposition after the ones supplied', function () {
    $userId = userWithCollections();
    $a = app(ServiceCollections::class)->create($userId, 'A'); // position 0
    $b = app(ServiceCollections::class)->create($userId, 'B'); // position 1
    $c = app(ServiceCollections::class)->create($userId, 'C'); // position 2

    // Reorder only B and A — C is omitted from the call entirely.
    app(ServiceCollections::class)->reposition($userId, [$b, $a]);

    $positions = DB::table('content.collections')->whereIn('id', [$a, $b, $c])->pluck('position', 'id');

    expect((int) $positions[$b])->toBe(0)
        ->and((int) $positions[$a])->toBe(1)
        ->and((int) $positions[$c])->toBe(2);
});

// The test above omits ONE id, which cannot tell "kept its relative order"
// apart from "appended in whatever order the driver felt like" — with a single
// element every ordering is the same ordering. This one omits THREE, seeded so
// that their position order (Zeta, Alpha, Mid) is the ONLY order the three
// obvious wrong implementations don't produce:
//   - sort by label   -> Alpha, Mid, Zeta
//   - sort by id      -> Zeta, Mid, Alpha   (uuids 1111…, 2222…, 3333…)
//   - sort by created -> Alpha, Mid, Zeta   (inserted in that order)
// so a reposition() that dropped its `orderBy('position')` on the omitted set
// lands a different sequence no matter which fallback the driver picks.
it('preserves the relative order of three collections omitted from a partial reposition', function () {
    $userId = userWithCollections();

    // Direct inserts, not create(): position, id and created_at all have to be
    // pinned independently, and create() derives position from the max.
    $seed = function (string $id, string $label, int $position, string $createdAt) use ($userId) {
        DB::connection('pgsql')->table('content.collections')->insert([
            'id' => $id,
            'user_id' => $userId,
            'parent_id' => null,
            'label' => $label,
            'kind' => 'service_category',
            'external_ref' => $label,
            'removed_at' => null,
            'position' => $position,
            'is_user_created' => false,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $id;
    };

    $first = $seed('44444444-4444-4444-4444-444444444444', 'Supplied First', 0, '2026-01-01 00:00:00');
    $second = $seed('55555555-5555-5555-5555-555555555555', 'Supplied Second', 1, '2026-01-02 00:00:00');
    // Omitted trio: id order, label order and created_at order each disagree
    // with the position order the contract promises to preserve.
    $seed('33333333-3333-3333-3333-333333333333', 'Alpha', 8, '2026-01-03 00:00:00');
    $seed('22222222-2222-2222-2222-222222222222', 'Mid', 9, '2026-01-04 00:00:00');
    $seed('11111111-1111-1111-1111-111111111111', 'Zeta', 7, '2026-01-05 00:00:00');

    app(ServiceCollections::class)->reposition($userId, [$second, $first]);

    $ordered = DB::table('content.collections')
        ->where('user_id', $userId)
        ->orderBy('position')
        ->get(['label', 'position']);

    expect($ordered->pluck('label')->all())
        ->toBe(['Supplied Second', 'Supplied First', 'Zeta', 'Alpha', 'Mid'])
        ->and($ordered->pluck('position')->map(fn ($p) => (int) $p)->all())
        ->toBe([0, 1, 2, 3, 4]);
});

it('reposition ignores an id that does not belong to the caller', function () {
    $mine = userWithCollections();
    $theirs = userWithCollections();
    app(ServiceCollections::class)->create($theirs, 'Theirs First');   // position 0, decoy
    $foreign = app(ServiceCollections::class)->create($theirs, 'Theirs Second'); // position 1
    $mineId = app(ServiceCollections::class)->create($mine, 'Mine');  // position 0

    app(ServiceCollections::class)->reposition($mine, [$foreign, $mineId]);

    expect((int) DB::table('content.collections')->where('id', $foreign)->value('position'))->toBe(1)
        ->and((int) DB::table('content.collections')->where('id', $mineId)->value('position'))->toBe(0);
});
