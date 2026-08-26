<?php

use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
use Illuminate\Support\Str;

// CCH-10: ShopController::brandProducts()'s picker-catalog cache took no
// lock (Cache::remember), so two opens of a cold picker by the same owner
// (double-click, multiple tabs) each independently re-scraped the upstream
// store. This is a WIRING test, not a concurrency proof — be honest about
// what it buys: it mocks CacheLockService itself (rather than the Cache
// facade) and asserts WHICH seam the controller calls through, with which
// key/TTL/lock/block, instead of on Redis/array-store call sequencing the
// full HTTP path also touches for unrelated reasons (auth, feature flags).
// It does NOT exercise two concurrent requests, so it cannot demonstrate
// that the lock actually serialises them — only that the single-flight seam
// is on the path and correctly parameterised.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

function shopSingleFlightUser(string $h): User
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

it('routes the picker-catalog read through CacheLockService::rememberLocked, keyed and timed correctly', function () {
    $user = shopSingleFlightUser('singleflight1');

    $store = new StoreRecord(
        externalRef: 'singleflight-store',
        provider: 'shopify',
        url: 'https://singleflight.example',
        discountCode: '',
    );
    app(ShopConnections::class)->anchor($user, $store->provider, $store->externalRef);
    app(ShopContentWriter::class)->upsertStore($store, (string) $user->id);

    $expectedKey = CacheKeyGenerator::shopifyBrandCatalog('singleflight-store');

    // Structurally unsatisfiable by the pre-fix Cache::remember() code: that
    // call never reaches CacheLockService at all, so this ->once() fails at
    // Mockery::close() against unlocked code — the assertion that actually
    // distinguishes locked from unlocked.
    $this->mock(CacheLockService::class, function ($m) use ($expectedKey) {
        $m->shouldReceive('rememberLocked')
            ->once()
            ->withArgs(function ($key, $ttl, $callback, $lockSeconds, $blockSeconds) use ($expectedKey) {
                // 600 = ShopController::CATALOG_TTL_MINUTES * 60, passed RAW —
                // jitter now lives inside rememberLocked itself (same
                // JitteredTtl trait), so a pre-jittered TTL here would mean
                // the controller double-jitters it.
                expect($key)->toBe($expectedKey)
                    ->and($ttl)->toBe(600)
                    ->and($callback)->toBeInstanceOf(Closure::class)
                    // Derived from connect_budget_seconds (default 45s, the
                    // same FetchBudget the closure runs under): lockSeconds
                    // exceeds the closure's worst case (45 + 5 margin) so a
                    // slow scrape can't outlive its own lock; blockSeconds
                    // matches the fetch's own budget so a second tab waits
                    // for the FIRST fetch to resolve instead of giving up
                    // early and re-scraping itself.
                    ->and($lockSeconds)->toBe(50)
                    ->and($blockSeconds)->toBe(45);

                return true;
            })
            ->andReturn([
                ['productId' => 'p1', 'title' => 'Single-Flight Tee'],
            ]);
    });

    actingAsUser($user)->getJson('/api/platforms/shop/brands/singleflight-store/products')
        ->assertOk()
        ->assertJsonPath('products.0.productId', 'p1');
});
