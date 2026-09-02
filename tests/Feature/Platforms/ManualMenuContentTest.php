<?php

use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\MenuItem;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Content\ManualMenuItems;
use App\Services\Content\ManualMenuWriter;
use App\Services\Content\MenuCollections;
use App\Services\Platforms\MenuApifyScraper;
use App\Services\Platforms\MenuMerger;
use App\Services\Platforms\MenuProjectionMapper;
use App\Services\Platforms\MenuScanApplier;
use App\Services\Platforms\MenuSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Slice 7 Task 6: the ten owner verbs write content.* now. Dishes are
    // content.items + facets, categories are content.collections kind
    // 'menu_category', the owner-edit marker is content.manual_overrides and
    // dish order is the pool:menus pins in site.section_items.
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

    // A site is needed for media ownership, the pool:menus pins and cache
    // busting (real users always have one).
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
        'pool' => 'content',
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
    // F17 (2026-08-18): the observer dispatches MenuFetchJob for ordering rows on
    // menu-platform hosts; these tests drive the menu by hand.
    Queue::fake([MenuFetchJob::class]);
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
 * Seed a SCRAPED menu into the content lane — the starting point for
 * edit/delete-of-scraped tests.
 *
 * This is what MenuFetchJob::persist() will do once Task 7 lands: the dishes go
 * through MenuProjectionMapper (never a second derivation of the coord — a
 * duplicate coord mints a duplicate dish), and the categories are created by
 * ProjectionWriter::upsertCollections(), which writes `is_user_created = false`
 * — the content-lane home of `source_platform = 'uber-eats'`. No
 * manual_overrides rows: nothing here was owner-authored.
 *
 * @param  array<string, list<array{name:string, base_price?:float, description?:string}>>  $categories
 */
function mmcSeedScraped(User $user, array $categories, bool $withOrdering = true): Menu
{
    if ($withOrdering) {
        mmcOrdering($user);
    }

    $menu = Menu::create([
        'user_id' => $user->id, 'content_source' => 'uber-eats',
        'currency' => 'AUD', 'fetch_status' => 'ok', 'last_fetched_at' => now(),
    ]);

    $writer = app(ManualMenuWriter::class);
    $position = 0;
    foreach ($categories as $name => $items) {
        $entry = [['id' => '', 'name' => $name, 'position' => $position++]];
        foreach ($items as $item) {
            $dish = (object) array_merge([
                'name' => '', 'description' => null, 'base_price' => null,
                'pickup_price' => null, 'delivery_price' => null, 'currency' => null,
                'image_url' => null, 'images' => null, 'rating' => null,
                'rating_count' => null, 'badges' => null,
            ], $item);
            $writer->write(
                (string) $user->id,
                MenuProjectionMapper::coordFor((string) $menu->id, (string) $dish->name),
                $writer->projectionFor($dish, $entry, [], $menu),
            );
        }
    }

    return $menu;
}

/** The owner's LIVE dishes, keyed by name. */
function mmcDishes(User $user): Collection
{
    return app(ManualMenuItems::class)->rows((string) $user->id)
        ->keyBy(fn (object $row) => (string) $row->headline);
}

/** Every dish including the removed ones, keyed by name. */
function mmcAllDishes(User $user): Collection
{
    return app(ManualMenuItems::class)->rows((string) $user->id, includeRemoved: true)
        ->keyBy(fn (object $row) => (string) $row->headline);
}

/** The owner's LIVE menu categories, keyed by label. */
function mmcCats(User $user): Collection
{
    return app(MenuCollections::class)->list((string) $user->id)
        ->keyBy(fn (stdClass $row) => (string) $row->label);
}

function mmcCatId(User $user, string $label): string
{
    return (string) mmcCats($user)->get($label)->id;
}

/** The content-lane `is_manual`: does this dish carry any owner-authored column? */
function mmcOverrides(string $itemId): Collection
{
    return collect(DB::connection('pgsql')->table('content.manual_overrides')
        ->where('item_id', $itemId)->get(['facet', 'column_name'])->all());
}

/** The site's build-state counter — lane 1 of the three cache lanes. */
function mmcRevision(User $user): int
{
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('id');

    return (int) (DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision') ?? 0);
}

function mmcRunFetch(User $user, bool $force = false): void
{
    (new MenuFetchJob((string) $user->id, $force))->handle(
        app(MenuSource::class), app(MenuApifyScraper::class), app(MenuMerger::class)
    );
}

// ── The four behaviour homes (Task 6 Step 1) ─────────────────────────────────

it('marks an owner-created category is_user_created and a scraped one not', function () {
    $user = mmcUser('mmh1');
    mmcSeedScraped($user, ['Mains' => [['name' => 'Burger']]]);

    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Specials'])->assertOk();

    $cats = mmcCats($user);
    // Home (3): content.collections has no source column, so the owner-side
    // source_platform values collapse to is_user_created = true and the
    // scraper-owned half to false.
    expect($cats['Specials']->is_user_created)->toBeTrue();
    expect($cats['Mains']->is_user_created)->toBeFalse();
    // Manual categories share the bare `menu:<slug>` key with a scraped row of
    // the same label ON PURPOSE — that shared key is what lets an owner adopt a
    // scraped category and edit it. Only SCAN categories are namespaced.
    expect($cats['Specials']->external_ref)->toBe(MenuProjectionMapper::categoryRef('Specials'));
});

it('records a manual_overrides row when the owner edits a scraped dish', function () {
    $user = mmcUser('mmh2');
    mmcSeedScraped($user, ['Mains' => [['name' => 'Burger', 'base_price' => 12.0]]]);
    $burger = mmcDishes($user)['Burger'];
    // Home (1): a freshly scraped dish carries no owner-authored column.
    expect(mmcOverrides((string) $burger->id))->toHaveCount(0);

    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$burger->id}", ['price' => 15.5])->assertOk();

    $overrides = mmcOverrides((string) $burger->id);
    expect($overrides->pluck('column_name')->all())->toBe(['headline']);
    expect($overrides->first()->facet)->toBe('f_text');
    expect((float) mmcDishes($user)['Burger']->base_price)->toBe(15.5);
});

it('records the description override only when the request sends one', function () {
    $user = mmcUser('mmh3');
    mmcSeedScraped($user, ['Mains' => [['name' => 'Burger', 'description' => 'Vendor copy.']]]);
    $burger = mmcDishes($user)['Burger'];

    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$burger->id}", ['price' => 9.0])->assertOk();
    expect(mmcOverrides((string) $burger->id)->pluck('column_name')->all())->toBe(['headline']);

    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$burger->id}", ['description' => 'Owner copy.'])->assertOk();
    expect(mmcOverrides((string) $burger->id)->pluck('column_name')->sort()->values()->all())
        ->toBe(['body', 'headline']);
    expect(mmcDishes($user)['Burger']->description)->toBe('Owner copy.');
});

it('keeps menus.suppressed_items as the home of a deleted scraped dish', function () {
    $user = mmcUser('mmh4');
    $menu = mmcSeedScraped($user, ['Mains' => [['name' => 'Burger'], ['name' => 'Fries']]]);
    $burger = mmcDishes($user)['Burger'];

    actingAsUser($user)->deleteJson("/api/platforms/menu/items/{$burger->id}")->assertOk();

    // Home (2): unchanged — site.menus survives the teardown and the key format
    // is what MenuFetchJob::suppressedItemKeys() still matches on.
    $menu->refresh();
    expect($menu->suppressed_items)->toBe([['category' => 'Mains', 'name' => 'Burger']]);
});

it('applies EDITABLE_SOURCES to the collection ownership bit', function () {
    $user = mmcUser('mmh5');
    mmcSeedScraped($user, ['Mains' => [['name' => 'Burger']]]);
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Specials'])->assertOk();

    $scraped = mmcCatId($user, 'Mains');
    $owned = mmcCatId($user, 'Specials');

    // Home (4): the const is unchanged; only what it is applied against moved.
    actingAsUser($user)->patchJson("/api/platforms/menu/categories/{$scraped}", ['name' => 'Hacked'])
        ->assertStatus(422)->assertJsonPath('message', "Synced categories can't be renamed.");
    actingAsUser($user)->deleteJson("/api/platforms/menu/categories/{$scraped}")
        ->assertStatus(422)->assertJsonPath('message', "Synced categories can't be deleted.");
    actingAsUser($user)->patchJson("/api/platforms/menu/categories/{$owned}", ['name' => 'Renamed'])->assertOk();

    expect(mmcCats($user)->keys()->sort()->values()->all())->toBe(['Mains', 'Renamed']);
});

// ── Cache lanes (Task 6 Step 7) ──────────────────────────────────────────────
//
// EXACT deltas, never `> 0`: writeManualItem() bumps content_revision itself, so
// a `> 0` assertion on an item write passes with the SiteCacheLanes call
// deleted — the trap slice 3a shipped. The category verbs write no content item
// at all, so their delta is the bust and nothing else.

it('bumps content_revision exactly once for a category write and twice for a dish write', function () {
    $user = mmcUser('mmc1');

    $before = mmcRevision($user);
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Mains'])->assertOk();
    expect(mmcRevision($user) - $before)->toBe(1);

    $before = mmcRevision($user);
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Burger', 'price' => 10.0])->assertOk();
    // 1 from ProjectionWriter::writeManualItem() + 1 from SiteCacheLanes::bust().
    expect(mmcRevision($user) - $before)->toBe(2);

    $itemId = (string) mmcDishes($user)['Burger']->id;

    $before = mmcRevision($user);
    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$itemId}", ['price' => 11.0])->assertOk();
    expect(mmcRevision($user) - $before)->toBe(2);

    // markRemoved() is a raw update that bumps nothing — the delete's whole
    // invalidation IS the bust.
    $before = mmcRevision($user);
    actingAsUser($user)->deleteJson("/api/platforms/menu/items/{$itemId}")->assertOk();
    expect(mmcRevision($user) - $before)->toBe(1);
});

it('bumps content_revision exactly once for each reorder', function () {
    $user = mmcUser('mmc2');
    mmcSeedScraped($user, ['Lunch' => [['name' => 'Roll'], ['name' => 'Bowl']], 'Dinner' => [['name' => 'Braise']]]);
    $lunch = mmcCatId($user, 'Lunch');
    $dishes = mmcDishes($user);

    $before = mmcRevision($user);
    actingAsUser($user)->postJson('/api/platforms/menu/categories/reorder', [
        'ids' => [mmcCatId($user, 'Dinner'), $lunch],
    ])->assertOk();
    expect(mmcRevision($user) - $before)->toBe(1);

    $before = mmcRevision($user);
    actingAsUser($user)->postJson('/api/platforms/menu/items/reorder', [
        'category_id' => $lunch, 'ids' => [(string) $dishes['Roll']->id],
    ])->assertOk();
    expect(mmcRevision($user) - $before)->toBe(1);
});

// ── Category CRUD ─────────────────────────────────────────────────────

it('creates a manual category and returns the full menu shape', function () {
    $user = mmcUser('mm1');

    $res = actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Appetizers'])
        ->assertOk()
        ->assertJsonStructure(['source', 'categories', 'links']);

    // site.menus survives the teardown and is still resolved — the payload gates on it.
    expect(Menu::query()->where('user_id', $user->id)->exists())->toBeTrue();
    $category = mmcCats($user)['Appetizers'];
    expect($category->position)->toBe(0);
    expect($category->is_user_created)->toBeTrue();
    // Nothing landed in the legacy table.
    expect(MenuCategory::query()->count())->toBe(0);

    // The freshly-created category surfaces in the returned menu (own-content, not orphaned).
    $responseCategory = collect($res->json('categories'))->firstWhere('name', 'Appetizers');
    expect($responseCategory)->not->toBeNull();
    expect($responseCategory['id'])->toBe((string) $category->id);
    expect($responseCategory['sourcePlatform'])->toBe('manual');
});

it('positions a second manual category after the first', function () {
    $user = mmcUser('mm1b');
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Starters'])->assertOk();
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Mains'])->assertOk();

    expect(mmcCats($user)->map(fn (stdClass $c) => $c->position)->all())
        ->toBe(['Starters' => 0, 'Mains' => 1]);
});

it('422s a duplicate category name case-insensitively', function () {
    $user = mmcUser('mm2');
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Desserts'])->assertOk();

    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => '  desserts '])
        ->assertStatus(422);

    expect(mmcCats($user))->toHaveCount(1);
});

it('422s an empty category name', function () {
    $user = mmcUser('mm2b');
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => '   '])->assertStatus(422);
});

it('renames a manual category and moves its natural key with the label', function () {
    $user = mmcUser('mm3');
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Old Name'])->assertOk();
    $categoryId = mmcCatId($user, 'Old Name');

    actingAsUser($user)->patchJson("/api/platforms/menu/categories/{$categoryId}", ['name' => 'New Name'])->assertOk();

    $category = mmcCats($user)['New Name'];
    expect((string) $category->id)->toBe($categoryId);
    // external_ref must follow the label or the next scrape's "New Name" upsert
    // misses this row and mints a duplicate.
    expect($category->external_ref)->toBe(MenuProjectionMapper::categoryRef('New Name'));
});

it('renames a scan category', function () {
    $user = mmcUser('mm3b');
    Menu::create(['user_id' => $user->id, 'content_source' => 'scan', 'fetch_status' => 'ok', 'last_fetched_at' => now()]);
    // A scan category is owner-side too (home 3): the three legacy strings
    // 'manual'/'scan'/'website-scan' all collapse to is_user_created = true.
    $id = app(MenuCollections::class)->ensure((string) $user->id, 'Scanned', ownerCreated: true);

    actingAsUser($user)->patchJson("/api/platforms/menu/categories/{$id}", ['name' => 'Renamed Scan'])->assertOk();
    expect(mmcCats($user)->keys()->all())->toBe(['Renamed Scan']);
});

it('422s renaming onto a name another category already holds', function () {
    $user = mmcUser('mm3c');
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Lunch'])->assertOk();
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Dinner'])->assertOk();

    // Forced by the schema, not a taste call: rename re-derives external_ref and
    // collections_user_kind_external_ref_uq would raise 23505 as a 500.
    actingAsUser($user)->patchJson('/api/platforms/menu/categories/'.mmcCatId($user, 'Lunch'), ['name' => 'Dinner'])
        ->assertStatus(422);
    expect(mmcCats($user)->keys()->sort()->values()->all())->toBe(['Dinner', 'Lunch']);
});

it('422s renaming a synced (scraped) category', function () {
    $user = mmcUser('mm4');
    mmcSeedScraped($user, ['Mains' => [['name' => 'Burger', 'base_price' => 12.0]]]);
    $id = mmcCatId($user, 'Mains');

    actingAsUser($user)->patchJson("/api/platforms/menu/categories/{$id}", ['name' => 'Hacked'])
        ->assertStatus(422)
        ->assertJsonPath('message', "Synced categories can't be renamed.");

    expect(mmcCats($user)->keys()->all())->toBe(['Mains']);
});

it('404s renaming a category that is not the callers', function () {
    $owner = mmcUser('mm4owner');
    mmcSeedScraped($owner, ['Mains' => [['name' => 'Burger']]]);
    $id = mmcCatId($owner, 'Mains');
    $other = mmcUser('mm4other');

    actingAsUser($other)->patchJson("/api/platforms/menu/categories/{$id}", ['name' => 'Nope'])->assertStatus(404);
});

// content.collections.id / content.items.id are real Postgres `uuid` columns —
// a malformed id must 404 at the router (whereUuid), not reach the lookup and
// 500 on invalid uuid syntax (22P02). SQLite's TEXT-typed test schema can't
// reproduce that 500, but the router-level whereUuid constraint is DB-agnostic.
it('404s a malformed (non-uuid) category or item id on every route', function () {
    $user = mmcUser('mm4c');

    actingAsUser($user)->patchJson('/api/platforms/menu/categories/not-a-uuid', ['name' => 'X'])->assertStatus(404);
    actingAsUser($user)->deleteJson('/api/platforms/menu/categories/not-a-uuid')->assertStatus(404);
    actingAsUser($user)->patchJson('/api/platforms/menu/items/not-a-uuid', ['name' => 'X'])->assertStatus(404);
    actingAsUser($user)->deleteJson('/api/platforms/menu/items/not-a-uuid')->assertStatus(404);
});

it('deletes a manual category and REMOVES (never hard-deletes) its orphaned items', function () {
    $user = mmcUser('mm5');
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Specials'])->assertOk();
    $catId = mmcCatId($user, 'Specials');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Chef Special', 'category_id' => $catId])->assertOk();
    $itemId = (string) mmcDishes($user)['Chef Special']->id;

    actingAsUser($user)->deleteJson("/api/platforms/menu/categories/{$catId}")->assertOk();

    expect(mmcCats($user))->toHaveCount(0);
    expect(mmcDishes($user))->toHaveCount(0);
    // markRemoved(), NOT a hard delete: MenuPayloadComposer's legacy fallback
    // fires when the content lane holds nothing at all for the owner — removed
    // rows included — so a hard delete would drop an emptied menu back to the
    // legacy tables and resurrect every dish the owner just deleted.
    expect(DB::connection('pgsql')->table('content.items')->where('id', $itemId)->whereNotNull('removed_at')->exists())
        ->toBeTrue();
});

// The dish's public URL slug lives in content.item_slugs now (slice 4 re-homed
// MenuItemObserver's duty onto ContentItemSlugAllocator::SLUGGED_KINDS).
// idx_item_slugs_unique (user_id, slug) is NOT partial, so a removed dish that
// keeps its slug squats that URL forever — markRemoved() frees it.
it('frees an orphaned dish\'s content.item_slugs row when its only category is deleted', function () {
    $user = mmcUser('mm5c');
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Solo Cat'])->assertOk();
    $catId = mmcCatId($user, 'Solo Cat');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Orphan Dish', 'category_id' => $catId])->assertOk();
    $itemId = (string) mmcDishes($user)['Orphan Dish']->id;

    expect(DB::connection('pgsql')->table('content.item_slugs')
        ->where('item_id', $itemId)->where('is_current', true)->exists())->toBeTrue();

    actingAsUser($user)->deleteJson("/api/platforms/menu/categories/{$catId}")->assertOk();

    expect(mmcDishes($user))->toHaveCount(0);
    expect(DB::connection('pgsql')->table('content.item_slugs')
        ->where('item_id', $itemId)->where('is_current', true)->exists())->toBeFalse();
});

// Control: a dish that also belongs to a category NOT being deleted is not an
// orphan — it survives deleteCategory's cleanup, and so must its slug row.
it('keeps a surviving dish\'s slug when only one of its categories is deleted', function () {
    $user = mmcUser('mm5d');
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Cat A'])->assertOk();
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Cat B'])->assertOk();

    actingAsUser($user)->postJson('/api/platforms/menu/items', [
        'name' => 'Shared Dish', 'category_ids' => [mmcCatId($user, 'Cat A'), mmcCatId($user, 'Cat B')],
    ])->assertOk();
    $itemId = (string) mmcDishes($user)['Shared Dish']->id;

    actingAsUser($user)->deleteJson('/api/platforms/menu/categories/'.mmcCatId($user, 'Cat A'))->assertOk();

    expect(mmcDishes($user)->has('Shared Dish'))->toBeTrue();
    expect(DB::connection('pgsql')->table('content.item_slugs')
        ->where('item_id', $itemId)->where('is_current', true)->exists())->toBeTrue();
});

it('does not suppress an owner-authored orphan when its only category is deleted', function () {
    $user = mmcUser('mm5e');
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Manual Cat'])->assertOk();
    $catId = mmcCatId($user, 'Manual Cat');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Manual Orphan', 'category_id' => $catId])->assertOk();

    actingAsUser($user)->deleteJson("/api/platforms/menu/categories/{$catId}")->assertOk();

    expect(mmcDishes($user))->toHaveCount(0);
    // Owner-authored (it carries manual_overrides) → never suppressed.
    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    expect($menu->suppressed_items)->toBeNull();
});

it('suppresses a scraped orphan when the category holding it is deleted', function () {
    $user = mmcUser('mm5f');
    $menu = mmcSeedScraped($user, ['Mains' => [['name' => 'Burger']]]);
    // Hand the scraped category to the owner so it is deletable at all.
    app(MenuCollections::class)->ensure((string) $user->id, 'Mains', ownerCreated: true);

    actingAsUser($user)->deleteJson('/api/platforms/menu/categories/'.mmcCatId($user, 'Mains'))->assertOk();

    $menu->refresh();
    expect($menu->suppressed_items)->toBe([['category' => 'Mains', 'name' => 'Burger']]);
});

it('422s deleting a synced (scraped) category', function () {
    $user = mmcUser('mm5b');
    mmcSeedScraped($user, ['Mains' => [['name' => 'Burger']]]);

    actingAsUser($user)->deleteJson('/api/platforms/menu/categories/'.mmcCatId($user, 'Mains'))->assertStatus(422);
    expect(mmcCats($user)->has('Mains'))->toBeTrue();
});

// ── Item create ───────────────────────────────────────────────────────

it('creates a manual item in a supplied category', function () {
    $user = mmcUser('mm6');
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Mains'])->assertOk();
    $catId = mmcCatId($user, 'Mains');

    actingAsUser($user)->postJson('/api/platforms/menu/items', [
        'name' => 'Ribeye', 'description' => '300g, grass fed.', 'price' => 42.0, 'category_id' => $catId,
    ])->assertOk();

    $item = mmcDishes($user)['Ribeye'];
    expect($item->description)->toBe('300g, grass fed.');
    expect((float) $item->base_price)->toBe(42.0);
    expect(array_map('strval', $item->category_ids))->toBe([$catId]);
    // Owner-authored → carries the marker (home 1).
    expect(mmcOverrides((string) $item->id)->pluck('column_name')->sort()->values()->all())
        ->toBe(['body', 'headline']);
    // Landed under the fixed slice-4 coord, never a second derivation.
    expect((string) $item->coord)->toBe(MenuProjectionMapper::coordFor(
        (string) Menu::query()->where('user_id', $user->id)->value('id'), 'Ribeye'
    ));
    expect(MenuItem::query()->count())->toBe(0);
});

it('pins each new dish at the end of the owner arrangement', function () {
    $user = mmcUser('mm6b');
    foreach (['Zucchini', 'Apple', 'Mango'] as $name) {
        actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => $name])->assertOk();
    }

    // Dish order is the pool:menus PINS, not rows()' alphabetical fallback —
    // so the payload reports creation order, not A/M/Z.
    $res = actingAsUser($user)->getJson('/api/platforms/menu')->assertOk();
    expect(collect($res->json('categories.0.items'))->pluck('name')->all())
        ->toBe(['Zucchini', 'Apple', 'Mango']);
});

it('find-or-creates a manual "Menu" category when no category is supplied', function () {
    $user = mmcUser('mm7');

    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Mystery Dish'])->assertOk();
    // A second uncategorised item reuses the same default category, not a new one.
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Another Dish'])->assertOk();

    $categories = mmcCats($user);
    expect($categories)->toHaveCount(1);
    expect($categories->keys()->all())->toBe(['Menu']);
    expect($categories['Menu']->is_user_created)->toBeTrue();
    expect(app(MenuCollections::class)->memberIds((string) $user->id, (string) $categories['Menu']->id))
        ->toHaveCount(2);
});

it('resolves image_media_id to the optimized variant url and sets images', function () {
    $user = mmcUser('mm8');
    $media = mmcMedia($user);
    $expectedUrl = $media->variantUrls()['optimized'];
    expect($expectedUrl)->not->toBeEmpty();

    actingAsUser($user)->postJson('/api/platforms/menu/items', [
        'name' => 'Photogenic Toast', 'image_media_id' => $media->id,
    ])->assertOk();

    $item = mmcDishes($user)['Photogenic Toast'];
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
    mmcSeedScraped($owner, ['Mains' => [['name' => 'Burger']]]);
    $foreignCat = mmcCatId($owner, 'Mains');
    $other = mmcUser('mm9other');

    actingAsUser($other)->postJson('/api/platforms/menu/items', ['name' => 'X', 'category_id' => $foreignCat])
        ->assertStatus(404);
});

it('422s an item price above the sane bound', function () {
    $user = mmcUser('mm9b');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Overpriced', 'price' => 100001])
        ->assertStatus(422);
});

it('brings a previously deleted dish back when the owner re-adds it by name', function () {
    $user = mmcUser('mm9c');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Iced Latte', 'price' => 5.0])->assertOk();
    $itemId = (string) mmcDishes($user)['Iced Latte']->id;
    actingAsUser($user)->deleteJson("/api/platforms/menu/items/{$itemId}")->assertOk();
    expect(mmcDishes($user))->toHaveCount(0);

    // The coord is name-derived, so this is the SAME item — items.removed_at is
    // one-way against a scrape, not against its owner re-adding it by hand.
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Iced Latte', 'price' => 6.0])->assertOk();

    $live = mmcDishes($user);
    expect($live)->toHaveCount(1);
    expect((string) $live['Iced Latte']->id)->toBe($itemId);
    expect((float) $live['Iced Latte']->base_price)->toBe(6.0);
    expect(DB::connection('pgsql')->table('content.item_slugs')
        ->where('item_id', $itemId)->where('is_current', true)->exists())->toBeTrue();
});

// ── Item edit / delete of scraped content ─────────────────────────────

it('detaches a scraped item from sync when it is edited', function () {
    $user = mmcUser('mm10');
    mmcSeedScraped($user, ['Mains' => [['name' => 'Burger', 'base_price' => 12.0]]]);
    $item = mmcDishes($user)['Burger'];
    expect(mmcOverrides((string) $item->id))->toHaveCount(0);

    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$item->id}", ['price' => 15.5])->assertOk();

    $fresh = mmcDishes($user)['Burger'];
    expect((float) $fresh->base_price)->toBe(15.5);
    expect(mmcOverrides((string) $fresh->id))->not->toHaveCount(0);
});

it('keeps the owner marker when an already-manual item is edited', function () {
    $user = mmcUser('mm10b');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Handmade', 'price' => 10.0])->assertOk();
    $item = mmcDishes($user)['Handmade'];

    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$item->id}", ['description' => 'Now with a description.'])->assertOk();

    $fresh = mmcDishes($user)['Handmade'];
    expect($fresh->description)->toBe('Now with a description.');
    expect((float) $fresh->base_price)->toBe(10.0);
    expect(mmcOverrides((string) $fresh->id))->not->toHaveCount(0);
});

it('renames a dish in place rather than minting a second one', function () {
    $user = mmcUser('mm10e');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Old Dish', 'price' => 8.0])->assertOk();
    $itemId = (string) mmcDishes($user)['Old Dish']->id;

    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$itemId}", ['name' => 'New Dish'])->assertOk();

    $live = mmcDishes($user);
    expect($live)->toHaveCount(1);
    // The dish keeps its id (and therefore its dashboard/detail URLs): the write
    // re-uses the STORED coord, it does not re-derive one from the new name.
    expect((string) $live['New Dish']->id)->toBe($itemId);
});

it('clears a description when the request sends an explicit null', function () {
    $user = mmcUser('mm10f');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Wordy', 'description' => 'Lots of words.'])->assertOk();
    $itemId = (string) mmcDishes($user)['Wordy']->id;

    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$itemId}", ['description' => null])->assertOk();

    // the singleton-facet write path only writes the columns its input carries, and the
    // mapper omits f_text entirely for a null description — so the clear has to
    // be issued explicitly or the old body silently survives.
    expect(mmcDishes($user)['Wordy']->description)->toBeNull();
});

it('moves an item to another category and clears its image on remove_image', function () {
    $user = mmcUser('mm10c');
    $media = mmcMedia($user);
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'A'])->assertOk();
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'B'])->assertOk();
    $catA = mmcCatId($user, 'A');
    $catB = mmcCatId($user, 'B');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Mover', 'category_id' => $catA, 'image_media_id' => $media->id])->assertOk();
    $item = mmcDishes($user)['Mover'];
    expect($item->image_url)->not->toBeNull();

    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$item->id}", ['category_id' => $catB, 'remove_image' => true])->assertOk();

    // The legacy single category_id REPLACES the membership set — the item now
    // lives only in B.
    $fresh = mmcDishes($user)['Mover'];
    expect(array_map('strval', $fresh->category_ids))->toBe([$catB]);
    expect($fresh->image_url)->toBeNull();
    expect($fresh->images)->toBe([]);
});

it('sets multiple category memberships via category_ids on create and update', function () {
    $user = mmcUser('mm10d');
    foreach (['Lunch', 'Dinner', 'Specials'] as $name) {
        actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => $name])->assertOk();
    }
    $ids = mmcCats($user)->map(fn (stdClass $c) => (string) $c->id);

    // Create in two categories at once.
    actingAsUser($user)->postJson('/api/platforms/menu/items', [
        'name' => 'Garlic Bread', 'price' => 12.0,
        'category_ids' => [$ids['Lunch'], $ids['Dinner']],
    ])->assertOk();
    $item = mmcDishes($user)['Garlic Bread'];
    expect(collect($item->category_labels)->values()->sort()->values()->all())->toBe(['Dinner', 'Lunch']);

    // Replace the set: drop Lunch, keep Dinner, add Specials.
    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$item->id}", [
        'category_ids' => [$ids['Dinner'], $ids['Specials']],
    ])->assertOk();
    expect(collect(mmcDishes($user)['Garlic Bread']->category_labels)->values()->sort()->values()->all())
        ->toBe(['Dinner', 'Specials']);

    // A category from someone else's menu 404s the whole write (all-or-nothing).
    $other = mmcUser('mm10dother');
    mmcSeedScraped($other, ['Mains' => [['name' => 'Foreign Dish']]]);
    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$item->id}", [
        'category_ids' => [$ids['Dinner'], mmcCatId($other, 'Mains')],
    ])->assertStatus(404);
    expect(collect(mmcDishes($user)['Garlic Bread']->category_labels)->values()->sort()->values()->all())
        ->toBe(['Dinner', 'Specials']);
});

it('preserves a scraped dish\'s ordering-platform links across an owner edit', function () {
    $user = mmcUser('mm10g');
    $menu = mmcSeedScraped($user, ['Mains' => []]);
    $writer = app(ManualMenuWriter::class);
    $dish = (object) [
        'name' => 'Burger', 'description' => null, 'base_price' => 12.0,
        'pickup_price' => 12.0, 'delivery_price' => 13.0, 'currency' => 'AUD',
        'image_url' => null, 'images' => null, 'rating' => 4.5,
        'rating_count' => 20, 'badges' => [['text' => 'Most liked']],
    ];
    $platforms = [(object) [
        'platform' => 'uber-eats', 'pickup_price' => 12.0, 'pickup_url' => 'https://www.ubereats.com/x',
        'delivery_price' => 13.0, 'delivery_url' => 'https://www.ubereats.com/x',
    ]];
    $itemId = $writer->write((string) $user->id, MenuProjectionMapper::coordFor((string) $menu->id, 'Burger'),
        $writer->projectionFor($dish, [['id' => '', 'name' => 'Mains', 'position' => 0]], $platforms, $menu));

    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$itemId}", ['price' => 14.0])->assertOk();

    // The projection REPLACES offers/tags/media/collections per source, so
    // anything the PATCH did not mention has to travel back through the mapper.
    $fresh = mmcDishes($user)['Burger'];
    expect((float) $fresh->base_price)->toBe(14.0);
    expect(collect($fresh->platforms)->pluck('platform')->all())->toBe(['uber-eats']);
    expect((float) $fresh->pickup_price)->toBe(12.0);
    expect($fresh->badges)->toBe([['text' => 'Most liked']]);
    expect((float) $fresh->rating)->toBe(4.5);
});

it('bulk-deletes items in one call, suppressing the scraped ones', function () {
    $user = mmcUser('mm11b');
    $menu = mmcSeedScraped($user, ['Mains' => [['name' => 'Burger'], ['name' => 'Fries']]]);
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Handmade'])->assertOk();

    $ids = mmcDishes($user)->map(fn (object $row) => (string) $row->id)->values()->all();
    actingAsUser($user)->postJson('/api/platforms/menu/items/bulk-delete', ['ids' => $ids])->assertOk();

    expect(mmcDishes($user))->toHaveCount(0);
    // Scraped dishes suppressed; the owner-authored one just removed.
    $menu->refresh();
    expect(collect($menu->suppressed_items)->pluck('name')->sort()->values()->all())->toBe(['Burger', 'Fries']);

    // Unknown ids are skipped, not errors.
    actingAsUser($user)->postJson('/api/platforms/menu/items/bulk-delete', ['ids' => [(string) Str::uuid()]])->assertOk();
});

it('removes a manual item without suppressing it', function () {
    $user = mmcUser('mm11');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Temporary'])->assertOk();
    $itemId = (string) mmcDishes($user)['Temporary']->id;

    actingAsUser($user)->deleteJson("/api/platforms/menu/items/{$itemId}")->assertOk();

    expect(mmcDishes($user))->toHaveCount(0);
    expect(mmcAllDishes($user))->toHaveCount(1);
    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    expect($menu->suppressed_items)->toBeNull(); // owner-authored delete never suppresses
});

it('deletes a scraped item and records it as suppressed', function () {
    $user = mmcUser('mm12');
    $menu = mmcSeedScraped($user, ['Mains' => [['name' => 'Burger'], ['name' => 'Fries']]]);
    $burger = mmcDishes($user)['Burger'];

    actingAsUser($user)->deleteJson("/api/platforms/menu/items/{$burger->id}")->assertOk();

    expect(mmcDishes($user)->has('Burger'))->toBeFalse();
    $menu->refresh();
    expect($menu->suppressed_items)->toBe([['category' => 'Mains', 'name' => 'Burger']]);
});

it('does not duplicate a suppression entry already recorded for the dish', function () {
    $user = mmcUser('mm12b');
    $menu = mmcSeedScraped($user, ['Mains' => [['name' => 'Burger']]]);
    // The entry is already there (an earlier delete, or a scan reapply that
    // recorded it) — the dedupe is on the NORMALISED pair, so a differently
    // cased/punctuated spelling must still match.
    $menu->forceFill(['suppressed_items' => [['category' => 'mains!', 'name' => '  BURGER ']]])->save();

    actingAsUser($user)->deleteJson('/api/platforms/menu/items/'.mmcDishes($user)['Burger']->id)->assertOk();

    $menu->refresh();
    expect($menu->suppressed_items)->toBe([['category' => 'mains!', 'name' => '  BURGER ']]);
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
    expect(mmcCats($user))->toHaveCount(0);
});

// ── MenuFetchJob's rebuild rules ─────────────────────────────────────────────
//
// These exercise MenuFetchJob::persist(), which still writes site.menu_* — Task
// 7 moves it. Task 6 moved the ten owner VERBS onto content.*, so the owner side
// of these scenarios is seeded directly in the legacy tables (and, for
// suppression, straight into menus.suppressed_items — the one signal that is
// UNCHANGED and still couples the two lanes). Task 7 re-couples the rest and
// should re-drive these through the API.

// Slice 7 Task 7 split the two lanes: the SCRAPE writes content.*, the 10 owner
// verbs still write site.menu_* until Task 6 moves them. So the cross-lane half
// of this test — a manual dish shadowing a same-named scraped one — cannot be
// expressed right now and comes back with Task 6, which owns both sides.
//
// What survives here is the contract MenuFetchJob actually reads:
// `menus.suppressed_items` is the ONE owner signal the scrape honours, and it is
// a WRITE skip (the removal itself is deleteItem()'s markRemoved(), Task 6).
// Owner-lane rows are untouched by the rebuild either way.
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
    // Run 2 re-offers Cola at a new price; suppression must skip it. Burger,
    // Fries and Water rebuild normally.
    $run2 = ['uber-eats' => [
        'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Mains', 'items' => [
                ['name' => 'Burger', 'pickupPrice' => 13.0, 'deliveryPrice' => 13.0],
                ['name' => 'Fries', 'pickupPrice' => 6.0, 'deliveryPrice' => 6.0],
            ]],
            ['name' => 'Drinks', 'items' => [
                ['name' => 'Cola', 'pickupPrice' => 99.0, 'deliveryPrice' => 99.0],
                ['name' => 'Water', 'pickupPrice' => 2.5, 'deliveryPrice' => 2.5],
            ]],
        ],
    ]];
    $this->mock(MenuApifyScraper::class, function ($m) use ($run1, $run2) {
        $m->shouldReceive('fetchStores')->twice()->andReturn($run1, $run2);
    });

    mmcRunFetch($user);
    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();

    // Owner edits, driven through the verbs — which write content.* since
    // Task 6, so the category id comes back from the content lane too.
    actingAsUser($user)->postJson('/api/platforms/menu/categories', ['name' => 'Specials'])->assertOk();
    $specialsId = mmcCatId($user, 'Specials');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Chef Special', 'price' => 30.0, 'category_id' => $specialsId])->assertOk();

    // Seeded directly rather than driven through deleteItem(): this IS the
    // record that verb writes, and menus.suppressed_items is the only input
    // MenuFetchJob reads. Seeding it keeps the scraped Cola LIVE, so the
    // write-skip below is asserted on its price rather than its absence.
    $menu->forceFill(['suppressed_items' => [['category' => 'Drinks', 'name' => 'Cola']]])->save();

    mmcRunFetch($user, force: true);

    // Manual category + its manual dish survive untouched.
    expect(mmcCats($user)->has('Specials'))->toBeTrue();
    $chefSpecial = mmcDishes($user)->get('Chef Special');
    expect($chefSpecial)->not->toBeNull();
    expect((float) $chefSpecial->base_price)->toBe(30.0);
    expect($chefSpecial->is_manual)->toBeTrue();

    $dishes = mmcDishes($user);

    // Suppressed Cola was NOT re-written — its price is still run 1's.
    expect($dishes['Cola']->base_price)->toBe(3.0);

    // Ordinary scraped dishes rebuilt fresh at their new prices.
    expect($dishes['Burger']->base_price)->toBe(13.0);
    expect($dishes['Fries']->base_price)->toBe(6.0);
    expect($dishes['Water']->base_price)->toBe(2.5);
});

// DATA-LOSS REGRESSION. An owner edit records content.manual_overrides rows
// (MenuContentController::recordOwnerEdits) — the content-lane `is_manual`.
// MenuFetchJob honours that on the RETIREMENT side: a dish the vendor drops is
// not marked removed if the owner has edited it, because items.removed_at is
// one-way and would silently bin the owner's work.
//
// The edit here is a RENAME on purpose. It splits the dish's two identities —
// headline_cache becomes the owner's name while the coord still hashes the
// vendor's — so this only passes if the exemption is keyed on the coord.
//
// Fries is the control: untouched by the owner, dropped by the same scrape, and
// it MUST still retire. Without it this test would pass on a job that had
// stopped retiring anything at all.
it('keeps an owner-edited dish the vendor dropped, and still retires an untouched one', function () {
    $user = mmcUser('mm16');
    mmcOrdering($user);

    $run1 = ['uber-eats' => [
        'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
        'categories' => [['name' => 'Mains', 'items' => [
            ['name' => 'Burger', 'pickupPrice' => 12.0, 'deliveryPrice' => 12.0],
            ['name' => 'Fries', 'pickupPrice' => 5.0, 'deliveryPrice' => 5.0],
        ]]],
    ]];
    // Run 2 drops BOTH dishes and offers an unrelated one.
    $run2 = ['uber-eats' => [
        'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
        'categories' => [['name' => 'Mains', 'items' => [
            ['name' => 'Soup', 'pickupPrice' => 9.0, 'deliveryPrice' => 9.0],
        ]]],
    ]];
    $this->mock(MenuApifyScraper::class, function ($m) use ($run1, $run2) {
        $m->shouldReceive('fetchStores')->twice()->andReturn($run1, $run2);
    });

    mmcRunFetch($user);
    $burgerId = (string) mmcDishes($user)['Burger']->id;

    // The owner edit, through the real verb — so this breaks if
    // recordOwnerEdits() ever stops firing, not just if the job regresses.
    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$burgerId}", [
        'name' => 'House Burger', 'price' => 18.0,
    ])->assertOk();
    expect(mmcOverrides($burgerId))->not->toHaveCount(0);

    mmcRunFetch($user, force: true);

    // The edited dish survived the vendor dropping it, at the owner's values.
    $live = mmcDishes($user);
    expect($live->has('House Burger'))->toBeTrue();
    expect($live['House Burger']->removed_at)->toBeNull();
    expect((float) $live['House Burger']->base_price)->toBe(18.0);
    expect($live['House Burger']->is_manual)->toBeTrue();
    // Same item, not a second one minted alongside it.
    expect((string) $live['House Burger']->id)->toBe($burgerId);

    // Control: the untouched dish the same scrape dropped DID retire.
    expect($live->has('Fries'))->toBeFalse();
    expect(mmcAllDishes($user)['Fries']->removed_at)->not->toBeNull();

    // And the rebuild itself ran normally.
    expect($live->has('Soup'))->toBeTrue();
});

// DATA-LOSS REGRESSION, the WRITE half of the one above. There the vendor
// dropped the edited dish; here it still lists it, so the scrape reaches the
// write and would re-project the vendor's values straight over the owner's.
//
// WHOLE-DISH lock (owner ruling): any override detaches the dish from platform
// sync entirely, restoring the legacy `is_manual` behaviour. So the frozen
// description below is the point, not a side effect — the vendor's new copy is
// refused on a column the owner never touched, because the DISH is locked, not
// the column.
//
// Again a RENAME, so the dish's two identities disagree (headline_cache holds
// 'House Burger', the coord still hashes 'Burger') and only a coord-keyed skip
// passes. Fries is the control: same scrape, no owner edit, MUST take the
// vendor's new price — otherwise this would pass on a job that had stopped
// writing altogether.
it('does not re-project the vendor over an owner-edited dish the vendor still lists', function () {
    $user = mmcUser('mm16b');
    mmcOrdering($user);

    $run1 = ['uber-eats' => [
        'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
        'categories' => [['name' => 'Mains', 'items' => [
            ['name' => 'Burger', 'description' => 'Vendor copy, run one.', 'pickupPrice' => 12.0, 'deliveryPrice' => 12.0],
            ['name' => 'Fries', 'pickupPrice' => 5.0, 'deliveryPrice' => 5.0],
        ]]],
    ]];
    // Run 2 keeps both dishes and re-prices them.
    $run2 = ['uber-eats' => [
        'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
        'categories' => [['name' => 'Mains', 'items' => [
            ['name' => 'Burger', 'description' => 'Vendor copy, run two.', 'pickupPrice' => 25.0, 'deliveryPrice' => 25.0],
            ['name' => 'Fries', 'pickupPrice' => 7.0, 'deliveryPrice' => 7.0],
        ]]],
    ]];
    $this->mock(MenuApifyScraper::class, function ($m) use ($run1, $run2) {
        $m->shouldReceive('fetchStores')->twice()->andReturn($run1, $run2);
    });

    mmcRunFetch($user);
    $burgerId = (string) mmcDishes($user)['Burger']->id;

    // Through the real verb, so this breaks if recordOwnerEdits() stops firing.
    // Name + price only — the description is deliberately NOT sent, so it
    // carries no override row of its own.
    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$burgerId}", [
        'name' => 'House Burger', 'price' => 18.0,
    ])->assertOk();
    expect(mmcOverrides($burgerId))->not->toHaveCount(0);

    mmcRunFetch($user, force: true);

    $live = mmcDishes($user);
    // The owner's name and price survived a scrape that offered its own.
    expect($live->has('House Burger'))->toBeTrue();
    expect($live->has('Burger'))->toBeFalse();
    expect((string) $live['House Burger']->id)->toBe($burgerId);
    expect((float) $live['House Burger']->base_price)->toBe(18.0);
    // Whole-dish, not per-column: an untouched column stayed on run 1's value
    // rather than following the vendor to run 2's.
    expect($live['House Burger']->description)->toBe('Vendor copy, run one.');
    // And the skip did not strand its coord: absentDishIds() saw the vendor
    // still listing it, so it is live, not retired.
    expect($live['House Burger']->removed_at)->toBeNull();

    // Control: the untouched dish took the vendor's new price, so the scrape
    // really did write this run.
    expect((float) $live['Fries']->base_price)->toBe(7.0);
});

// THE PRICE EDIT, which has no override column of its own to hide behind.
// content.offers is a SET resolved by union, so FacetRegistry admits no offer
// override — a price-only PATCH can never freeze "price". What it does leave is
// the f_text/headline row recordOwnerEdits() writes on EVERY owner write, and
// the WHOLE-DISH lock is what turns that one row into a price that sticks. This
// pins the pairing: weaken recordOwnerEdits() to only write when a name is sent,
// or narrow the lock to the overridden columns, and the price reverts again.
it('keeps an owner price-only edit across a scrape that re-prices the dish', function () {
    $user = mmcUser('mm16c');
    mmcOrdering($user);

    $run1 = ['uber-eats' => [
        'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
        'categories' => [['name' => 'Mains', 'items' => [
            ['name' => 'Burger', 'pickupPrice' => 12.0, 'deliveryPrice' => 12.0],
            ['name' => 'Fries', 'pickupPrice' => 5.0, 'deliveryPrice' => 5.0],
        ]]],
    ]];
    $run2 = ['uber-eats' => [
        'store' => ['name' => 'Ollies', 'currency' => 'AUD'],
        'categories' => [['name' => 'Mains', 'items' => [
            ['name' => 'Burger', 'pickupPrice' => 25.0, 'deliveryPrice' => 25.0],
            ['name' => 'Fries', 'pickupPrice' => 7.0, 'deliveryPrice' => 7.0],
        ]]],
    ]];
    $this->mock(MenuApifyScraper::class, function ($m) use ($run1, $run2) {
        $m->shouldReceive('fetchStores')->twice()->andReturn($run1, $run2);
    });

    mmcRunFetch($user);
    $burgerId = (string) mmcDishes($user)['Burger']->id;

    // Price ONLY — no name, no description. The dish keeps the vendor's name,
    // so its coord and its headline still agree; nothing here is a rename.
    actingAsUser($user)->patchJson("/api/platforms/menu/items/{$burgerId}", ['price' => 18.0])->assertOk();
    expect(mmcOverrides($burgerId)->pluck('column_name')->all())->toContain('headline');

    mmcRunFetch($user, force: true);

    $live = mmcDishes($user);
    expect((float) $live['Burger']->base_price)->toBe(18.0);
    expect($live['Burger']->removed_at)->toBeNull();
    expect((string) $live['Burger']->id)->toBe($burgerId);
    // Control: the scrape still re-priced the dish nobody touched.
    expect((float) $live['Fries']->base_price)->toBe(7.0);
});

it('preserves a manual dish when the last ordering link is removed (clearScrapedContent)', function () {
    $user = mmcUser('mm15');

    // Phase 6: this used to seed site.menu_* and assert those rows survived.
    // The tables are gone and hasOwnerContent() asks content.* only, so the
    // same question — "does anything owner-authored survive, and is the menu
    // therefore kept alive?" — is now asked of the one remaining lane.
    $menu = mmcSeedScraped(
        $user,
        ['Mains' => [['name' => 'Handmade Dish', 'base_price' => 20.0]]],
        withOrdering: false,
    );

    // No ordering source resolvable → clearScrapedContent path.
    $this->mock(MenuApifyScraper::class, fn ($m) => $m->shouldReceive('fetchStores')->never());
    mmcRunFetch($user);

    // The dish survives and the menu row is kept alive rather than soft-deleted.
    expect(mmcDishes($user)->has('Handmade Dish'))->toBeTrue();
    $menu->refresh();
    expect($menu->trashed())->toBeFalse();
    expect($menu->content_source)->toBe('manual');
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

    // The owner's delete of the scraped Cola, in the shape the verb leaves
    // behind for the fetch job: the legacy row gone and the suppression
    // recorded on site.menus (home 2 — unchanged by Task 6).
    MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Cola')->delete();
    // Owner deletes the scraped Cola → suppressed. Seeded directly rather than
    // driven through deleteItem(): the record is identical either way, and
    // menus.suppressed_items is the only input the reapply filter reads. Seeding
    // it leaves the dish LIVE, so the skip is asserted on its price below.
    $menu->forceFill(['suppressed_items' => [['category' => 'Drinks', 'name' => 'Cola']]])->save();

    // Forced rebuild: persist() skips the scraped Cola (suppression), and the
    // automatic scan reapply must not re-add it from scan_items either.
    mmcRunFetch($user, force: true);

    expect(MenuItem::query()->where('menu_id', $menu->id)->where('name', 'Cola')->exists())->toBeFalse();
    // ...and the scrape skipped re-writing it, so its price is still run 1's.
    expect(mmcDishes($user)['Cola']->base_price)->toBe(3.0);

    // The reapply itself still ran — the non-suppressed scan-only dish landed
    // under a scan category — proving only the suppressed dish was dropped.
    // Since slice 7 Task 8 the reapply writes content.*, so the scan category
    // is a content.collections row in the `menu:scan:*` ref namespace.
    $items = app(ManualMenuItems::class);
    $lemonade = $items->rows((string) $user->id)->firstWhere('headline', 'Lemonade');
    expect($lemonade)->not->toBeNull();
    $categories = $items->categories((string) $user->id)->keyBy('id');
    expect((string) $categories[$lemonade->category_ids[0]]->external_ref)
        ->toBe(MenuScanApplier::categoryRefFor('scan', 'Beverages'));

    // The suppression record survives for future rebuilds.
    $menu->refresh();
    expect($menu->suppressed_items)->toBe([['category' => 'Drinks', 'name' => 'Cola']]);
});

it('serves a manual-only menu on status and show (owner content is never orphaned)', function () {
    $user = mmcUser('mm16');
    actingAsUser($user)->postJson('/api/platforms/menu/items', ['name' => 'Solo Manual', 'price' => 9.0])->assertOk();

    // Both signals now ask the content lane: an owner-built menu leaves
    // site.menu_categories/menu_items empty, and the legacy-only questions
    // would report it as an orphan with zero dishes.
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
});

// ── Reorder (categories + items within a category) ───────────────────────────

it('reorders categories, unlisted ones trailing', function () {
    $user = mmcUser('mreo1');
    mmcSeedScraped($user, [
        'Breakfast' => [['name' => 'Avo']],
        'Lunch' => [['name' => 'Roll']],
        'Dinner' => [['name' => 'Braise']],
    ]);
    $cats = mmcCats($user);

    // Move Dinner first, list only it and Breakfast — Lunch trails.
    actingAsUser($user)->postJson('/api/platforms/menu/categories/reorder', [
        'ids' => [(string) $cats['Dinner']->id, (string) $cats['Breakfast']->id],
    ])->assertOk();

    expect(mmcCats($user)->keys()->all())->toBe(['Dinner', 'Breakfast', 'Lunch']);
});

it('reorders items within one category by permuting their pins', function () {
    $user = mmcUser('mreo2');
    mmcSeedScraped($user, [
        'Lunch' => [['name' => 'Roll'], ['name' => 'Bowl'], ['name' => 'Pie']],
    ]);
    $lunch = mmcCatId($user, 'Lunch');
    $dishes = mmcDishes($user);

    actingAsUser($user)->postJson('/api/platforms/menu/items/reorder', [
        'category_id' => $lunch,
        'ids' => [(string) $dishes['Pie']->id, (string) $dishes['Roll']->id],
    ])->assertOk();

    $res = actingAsUser($user)->getJson('/api/platforms/menu')->assertOk();
    $category = collect($res->json('categories'))->firstWhere('name', 'Lunch');
    expect(collect($category['items'])->pluck('name')->all())->toBe(['Pie', 'Roll', 'Bowl']);
});

it('leaves another category\'s dish order untouched when one category is reordered', function () {
    $user = mmcUser('mreo2b');
    mmcSeedScraped($user, [
        'Lunch' => [['name' => 'Roll'], ['name' => 'Bowl']],
        'Dinner' => [['name' => 'Braise'], ['name' => 'Ale']],
    ]);
    $dishes = mmcDishes($user);

    $before = collect(actingAsUser($user)->getJson('/api/platforms/menu')->json('categories'))
        ->firstWhere('name', 'Dinner');

    actingAsUser($user)->postJson('/api/platforms/menu/items/reorder', [
        'category_id' => mmcCatId($user, 'Lunch'),
        'ids' => [(string) $dishes['Roll']->id, (string) $dishes['Bowl']->id],
    ])->assertOk();

    // sort_key is ONE global scale per site, so a dense 0..n-1 rewrite here
    // would reshuffle every other category against these dishes.
    $after = collect(actingAsUser($user)->getJson('/api/platforms/menu')->json('categories'))
        ->firstWhere('name', 'Dinner');
    expect(collect($after['items'])->pluck('name')->all())
        ->toBe(collect($before['items'])->pluck('name')->all());
});

it('404s reordering items with an id outside the category', function () {
    $user = mmcUser('mreo3');
    mmcSeedScraped($user, [
        'Lunch' => [['name' => 'Roll']],
        'Dinner' => [['name' => 'Braise']],
    ]);

    actingAsUser($user)->postJson('/api/platforms/menu/items/reorder', [
        'category_id' => mmcCatId($user, 'Lunch'),
        'ids' => [(string) mmcDishes($user)['Braise']->id],
    ])->assertStatus(404);
});

it('403s menu reorders without the menu capability', function () {
    $user = mmcUser('mreo4', 'partna', 'creator');

    actingAsUser($user)->postJson('/api/platforms/menu/categories/reorder', ['ids' => []])
        ->assertStatus(403);
});
