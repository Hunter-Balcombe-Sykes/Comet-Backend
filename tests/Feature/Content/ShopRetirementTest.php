<?php

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
