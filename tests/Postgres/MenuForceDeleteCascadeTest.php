<?php

// Nightwatch #297 / DINT-102 narrowing: the load-bearing half of the fix.
//
// MenuObserver used to refuse a hard delete of any Menu that still had live
// categories. MenuCategory does NOT use SoftDeletes and nothing deletes
// categories when a Menu is soft-deleted, so categories()->exists() stayed
// true forever and PurgeSoftDeleted retried the same rows every night at
// 03:20 UTC, permanently. The guard's premise — that a hard delete could
// "silently orphan site.menu_categories / site.menu_items rows under a
// vanished menu_id" — is false: all three children are ON DELETE CASCADE.
//
// This can only be asserted here. The default lane is SQLite, where foreign
// keys are off unless a pragma enables them, so the cascade cannot fire and
// tests/Feature/Platforms/MenuTest.php can only assert that forceDelete() no
// longer throws. The cascade IS the reason the guard was safe to narrow, so
// it needs a real Postgres server to be a real assertion. If this test ever
// shows children surviving, the FK evidence is wrong and MenuObserver's
// forceDelete path must be re-guarded.
//
// Self-provisioned schema, like the rest of tests/Postgres/, from
// supabase/migrations/20260726000000_baseline_pilot.sql (tables 1707-1829,
// constraints 3907/3927/3932). The three FKs are verbatim — they ARE the thing
// under test. The tables are reduced to the columns this test writes: the
// cascade is a property of the constraints, not of the column list, so
// carrying all 40-odd columns would add noise without adding assurance.

use App\Models\Core\Site\Menu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS site');

    // Children first — dropping a parent other tables reference needs them gone.
    $pg->statement('DROP TABLE IF EXISTS site.menu_platform_links');
    $pg->statement('DROP TABLE IF EXISTS site.menu_items');
    $pg->statement('DROP TABLE IF EXISTS site.menu_categories');
    $pg->statement('DROP TABLE IF EXISTS site.menus');

    $pg->statement("CREATE TABLE site.menus (
        id             uuid PRIMARY KEY,
        user_id        uuid NOT NULL,
        fetch_status   text NOT NULL DEFAULT 'pending',
        content_source text,
        created_at     timestamptz,
        updated_at     timestamptz,
        deleted_at     timestamptz,
        CONSTRAINT menus_fetch_status_check
            CHECK (fetch_status = ANY (ARRAY['pending'::text, 'ok'::text, 'unavailable'::text]))
    )");

    $pg->statement('CREATE TABLE site.menu_categories (
        id              uuid PRIMARY KEY,
        menu_id         uuid NOT NULL,
        name            text NOT NULL,
        position        integer NOT NULL DEFAULT 0,
        source_platform text,
        created_at      timestamptz,
        updated_at      timestamptz,
        CONSTRAINT menu_categories_menu_id_fkey
            FOREIGN KEY (menu_id) REFERENCES site.menus(id) ON DELETE CASCADE
    )');

    $pg->statement('CREATE TABLE site.menu_items (
        id         uuid PRIMARY KEY,
        menu_id    uuid NOT NULL,
        name       text NOT NULL,
        is_manual  boolean NOT NULL DEFAULT false,
        created_at timestamptz,
        updated_at timestamptz,
        CONSTRAINT menu_items_menu_id_fkey
            FOREIGN KEY (menu_id) REFERENCES site.menus(id) ON DELETE CASCADE
    )');

    $pg->statement('CREATE TABLE site.menu_platform_links (
        id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        menu_id    uuid NOT NULL,
        platform   text NOT NULL,
        store_url  text,
        status     text,
        created_at timestamptz,
        updated_at timestamptz,
        CONSTRAINT menu_platform_links_menu_id_fkey
            FOREIGN KEY (menu_id) REFERENCES site.menus(id) ON DELETE CASCADE
    )');
});

afterEach(function () {
    $pg = DB::connection('pgsql');
    $pg->statement('DROP TABLE IF EXISTS site.menu_platform_links');
    $pg->statement('DROP TABLE IF EXISTS site.menu_items');
    $pg->statement('DROP TABLE IF EXISTS site.menu_categories');
    $pg->statement('DROP TABLE IF EXISTS site.menus');
});

/** A trashed menu with a live category, item and platform link. Returns its id. */
function seedJammedMenu(): string
{
    $menuId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.menus')->insert([
        'id' => $menuId,
        'user_id' => (string) Str::uuid(),
        'fetch_status' => 'ok',
        'content_source' => 'uber-eats',
        'created_at' => now(),
        'updated_at' => now(),
        // Trashed well past the 30-day retention window — exactly the state
        // PurgeSoftDeleted finds and has been failing on since 2026-06.
        'deleted_at' => now()->subDays(45),
    ]);

    DB::connection('pgsql')->table('site.menu_categories')->insert([
        'id' => (string) Str::uuid(), 'menu_id' => $menuId, 'name' => 'Mains',
        'position' => 0, 'source_platform' => 'uber-eats',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::connection('pgsql')->table('site.menu_items')->insert([
        'id' => (string) Str::uuid(), 'menu_id' => $menuId, 'name' => 'Margherita',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::connection('pgsql')->table('site.menu_platform_links')->insert([
        'id' => (string) Str::uuid(), 'menu_id' => $menuId, 'platform' => 'uber-eats',
        'store_url' => 'https://www.ubereats.com/store/x', 'status' => 'ok',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $menuId;
}

it('force-deletes a menu with live children and Postgres cascades the whole tree away', function () {
    $menuId = seedJammedMenu();

    $menu = Menu::withTrashed()->findOrFail($menuId);

    // Pre-state: this is precisely what the old guard refused to touch.
    expect($menu->categories()->exists())->toBeTrue();

    $menu->forceDelete();

    expect(DB::connection('pgsql')->table('site.menus')->where('id', $menuId)->exists())->toBeFalse()
        ->and(DB::connection('pgsql')->table('site.menu_categories')->where('menu_id', $menuId)->exists())->toBeFalse()
        ->and(DB::connection('pgsql')->table('site.menu_items')->where('menu_id', $menuId)->exists())->toBeFalse()
        ->and(DB::connection('pgsql')->table('site.menu_platform_links')->where('menu_id', $menuId)->exists())->toBeFalse();
});

it('still refuses a soft delete while categories are live, leaving the tree intact', function () {
    $menuId = seedJammedMenu();
    DB::connection('pgsql')->table('site.menus')->where('id', $menuId)->update(['deleted_at' => null]);

    $menu = Menu::findOrFail($menuId);

    // The narrowing is to forceDelete ONLY. A soft delete still leaves the
    // category tree live and reachable under a menu the user cannot see,
    // which is the state DINT-102 was actually about.
    expect(fn () => $menu->delete())->toThrow(RuntimeException::class);

    expect(DB::connection('pgsql')->table('site.menus')->where('id', $menuId)->whereNull('deleted_at')->exists())->toBeTrue()
        ->and(DB::connection('pgsql')->table('site.menu_categories')->where('menu_id', $menuId)->exists())->toBeTrue();
});
