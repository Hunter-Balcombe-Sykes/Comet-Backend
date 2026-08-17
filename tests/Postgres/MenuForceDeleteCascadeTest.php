<?php

// Nightwatch #297 / DINT-102 narrowing: the load-bearing half of the fix.
//
// MenuObserver used to refuse a hard delete of any Menu that still had live
// categories. MenuCategory did NOT use SoftDeletes and nothing deleted
// categories when a Menu was soft-deleted, so categories()->exists() stayed
// true forever and PurgeSoftDeleted retried the same rows every night at
// 03:20 UTC, permanently. The guard's premise — that a hard delete could
// "silently orphan child rows under a vanished menu_id" — was false: the
// children are ON DELETE CASCADE.
//
// Slice 7 Phase 6 finished the job. site.menu_categories / menu_items /
// menu_item_categories / menu_item_platforms are DROPPED, so the orphan the
// guard defended against cannot exist at all and MenuObserver retired with
// them. What survives is site.menu_platform_links — the menu's one remaining
// child — and its cascade is still the reason PurgeSoftDeleted can hard-delete
// a trashed menu without orphaning anything.
//
// This can only be asserted here. The default lane is SQLite, where foreign
// keys are off unless a pragma enables them, so the cascade cannot fire there.
//
// Self-provisioned schema, like the rest of tests/Postgres/, from
// supabase/migrations/20260726000000_baseline_pilot.sql. The FK is verbatim —
// it IS the thing under test. The tables are reduced to the columns this test
// writes: the cascade is a property of the constraint, not of the column list.

use App\Models\Core\Site\Menu;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS site');

    // Child first — dropping a parent another table references needs it gone.
    $pg->statement('DROP TABLE IF EXISTS site.menu_platform_links');
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
    $pg->statement('DROP TABLE IF EXISTS site.menus');
});

/** A trashed menu with a live platform link. Returns its id. */
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

    DB::connection('pgsql')->table('site.menu_platform_links')->insert([
        'id' => (string) Str::uuid(), 'menu_id' => $menuId, 'platform' => 'uber-eats',
        'store_url' => 'https://www.ubereats.com/store/x', 'status' => 'ok',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $menuId;
}

it('force-deletes a trashed menu and Postgres cascades its platform links away', function () {
    $menuId = seedJammedMenu();

    $menu = Menu::withTrashed()->findOrFail($menuId);

    expect($menu->platformLinks()->exists())->toBeTrue();

    $menu->forceDelete();

    expect(DB::connection('pgsql')->table('site.menus')->where('id', $menuId)->exists())->toBeFalse()
        ->and(DB::connection('pgsql')->table('site.menu_platform_links')->where('menu_id', $menuId)->exists())->toBeFalse();
});

it('soft-deletes a menu without touching its platform links', function () {
    $menuId = seedJammedMenu();
    DB::connection('pgsql')->table('site.menus')->where('id', $menuId)->update(['deleted_at' => null]);

    $menu = Menu::findOrFail($menuId);

    // No longer guarded: MenuObserver's refusal died with the category table it
    // protected. A soft delete is an ordinary soft delete, and the links stay
    // put — they are the menu's bookkeeping, restored with it.
    $menu->delete();

    expect(DB::connection('pgsql')->table('site.menus')->where('id', $menuId)->whereNotNull('deleted_at')->exists())->toBeTrue()
        ->and(DB::connection('pgsql')->table('site.menu_platform_links')->where('menu_id', $menuId)->exists())->toBeTrue();
});
