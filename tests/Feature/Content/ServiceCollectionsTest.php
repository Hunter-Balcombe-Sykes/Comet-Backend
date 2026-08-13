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
