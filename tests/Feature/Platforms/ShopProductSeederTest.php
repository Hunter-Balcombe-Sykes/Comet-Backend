<?php

use App\Models\Core\User\User;
use App\Services\Platforms\ShopProductSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Task 6 fix round 1, Finding 1: ShopProductSeeder stopped writing
// site.shop_products (Task 6's whole point), but its "existing selection"
// merge originally kept READING from it — a frozen snapshot from the moment
// this task shipped. CommerceProbeJob fires this seeder once per resolved
// link, so a user with two different product links scanned over time is a
// real, non-racy path that calls seed() twice — the second call's merge
// must see what the first call wrote, or syncStore()'s retire-absent
// silently drops it. ShopContentWriter::currentCatalogue() replaces the
// legacy read.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
});

function shopSeederUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('seed() called twice for the same user keeps BOTH products in content.*', function () {
    $user = shopSeederUser('seeder1');

    $productA = ['productId' => 'a1', 'title' => 'A', 'url' => 'https://example.com/a', 'price' => '10.00'];
    $productB = ['productId' => 'b1', 'title' => 'B', 'url' => 'https://example.com/b', 'price' => '20.00'];

    expect(app(ShopProductSeeder::class)->seed($user, $productA))->toBeTrue();
    expect(app(ShopProductSeeder::class)->seed($user, $productB))->toBeTrue();

    $collectionId = DB::table('content.storefronts')->where('external_ref', 'individual')->value('collection_id');
    expect($collectionId)->not->toBeNull();

    $skus = DB::table('content.collection_items as ci')
        ->join('content.f_catalog as f', 'f.item_id', '=', 'ci.item_id')
        ->where('ci.collection_id', $collectionId)
        ->orderBy('ci.position')
        ->pluck('f.sku')
        ->all();

    // Newest first: b1 landed second, so it leads. Before this fix, the
    // second seed() call's merge re-read an empty legacy table (this seeder
    // never wrote to it) and produced ['b1'] alone, retiring a1's item.
    expect($skus)->toBe(['b1', 'a1'])
        ->and(DB::table('content.items')->where('kind', 'product')->whereNull('removed_at')->count())->toBe(2);
});

it('seed() re-adding an already-present product moves it to the front without duplicating', function () {
    $user = shopSeederUser('seeder2');

    $productA = ['productId' => 'a1', 'title' => 'A', 'url' => 'https://example.com/a', 'price' => '10.00'];
    $productB = ['productId' => 'b1', 'title' => 'B', 'url' => 'https://example.com/b', 'price' => '20.00'];

    app(ShopProductSeeder::class)->seed($user, $productA);
    app(ShopProductSeeder::class)->seed($user, $productB);
    app(ShopProductSeeder::class)->seed($user, $productA);

    $collectionId = DB::table('content.storefronts')->where('external_ref', 'individual')->value('collection_id');
    $skus = DB::table('content.collection_items as ci')
        ->join('content.f_catalog as f', 'f.item_id', '=', 'ci.item_id')
        ->where('ci.collection_id', $collectionId)
        ->orderBy('ci.position')
        ->pluck('f.sku')
        ->all();

    expect($skus)->toBe(['a1', 'b1'])
        ->and(DB::table('content.items')->where('kind', 'product')->count())->toBe(2);
});

it('caps the individual bucket at MAX_INDIVIDUAL_PRODUCTS across repeated seed() calls', function () {
    $user = shopSeederUser('seeder3');

    foreach (range(1, 21) as $n) {
        app(ShopProductSeeder::class)->seed($user, [
            'productId' => "p{$n}", 'title' => "Product {$n}", 'url' => "https://example.com/p{$n}",
        ]);
    }

    $collectionId = DB::table('content.storefronts')->where('external_ref', 'individual')->value('collection_id');
    $skus = DB::table('content.collection_items as ci')
        ->join('content.f_catalog as f', 'f.item_id', '=', 'ci.item_id')
        ->where('ci.collection_id', $collectionId)
        ->orderBy('ci.position')
        ->pluck('f.sku')
        ->all();

    expect($skus)->toHaveCount(20)
        ->and($skus[0])->toBe('p21') // newest first
        ->and($skus)->not->toContain('p1'); // oldest evicted by the 20-cap
});
