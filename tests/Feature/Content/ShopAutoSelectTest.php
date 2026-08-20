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

function autoSelectProduct(string $userId, string $collectionId, int $position): string
{
    static $sources = [];
    $sourceId = $sources[$userId] ??= poolSource($userId, null);
    $itemId = poolItem($userId, $sourceId, 'product', 'P'.$position, '2026-08-0'.(($position % 8) + 1).'T00:00:00Z');
    DB::table('content.collection_items')->insert([
        'collection_id' => $collectionId, 'item_id' => $itemId,
        'source_id' => null, 'position' => $position,
    ]);

    return $itemId;
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
