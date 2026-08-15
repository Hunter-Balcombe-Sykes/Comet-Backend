<?php

use App\Models\Core\Site\Site;
use App\Services\Migration\MenuBackfiller;
use App\Services\Platforms\MenuProjectionMapper;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Slice 4: site.menu_items becomes menu_item-kind content items through the
// slice-0b manual lane. Production code, idempotent, re-runnable (convergence
// invariant #4) — never a throwaway script.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // The backfiller's slug phase reads site.item_slugs even when there is
    // nothing to carry — the table has to exist for the run to complete.
    setupItemSlugsTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    Queue::fake();
});

function menuItemCoords(): array
{
    return DB::connection('pgsql')->table('content.source_items')
        ->where('kind', 'menu_item')->pluck('coord')->all();
}

function menuItemCount(): int
{
    return DB::connection('pgsql')->table('content.items')->where('kind', 'menu_item')->count();
}

it('lands one content item per dish, keyed on the name-derived coord', function () {
    [$userId, $siteId, $menuId] = seedMenuWithDishes(['Iced Latte', 'Flat White']);

    $result = app(MenuBackfiller::class)->run();

    expect($result['backfilled'])->toBe(2)
        ->and($result['failed'])->toBe(0)
        ->and(menuItemCoords())
        ->toContain(MenuProjectionMapper::coordFor($menuId, 'Iced Latte'))
        ->toContain(MenuProjectionMapper::coordFor($menuId, 'Flat White'));
});

it('is idempotent — a second run updates and mints nothing new', function () {
    seedMenuWithDishes(['Iced Latte']);

    app(MenuBackfiller::class)->run();
    $after = menuItemCount();
    app(MenuBackfiller::class)->run();

    expect(menuItemCount())->toBe($after)->toBe(1);
});

it('reports without writing under --dry-run', function () {
    seedMenuWithDishes(['Iced Latte']);

    $result = app(MenuBackfiller::class)->run(dryRun: true);

    expect($result['backfilled'])->toBe(1)
        ->and(menuItemCount())->toBe(0);
});

it('counts a dish whose owner has no site rather than dropping it silently', function () {
    // §8.2: each backfiller derives user_id through the site explicitly and
    // fails loudly on a site with no owner. A silently skipped dish is one that
    // vanishes from the coverage count without anyone deciding to drop it.
    [$userId, $siteId] = seedMenuWithDishes(['Iced Latte']);
    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->delete();

    $result = app(MenuBackfiller::class)->run();

    expect($result['skipped_no_site'])->toBe(1)
        ->and($result['backfilled'])->toBe(0);
});

it('folds two dishes with the same normalised name onto one coord', function () {
    // site.menu_items has no uniqueness on (menu_id, name), so "Iced Latte" and
    // "ICED  LATTE" are two permitted rows collapsing to ONE coord. Without the
    // dedupe the run would write that coord twice and count two, leaving the
    // coverage gate unreconcilable.
    seedMenuWithDishes(['Iced Latte', 'ICED  LATTE']);

    $result = app(MenuBackfiller::class)->run();

    expect($result['backfilled'])->toBe(1)
        ->and($result['duplicate_name'])->toBe(1)
        ->and(menuItemCount())->toBe(1);
});

it('skips a nameless dish instead of minting a coord for the empty string', function () {
    [$userId, $siteId, $menuId] = seedMenuWithDishes(['Iced Latte']);
    DB::connection('pgsql')->table('site.menu_items')->insert([
        'id' => (string) Str::uuid(), 'menu_id' => $menuId, 'name' => '   ',
        'is_manual' => 0, 'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    $result = app(MenuBackfiller::class)->run();

    expect($result['skipped_no_name'])->toBe(1)
        ->and($result['backfilled'])->toBe(1);
});

it('writes the category memberships as collections', function () {
    seedMenuWithDishes(['Iced Latte'], categories: ['Drinks', 'Coffee']);

    app(MenuBackfiller::class)->run();

    // Scoped to menu_category on purpose: the same dish also joins an
    // order_platform collection (Unit 6), and an unscoped count would silently
    // start measuring both.
    $categoryIds = DB::connection('pgsql')->table('content.collections')
        ->where('kind', 'menu_category')->pluck('id');

    expect($categoryIds)->toHaveCount(2)
        ->and(DB::connection('pgsql')->table('content.collection_items')
            ->whereIn('collection_id', $categoryIds)->count())->toBe(2);
});

it('carries the per-platform order url onto an offer', function () {
    seedMenuWithDishes(['Iced Latte'], platforms: ['uber_eats']);

    app(MenuBackfiller::class)->run();

    $urls = DB::connection('pgsql')->table('content.offers')->whereNotNull('url')->pluck('url');

    expect($urls)->toHaveCount(1)
        ->and($urls->first())->toContain('uber_eats');
});

// ── Unit 6: ordering platforms (owner ruling 2026-08-15) ────────────────────

function menuPlatformLink(string $menuId, string $platform, string $storeUrl): void
{
    DB::connection('pgsql')->table('site.menu_platform_links')->insert([
        'id' => (string) Str::uuid(),
        'menu_id' => $menuId,
        'platform' => $platform,
        'store_url' => $storeUrl,
        'status' => 'ok',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

it('turns each menu platform link into an order_platform collection with a storefront', function () {
    [$userId, $siteId, $menuId] = seedMenuWithDishes(['Iced Latte'], platforms: ['uber_eats']);
    menuPlatformLink($menuId, 'uber_eats', 'https://ubereats.com/store/x');

    app(MenuBackfiller::class)->run();

    $collection = DB::connection('pgsql')->table('content.collections')
        ->where('kind', 'order_platform')->first();

    expect($collection)->not->toBeNull()
        ->and($collection->external_ref)->toBe('order:uber_eats')
        ->and(DB::connection('pgsql')->table('content.storefronts')
            ->where('collection_id', $collection->id)->value('url'))
        ->toBe('https://ubereats.com/store/x');
});

it('makes every dish sold on that platform a member of its collection', function () {
    [$userId, $siteId, $menuId] = seedMenuWithDishes(['Iced Latte', 'Flat White'], platforms: ['uber_eats']);
    menuPlatformLink($menuId, 'uber_eats', 'https://ubereats.com/store/x');

    app(MenuBackfiller::class)->run();

    $collection = DB::connection('pgsql')->table('content.collections')
        ->where('kind', 'order_platform')->first();

    expect(DB::connection('pgsql')->table('content.collection_items')
        ->where('collection_id', $collection->id)->count())->toBe(2);
});

it('writes the storefront even when the platform link has no dishes yet', function () {
    // A ghost platform: the owner connected a store whose scrape has not landed
    // per-dish rows. The order link is still real and must reach the wire, or
    // connecting a platform looks like it did nothing.
    [$userId, $siteId, $menuId] = seedMenuWithDishes(['Iced Latte'], platforms: []);
    menuPlatformLink($menuId, 'doordash', 'https://doordash.com/store/y');

    app(MenuBackfiller::class)->run();

    expect(DB::connection('pgsql')->table('content.collections')
        ->where('external_ref', 'order:doordash')->exists())->toBeTrue()
        ->and(DB::connection('pgsql')->table('content.storefronts')->count())->toBe(1);
});

it('does not overwrite a collection position the owner has reordered', function () {
    // position is INSERT-ONLY on collections — ProjectionWriter's upsert list
    // omits it deliberately, so a scheduled run cannot snap an owner's reorder
    // back. A re-run of this backfill must obey the same rule.
    [$userId, $siteId, $menuId] = seedMenuWithDishes(['Iced Latte'], platforms: ['uber_eats']);
    menuPlatformLink($menuId, 'uber_eats', 'https://ubereats.com/store/x');
    app(MenuBackfiller::class)->run();

    DB::connection('pgsql')->table('content.collections')
        ->where('kind', 'order_platform')->update(['position' => 7]);
    app(MenuBackfiller::class)->run();

    expect(DB::connection('pgsql')->table('content.collections')
        ->where('kind', 'order_platform')->value('position'))->toBe(7);
});

it('follows a changed store url on a re-run', function () {
    // The sidecar's url is vendor-owned, unlike position: a store that moves
    // must be followed, or the order button points at a dead page.
    [$userId, $siteId, $menuId] = seedMenuWithDishes(['Iced Latte'], platforms: ['uber_eats']);
    menuPlatformLink($menuId, 'uber_eats', 'https://ubereats.com/store/x');
    app(MenuBackfiller::class)->run();

    DB::connection('pgsql')->table('site.menu_platform_links')
        ->update(['store_url' => 'https://ubereats.com/store/moved']);
    app(MenuBackfiller::class)->run();

    expect(DB::connection('pgsql')->table('content.storefronts')->value('url'))
        ->toBe('https://ubereats.com/store/moved')
        ->and(DB::connection('pgsql')->table('content.storefronts')->count())->toBe(1);
});

it('leaves a menu it was not scoped to alone', function () {
    [$userA] = seedMenuWithDishes(['Iced Latte']);
    seedMenuWithDishes(['Flat White']);

    $result = app(MenuBackfiller::class)->run(userId: $userA);

    expect($result['backfilled'])->toBe(1)
        ->and(menuItemCount())->toBe(1);
});

// ── Unit 7: pin seeding ─────────────────────────────────────────────────────

it('seeds a dense 1-based pin order from the legacy menu order', function () {
    // The pool's auto half offers alphabetical / occurrence / recency and none
    // of them is "the order the owner chose" — so the owner's arrangement is
    // carried by pins, seeded once from the legacy category/dish positions.
    [$userId, $siteId] = seedMenuWithDishes(['Iced Latte', 'Flat White']);
    app(MenuBackfiller::class)->run();

    $this->artisan('content:provision-menu-pins')->assertSuccessful();

    $section = app(PoolSectionProvisioner::class)
        ->ensure(Site::query()->findOrFail($siteId), 'menus');

    expect(DB::connection('pgsql')->table('site.section_items')
        ->where('section_id', $section->id)->where('state', 'pinned')
        ->orderBy('sort_key')->pluck('sort_key')->all())
        ->toBe([1.0, 2.0]);
});

it('pins in the legacy category order, not in item creation order', function () {
    [$userId, $siteId, $menuId, $categoryIds] = seedMenuWithDishes(
        ['Iced Latte', 'Flat White'], categories: ['Drinks'],
    );

    // Move the second dish into a category that sorts FIRST, so creation order
    // and menu order disagree.
    $firstCategory = (string) Str::uuid();
    DB::connection('pgsql')->table('site.menu_categories')->insert([
        'id' => $firstCategory, 'menu_id' => $menuId, 'name' => 'Specials',
        'position' => -1, 'source_platform' => 'uber-eats',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);
    $second = (string) DB::connection('pgsql')->table('site.menu_items')
        ->where('name', 'Flat White')->value('id');
    DB::connection('pgsql')->table('site.menu_item_categories')
        ->where('menu_item_id', $second)->update(['menu_category_id' => $firstCategory]);

    app(MenuBackfiller::class)->run();
    $this->artisan('content:provision-menu-pins');

    $section = app(PoolSectionProvisioner::class)
        ->ensure(Site::query()->findOrFail($siteId), 'menus');

    $firstPinned = DB::connection('pgsql')->table('site.section_items')
        ->where('section_id', $section->id)->orderBy('sort_key')->value('item_id');

    expect(DB::connection('pgsql')->table('content.items')->where('id', $firstPinned)->value('headline_cache'))
        ->toBe('Flat White');
});

it('leaves an existing pin alone rather than rewriting it', function () {
    // 5b's provision-shop-pins is the precedent: a re-run must not undo a drag
    // the owner made afterwards.
    [$userId, $siteId] = seedMenuWithDishes(['Iced Latte', 'Flat White']);
    app(MenuBackfiller::class)->run();
    $this->artisan('content:provision-menu-pins');

    $section = app(PoolSectionProvisioner::class)
        ->ensure(Site::query()->findOrFail($siteId), 'menus');
    DB::connection('pgsql')->table('site.section_items')
        ->where('section_id', $section->id)->orderBy('sort_key')->limit(1)->update(['sort_key' => 99]);

    $this->artisan('content:provision-menu-pins');

    expect((float) DB::connection('pgsql')->table('site.section_items')
        ->where('section_id', $section->id)->max('sort_key'))->toBe(99.0)
        ->and(DB::connection('pgsql')->table('site.section_items')
            ->where('section_id', $section->id)->count())->toBe(2);
});

it('pins a dish in two categories once, at its earliest position', function () {
    [$userId, $siteId] = seedMenuWithDishes(['Iced Latte'], categories: ['Drinks', 'Coffee']);
    app(MenuBackfiller::class)->run();

    $this->artisan('content:provision-menu-pins');

    $section = app(PoolSectionProvisioner::class)
        ->ensure(Site::query()->findOrFail($siteId), 'menus');

    expect(DB::connection('pgsql')->table('site.section_items')
        ->where('section_id', $section->id)->count())->toBe(1);
});

it('writes nothing under --dry-run', function () {
    [$userId, $siteId] = seedMenuWithDishes(['Iced Latte']);
    app(MenuBackfiller::class)->run();

    $this->artisan('content:provision-menu-pins', ['--dry-run' => true])->assertSuccessful();

    expect(DB::connection('pgsql')->table('site.section_items')->count())->toBe(0);
});
