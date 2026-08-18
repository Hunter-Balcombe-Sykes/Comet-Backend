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

/**
 * R6 (2026-08-18 Instagram wave) — the commerce probe recorded its misses but
 * not its match.
 *
 * bd593dfdf moved StoreBrandSeeder's observation write above its !isMatch()
 * return, so a probe MISS finally landed in routing.link_observations. But
 * CommerceProbeJob::probe() only reaches StoreBrandSeeder on the store arms;
 * a URL whose page carries Product markup goes to THIS seeder, which wrote no
 * observation at all. The wave logged all six shop-probe misses and nothing
 * for paytherent.net.au — the one URL that actually produced a product item
 * and a partna.manual_product connection. The ledger read backwards from the
 * purpose StoreBrandSeeder's own comment states: "why is this on my page?"
 * had no answer, only "why isn't it?".
 */
it('records a resolved product in the observation log', function () {
    setupRoutingTables();
    $user = shopSeederUser('seederobs');

    app(ShopProductSeeder::class)->seed($user, [
        'productId' => 'a1', 'title' => 'Private: Demo', 'url' => 'https://paytherent.net.au/',
    ], 'commerce_probe');

    $observation = DB::table('routing.link_observations')->where('user_id', $user->id)->first();

    expect($observation)->not->toBeNull();
    expect($observation->source)->toBe('commerce_probe');
    expect($observation->surface_key)->toBe('partna.manual_product');
    expect($observation->verdict)->toBe('place');
    expect($observation->registrable_key)->toBe('paytherent.net.au');
});

/** The other half of the same sentence: a refusal is a decision too. */
it('records a tombstoned product refusal in the observation log', function () {
    setupRoutingTables();
    $user = shopSeederUser('seedertomb');

    // A shop connection the user explicitly removed, with no live sibling —
    // the guard seed() already had, which used to return false silently.
    DB::table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'surface_key' => 'partna.manual_product',
        'resource_id' => 'individual',
        'routing_class' => 'shop',
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => now(),
    ]);

    $written = app(ShopProductSeeder::class)->seed($user, [
        'productId' => 'a1', 'title' => 'A', 'url' => 'https://example.com/a',
    ], 'commerce_probe');

    $observation = DB::table('routing.link_observations')->where('user_id', $user->id)->first();

    expect($written)->toBeFalse();
    expect($observation)->not->toBeNull();
    expect($observation->verdict)->toBe('reject');
    expect($observation->block_reason)->toBe('tombstoned');
});
