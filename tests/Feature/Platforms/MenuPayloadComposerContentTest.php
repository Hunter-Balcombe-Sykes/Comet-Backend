<?php

use App\Models\Core\User\User;
use App\Services\Migration\MenuBackfiller;
use App\Services\Platforms\MenuPayloadComposer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Slice 7 Task 5 — the characterisation harness for the composer's cutover.
//
// The oracle is the LEGACY payload, snapshotted before anything moves: seed a
// menu through site.menu_*, compose it, then backfill the same data into
// content.* and TRUNCATE the legacy dish tables. Anything the content path
// still returns it cannot have read from the tables that no longer hold it.
//
// Two things are deliberately normalised away and nothing else:
//   - uuids, because content.items/collections mint their own (mpcCanonical),
//   - the enumerated, documented losses (mpcKnownLosses) — each one has its own
//     test below stating WHY it is gone.
// Everything else must match key for key, value for value, ORDER INCLUDED: dish
// order is carried by the pool:menus pins, so every fixture here seeds dishes
// out of alphabetical sequence and an ordering regression fails the oracle.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupItemSlugsTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    Queue::fake();
});

/** compose() through the real resolution path, exactly as GET /platforms/menu does. */
function mpcCompose(string $userId): array
{
    $user = User::query()->findOrFail($userId);
    $composer = app(MenuPayloadComposer::class);

    return $composer->compose($user, $composer->load($user));
}

/**
 * The slice-4 cutover, both halves, in the order the checkpoint ran them:
 * MenuBackfiller lands the dishes as content items, then
 * content:provision-menu-pins seeds the owner's arrangement as pool:menus pins
 * (site.section_items.sort_key), read from site.menu_categories.position +
 * site.menu_item_categories.position. Running only the first half would leave
 * the order behind and is exactly the mistake this file must not encode.
 */
function mpcBackfillToContent(): void
{
    app(MenuBackfiller::class)->run();
    Artisan::call('content:provision-menu-pins');
}

/** The legacy dish tables gone — the composer must not be reading them. */
function mpcTruncateLegacyDishes(): void
{
    foreach (['site.menu_items', 'site.menu_categories', 'site.menu_item_categories', 'site.menu_item_platforms'] as $table) {
        DB::connection('pgsql')->table($table)->delete();
    }
}

/**
 * Replace every uuid with the name behind it. The content lane mints its own
 * ids for items and collections, so identity is the ONE thing that cannot be
 * compared directly — and comparing names instead still catches a dish landing
 * under the wrong category or a categoryIds list losing a membership.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function mpcCanonical(array $payload): array
{
    $names = [];
    foreach ($payload['categories'] as $category) {
        $names[$category['id']] = 'category:'.$category['name'];
        foreach ($category['items'] as $item) {
            $names[$item['id']] = 'item:'.$item['name'];
        }
    }

    $payload['categories'] = array_map(function (array $category) use ($names) {
        $category['id'] = $names[$category['id']];
        $category['items'] = array_map(function (array $item) use ($names) {
            $item['id'] = $names[$item['id']];
            $item['categoryIds'] = array_map(fn (string $id) => $names[$id] ?? $id, $item['categoryIds']);

            return $item;
        }, $category['items']);

        return $category;
    }, $payload['categories']);

    return $payload;
}

/**
 * The legacy oracle with each documented loss applied. Every entry here is a
 * column MenuProjectionMapper never wrote, so the content lane cannot return
 * it — see MenuPayloadComposer::contentCategories() and the per-loss tests at
 * the bottom of this file. Changing this list without changing a projection is
 * how a real regression would get waved through.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function mpcKnownLosses(array $payload): array
{
    $payload['categories'] = array_map(function (array $category) {
        // No home in content.collections yet (Task 6 decides it).
        $category['sourcePlatform'] = null;
        $category['items'] = array_map(function (array $item) {
            // Task 6 homes isManual on site.menus; nothing carries it today.
            $item['isManual'] = false;
            $item['pickupSource'] = null;
            $item['deliverySource'] = null;
            // dd_external_id has no projection target, so no dish deep link is derivable.
            $item['links'] = null;
            // content.item_tags keeps the display text only, alphabetically.
            $item['badges'] = $item['badges'] === null ? null : collect($item['badges'])
                ->map(fn (array $badge) => ['text' => $badge['text']])
                ->sortBy('text')->values()->all();

            return $item;
        }, $category['items']);

        return $category;
    }, $payload['categories']);

    return $payload;
}

/**
 * The one per-dish deep link the dashboard emits: menu_items.dd_external_id
 * plus the DoorDash store link it hangs off (MenuItemDeepLinks::forItem).
 */
function mpcDoorDashDeepLink(string $menuId): void
{
    DB::connection('pgsql')->table('site.menu_items')->where('menu_id', $menuId)
        ->update(['dd_external_id' => 'dd-123']);
    DB::connection('pgsql')->table('site.menu_platform_links')->insert([
        'id' => (string) Str::uuid(), 'menu_id' => $menuId,
        'platform' => 'doordash', 'store_url' => 'https://www.doordash.com/store/x',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);
}

it('composes the same payload from content.* as it did from the legacy tables', function () {
    // Dish names are REVERSE alphabetical on purpose: order is carried by the
    // pool:menus pins, so an ordering regression has to fail this test too, not
    // only the dedicated one below.
    [$userId, , $menuId] = seedMenuWithDishes(['Iced Latte', 'Croissant'], categories: ['Drinks'], platforms: ['uber_eats']);
    // dd_external_id + a DoorDash store link on purpose: without them the
    // oracle's `links` key is null on BOTH sides and the deep-link loss would
    // pass unnoticed. 31 of dev's 318 dishes carry one.
    mpcDoorDashDeepLink($menuId);
    $legacy = mpcCanonical(mpcCompose($userId));
    expect($legacy['categories'][0]['items'][0]['links'])
        ->toBe(['doordash' => 'https://www.doordash.com/store/x/?event_type=item_click&item_id=dd-123']);

    mpcBackfillToContent();
    mpcTruncateLegacyDishes();

    expect(mpcCanonical(mpcCompose($userId)))->toEqual(mpcKnownLosses($legacy));
});

it('reads the dishes from content.* and not from the legacy tables', function () {
    [$userId] = seedMenuWithDishes(['Iced Latte'], categories: ['Drinks'], platforms: ['uber_eats']);

    mpcBackfillToContent();
    mpcTruncateLegacyDishes();

    $payload = mpcCompose($userId);

    expect($payload['categories'])->toHaveCount(1)
        ->and($payload['categories'][0]['name'])->toBe('Drinks')
        ->and($payload['categories'][0]['items'])->toHaveCount(1)
        ->and($payload['categories'][0]['items'][0]['name'])->toBe('Iced Latte');
});

it('keeps the store fields on site.menus, which survives the teardown', function () {
    // compose()'s first ten keys read the per-menu bookkeeping row directly —
    // only categories move. A regression here means load() was repointed too.
    [$userId] = seedMenuWithDishes(['Iced Latte']);
    mpcBackfillToContent();
    mpcTruncateLegacyDishes();

    $payload = mpcCompose($userId);

    expect($payload['source'])->toBe('uber-eats')
        ->and($payload['currency'])->toBe('AUD')
        ->and($payload['fetchStatus'])->toBe('ok');
});

it('carries a dish listed under two categories into both, with the full membership set', function () {
    [$userId] = seedMenuWithDishes(['Iced Latte'], categories: ['Drinks', 'Coffee'], platforms: []);
    $legacy = mpcCanonical(mpcCompose($userId));

    mpcBackfillToContent();
    mpcTruncateLegacyDishes();

    $content = mpcCanonical(mpcCompose($userId));

    expect(collect($content['categories'])->pluck('name')->all())
        ->toBe(collect($legacy['categories'])->pluck('name')->all())
        ->and($content['categories'][0]['items'][0]['categoryIds'])
        ->toBe($legacy['categories'][0]['items'][0]['categoryIds']);
});

it('rebuilds the per-platform availability list from the projected offers', function () {
    [$userId] = seedMenuWithDishes(['Iced Latte'], categories: ['Drinks'], platforms: ['uber_eats']);
    $legacy = mpcCanonical(mpcCompose($userId));

    mpcBackfillToContent();
    mpcTruncateLegacyDishes();

    expect(mpcCanonical(mpcCompose($userId))['categories'][0]['items'][0]['platforms'])
        ->toEqual($legacy['categories'][0]['items'][0]['platforms']);
});

it('falls back to the legacy tables for an owner the content lane holds nothing for', function () {
    // Phase 2 moves the four write paths one task at a time (Tasks 6-8), so
    // between them a menu can exist ONLY in site.menu_*. The fallback is what
    // keeps that owner's dashboard rendering; Phase 5 deletes it with the tables.
    [$userId] = seedMenuWithDishes(['Iced Latte'], categories: ['Drinks'], platforms: ['uber_eats']);

    $payload = mpcCompose($userId);

    expect($payload['categories'][0]['name'])->toBe('Drinks')
        ->and($payload['categories'][0]['sourcePlatform'])->toBe('uber-eats')
        ->and($payload['categories'][0]['items'][0]['name'])->toBe('Iced Latte');
});

it('never falls back once the owner has deleted their way down to an empty content menu', function () {
    // The fallback gate counts REMOVED items too. Without that, an owner who
    // deleted their last dish would drop back to the legacy tables and watch
    // every dish they deleted reappear.
    [$userId] = seedMenuWithDishes(['Iced Latte'], categories: ['Drinks'], platforms: ['uber_eats']);
    mpcBackfillToContent();
    DB::connection('pgsql')->table('content.items')
        ->where('kind', 'menu_item')
        ->update(['removed_at' => now()->toDateTimeString()]);

    // The category collection survives the last dish, so this asserts the
    // absence of the LEGACY read (sourcePlatform 'uber-eats' + the dish), not
    // an empty payload.
    $categories = mpcCompose($userId)['categories'];

    expect($categories)->toHaveCount(1)
        ->and($categories[0]['sourcePlatform'])->toBeNull()
        ->and($categories[0]['items'])->toBe([]);
});

// ── The enumerated losses ───────────────────────────────────────────────────
// Each is a column MenuProjectionMapper never wrote. They are pinned rather
// than reconstructed, so a later "fix" that guesses one is a red test.

it('loses the category sourcePlatform, which content.collections has no column for', function () {
    [$userId] = seedMenuWithDishes(['Iced Latte'], categories: ['Drinks']);
    mpcBackfillToContent();
    mpcTruncateLegacyDishes();

    expect(mpcCompose($userId)['categories'][0]['sourcePlatform'])->toBeNull();
});

// dd_external_id is the costliest of the four: 31 of dev's 318 dishes carry one
// and each is a live DoorDash deep link. Recovering it is a MenuProjectionMapper
// change (f_catalog.sku / variant_ref are already projection-supported columns)
// plus a `content:backfill-menus` re-run — a shared-mapper edit and a data
// migration, both outside Task 5. Pinned here so the loss is a decision.
it('loses isManual, pickupSource, deliverySource and the per-dish deep link', function () {
    [$userId, , $menuId] = seedMenuWithDishes(['Iced Latte']);
    DB::connection('pgsql')->table('site.menu_items')->where('menu_id', $menuId)->update([
        'is_manual' => 1,
        'pickup_source' => 'doordash',
        'delivery_source' => 'uber-eats',
    ]);
    mpcDoorDashDeepLink($menuId);
    // The legacy path really does emit all four — otherwise this pins nothing.
    $legacyItem = mpcCompose($userId)['categories'][0]['items'][0];
    expect($legacyItem['isManual'])->toBeTrue()
        ->and($legacyItem['pickupSource'])->toBe('doordash')
        ->and($legacyItem['deliverySource'])->toBe('uber-eats')
        ->and($legacyItem['links'])
        ->toBe(['doordash' => 'https://www.doordash.com/store/x/?event_type=item_click&item_id=dd-123']);

    mpcBackfillToContent();
    mpcTruncateLegacyDishes();

    $item = mpcCompose($userId)['categories'][0]['items'][0];

    expect($item['isManual'])->toBeFalse()
        ->and($item['pickupSource'])->toBeNull()
        ->and($item['deliverySource'])->toBeNull()
        ->and($item['links'])->toBeNull();
});

it('loses the badge type code and the vendor badge order', function () {
    [$userId, , $menuId] = seedMenuWithDishes(['Iced Latte']);
    DB::connection('pgsql')->table('site.menu_items')->where('menu_id', $menuId)
        ->update(['badges' => json_encode([['text' => 'Vegan', 'type' => 'diet_1'], ['text' => 'Popular', 'type' => 'most_liked_1']])]);

    mpcBackfillToContent();
    mpcTruncateLegacyDishes();

    // content.item_tags carries one classification column and tag_type already
    // spends it on 'badge', so the vendor's own type code is gone; it has no
    // ordinal column either, so alphabetical is the stable answer.
    expect(mpcCompose($userId)['categories'][0]['items'][0]['badges'])
        ->toBe([['text' => 'Popular'], ['text' => 'Vegan']]);
});

// ── The order that is NOT lost ──────────────────────────────────────────────

it('keeps the within-category dish order, carried by the pool:menus pins', function () {
    // content.collection_items.position cannot answer this — it is a dish's
    // ordinal within its OWN collection list, so every dish in a one-category
    // menu holds 0 (dev: 348 of 402 rows at 0, against 33 distinct values on
    // site.menu_item_categories.position). ProvisionMenuPinsCommand exists for
    // exactly that reason and seeded site.section_items.sort_key from the
    // legacy order in slice 4, so the arrangement survives the teardown.
    //
    // Seeded in reverse alphabetical order deliberately: sorting by name would
    // produce the opposite list and this test would fail.
    [$userId] = seedMenuWithDishes(['Zucchini Fries', 'Apple Pie'], categories: ['Drinks'], platforms: []);

    expect(collect(mpcCompose($userId)['categories'][0]['items'])->pluck('name')->all())
        ->toBe(['Zucchini Fries', 'Apple Pie']);

    mpcBackfillToContent();
    mpcTruncateLegacyDishes();

    expect(collect(mpcCompose($userId)['categories'][0]['items'])->pluck('name')->all())
        ->toBe(['Zucchini Fries', 'Apple Pie']);
});

it('trails an unpinned dish behind the arrangement instead of interleaving it', function () {
    // A dish landed after the pins were seeded holds no sort_key. Pins first,
    // then the rest — the same concatenation PoolResolver::resolve() performs,
    // so the dashboard and the public pool agree on what the owner's order is.
    //
    // Apple Pie loses its pin, so the expected list is neither the seeded order
    // nor alphabetical — only pins-then-the-rest produces it.
    [$userId] = seedMenuWithDishes(['Apple Pie', 'Zucchini Fries'], categories: ['Drinks'], platforms: []);
    mpcBackfillToContent();
    DB::connection('pgsql')->table('site.section_items')
        ->whereIn('item_id', function ($q) {
            $q->from('content.items')->select('id')->where('headline_cache', 'Apple Pie');
        })->delete();
    mpcTruncateLegacyDishes();

    expect(collect(mpcCompose($userId)['categories'][0]['items'])->pluck('name')->all())
        ->toBe(['Zucchini Fries', 'Apple Pie']);
});
