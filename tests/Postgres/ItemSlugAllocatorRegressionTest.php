<?php

// Regression sentinel for commit 3cd4ff63: ItemSlugAllocator::insertUnique()
// must isolate a slug-collision from the OUTER transaction a caller may
// already hold (e.g. MenuScanApplier::apply(), which wraps the
// MenuItem::create() that reaches the allocator through MenuItemObserver).
//
// Unlike tests/Feature/Site/ItemSlugAllocatorSavepointTest.php (now moved to
// tests/Postgres/ItemSlugAllocatorSavepointTest.php), which reproduces just the
// insertOrIgnore-in-a-savepoint MECHANISM against a scratch table, this test
// drives the REAL production App\Services\Site\ItemSlugAllocator end to end
// against a real site.item_slugs table (schema replicated from
// supabase/migrations/20260724120000_create_item_slugs.sql), inside a genuine
// outer transaction, exactly the shape MenuScanApplier::apply() creates. It
// proves the fix at the call-site the bug actually shipped through, not just
// at the mechanism it relies on.
//
// PostgreSQL aborts the WHOLE transaction on any statement error (SQLSTATE
// 25P02 on every later statement). SQLite does not, so this can only run for
// real against Postgres — see Tests\PostgresTestCase for the skip-without-
// Postgres guard. Runs via `composer test:pg` / phpunit.pg.xml.
//
// Pre-fix (insert-and-catch, no savepoint — see 3cd4ff63's diff), the second
// ensureCurrent() call below raises a 23505 on the colliding base slug, and
// the very next statement in the SAME transaction fails with 25P02 — either
// the transaction() closure throws (propagating out of this test) or, if
// caught somewhere, the third ensureCurrent() call / the final assertions
// observe a poisoned/rolled-back transaction. Post-fix, insertOrIgnore behind
// a nested transaction (savepoint) absorbs the collision with zero rows
// affected, the outer transaction stays healthy, and all three items commit.

use App\Services\Site\ItemSlugAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    // Isolate this test from any prior run's residue — drop and recreate the
    // real table shape (see supabase/migrations/20260724120000) rather than
    // relying on cross-test state.
    $pg->statement('CREATE SCHEMA IF NOT EXISTS core');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS site');
    $pg->statement('DROP TABLE IF EXISTS site.item_slugs');
    $pg->statement('CREATE TABLE IF NOT EXISTS core.users (id uuid PRIMARY KEY DEFAULT gen_random_uuid())');
    $pg->statement('CREATE TABLE site.item_slugs (
        id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id    uuid NOT NULL,
        item_type  text NOT NULL,
        item_key   text NOT NULL,
        slug       text NOT NULL,
        is_current boolean NOT NULL DEFAULT true,
        created_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT item_slugs_user_fk FOREIGN KEY (user_id) REFERENCES core.users(id) ON DELETE CASCADE,
        CONSTRAINT item_slugs_type_check CHECK (item_type IN (\'event\',\'menu_item\'))
    )');
    $pg->statement('CREATE UNIQUE INDEX item_slugs_unique_slug ON site.item_slugs (user_id, slug)');
    $pg->statement('CREATE UNIQUE INDEX item_slugs_one_current ON site.item_slugs (user_id, item_type, item_key) WHERE is_current');
    $pg->statement('CREATE INDEX item_slugs_lookup ON site.item_slugs (user_id, item_type, item_key)');

    $this->userId = (string) Str::uuid();
    $pg->table('core.users')->insert(['id' => $this->userId]);
});

it('survives a real slug collision inside an outer transaction and keeps the transaction usable', function () {
    $pg = DB::connection('pgsql');
    $userId = $this->userId;

    $pg->transaction(function () use ($userId) {
        $allocator = app(ItemSlugAllocator::class);

        // Mints the base slug for the first item.
        $first = $allocator->ensureCurrent($userId, ItemSlugAllocator::TYPE_MENU_ITEM, 'key-1', 'Fish Tacos');

        // Same base ("fish-tacos") for a DIFFERENT item — collides at
        // item_slugs_unique_slug. This is where insertUnique()'s savepoint is
        // exercised: pre-fix, this collision aborts the whole outer
        // transaction (25P02); post-fix, insertOrIgnore + savepoint resolve
        // it cleanly to "fish-tacos-2".
        $second = $allocator->ensureCurrent($userId, ItemSlugAllocator::TYPE_MENU_ITEM, 'key-2', 'Fish Tacos');

        // Proves the outer transaction is STILL healthy after the collision —
        // pre-fix this statement would fail with 25P02 because the whole
        // transaction is already aborted.
        $third = $allocator->ensureCurrent($userId, ItemSlugAllocator::TYPE_MENU_ITEM, 'key-3', 'Nachos');

        expect($first)->toBe('fish-tacos');
        expect($second)->toBe('fish-tacos-2');
        expect($third)->toBe('nachos');
    });

    // Re-read after commit: if the transaction had actually been aborted/rolled
    // back, none of these rows would exist and these assertions would fail —
    // that's the pre-fix red this sentinel is built to catch.
    $rows = $pg->table('site.item_slugs')
        ->where('user_id', $userId)
        ->orderBy('item_key')
        ->get(['item_key', 'slug', 'is_current']);

    expect($rows)->toHaveCount(3);

    $byKey = $rows->keyBy('item_key');
    expect((string) $byKey['key-1']->slug)->toBe('fish-tacos');
    expect((string) $byKey['key-2']->slug)->toBe('fish-tacos-2');
    expect((string) $byKey['key-3']->slug)->toBe('nachos');

    // Assert is_current in SQL, not PHP: pdo_pgsql returns the boolean as the
    // string 't'/'f', and (bool) 'f' === true would make a PHP-side check vacuous.
    $currentCount = $pg->table('site.item_slugs')
        ->where('user_id', $userId)
        ->where('is_current', true)
        ->count();
    expect($currentCount)->toBe(3);
});
