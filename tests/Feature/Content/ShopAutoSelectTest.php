<?php

use App\Models\Core\Site\Site;
use App\Services\Shop\ShopAutoSelector;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    Queue::fake();
});

/** A storefront WITH its content.storefronts sidecar — unlike this suite's
 * shared storefront() helper, ShopAutoSelector reads the sidecar's gate
 * columns, so the row must exist. */
function autoSelectStore(string $userId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::table('content.collections')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => 'storefront', 'label' => 'Store',
        'is_user_created' => false, 'position' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.storefronts')->insert([
        'collection_id' => $id, 'user_id' => $userId,
        'provider' => 'shopify', 'external_ref' => 'ref-'.Str::random(6),
        'referral_query' => '',
        'products_curated_at' => null, 'products_autoselected_at' => null,
        'created_at' => now(), 'updated_at' => now(),
        ...$overrides,
    ]);

    return $id;
}

function autoSelectProduct(string $userId, string $collectionId, int $position, ?string $name = null): string
{
    static $sources = [];
    // Query-first like shopProduct(): idx_content_sources_manual allows ONE
    // manual source per user, and autoSelectMenuItem() may have minted it.
    $sourceId = $sources[$userId] ??= (DB::table('content.sources')
        ->where('user_id', $userId)->where('kind', 'manual')->value('id')
        ?? poolSource($userId, null));
    $itemId = poolItem($userId, $sourceId, 'product', $name ?? 'P'.$position, '2026-08-0'.(($position % 8) + 1).'T00:00:00Z');
    DB::table('content.collection_items')->insert([
        'collection_id' => $collectionId, 'item_id' => $itemId,
        'source_id' => null, 'position' => $position,
    ]);

    return $itemId;
}

/** A live dish on the user's menu — content.items kind='menu_item', the read
 * the Item 12 name backstop compares against. */
function autoSelectMenuItem(string $userId, string $name): string
{
    $sourceId = DB::table('content.sources')
        ->where('user_id', $userId)->where('kind', 'manual')->value('id')
        ?? poolSource($userId, null);

    return poolItem($userId, $sourceId, 'menu_item', $name, '2026-08-01T00:00:00Z');
}

/** The Item 12 food-guard fixture: sector on core.users, previous website on
 * the workplace card — the pair the own-domain comparison reads. */
function autoSelectOwnWebsite(string $userId, string $siteId, string $previousWebsite, ?string $sector): void
{
    DB::table('core.users')->where('id', $userId)->update(['sector' => $sector]);
    DB::table('site.workplaces')->insert([
        'site_id' => $siteId, 'previous_website' => $previousWebsite,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function autoselectedAt(string $collectionId): mixed
{
    return DB::table('content.storefronts')->where('collection_id', $collectionId)->value('products_autoselected_at');
}

it('pins up to 5 newest products in catalogue order on first connect, once', function () {
    [$pro, $siteId] = poolTenant();
    $store = autoSelectStore($pro->id);
    $ids = [];
    foreach (range(0, 6) as $pos) {
        $ids[$pos] = autoSelectProduct($pro->id, $store, $pos);
    }

    $pinned = app(ShopAutoSelector::class)->selectInitial($store);

    expect($pinned)->toBe(5);

    $sectionId = DB::table('site.sections')
        ->where('site_id', $siteId)->where('key', 'pool:shop')->value('id');
    $pins = DB::table('site.section_items')->where('section_id', $sectionId)
        ->where('state', 'pinned')->orderBy('sort_key')->pluck('item_id')->all();

    // position asc = the store's own newest-first order; 5 and 6 miss the cut.
    expect($pins)->toBe([$ids[0], $ids[1], $ids[2], $ids[3], $ids[4]])
        ->and(autoselectedAt($store))->not->toBeNull();

    // Second run: the stamp makes it a permanent no-op.
    expect(app(ShopAutoSelector::class)->selectInitial($store))->toBe(0)
        ->and(DB::table('site.section_items')->where('section_id', $sectionId)->count())->toBe(5);
});

it('does nothing when the owner already hand-curated the store', function () {
    [$pro, $siteId] = poolTenant();
    $store = autoSelectStore($pro->id, ['products_curated_at' => now()->toDateTimeString()]);
    autoSelectProduct($pro->id, $store, 0);

    expect(app(ShopAutoSelector::class)->selectInitial($store))->toBe(0)
        ->and(DB::table('site.section_items')->count())->toBe(0)
        ->and(autoselectedAt($store))->toBeNull();
});

it('stamps but seeds nothing when the owner already engaged with this store on pool:shop', function () {
    [$pro, $siteId] = poolTenant();
    $store = autoSelectStore($pro->id);
    $itemA = autoSelectProduct($pro->id, $store, 0);
    autoSelectProduct($pro->id, $store, 1);

    // The owner deselected an auto item — an 'excluded' row IS engagement.
    $site = Site::query()->find($siteId);
    $section = app(PoolSectionProvisioner::class)->ensure($site, 'shop');
    DB::table('site.section_items')->insert([
        'id' => (string) Str::uuid(), 'section_id' => $section->id,
        'item_id' => $itemA, 'state' => 'excluded', 'sort_key' => 1.0, 'created_at' => now(),
    ]);

    expect(app(ShopAutoSelector::class)->selectInitial($store))->toBe(0)
        ->and(autoselectedAt($store))->not->toBeNull()
        ->and(DB::table('site.section_items')->where('state', 'pinned')->count())->toBe(0);
});

it('leaves the stamp unset on an empty catalogue so a later reconnect gets its chance', function () {
    [$pro] = poolTenant();
    $store = autoSelectStore($pro->id);

    expect(app(ShopAutoSelector::class)->selectInitial($store))->toBe(0)
        ->and(autoselectedAt($store))->toBeNull();

    // The catalogue fills later; the next connect-time call still fires.
    autoSelectProduct($pro->id, $store, 0);
    expect(app(ShopAutoSelector::class)->selectInitial($store))->toBe(1)
        ->and(autoselectedAt($store))->not->toBeNull();
});

it('skips removed items and appends after another store\'s existing pins', function () {
    [$pro, $siteId] = poolTenant();

    // Store A was seeded earlier: two pins already on the section.
    $storeA = autoSelectStore($pro->id);
    autoSelectProduct($pro->id, $storeA, 0);
    autoSelectProduct($pro->id, $storeA, 1);
    expect(app(ShopAutoSelector::class)->selectInitial($storeA))->toBe(2);

    // Store B connects: its first product is retired, second is live.
    $storeB = autoSelectStore($pro->id);
    $gone = autoSelectProduct($pro->id, $storeB, 0);
    $live = autoSelectProduct($pro->id, $storeB, 1);
    DB::table('content.items')->where('id', $gone)->update(['removed_at' => now()]);

    expect(app(ShopAutoSelector::class)->selectInitial($storeB))->toBe(1);

    $sectionId = DB::table('site.sections')
        ->where('site_id', $siteId)->where('key', 'pool:shop')->value('id');
    $pins = DB::table('site.section_items')->where('section_id', $sectionId)
        ->where('state', 'pinned')->orderBy('sort_key')->pluck('item_id')->all();

    // Store A's pins keep their order; store B's live product appends last.
    expect(count($pins))->toBe(3)
        ->and(end($pins))->toBe($live)
        ->and($pins)->not->toContain($gone);
});

it('no-ops on a user with no site row', function () {
    [$pro] = poolTenant();
    $store = autoSelectStore($pro->id);
    autoSelectProduct($pro->id, $store, 0);
    DB::table('site.sites')->where('user_id', $pro->id)->delete();

    expect(app(ShopAutoSelector::class)->selectInitial($store))->toBe(0)
        ->and(autoselectedAt($store))->toBeNull();
});

// ── Item 12: menu/shop separation ────────────────────────────────────────────

it('fills library-only for a food account whose store is on their own website domain', function () {
    // The famished-wolf shape: a restaurant's own WooCommerce ordering site is
    // probed as a store, and auto-select put the menu on the Sell page twice.
    [$pro, $siteId] = poolTenant();
    autoSelectOwnWebsite($pro->id, $siteId, 'https://www.thefamishedwolf.com.au', 'restaurant');
    $store = autoSelectStore($pro->id, ['url' => 'https://thefamishedwolf.com.au']);
    autoSelectProduct($pro->id, $store, 0, 'Wolf Dogs');
    autoSelectProduct($pro->id, $store, 1, 'Classic Mac');

    expect(app(ShopAutoSelector::class)->selectInitial($store))->toBe(0)
        // No stamp: a policy answer, not the owner's — same rule as an empty
        // catalogue.
        ->and(autoselectedAt($store))->toBeNull()
        ->and(DB::table('site.section_items')->count())->toBe(0)
        // The catalogue itself is untouched — library-only, not un-ingested.
        ->and(DB::table('content.collection_items')->where('collection_id', $store)->count())->toBe(2);
});

it('auto-fills unchanged for a non-food account with a store on their own domain', function () {
    [$pro, $siteId] = poolTenant();
    autoSelectOwnWebsite($pro->id, $siteId, 'https://www.beardbrand.com', 'barber');
    $store = autoSelectStore($pro->id, ['url' => 'https://beardbrand.com']);
    autoSelectProduct($pro->id, $store, 0);

    expect(app(ShopAutoSelector::class)->selectInitial($store))->toBe(1)
        ->and(autoselectedAt($store))->not->toBeNull();
});

it('auto-fills unchanged for a food account whose store is on a different domain', function () {
    // The mixed case the guard must preserve: a restaurant that also sells
    // real merch through a store that is NOT their own website.
    [$pro, $siteId] = poolTenant();
    autoSelectOwnWebsite($pro->id, $siteId, 'https://www.thefamishedwolf.com.au', 'restaurant');
    $store = autoSelectStore($pro->id, ['url' => 'https://wolf-merch.myshopify.com']);
    autoSelectProduct($pro->id, $store, 0);

    expect(app(ShopAutoSelector::class)->selectInitial($store))->toBe(1)
        ->and(autoselectedAt($store))->not->toBeNull();
});

it('never auto-pins a product whose normalized name matches a live menu item, in any sector', function () {
    // The Classic Mac class, outside the sector guard: no workplace, no
    // sector — the name collision alone keeps the dish off the Sell page,
    // and the newest non-colliding products still fill all 5 slots.
    [$pro, $siteId] = poolTenant();
    autoSelectMenuItem($pro->id, 'Classic Mac!');
    $store = autoSelectStore($pro->id);
    $collided = autoSelectProduct($pro->id, $store, 0, 'Classic Mac');
    $ids = [];
    foreach (range(1, 6) as $pos) {
        $ids[$pos] = autoSelectProduct($pro->id, $store, $pos);
    }

    expect(app(ShopAutoSelector::class)->selectInitial($store))->toBe(5);

    $sectionId = DB::table('site.sections')
        ->where('site_id', $siteId)->where('key', 'pool:shop')->value('id');
    $pins = DB::table('site.section_items')->where('section_id', $sectionId)
        ->where('state', 'pinned')->orderBy('sort_key')->pluck('item_id')->all();

    // Filter-then-take: the collision costs the dish its slot, never the
    // catalogue a pin — P1..P5 fill all five.
    expect($pins)->toBe([$ids[1], $ids[2], $ids[3], $ids[4], $ids[5]])
        ->and($pins)->not->toContain($collided);
});

it('leaves the stamp unset when every product collides with the menu', function () {
    [$pro] = poolTenant();
    $menuItemId = autoSelectMenuItem($pro->id, 'Classic Mac');
    $store = autoSelectStore($pro->id);
    autoSelectProduct($pro->id, $store, 0, 'Classic Mac');

    expect(app(ShopAutoSelector::class)->selectInitial($store))->toBe(0)
        ->and(autoselectedAt($store))->toBeNull();

    // A removed dish no longer vetoes — the next select-time call gets its
    // chance, same as a late-filling catalogue.
    DB::table('content.items')->where('id', $menuItemId)->update(['removed_at' => now()]);
    expect(app(ShopAutoSelector::class)->selectInitial($store))->toBe(1)
        ->and(autoselectedAt($store))->not->toBeNull();
});
