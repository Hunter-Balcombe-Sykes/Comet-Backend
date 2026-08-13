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
