<?php

// Regression sentinel for commit 3cd4ff63, re-pointed at the surviving
// allocator in slice 7 Phase 6.
//
// ORIGINALLY this drove App\Services\Site\ItemSlugAllocator (site.item_slugs)
// end to end, because that is where the bug shipped: MenuScanApplier::apply()
// wrapped a MenuItem::create() that reached the allocator through
// MenuItemObserver, all inside one outer transaction. That class, that
// observer and that table's menu lane are all gone.
//
// The BUG SHAPE is not gone, and that is why this file still exists.
// App\Services\Content\ContentItemSlugAllocator does the same insert-unique
// dance against content.item_slugs, is reached inside a caller's transaction
// the same way (ProjectionWriter::refreshItemCaches, ManualPoolWriter), and
// carries the identical protection — insertOrIgnore rather than
// insert-and-catch, so an expected collision returns 0 rows affected instead
// of raising (see that class's own note at the `insertOrIgnore` call).
//
// PostgreSQL aborts the WHOLE transaction on any statement error (SQLSTATE
// 25P02 on every later statement). SQLite does not, so this can only run for
// real against Postgres — see Tests\PostgresTestCase for the skip-without-
// Postgres guard. Runs via `composer test:pg` / phpunit.pg.xml.
//
// What it proves: two DIFFERENT items whose names slugify identically collide
// on the (user_id, slug) unique index inside a caller's transaction; the
// collision resolves to a `-2` suffix, the outer transaction stays usable for
// a third write, and all three rows commit.

use App\Services\Content\ContentItemSlugAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    // Isolate from any prior run's residue — recreate the real table shape
    // (supabase/migrations/20260731210000 + 20260812040000) rather than
    // relying on cross-test state.
    $pg->statement('CREATE SCHEMA IF NOT EXISTS core');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS content');
    $pg->statement('DROP TABLE IF EXISTS content.item_slugs');
    $pg->statement('CREATE TABLE IF NOT EXISTS core.users (id uuid PRIMARY KEY DEFAULT gen_random_uuid())');
    $pg->statement('CREATE TABLE content.item_slugs (
        id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id    uuid NOT NULL,
        item_id    uuid NOT NULL,
        slug       text NOT NULL,
        is_current boolean NOT NULL DEFAULT true,
        created_at timestamptz NOT NULL DEFAULT now(),
        retired_at timestamptz NULL
    )');
    // The collision surface under test.
    $pg->statement('CREATE UNIQUE INDEX content_item_slugs_unique_slug ON content.item_slugs (user_id, slug)');
    // Mirrors idx_content_item_slugs_one_current (20260812040000).
    $pg->statement('CREATE UNIQUE INDEX content_item_slugs_one_current ON content.item_slugs (item_id) WHERE is_current');

    $this->userId = (string) Str::uuid();
    $pg->table('core.users')->insert(['id' => $this->userId]);
});

it('survives a real slug collision inside an outer transaction and keeps the transaction usable', function () {
    $pg = DB::connection('pgsql');
    $userId = $this->userId;

    $itemA = (string) Str::uuid();
    $itemB = (string) Str::uuid();
    $itemC = (string) Str::uuid();

    $pg->transaction(function () use ($userId, $itemA, $itemB, $itemC) {
        $allocator = app(ContentItemSlugAllocator::class);

        // Mints the base slug for the first item.
        $first = $allocator->ensureCurrent($userId, $itemA, 'Fish Tacos');

        // Same base ("fish-tacos") for a DIFFERENT item — collides at
        // content_item_slugs_unique_slug. This is the savepoint/insertOrIgnore
        // path: pre-fix, this collision aborts the whole outer transaction
        // (25P02); post-fix it resolves cleanly to "fish-tacos-2".
        $second = $allocator->ensureCurrent($userId, $itemB, 'Fish Tacos');

        // Proves the outer transaction is STILL healthy after the collision —
        // pre-fix this statement fails with 25P02 because the transaction is
        // already aborted.
        $third = $allocator->ensureCurrent($userId, $itemC, 'Nachos');

        expect($first)->toBe('fish-tacos');
        expect($second)->toBe('fish-tacos-2');
        expect($third)->toBe('nachos');
    });

    // Re-read after commit: if the transaction had actually been aborted or
    // rolled back, none of these rows would exist — that is the pre-fix red
    // this sentinel is built to catch.
    $rows = $pg->table('content.item_slugs')
        ->where('user_id', $userId)
        ->get(['item_id', 'slug', 'is_current']);

    expect($rows)->toHaveCount(3);

    $byItem = $rows->keyBy('item_id');
    expect((string) $byItem[$itemA]->slug)->toBe('fish-tacos');
    expect((string) $byItem[$itemB]->slug)->toBe('fish-tacos-2');
    expect((string) $byItem[$itemC]->slug)->toBe('nachos');
});
