<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Services\Migration\ShopBackfiller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Slice 5a §3.4: the live site.shop_brands/site.shop_products rows become
// storefront collections + product items through the slice-0b manual lane —
// production code, tested, idempotent, re-runnable. Never raw writes into
// content.*.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
});

it('lands a store as a collection with a storefront sidecar', function () {
    [$user, $brand] = makeShopBrand(['name' => 'Allbirds', 'referral_query' => 'ref=partna',
        'discount_code' => 'TEN', 'provider' => 'shopify']);
    makeShopProduct($brand, ['url' => 'https://allbirds.test/a', 'price' => '95.00']);

    app(ShopBackfiller::class)->run();

    $collection = DB::table('content.collections')->where('user_id', $user->id)->first();
    expect($collection->label)->toBe('Allbirds')
        ->and($collection->kind)->toBe('storefront')
        // SQLite's stand-in has no real boolean type — the column round-trips
        // as an int(0/1), not PHP's `false` — cast rather than assert on the
        // raw driver value (mirrors the same gotcha elsewhere in this suite).
        ->and((bool) $collection->is_user_created)->toBeFalse();

    $store = DB::table('content.storefronts')->where('collection_id', $collection->id)->first();
    expect($store->referral_query)->toBe('ref=partna')
        ->and($store->discount_code)->toBe('TEN');
});

it('lands products as items joined to their store collection', function () {
    [$user, $brand] = makeShopBrand();
    makeShopProduct($brand, ['url' => 'https://s.test/a', 'position' => 0]);
    makeShopProduct($brand, ['url' => 'https://s.test/b', 'position' => 1]);

    $result = app(ShopBackfiller::class)->run();

    expect($result['products'])->toBe(2)
        ->and(DB::table('content.items')->where('kind', 'product')->count())->toBe(2)
        ->and(DB::table('content.collection_items')->count())->toBe(2);
});

it('preserves the owner ordering on collection_items', function () {
    [$user, $brand] = makeShopBrand();
    makeShopProduct($brand, ['url' => 'https://s.test/a', 'position' => 1]);
    makeShopProduct($brand, ['url' => 'https://s.test/b', 'position' => 0]);

    app(ShopBackfiller::class)->run();

    $positions = DB::table('content.collection_items')->orderBy('position')->pluck('position');
    expect($positions->all())->toBe([0, 1]);
});

it('is idempotent — a second run mints nothing new and keeps item ids', function () {
    [$user, $brand] = makeShopBrand();
    makeShopProduct($brand, ['url' => 'https://s.test/a']);

    app(ShopBackfiller::class)->run();
    $before = DB::table('content.items')->pluck('id')->sort()->values();

    app(ShopBackfiller::class)->run();
    $after = DB::table('content.items')->pluck('id')->sort()->values();

    expect($after->all())->toBe($before->all())
        ->and(DB::table('content.collections')->count())->toBe(1);
});

it('writes nothing on a dry run but still counts', function () {
    [$user, $brand] = makeShopBrand();
    makeShopProduct($brand, ['url' => 'https://s.test/a']);

    $result = app(ShopBackfiller::class)->run(dryRun: true);

    expect($result['products'])->toBe(1)
        ->and(DB::table('content.items')->count())->toBe(0);
});

it('skips and counts a product with no url rather than minting a coord for empty string', function () {
    [$user, $brand] = makeShopBrand();
    makeShopProduct($brand, ['url' => '']);

    expect(app(ShopBackfiller::class)->run()['skipped_no_url'])->toBe(1);
});

it('fires all three cache lanes for a touched site', function () {
    Queue::fake();
    [$user, $brand, $site] = makeShopBrand(withSite: true);
    makeShopProduct($brand, ['url' => 'https://s.test/a']);
    // Laravel binds timestamps at SECOND precision (same hazard documented in
    // MediaUploadBackfillerTest's identical assertion) — without backdating,
    // this run's own updated_at can land in the same second as the fixture's
    // and the "changed" assertion below would pass or fail on wall-clock luck.
    DB::table('site.sites')->where('id', $site->id)->update(['updated_at' => now()->subMinute()]);
    $before = $site->fresh()->updated_at;

    app(ShopBackfiller::class)->run();

    Queue::assertPushed(CloudflareCachePurgeJob::class);
    expect($site->fresh()->updated_at->gt($before))->toBeTrue();
    expect(DB::table('site.site_build_state')->where('site_id', $site->id)
        ->value('content_revision'))->toBeGreaterThan(0);
});
