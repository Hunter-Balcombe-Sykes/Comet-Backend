<?php

use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\MenuItem;
use App\Models\Core\Site\MenuItemPlatform;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Platforms\MenuApifyScraper;
use App\Services\Platforms\MenuMerger;
use App\Services\Platforms\MenuSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // MenuItemObserver + MenuFetchJob write site.item_slugs best-effort — with
    // no table they swallow "no such table" and mask real slug regressions.
    setupItemSlugsTable();
    // Slice 7 Task 5: MenuPayloadComposer reads content.* for the dishes and
    // only falls back to site.menu_* when that lane holds nothing for the
    // owner. The tables have to EXIST for the fallback to be reachable — these
    // writes still land in site.menu_* until Task 6 moves them.
    setupContentTables();
});

// Menu is a food-business capability (can_use_menu requires isBusiness() &&
// isFood()). Every write here is gated on it, so the whole suite's default
// persona is business/restaurant; the gate test overrides to 'partna'.
// Helper names are prefixed `mmc` to avoid clashing with MenuTest's menuUser/
// ordering/seedMenu when all three files load into one suite.
function mmcUser(string $handle, string $accountType = 'business', string $sector = 'restaurant'): User
{
    $user = User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => $accountType,
        'sector' => $sector,
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);

    // A site is needed for media ownership + cache busting (real users always have one).
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => $handle,
        'is_published' => 1,
        'settings' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return $user->fresh();
}

/** An owned, ready image media with a webp 'optimized' variant. */
function mmcMedia(User $user): SiteMedia
{
    setupMediaTables();
    $site = $user->loadMissing('site')->site;
    $mediaId = (string) Str::uuid();

    DB::connection('pgsql')->table('site.site_media')->insert([
        'id' => $mediaId,
        'site_id' => $site->id,
        'pool' => 'gallery',
        'media_type' => 'image',
        'processing_state' => 'ready',
        'is_active' => 1,
        'path' => 'images/original.jpg',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    DB::connection('pgsql')->table('site.media_variants')->insert([
        'id' => (string) Str::uuid(), 'media_id' => $mediaId,
        'variant_key' => 'optimized', 'artifact_type' => 'webp',
        'disk' => 'media', 'path' => 'images/optimized.webp', 'mime' => 'image/webp',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    return SiteMedia::query()->findOrFail($mediaId);
}

/** An Uber Eats online-ordering link, so MenuSource::resolveAll() is non-null. */
function mmcOrdering(User $user, string $url = 'https://www.ubereats.com/store/x'): IntegrationConnection
{
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);

    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'uber_eats.order',
        'resource_id' => $rid,
        'payload' => ['id' => $rid, 'provider' => 'custom', 'url' => $url, 'name' => 'Order', 'source' => 'manual'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
}

/**
 * Seed a scraped menu directly (source_platform='uber-eats', is_manual=false) —
 * the starting point for edit/delete-of-scraped tests.
 *
 * @param  array<string, list<array{name:string, base_price?:float}>>  $categories
 */
function mmcSeedScraped(User $user, array $categories): Menu
{
    $menu = Menu::create([
        'user_id' => $user->id, 'content_source' => 'uber-eats',
        'currency' => 'AUD', 'fetch_status' => 'ok', 'last_fetched_at' => now(),
    ]);
    $ci = 0;
    foreach ($categories as $name => $items) {
        $cat = MenuCategory::create(['menu_id' => $menu->id, 'name' => $name, 'position' => $ci++, 'source_platform' => 'uber-eats']);
        $ii = 0;
        foreach ($items as $item) {
            $row = MenuItem::create(array_merge([
                'menu_id' => $menu->id, 'is_manual' => false,
            ], $item));
            $row->categories()->attach($cat->id, ['position' => $ii++]);
        }
    }

    return $menu;
}

function mmcRunFetch(User $user, bool $force = false): void
{
    (new MenuFetchJob((string) $user->id, $force))->handle(
        app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class)
    );
}

// ── Category CRUD ─────────────────────────────────────────────────────

it('creates a manual category and returns the full menu shape', function () {
    $user = mmcUser('mm1');

    $res = actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Appetizers'])
        ->assertOk()
        ->assertJsonStructure(['source', 'categories', 'links']);

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    $category = MenuCategory::query()->where('menu_id', $menu->id)->firstOrFail();
    expect($category->name)->toBe('Appetizers');
    expect($category->source_platform)->toBe('manual');
    expect($category->position)->toBe(0);
    // The freshly-created category surfaces in the returned menu (own-content, not orphaned).
    expect(collect($res->json('categories'))->pluck('name')->all())->toContain('Appetizers');
    // Composer echoes the category's persisted id (addresses PATCH/DELETE
    // .../categories/{id}) and sourcePlatform (drives the sync-detach warning).
    $responseCategory = collect($res->json('categories'))->firstWhere('name', 'Appetizers');
    expect($responseCategory['id'])->toBe((string) $category->id);
    expect($responseCategory['sourcePlatform'])->toBe('manual');
});

it('positions a second manual category after the first', function () {
    $user = mmcUser('mm1b');
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Starters'])->assertOk();
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Mains'])->assertOk();

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    $positions = MenuCategory::query()->where('menu_id', $menu->id)->orderBy('position')->pluck('name', 'position')->all();
    expect($positions)->toBe([0 => 'Starters', 1 => 'Mains']);
});

it('422s a duplicate category name case-insensitively', function () {
    $user = mmcUser('mm2');
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Desserts'])->assertOk();

    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => '  desserts '])
        ->assertStatus(422);

    expect(MenuCategory::query()->where('menu_id', Menu::query()->where('user_id', $user->id)->value('id'))->count())->toBe(1);
});

it('422s an empty category name', function () {
    $user = mmcUser('mm2b');
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => '   '])->assertStatus(422);
});

it('renames a manual category', function () {
    $user = mmcUser('mm3');
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Old Name'])->assertOk();
    $categoryId = MenuCategory::query()->where('menu_id', Menu::query()->where('user_id', $user->id)->value('id'))->value('id');

    actingAsUser($user)->patchJson("/api/platforms/menu/categories/{$categoryId}", ['name' => 'New Name'])->assertOk();

    expect(MenuCategory::query()->whereKey($categoryId)->value('name'))->toBe('New Name');
});

it('renames a scan category', function () {
    $user = mmcUser('mm3b');
    $menu = Menu::create(['user_id' => $user->id, 'content_source' => 'scan', 'fetch_status' => 'ok', 'last_fetched_at' => now()]);
    $cat = MenuCategory::create(['menu_id' => $menu->id, 'name' => 'Scanned', 'position' => 0, 'source_platform' => 'scan']);

    actingAsUser($user)->patchJson("/api/platforms/menu/categories/{$cat->id}", ['name' => 'Renamed Scan'])->assertOk();
    expect($cat->fresh()->name)->toBe('Renamed Scan');
});

it('422s renaming a synced (scraped) category', function () {
    $user = mmcUser('mm4');
    $menu = mmcSeedScraped($user, ['Mains' => [['name' => 'Burger', 'base_price' => 12.0]]]);
    $cat = MenuCategory::query()->where('menu_id', $menu->id)->firstOrFail();

    actingAsUser($user)->patchJson("/api/platforms/menu/categories/{$cat->id}", ['name' => 'Hacked'])
        ->assertStatus(422)
        ->assertJsonPath('message', "Synced categories can't be renamed.");

    expect($cat->fresh()->name)->toBe('Mains');
});

it('404s renaming a category that is not the callers', function () {
    $owner = mmcUser('mm4owner');
    $menu = mmcSeedScraped($owner, ['Mains' => [['name' => 'Burger']]]);
    $cat = MenuCategory::query()->where('menu_id', $menu->id)->firstOrFail();
    $other = mmcUser('mm4other');

    actingAsUser($other)->patchJson("/api/platforms/menu/categories/{$cat->id}", ['name' => 'Nope'])->assertStatus(404);
});

// menu_categories.id / menu_items.id are real Postgres `uuid` columns — a
// malformed id must 404 at the router (whereUuid), not reach
// MenuCategory::find()/MenuItem::find() and 500 on invalid uuid syntax
// (22P02). SQLite's TEXT-typed test schema can't reproduce that 500, but the
// router-level whereUuid constraint is DB-agnostic, so this still guards it.
it('404s a malformed (non-uuid) category or item id on every route', function () {
    $user = mmcUser('mm4c');

    actingAsUser($user)->patchJson('/api/platforms/menu/categories/not-a-uuid', ['name' => 'X'])->assertStatus(404);
    actingAsUser($user)->deleteJson('/api/platforms/menu/categories/not-a-uuid')->assertStatus(404);
    actingAsUser($user)->patchJson('/api/platforms/menu/items/not-a-uuid', ['name' => 'X'])->assertStatus(404);
    actingAsUser($user)->deleteJson('/api/platforms/menu/items/not-a-uuid')->assertStatus(404);
});

it('deletes a manual category and its items', function () {
    $user = mmcUser('mm5');
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Specials'])->assertOk();
    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    $catId = MenuCategory::query()->where('menu_id', $menu->id)->value('id');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Chef Special', 'category_id' => $catId])->assertOk();
    $itemId = MenuItem::query()->where('name', 'Chef Special')->value('id');
    MenuItemPlatform::create(['menu_item_id' => $itemId, 'platform' => 'uber-eats']);

    actingAsUser($user)->deleteJson("/api/platforms/menu/categories/{$catId}")->assertOk();

    expect(MenuCategory::query()->whereKey($catId)->exists())->toBeFalse();
    expect(MenuItem::query()->whereKey($itemId)->exists())->toBeFalse();
    expect(MenuItemPlatform::query()->where('menu_item_id', $itemId)->exists())->toBeFalse();
});

// SLUG-RESIDUAL-1: deleteCategory's orphan cleanup is a query-builder mass
// delete (MenuItem::query()->whereIn(...)->delete()), which hydrates no
// models and fires no MenuItemObserver events — so without the explicit
// forgetMany() call, an orphaned dish's item_slugs row survives the dish
// itself and squats its (user_id, slug) pair forever (the unique index is
// NOT partial). Same defect class as 271-DINT-1's MenuFetchJob fix.
it('frees an orphaned dish\'s item_slugs row when its only category is deleted', function () {
    $user = mmcUser('mm5c');
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Solo Cat'])->assertOk();
    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    $catId = MenuCategory::query()->where('menu_id', $menu->id)->value('id');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Orphan Dish', 'category_id' => $catId])->assertOk();
    $itemId = (string) MenuItem::query()->where('name', 'Orphan Dish')->value('id');

    // MenuItemObserver::created() minted a current slug row on create.
    expect(DB::connection('pgsql')->table('site.item_slugs')
        ->where('item_type', 'menu_item')->where('item_key', $itemId)->where('is_current', true)->exists())
        ->toBeTrue();

    actingAsUser($user)->deleteJson("/api/platforms/menu/categories/{$catId}")->assertOk();

    expect(MenuItem::query()->whereKey($itemId)->exists())->toBeFalse();
    expect(DB::connection('pgsql')->table('site.item_slugs')
        ->where('item_type', 'menu_item')->where('item_key', $itemId)->exists())
        ->toBeFalse();
});

// Control: a dish that also belongs to a category NOT being deleted is not an
// orphan — it survives deleteCategory's cleanup, and so must its slug row.
it('keeps a surviving dish\'s item_slugs row when only one of its categories is deleted', function () {
    $user = mmcUser('mm5d');
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Cat A'])->assertOk();
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Cat B'])->assertOk();
    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    $ids = MenuCategory::query()->where('menu_id', $menu->id)->pluck('id', 'name');

    actingAsUser($user)->postJson('/api/platforms/menu/items', [
        'name' => 'Shared Dish', 'category_ids' => [(string) $ids['Cat A'], (string) $ids['Cat B']],
    ])->assertOk();
    $itemId = (string) MenuItem::query()->where('name', 'Shared Dish')->value('id');

    actingAsUser($user)->deleteJson("/api/platforms/menu/categories/{$ids['Cat A']}")->assertOk();

    expect(MenuItem::query()->whereKey($itemId)->exists())->toBeTrue();
    expect(DB::connection('pgsql')->table('site.item_slugs')
        ->where('item_type', 'menu_item')->where('item_key', $itemId)->where('is_current', true)->exists())
        ->toBeTrue();
});

// A manual dish orphaned by the same delete is not suppressed but is still
// hard-deleted — its slug must free up too.
it('frees a manual orphaned dish\'s item_slugs row when its only category is deleted', function () {
    $user = mmcUser('mm5e');
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Manual Cat'])->assertOk();
    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    $catId = MenuCategory::query()->where('menu_id', $menu->id)->value('id');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Manual Orphan', 'category_id' => $catId])->assertOk();
    $itemId = (string) MenuItem::query()->where('name', 'Manual Orphan')->value('id');

    actingAsUser($user)->deleteJson("/api/platforms/menu/categories/{$catId}")->assertOk();

    expect(MenuItem::query()->whereKey($itemId)->exists())->toBeFalse();
    expect(DB::connection('pgsql')->table('site.item_slugs')
        ->where('item_type', 'menu_item')->where('item_key', $itemId)->exists())
        ->toBeFalse();
    // Manual dish → not suppressed (only non-manual orphans get suppressed).
    $menu->refresh();
    expect($menu->suppressed_items)->toBeNull();
});

it('422s deleting a synced (scraped) category', function () {
    $user = mmcUser('mm5b');
    $menu = mmcSeedScraped($user, ['Mains' => [['name' => 'Burger']]]);
    $cat = MenuCategory::query()->where('menu_id', $menu->id)->firstOrFail();

    actingAsUser($user)->deleteJson("/api/platforms/menu/categories/{$cat->id}")->assertStatus(422);
    expect($cat->fresh())->not->toBeNull();
});

// ── Item create ───────────────────────────────────────────────────────

it('creates a manual item in a supplied category', function () {
    $user = mmcUser('mm6');
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Mains'])->assertOk();
    $catId = MenuCategory::query()->where('menu_id', Menu::query()->where('user_id', $user->id)->value('id'))->value('id');

    actingAsUser($user)->postJson('/api/platforms/menu/items', [
        'name' => 'Ribeye', 'description' => '300g, grass fed.', 'price' => 42.0, 'category_id' => $catId,
    ])->assertOk();

    $item = MenuItem::query()->where('name', 'Ribeye')->firstOrFail();
    expect($item->description)->toBe('300g, grass fed.');
    expect((float) $item->base_price)->toBe(42.0);
    expect($item->is_manual)->toBeTrue();
    $membership = $item->categories()->firstOrFail();
    expect((string) $membership->id)->toBe((string) $catId);
    expect((int) $membership->pivot->position)->toBe(0);
});

it('find-or-creates a manual "Menu" category when no category is supplied', function () {
    $user = mmcUser('mm7');

    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Mystery Dish'])->assertOk();
    // A second uncategorised item reuses the same default category, not a new one.
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Another Dish'])->assertOk();

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    $categories = MenuCategory::query()->where('menu_id', $menu->id)->get();
    expect($categories)->toHaveCount(1);
    expect($categories->first()->name)->toBe('Menu');
    expect($categories->first()->source_platform)->toBe('manual');
    expect($categories->first()->items()->count())->toBe(2);
});

it('resolves image_media_id to the optimized variant url and sets images', function () {
    $user = mmcUser('mm8');
    $media = mmcMedia($user);
    $expectedUrl = $media->variantUrls()['optimized'];
    expect($expectedUrl)->not->toBeEmpty();

    actingAsUser($user)->postJson('/api/platforms/menu/items', [
        'name' => 'Photogenic Toast', 'image_media_id' => $media->id,
    ])->assertOk();

    $item = MenuItem::query()->where('name', 'Photogenic Toast')->firstOrFail();
    expect($item->image_url)->toBe($expectedUrl);
    expect($item->images)->toBe([$expectedUrl]);
});

it('404s an image_media_id that is not the callers', function () {
    $owner = mmcUser('mm8owner');
    $media = mmcMedia($owner);
    $other = mmcUser('mm8other');

    actingAsUser($other)->postJson('/api/platforms/menu/items', [
        'name' => 'Stolen Photo', 'image_media_id' => $media->id,
    ])->assertStatus(404);

    // No menu was created for the failed request.
    expect(Menu::query()->where('user_id', $other->id)->exists())->toBeFalse();
});

it('404s an item created against a category from another menu', function () {
    $owner = mmcUser('mm9owner');
    $menu = mmcSeedScraped($owner, ['Mains' => [['name' => 'Burger']]]);
    $foreignCat = MenuCategory::query()->where('menu_id', $menu->id)->value('id');
    $other = mmcUser('mm9other');

    actingAsUser($other)->postJson('/api/platforms/menu/items', ['name' => 'X', 'category_id' => $foreignCat])
        ->assertStatus(404);
});

it('422s an item price above the sane bound', function () {
    $user = mmcUser('mm9b');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Overpriced', 'price' => 100001])
        ->assertStatus(422);
});

// ── Item edit / delete of scraped content ─────────────────────────────

it('flips a scraped item to manual when it is edited', function () {
    $user = mmcUser('mm10');
    $menu = mmcSeedScraped($user, ['Mains' => [['name' => 'Burger', 'base_price' => 12.0]]]);
    $item = MenuItem::query()->where('menu_id', $menu->id)->firstOrFail();
    expect($item->is_manual)->toBeFalse();

    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$item->id}", ['price' => 15.5])->assertOk();

    $item->refresh();
    expect((float) $item->base_price)->toBe(15.5);
    expect($item->is_manual)->toBeTrue(); // detached from platform sync
});

it('leaves is_manual true when a manual item is edited', function () {
    $user = mmcUser('mm10b');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Handmade', 'price' => 10.0])->assertOk();
    $item = MenuItem::query()->where('name', 'Handmade')->firstOrFail();

    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$item->id}", ['description' => 'Now with a description.'])->assertOk();
    $item->refresh();
    expect($item->description)->toBe('Now with a description.');
    expect($item->is_manual)->toBeTrue();
});

it('moves an item to another category and clears its image on remove_image', function () {
    $user = mmcUser('mm10c');
    $media = mmcMedia($user);
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'A'])->assertOk();
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'B'])->assertOk();
    $menuId = Menu::query()->where('user_id', $user->id)->value('id');
    $catA = MenuCategory::query()->where('menu_id', $menuId)->where('name', 'A')->value('id');
    $catB = MenuCategory::query()->where('menu_id', $menuId)->where('name', 'B')->value('id');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Mover', 'category_id' => $catA, 'image_media_id' => $media->id])->assertOk();
    $item = MenuItem::query()->where('name', 'Mover')->firstOrFail();
    expect($item->image_url)->not->toBeNull();

    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$item->id}", ['category_id' => $catB, 'remove_image' => true])->assertOk();

    // The legacy single category_id REPLACES the membership set — the item now
    // lives only in B.
    $item->refresh();
    expect($item->categories->pluck('id')->map(fn ($id) => (string) $id)->all())->toBe([(string) $catB]);
    expect($item->image_url)->toBeNull();
    expect($item->images)->toBeNull();
});

it('sets multiple category memberships via category_ids on create and update', function () {
    $user = mmcUser('mm10d');
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Lunch'])->assertOk();
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Dinner'])->assertOk();
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Specials'])->assertOk();
    $menuId = Menu::query()->where('user_id', $user->id)->value('id');
    $ids = MenuCategory::query()->where('menu_id', $menuId)->pluck('id', 'name');

    // Create in two categories at once.
    actingAsUser($user)->postJson('/api/platforms/menu/items', [
        'name' => 'Garlic Bread', 'price' => 12.0,
        'category_ids' => [(string) $ids['Lunch'], (string) $ids['Dinner']],
    ])->assertOk();
    $item = MenuItem::query()->where('name', 'Garlic Bread')->firstOrFail();
    expect($item->categories->pluck('name')->sort()->values()->all())->toBe(['Dinner', 'Lunch']);

    // Replace the set: drop Lunch, keep Dinner, add Specials.
    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$item->id}", [
        'category_ids' => [(string) $ids['Dinner'], (string) $ids['Specials']],
    ])->assertOk();
    $item->refresh();
    expect($item->categories->pluck('name')->sort()->values()->all())->toBe(['Dinner', 'Specials']);

    // A category from someone else's menu 404s the whole write (all-or-nothing).
    $other = mmcUser('mm10dother');
    $foreign = mmcSeedScraped($other, ['Mains' => [['name' => 'Foreign Dish']]]);
    $foreignCat = MenuCategory::query()->where('menu_id', $foreign->id)->value('id');
    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$item->id}", [
        'category_ids' => [(string) $ids['Dinner'], (string) $foreignCat],
    ])->assertStatus(404);
    $item->refresh();
    expect($item->categories->pluck('name')->sort()->values()->all())->toBe(['Dinner', 'Specials']);
});

it('bulk-deletes items in one call, suppressing the scraped ones', function () {
    $user = mmcUser('mm11b');
    $menu = mmcSeedScraped($user, ['Mains' => [['name' => 'Burger'], ['name' => 'Fries']]]);
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Handmade'])->assertOk();

    $ids = MenuItem::query()->where('menu_id', $menu->id)->pluck('id')->map(fn ($id) => (string) $id)->all();
    actingAsUser($user)->postJson('/api/platforms/menu/items/bulk-delete', ['ids' => $ids])->assertOk();

    expect(MenuItem::query()->where('menu_id', $menu->id)->count())->toBe(0);
    // Scraped dishes suppressed; the manual one just deleted.
    $menu->refresh();
    $suppressedNames = collect($menu->suppressed_items)->pluck('name')->sort()->values()->all();
    expect($suppressedNames)->toBe(['Burger', 'Fries']);

    // Unknown ids are skipped, not errors.
    actingAsUser($user)->postJson('/api/platforms/menu/items/bulk-delete', ['ids' => [(string) Str::uuid()]])->assertOk();
});

it('hard-deletes a manual item without suppressing it', function () {
    $user = mmcUser('mm11');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Temporary'])->assertOk();
    $item = MenuItem::query()->where('name', 'Temporary')->firstOrFail();

    actingAsUser($user)->deleteJson("/api/platforms/menu/items/{$item->id}")->assertOk();

    expect(MenuItem::query()->whereKey($item->id)->exists())->toBeFalse();
    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    expect($menu->suppressed_items)->toBeNull(); // manual delete never suppresses
});

it('deletes a scraped item and records it as suppressed', function () {
    $user = mmcUser('mm12');
    $menu = mmcSeedScraped($user, ['Mains' => [['name' => 'Burger'], ['name' => 'Fries']]]);
    $burger = MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Burger')->firstOrFail();

    actingAsUser($user)->deleteJson("/api/platforms/menu/items/{$burger->id}")->assertOk();

    expect(MenuItem::query()->whereKey($burger->id)->exists())->toBeFalse();
    $menu->refresh();
    expect($menu->suppressed_items)->toBe([['category' => 'Mains', 'name' => 'Burger']]);
});

it('does not duplicate a suppression entry when the same scraped dish is deleted twice', function () {
    $user = mmcUser('mm12b');
    $menu = mmcSeedScraped($user, ['Mains' => [['name' => 'Burger'], ['name' => 'Fries']]]);
    // Two scraped rows with the same name/category (a real duplicate) — deleting
    // both must record the suppression once.
    $dupe = MenuItem::create(['menu_id' => $menu->id, 'name' => 'Burger', 'is_manual' => false]);
    $dupe->categories()->attach(MenuCategory::query()->where('menu_id', $menu->id)->value('id'), ['position' => 2]);
    foreach (MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Burger')->pluck('id') as $id) {
        actingAsUser($user)->deleteJson("/api/platforms/menu/items/{$id}")->assertOk();
    }

    $menu->refresh();
    expect($menu->suppressed_items)->toBe([['category' => 'Mains', 'name' => 'Burger']]);
});

// ── Capability gate ───────────────────────────────────────────────────

it('403s every manual write for a non-food (partna) account', function () {
    // partna accounts never have can_use_menu (isBusiness() is false).
    $user = mmcUser('mm13', 'partna', 'creator');

    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'X'])->assertStatus(403);
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Y'])->assertStatus(403);
    actingAsUser($user)->patchJson('/api/platforms/menu/categories/'.Str::uuid(), ['name' => 'Z'])->assertStatus(403);
    actingAsUser($user)->deleteJson('/api/platforms/menu/items/'.Str::uuid())->assertStatus(403);

    expect(Menu::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

// ── Rebuild suite: manual content survives the wholesale scrape rebuild ──
// The one integration test that exercises every rebuild rule at once:
//   - a manual category survives untouched
//   - a manual dish in a SCRAPED category survives
//   - an edited (detached) scraped dish keeps the owner's value
//   - a scraped dish colliding with a manual one is skipped (manual wins)
//   - a suppressed dish is not resurrected
//   - ordinary scraped dishes rebuild fresh

it('preserves manual content and honours suppression across a forced scrape rebuild', function () {
    $user = mmcUser('mm14');
    mmcOrdering($user);

    $run1 = ['uber-eats' => [
        'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Mains', 'items' => [
                ['name' => 'Burger', 'pickupPrice' => 12.0, 'deliveryPrice' => 12.0],
                ['name' => 'Fries', 'pickupPrice' => 5.0, 'deliveryPrice' => 5.0],
            ]],
            ['name' => 'Drinks', 'items' => [
                ['name' => 'Cola', 'pickupPrice' => 3.0, 'deliveryPrice' => 3.0],
                ['name' => 'Water', 'pickupPrice' => 2.0, 'deliveryPrice' => 2.0],
            ]],
        ],
    ]];
    // Run 2 re-offers Fries, House Burger, and Cola — each must be skipped for a
    // different reason (manual collision / manual collision / suppression). Burger
    // + Water rebuild normally at fresh prices.
    $run2 = ['uber-eats' => [
        'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Mains', 'items' => [
                ['name' => 'Burger', 'pickupPrice' => 13.0, 'deliveryPrice' => 13.0],
                ['name' => 'Fries', 'pickupPrice' => 99.0, 'deliveryPrice' => 99.0],
                ['name' => 'House Burger', 'pickupPrice' => 99.0, 'deliveryPrice' => 99.0],
            ]],
            ['name' => 'Drinks', 'items' => [
                ['name' => 'Cola', 'pickupPrice' => 3.0, 'deliveryPrice' => 3.0],
                ['name' => 'Water', 'pickupPrice' => 2.5, 'deliveryPrice' => 2.5],
            ]],
        ],
    ]];
    $this->mock(MenuApifyScraper::class, function ($m) use ($run1, $run2) {
        $m->shouldReceive('fetchStores')->twice()->andReturn($run1, $run2);
    });

    // First scrape.
    mmcRunFetch($user);
    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    $mainsId = MenuCategory::query()->where('menu_id', $menu->id)->where('name', 'Mains')->value('id');

    // Owner edits: manual category + item, a manual dish inside a scraped category,
    // a detach-by-edit, and a suppress-by-delete.
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Specials'])->assertOk();
    $specialsId = MenuCategory::query()->where('menu_id', $menu->id)->where('name', 'Specials')->value('id');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Chef Special', 'price' => 30.0, 'category_id' => $specialsId])->assertOk();
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'House Burger', 'price' => 18.0, 'category_id' => $mainsId])->assertOk();

    $friesId = MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Fries')->value('id');
    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$friesId}", ['price' => 6.0])->assertOk();
    $colaId = MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Cola')->value('id');
    actingAsUser($user)->deleteJson("/api/platforms/menu/items/{$colaId}")->assertOk();

    // Forced rebuild.
    mmcRunFetch($user, force: true);

    $items = MenuItem::query()->where('menu_id', $menu->id)->get()->keyBy('name');

    // Manual category + its manual dish survive untouched.
    expect(MenuCategory::query()->whereKey($specialsId)->exists())->toBeTrue();
    expect($items->has('Chef Special'))->toBeTrue();
    expect((float) $items['Chef Special']->base_price)->toBe(30.0);

    // Manual dish in a scraped category survives; the colliding scraped "House
    // Burger" was skipped (exactly one, still the manual one at the owner's price).
    expect(MenuItem::query()->where('menu_id', $menu->id)->where('name', 'House Burger')->count())->toBe(1);
    expect($items['House Burger']->is_manual)->toBeTrue();
    expect((float) $items['House Burger']->base_price)->toBe(18.0);

    // Edited (detached) Fries kept the owner's price — run2's 99 was skipped.
    expect((float) $items['Fries']->base_price)->toBe(6.0);
    expect($items['Fries']->is_manual)->toBeTrue();

    // Suppressed Cola stayed gone even though run2 re-offered it.
    expect($items->has('Cola'))->toBeFalse();
    $menu->refresh();
    expect($menu->suppressed_items)->toBe([['category' => 'Drinks', 'name' => 'Cola']]);

    // Ordinary scraped dishes rebuilt fresh at their new prices.
    expect((float) $items['Burger']->base_price)->toBe(13.0);
    expect($items['Burger']->is_manual)->toBeFalse();
    expect((float) $items['Water']->base_price)->toBe(2.5);

    // The scraped "Mains" category was kept alive by its manual dishes.
    expect(MenuCategory::query()->whereKey($mainsId)->exists())->toBeTrue();
});

it('preserves a manual dish when the last ordering link is removed (clearScrapedContent)', function () {
    $user = mmcUser('mm15');
    $menu = mmcSeedScraped($user, ['Mains' => [['name' => 'Scraped Dish', 'base_price' => 10.0]]]);
    $mainsId = MenuCategory::query()->where('menu_id', $menu->id)->value('id');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Handmade Dish', 'price' => 20.0, 'category_id' => $mainsId])->assertOk();
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Specials'])->assertOk();

    // No ordering source resolvable → clearScrapedContent path.
    $this->mock(MenuApifyScraper::class, fn ($m) => $m->shouldReceive('fetchStores')->never());
    mmcRunFetch($user);

    // Scraped dish gone; the manual dish + manual category survive; menu kept alive.
    expect(MenuItem::query()->where('name', 'Scraped Dish')->exists())->toBeFalse();
    expect(MenuItem::query()->where('name', 'Handmade Dish')->exists())->toBeTrue();
    expect(MenuCategory::query()->where('menu_id', $menu->id)->where('name', 'Specials')->exists())->toBeTrue();
    $menu->refresh();
    expect($menu->trashed())->toBeFalse();
    expect($menu->content_source)->toBe('manual');
    // The scraped "Mains" category survived because it still holds the manual dish.
    expect(MenuCategory::query()->whereKey($mainsId)->exists())->toBeTrue();
});

// The AUTOMATIC scan reapply (MenuFetchJob → MenuScanApplier, enrichOnly) runs
// after every scrape persist, from the persisted menus.scan_items. Without the
// suppression filter it would recreate an owner-deleted dish via the applier's
// no-match→create path — persist() skips the scraped copy, then the reapply
// re-adds a scan copy. Matching is NAME-ONLY (scan category names rarely equal
// scrape category names — 'Beverages' vs 'Drinks' here); the manual dashboard
// /scan/apply path stays unfiltered by design.
it('does not resurrect a suppressed dish through the automatic scan reapply', function () {
    $user = mmcUser('mm17');
    mmcOrdering($user);

    $run = ['uber-eats' => [
        'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Drinks', 'items' => [
                ['name' => 'Cola', 'pickupPrice' => 3.0, 'deliveryPrice' => 3.0],
                ['name' => 'Water', 'pickupPrice' => 2.0, 'deliveryPrice' => 2.0],
            ]],
        ],
    ]];
    $this->mock(MenuApifyScraper::class, function ($m) use ($run) {
        $m->shouldReceive('fetchStores')->twice()->andReturn($run, $run);
    });

    mmcRunFetch($user);
    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();

    // A persisted Google-photos scan knows the deleted dish (under the photo's
    // own category name, not the scrape's) plus one legitimate scan-only dish.
    $menu->forceFill(['scan_items' => ['items' => [
        ['name' => 'Cola', 'description' => 'Ice cold cola.', 'price' => 3.5, 'category' => 'Beverages'],
        ['name' => 'Lemonade', 'description' => 'House-made lemonade.', 'price' => 4.0, 'category' => 'Beverages'],
    ], 'source' => 'google-photos']])->save();

    // Owner deletes the scraped Cola → suppressed.
    $colaId = MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Cola')->value('id');
    actingAsUser($user)->deleteJson("/api/platforms/menu/items/{$colaId}")->assertOk();

    // Forced rebuild: persist() skips the scraped Cola (suppression), and the
    // automatic scan reapply must not re-add it from scan_items either.
    mmcRunFetch($user, force: true);

    expect(MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Cola')->exists())->toBeFalse();

    // The reapply itself still ran — the non-suppressed scan-only dish landed
    // under a scan category — proving only the suppressed dish was dropped.
    $lemonade = MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Lemonade')->first();
    expect($lemonade)->not->toBeNull();
    expect($lemonade->categories->first()->source_platform)->toBe('scan');

    // The suppression record survives for future rebuilds.
    $menu->refresh();
    expect($menu->suppressed_items)->toBe([['category' => 'Drinks', 'name' => 'Cola']]);
});

it('serves a manual-only menu on status and show (owner content is never orphaned)', function () {
    $user = mmcUser('mm16');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Solo Manual', 'price' => 9.0])->assertOk();

    actingAsUser($user)->getJson('/api/platforms/menu/status')
        ->assertOk()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('itemCount', 1)
        ->assertJsonPath('source', 'manual');

    $res = actingAsUser($user)->getJson('/api/platforms/menu')->assertOk();
    expect($res->json('source'))->toBe('manual');
    expect($res->json('categories.0.name'))->toBe('Menu');
    expect($res->json('categories.0.id'))->not->toBeNull();
    expect($res->json('categories.0.sourcePlatform'))->toBe('manual');
    expect($res->json('categories.0.items.0.name'))->toBe('Solo Manual');
    // Owner-authored item — is_manual=true (never omitted/false for a hand-added dish).
    expect($res->json('categories.0.items.0.isManual'))->toBeTrue();
});

// ── Reorder (categories + items within a category) ───────────────────────────

it('reorders categories, unlisted ones trailing', function () {
    $user = mmcUser('mreo1');
    $menu = mmcSeedScraped($user, [
        'Breakfast' => [['name' => 'Avo']],
        'Lunch' => [['name' => 'Roll']],
        'Dinner' => [['name' => 'Braise']],
    ]);
    $cats = MenuCategory::query()->where('menu_id', $menu->id)->orderBy('position')->get();

    // Move Dinner first, list only it and Breakfast — Lunch trails.
    actingAsUser($user)->postJson('/api/platforms/menu/categories/reorder', [
        'ids' => [$cats[2]->id, $cats[0]->id],
    ])->assertOk();

    $order = MenuCategory::query()->where('menu_id', $menu->id)->orderBy('position')->pluck('name')->all();
    expect($order)->toBe(['Dinner', 'Breakfast', 'Lunch']);
});

it('reorders items within one category via pivot positions', function () {
    $user = mmcUser('mreo2');
    $menu = mmcSeedScraped($user, [
        'Lunch' => [['name' => 'Roll'], ['name' => 'Bowl'], ['name' => 'Pie']],
    ]);
    $cat = MenuCategory::query()->where('menu_id', $menu->id)->firstOrFail();
    $items = $cat->items()->orderByPivot('position')->pluck('site.menu_items.id')->all();

    actingAsUser($user)->postJson('/api/platforms/menu/items/reorder', [
        'category_id' => $cat->id,
        'ids' => [$items[2], $items[0]],
    ])->assertOk();

    $names = $cat->fresh()->items()->orderByPivot('position')->pluck('name')->all();
    expect($names)->toBe(['Pie', 'Roll', 'Bowl']);
});

it('404s reordering items with an id outside the category', function () {
    $user = mmcUser('mreo3');
    $menu = mmcSeedScraped($user, [
        'Lunch' => [['name' => 'Roll']],
        'Dinner' => [['name' => 'Braise']],
    ]);
    $lunch = MenuCategory::query()->where('menu_id', $menu->id)->where('name', 'Lunch')->firstOrFail();
    $braise = MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Braise')->firstOrFail();

    actingAsUser($user)->postJson('/api/platforms/menu/items/reorder', [
        'category_id' => $lunch->id,
        'ids' => [$braise->id],
    ])->assertStatus(404);
});

it('403s menu reorders without the menu capability', function () {
    $user = mmcUser('mreo4', 'partna', 'creator');

    actingAsUser($user)->postJson('/api/platforms/menu/categories/reorder', ['ids' => []])
        ->assertStatus(403);
});
