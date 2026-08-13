<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Services\Platforms\IntegrationConnectionCacheRefresher;
use App\Services\Platforms\ShopCatalog;
use App\Services\Platforms\Strategies\Fetch\FetchNotModifiedException;
use App\Services\Platforms\Strategies\Fetch\ShopFetch;
use App\Services\Shop\ShopContentWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Queue::fake();
});

function shopCollection(string $userId): string
{
    $id = (string) Str::uuid();
    DB::table('content.collections')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => 'storefront',
        'label' => 'Test Store', 'is_user_created' => false, 'position' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function shopBlob(string $url, string $title = 'A product'): array
{
    return [
        'url' => $url, 'title' => $title, 'price' => '10.00',
        'currency' => 'AUD', 'available' => true, 'variants' => [],
        'images' => [], 'productId' => '', 'handle' => '', 'vendor' => '',
        'description' => '', 'image' => null, 'variantId' => '',
    ];
}

it('un-retires a product the owner removes and re-adds', function () {
    $pro = createTenant('shop-readd');
    $collectionId = shopCollection($pro->id);
    $writer = app(ShopContentWriter::class);
    $url = 'https://store.example.com/products/hat';

    $writer->syncStore($pro->id, $collectionId, [shopBlob($url)], 'AUD');
    $itemId = DB::table('content.collection_items')
        ->where('collection_id', $collectionId)->value('item_id');

    // The owner removes it: syncing an empty catalogue retires the item.
    $writer->syncStore($pro->id, $collectionId, [], 'AUD');
    expect(DB::table('content.items')->where('id', $itemId)->value('removed_at'))->not->toBeNull();

    // The owner re-adds the same URL. It must return, on the SAME item row —
    // a new row would orphan analytics.item_views and any pin.
    $writer->syncStore($pro->id, $collectionId, [shopBlob($url)], 'AUD');

    expect(DB::table('content.items')->where('id', $itemId)->value('removed_at'))->toBeNull()
        ->and(DB::table('content.collection_items')
            ->where('collection_id', $collectionId)->value('item_id'))->toBe($itemId);
});

it('does not un-retire an item outside the catalogue being synced', function () {
    $pro = createTenant('shop-scope');
    $a = shopCollection($pro->id);
    $b = shopCollection($pro->id);
    $writer = app(ShopContentWriter::class);

    $writer->syncStore($pro->id, $b, [shopBlob('https://store.example.com/products/other')], 'AUD');
    $otherId = DB::table('content.collection_items')->where('collection_id', $b)->value('item_id');
    DB::table('content.items')->where('id', $otherId)->update(['removed_at' => now()]);

    // Syncing collection A must leave B's retired item alone.
    $writer->syncStore($pro->id, $a, [shopBlob('https://store.example.com/products/hat')], 'AUD');

    expect(DB::table('content.items')->where('id', $otherId)->value('removed_at'))->not->toBeNull();
});

// ── The un-retire in syncStore() is not itself owner-exclusive — ShopFetch's
// scheduled 6-hourly sync reaches it too. The owner-vs-connector boundary
// lives in ShopFetch's CALLERS: it skips a hand-curated brand and the
// individual bucket. These two pin that boundary at the ShopFetch level —
// each MUST fail if its matching filter is deleted from ShopFetch::fetch(),
// because a deleted filter leaves the excluded brand in $latestBrands and
// ShopCatalog::syncLatest() gets called on it, which the mock forbids.

/** A shop connection with one brand, shaped for a single ShopFetch::fetch() call. */
function shopFetchBoundaryBrand(string $userId, array $brandAttrs = []): IntegrationConnection
{
    $conn = IntegrationConnection::create([
        'user_id' => $userId, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    ShopBrand::create($brandAttrs + [
        'connection_id' => $conn->id, 'brand_id' => 'boundary-brand', 'provider' => 'shopify',
        'url' => 'https://boundary.example', 'selection_mode' => 'manual', 'position' => 0,
    ]);

    return $conn;
}

it('ShopFetch never calls syncLatest for a hand-curated brand', function () {
    $pro = createTenant('shop-fetch-curated');
    $conn = shopFetchBoundaryBrand($pro->id, ['products_curated_at' => now()]);

    $catalog = Mockery::mock(ShopCatalog::class);
    $catalog->shouldNotReceive('syncLatest');
    $refresher = Mockery::mock(IntegrationConnectionCacheRefresher::class);
    $refresher->shouldNotReceive('refresh');

    // The curated brand is the ONLY brand on this connection, so excluding it
    // leaves $latestBrands empty — the quiet 304 path, not a hard error.
    expect(fn () => (new ShopFetch($catalog, $refresher, app(ShopContentWriter::class)))->fetch($conn->fresh()))
        ->toThrow(FetchNotModifiedException::class);
});

it('ShopFetch never calls syncLatest for an individual-bucket brand', function () {
    $pro = createTenant('shop-fetch-individual');
    $conn = shopFetchBoundaryBrand($pro->id, ['is_individual' => true]);

    $catalog = Mockery::mock(ShopCatalog::class);
    $catalog->shouldNotReceive('syncLatest');
    $refresher = Mockery::mock(IntegrationConnectionCacheRefresher::class);
    $refresher->shouldNotReceive('refresh');

    // Same shape as the curated case: the only brand present is excluded, so
    // $latestBrands is empty and the fetch is a quiet no-op, not an error.
    expect(fn () => (new ShopFetch($catalog, $refresher, app(ShopContentWriter::class)))->fetch($conn->fresh()))
        ->toThrow(FetchNotModifiedException::class);
});

it('keeps an item that carries a stale coord alongside a live one', function () {
    $pro = createTenant('shop-stale');
    $collectionId = shopCollection($pro->id);
    $writer = app(ShopContentWriter::class);
    $url = 'https://store.example.com/products/hat';

    $writer->syncStore($pro->id, $collectionId, [shopBlob($url)], 'AUD');
    $itemId = DB::table('content.collection_items')
        ->where('collection_id', $collectionId)->value('item_id');

    // A product that gained a URL upstream carries a second, now-stale coord
    // on the same item — the pid:-derived one it was first written under.
    // Also anchored (content.item_anchors), matching how it would really have
    // landed: bound to $itemId by a PRIOR resolveItems() pass back when this
    // coord was the only one the product had, then left dangling once the
    // product started resolving under the url-derived coord instead. Without
    // the anchor, resolveItems()'s union-find (no shared identity key, no
    // prior binding) mints a BRAND NEW item for this coord on the very next
    // syncStore() call and repoints this row's item_id there before
    // retireAbsent() ever runs — which would silently defeat this test's
    // premise rather than exercise the bug.
    $sourceId = DB::table('content.source_items')->where('item_id', $itemId)->value('source_id');
    $staleCoord = 'manual:stale-'.Str::random(8);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => $staleCoord, 'item_id' => $itemId,
        'kind' => 'product', 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('content.item_anchors')->insert([
        'coord' => $staleCoord, 'user_id' => $pro->id, 'item_id' => $itemId, 'bound_at' => now(),
    ]);

    // Re-sync the SAME live catalogue. Nothing left it, so nothing may retire.
    $writer->syncStore($pro->id, $collectionId, [shopBlob($url)], 'AUD');

    expect(DB::table('content.items')->where('id', $itemId)->value('removed_at'))->toBeNull()
        ->and(DB::table('content.collection_items')
            ->where('collection_id', $collectionId)->where('item_id', $itemId)->exists())->toBeTrue();
});

// The delete-links-FIRST-then-requery ordering in retireAbsent() is
// load-bearing: reversed, the synced store's own stale link satisfies the
// "still linked to a storefront of this user" test and cross-store retirement
// silently becomes a no-op. This asserts the cross-store case, not merely the
// single-store one, so that inversion fails here.
it('still retires an item dropped from its only store while sparing one held elsewhere', function () {
    $pro = createTenant('shop-cross');
    $a = shopCollection($pro->id);
    $b = shopCollection($pro->id);
    $writer = app(ShopContentWriter::class);
    $shared = 'https://store.example.com/products/shared';
    $only = 'https://store.example.com/products/only';

    $writer->syncStore($pro->id, $a, [shopBlob($shared), shopBlob($only, 'Only')], 'AUD');
    $writer->syncStore($pro->id, $b, [shopBlob($shared)], 'AUD');

    $sharedId = DB::table('content.collection_items')->where('collection_id', $b)->value('item_id');
    $onlyId = DB::table('content.collection_items')
        ->where('collection_id', $a)->where('item_id', '!=', $sharedId)->value('item_id');

    // Store A drops both. The shared one survives (B still lists it); the
    // other retires.
    $writer->syncStore($pro->id, $a, [], 'AUD');

    expect(DB::table('content.items')->where('id', $sharedId)->value('removed_at'))->toBeNull()
        ->and(DB::table('content.items')->where('id', $onlyId)->value('removed_at'))->not->toBeNull();
});
