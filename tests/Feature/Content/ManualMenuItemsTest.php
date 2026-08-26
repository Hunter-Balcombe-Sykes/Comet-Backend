<?php

use App\Models\Core\Site\MenuItem;
use App\Models\Core\User\User;
use App\Services\Content\ManualMenuItems;
use App\Services\Content\ManualMenuWriter;
use App\Services\Platforms\MenuProjectionMapper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Slice 7 unit A, read side. This is MenuProjectionMapper run backwards: the
// mapper folded a legacy dish into content.items + offers + tags + media +
// collections, and this class folds it back into the legacy shape the dashboard
// still reads. Every assertion below therefore seeds through the REAL writer
// (ManualMenuWriter → ProjectionWriter), never by hand-inserting content rows —
// a hand-built fixture would let the two sides drift and still pass.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Queue::fake();
});

/** The menu id every helper below scopes its coords to — one vendor per test. */
function mmiMenuId(): string
{
    return '11111111-1111-4111-8111-111111111111';
}

/**
 * Land one dish through the writer, exactly as Task 6's controller and Task 7's
 * fetch job will.
 *
 * @param  array<string, mixed>  $dish  legacy site.menu_items-shaped columns
 * @param  list<array{id: string, name: string, position: int}>  $categories
 * @param  list<array<string, mixed>>  $platformRows  legacy site.menu_item_platforms-shaped
 */
function mmiWriteDish(User $user, array $dish, array $categories = [], array $platformRows = [], ?string $currency = 'AUD'): string
{
    $writer = app(ManualMenuWriter::class);

    return $writer->write(
        (string) $user->id,
        $writer->coordFor(mmiMenuId(), (string) $dish['name']),
        $writer->projectionFor(
            (object) $dish,
            $categories,
            array_map(fn (array $row) => (object) $row, $platformRows),
            (object) ['currency' => $currency],
        ),
    );
}

/** One tenant, one dish, its three aggregate channels priced as asked. */
function mmiSeedDishWithOffers(?float $base = null, ?float $pickup = null, ?float $delivery = null): User
{
    $user = createTenant('mmi-'.Str::lower(Str::random(8)));

    mmiWriteDish($user, [
        'name' => 'Iced Latte',
        'description' => 'Cold and strong',
        'base_price' => $base,
        'pickup_price' => $pickup,
        'delivery_price' => $delivery,
    ]);

    return $user;
}

/** Soft-delete every menu_item this test landed — the owner's delete. */
function mmiMarkRemoved(): void
{
    DB::connection('pgsql')->table('content.items')
        ->where('kind', 'menu_item')
        ->update(['removed_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()]);
}

it('returns one row per live menu_item with its offers folded to legacy columns', function () {
    $user = mmiSeedDishWithOffers(base: 5.50, pickup: 5.00, delivery: 6.50);

    $rows = app(ManualMenuItems::class)->rows((string) $user->id);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->base_price)->toBe(5.50)
        ->and($rows[0]->pickup_price)->toBe(5.00)
        ->and($rows[0]->delivery_price)->toBe(6.50);
});

it('omits items whose removed_at is set', function () {
    $user = mmiSeedDishWithOffers(base: 5.50);
    mmiMarkRemoved();

    expect(app(ManualMenuItems::class)->rows((string) $user->id))->toHaveCount(0);
});

it('includes removed items when asked', function () {
    $user = mmiSeedDishWithOffers(base: 5.50);
    mmiMarkRemoved();

    expect(app(ManualMenuItems::class)->rows((string) $user->id, includeRemoved: true))->toHaveCount(1);
});

it('carries the headline, description, currency and coord', function () {
    $user = mmiSeedDishWithOffers(base: 5.50);

    $row = app(ManualMenuItems::class)->rows((string) $user->id)[0];

    expect($row->headline)->toBe('Iced Latte')
        ->and($row->description)->toBe('Cold and strong')
        ->and($row->currency)->toBe('AUD')
        ->and($row->coord)->toBe(MenuProjectionMapper::coordFor(mmiMenuId(), 'Iced Latte'));
});

it('leaves a channel the dish carries no offer for as null', function () {
    // A dish priced only for delivery is real — the legacy columns were
    // independently nullable and a 0 here would advertise a free pickup.
    $user = mmiSeedDishWithOffers(delivery: 6.50);

    $row = app(ManualMenuItems::class)->rows((string) $user->id)[0];

    expect($row->base_price)->toBeNull()
        ->and($row->pickup_price)->toBeNull()
        ->and($row->delivery_price)->toBe(6.50);
});

it('scopes rows to one owner', function () {
    $mine = mmiSeedDishWithOffers(base: 5.50);
    mmiSeedDishWithOffers(base: 9.00);

    expect(app(ManualMenuItems::class)->rows((string) $mine->id))->toHaveCount(1);
});

it('finds a single item by id and returns null for another owner\'s', function () {
    $mine = mmiSeedDishWithOffers(base: 5.50);
    $theirs = mmiSeedDishWithOffers(base: 9.00);

    $items = app(ManualMenuItems::class);
    $row = $items->rows((string) $mine->id)[0];

    expect($items->find((string) $mine->id, (string) $row->id)?->headline)->toBe('Iced Latte')
        ->and($items->find((string) $theirs->id, (string) $row->id))->toBeNull();
});

it('does not find a removed item unless asked', function () {
    $user = mmiSeedDishWithOffers(base: 5.50);
    $id = (string) app(ManualMenuItems::class)->rows((string) $user->id)[0]->id;
    mmiMarkRemoved();

    expect(app(ManualMenuItems::class)->find((string) $user->id, $id))->toBeNull()
        ->and(app(ManualMenuItems::class)->find((string) $user->id, $id, includeRemoved: true))->not->toBeNull();
});

it('lists menu_category collections in position then label order', function () {
    $user = createTenant('mmi-'.Str::lower(Str::random(8)));
    mmiWriteDish($user, ['name' => 'Iced Latte', 'base_price' => 5.5], [
        ['id' => (string) Str::uuid(), 'name' => 'Drinks', 'position' => 1],
        ['id' => (string) Str::uuid(), 'name' => 'Coffee', 'position' => 0],
    ]);

    expect(app(ManualMenuItems::class)->categories((string) $user->id)->pluck('label')->all())
        ->toBe(['Coffee', 'Drinks']);
});

it('never lists an order_platform collection as a category', function () {
    // Both live in content.collections; only `kind` keeps them apart, and an
    // unscoped read would hand the owner a "DoorDash" menu category.
    $user = createTenant('mmi-'.Str::lower(Str::random(8)));
    mmiWriteDish($user, ['name' => 'Iced Latte', 'base_price' => 5.5],
        [['id' => (string) Str::uuid(), 'name' => 'Drinks', 'position' => 0]],
        [['platform' => 'uber_eats', 'delivery_price' => 6.5, 'item_url' => 'https://ubereats.com/store/x/item']],
    );

    expect(app(ManualMenuItems::class)->categories((string) $user->id)->pluck('label')->all())
        ->toBe(['Drinks']);
});

it('omits a category the owner deleted', function () {
    $user = createTenant('mmi-'.Str::lower(Str::random(8)));
    mmiWriteDish($user, ['name' => 'Iced Latte', 'base_price' => 5.5],
        [['id' => (string) Str::uuid(), 'name' => 'Drinks', 'position' => 0]]);
    DB::connection('pgsql')->table('content.collections')
        ->where('kind', 'menu_category')->update(['removed_at' => now()->toDateTimeString()]);

    expect(app(ManualMenuItems::class)->categories((string) $user->id))->toHaveCount(0);
});

it('carries each dish\'s live category memberships in position order', function () {
    $user = createTenant('mmi-'.Str::lower(Str::random(8)));
    mmiWriteDish($user, ['name' => 'Iced Latte', 'base_price' => 5.5], [
        ['id' => (string) Str::uuid(), 'name' => 'Drinks', 'position' => 0],
        ['id' => (string) Str::uuid(), 'name' => 'Coffee', 'position' => 1],
    ]);

    $items = app(ManualMenuItems::class);
    $row = $items->rows((string) $user->id)[0];
    $byLabel = $items->categories((string) $user->id)->keyBy('label');

    expect($row->category_ids)->toBe([
        (string) $byLabel['Drinks']->id,
        (string) $byLabel['Coffee']->id,
    ]);
});

it('rebuilds the per-platform availability list from the url-bearing offers', function () {
    $user = createTenant('mmi-'.Str::lower(Str::random(8)));
    mmiWriteDish($user, ['name' => 'Iced Latte', 'base_price' => 5.5], [], [[
        'platform' => 'uber_eats',
        'pickup_price' => 5.0,
        'delivery_price' => 6.5,
        'item_url' => 'https://ubereats.com/store/x/item/u1',
        'external_ref' => 'u1',
    ]]);

    $row = app(ManualMenuItems::class)->rows((string) $user->id)[0];
    $platforms = $row->platforms;

    // The aggregate three and the per-platform offers share a channel string —
    // the platform label (post-2026-08-26) separates them, so a per-platform
    // pickup offer must NOT leak into the aggregate pickup_price the dish
    // never had.
    expect($row->pickup_price)->toBeNull()
        ->and($row->delivery_price)->toBeNull()
        ->and($platforms)->toHaveCount(1)
        ->and($platforms[0]->platform)->toBe('uber_eats')
        ->and($platforms[0]->pickup_price)->toBe(5.0)
        ->and($platforms[0]->delivery_price)->toBe(6.5)
        ->and($platforms[0]->item_url)->toBe('https://ubereats.com/store/x/item/u1');
});

it('attributes prices and item links to their own platform when a dish sells on two', function () {
    // The projection STORES the platform label since 2026-08-26 — no
    // storefront host-matching needed. Getting this wrong points a DoorDash
    // button at Uber Eats.
    $user = createTenant('mmi-'.Str::lower(Str::random(8)));
    mmiWriteDish($user, ['name' => 'Iced Latte', 'base_price' => 5.5], [], [
        ['platform' => 'uber_eats', 'delivery_price' => 6.5, 'item_url' => 'https://ubereats.com/store/x/item/u1', 'external_ref' => 'u1'],
        ['platform' => 'doordash', 'delivery_price' => 7.0, 'item_url' => 'https://doordash.com/store/y?itemId=d1', 'external_ref' => 'd1'],
    ]);

    $platforms = collect(app(ManualMenuItems::class)->rows((string) $user->id)[0]->platforms)
        ->keyBy('platform');

    expect($platforms['uber_eats']->delivery_price)->toBe(6.5)
        ->and($platforms['uber_eats']->item_url)->toBe('https://ubereats.com/store/x/item/u1')
        ->and($platforms['doordash']->delivery_price)->toBe(7.0)
        ->and($platforms['doordash']->item_url)->toBe('https://doordash.com/store/y?itemId=d1')
        ->and($platforms['doordash']->external_ref)->toBe('d1');
});

it('keeps a legacy two-platform dish\'s platforms but drops the urls when no storefront resolves them', function () {
    // C6 (2026-08-27): the host-matching fallback is DELETED — a legacy row
    // (url, no platform label) contributes NOTHING to the fold: platform
    // memberships still name the platforms, but prices and links stay null
    // rather than being guessed. Pinned so a later "fix" that reintroduces
    // host-guessing is a red test. (Live legacy rows exist only on RETIRED
    // items after the 2026-08-27 full-menu refresh.)
    $user = createTenant('mmi-'.Str::lower(Str::random(8)));
    $itemId = mmiWriteDish($user, ['name' => 'Iced Latte', 'base_price' => 5.5], [], [
        ['platform' => 'uber_eats', 'delivery_price' => 6.5],
        ['platform' => 'doordash', 'delivery_price' => 7.0],
    ]);
    // Rewrite the two per-platform offers into their LEGACY shape: url set,
    // platform label absent — exactly what pre-migration rows hold.
    foreach ([['https://ubereats.com/store/x/d', 650], ['https://doordash.com/store/y/d', 700]] as [$url, $minor]) {
        DB::connection('pgsql')->table('content.offers')
            ->where('item_id', $itemId)->where('channel', 'delivery')->where('amount_minor', $minor)
            ->whereNotNull('platform')
            ->update(['platform' => null, 'url' => $url, 'item_url' => null, 'external_ref' => null]);
    }

    $platforms = collect(app(ManualMenuItems::class)->rows((string) $user->id)[0]->platforms);

    expect($platforms->pluck('platform')->sort()->values()->all())->toBe(['doordash', 'uber_eats'])
        ->and($platforms->pluck('item_url')->filter()->all())->toBe([]);
});

/** The order_platform sidecar Task 7 writes per site.menu_platform_links row. */
function mmiStorefront(User $user, string $platform, string $storeUrl): void
{
    $collectionId = DB::connection('pgsql')->table('content.collections')
        ->where('user_id', $user->id)
        ->where('external_ref', MenuProjectionMapper::orderPlatformRef($platform))
        ->value('id');

    DB::connection('pgsql')->table('content.storefronts')->insert([
        'collection_id' => $collectionId,
        'provider' => $platform,
        'url' => $storeUrl,
        'external_ref' => $platform,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

it('carries the cover image url and the hero-first image set', function () {
    $user = createTenant('mmi-'.Str::lower(Str::random(8)));
    mmiWriteDish($user, [
        'name' => 'Iced Latte',
        'base_price' => 5.5,
        'image_url' => 'https://cdn.test/hero.jpg',
        'images' => ['https://cdn.test/hero.jpg', 'https://cdn.test/second.jpg'],
    ]);

    $row = app(ManualMenuItems::class)->rows((string) $user->id)[0];

    expect($row->image_url)->toBe('https://cdn.test/hero.jpg')
        ->and($row->images)->toBe(['https://cdn.test/hero.jpg', 'https://cdn.test/second.jpg']);
});

it('carries the DoorDash rating pair back onto the legacy columns', function () {
    $user = createTenant('mmi-'.Str::lower(Str::random(8)));
    mmiWriteDish($user, ['name' => 'Iced Latte', 'base_price' => 5.5, 'rating' => 96.0, 'rating_count' => 41]);

    $row = app(ManualMenuItems::class)->rows((string) $user->id)[0];

    expect($row->rating)->toBe(96.0)->and($row->rating_count)->toBe(41);
});

it('carries badges back as the legacy text maps, alphabetically', function () {
    // Seeded out of alphabetical order on purpose: content.item_tags has no
    // ordinal column, so the vendor's badge order did NOT survive the
    // projection. Pinning the stable order here records that loss instead of
    // leaving it to be rediscovered as a flake.
    $user = createTenant('mmi-'.Str::lower(Str::random(8)));
    mmiWriteDish($user, ['name' => 'Iced Latte', 'base_price' => 5.5, 'badges' => ['Vegan', 'Popular']]);

    expect(app(ManualMenuItems::class)->rows((string) $user->id)[0]->badges)
        ->toBe([['text' => 'Popular'], ['text' => 'Vegan']]);
});

it('hydrates an unsaved legacy MenuItem the dashboard can render', function () {
    $user = createTenant('mmi-'.Str::lower(Str::random(8)));
    mmiWriteDish($user, [
        'name' => 'Iced Latte',
        'description' => 'Cold and strong',
        'base_price' => 5.5,
        'pickup_price' => 5.0,
        'delivery_price' => 6.5,
        'image_url' => 'https://cdn.test/hero.jpg',
    ], [['id' => (string) Str::uuid(), 'name' => 'Drinks', 'position' => 0]], [[
        'platform' => 'uber_eats', 'delivery_price' => 6.5, 'item_url' => 'https://ubereats.com/store/x/item/u1', 'external_ref' => 'u1',
    ]]);

    $items = app(ManualMenuItems::class);
    $row = $items->rows((string) $user->id)[0];
    $model = $items->toMenuItemModel($row);

    expect($model)->toBeInstanceOf(MenuItem::class)
        ->and($model->exists)->toBeFalse()
        ->and($model->id)->toBe((string) $row->id)
        ->and($model->name)->toBe('Iced Latte')
        ->and($model->description)->toBe('Cold and strong')
        ->and($model->base_price)->toBe(5.5)
        ->and($model->pickup_price)->toBe(5.0)
        ->and($model->delivery_price)->toBe(6.5)
        ->and($model->image_url)->toBe('https://cdn.test/hero.jpg')
        ->and($model->currency)->toBe('AUD')
        ->and($model->platformLinks)->toHaveCount(1)
        ->and($model->platformLinks[0]->platform)->toBe('uber_eats')
        ->and($model->platformLinks[0]->item_url)->toBe('https://ubereats.com/store/x/item/u1')
        ->and($model->categories->pluck('id')->all())->toBe($row->category_ids);
});

it('never persists the hydrated model or lazily queries the legacy tables', function () {
    // The legacy tables are dropped in Phase 5. A model that still lazy-loads
    // `platformLinks` would start throwing the moment they go, in production,
    // long after this suite went green.
    $user = mmiSeedDishWithOffers(base: 5.50);
    $items = app(ManualMenuItems::class);

    $model = $items->toMenuItemModel($items->rows((string) $user->id)[0]);

    expect($model->exists)->toBeFalse()
        ->and($model->relationLoaded('platformLinks'))->toBeTrue()
        ->and($model->relationLoaded('categories'))->toBeTrue();
});

// Slice 7 Task 6 chose content.manual_overrides as the content-lane home for
// is_manual, but the write and the read landed in different tasks: the
// controller recorded overrides while this reader still hardcoded false, so an
// owner-edited dish read as synced and MenuFetchJob would have clobbered it on
// the next scrape. That is the loop this closes.
it('reports is_manual for a dish the owner has authored a column of', function () {
    $user = mmiSeedDishWithOffers(base: 5.50);
    $itemId = (string) DB::connection('pgsql')->table('content.items')->value('id');

    expect(app(ManualMenuItems::class)->rows((string) $user->id)[0]->is_manual)->toBeFalse();

    DB::connection('pgsql')->table('content.manual_overrides')->insert([
        'id' => (string) Str::uuid(),
        'item_id' => $itemId,
        'facet' => 'f_text',
        'column_name' => 'headline',
        'value' => 'Owner Name',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = app(ManualMenuItems::class)->rows((string) $user->id)[0];
    expect($row->is_manual)->toBeTrue()
        ->and(app(ManualMenuItems::class)->toMenuItemModel($row)->is_manual)->toBeTrue();
});
