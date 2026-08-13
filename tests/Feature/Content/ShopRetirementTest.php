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
