<?php

// PWL-6 / PWL-11 / PWL-12: ShopController::forget(), CustomLinksController::
// removeLink()/forget(), and OnlineOrderingController::removeEntry()/forget()
// deleted site.platform_connections rows (and, for Shop, the store's own
// content.* rows — then the child shop_brands/shop_products rows, now dropped
// and re-homed onto content.storefronts) without taking ManagesIntegrationConnection::
// withConnectionLock() — the sole convention every sibling write on these
// controllers already uses (ShopController::removeBrand/removeProduct/
// addBrand/etc., CustomLinksController::addLink, OnlineOrderingController::
// addEntry). An unlocked delete could race a locked writer (or a concurrent
// scheduled refresh) and clobber/skip work underneath the "in-use" lock.
//
// Mirrors SessionA2LockTest.php's proof shape: pre-acquire the SAME key
// formula CacheKeyGenerator::platformConnectionLock($platform, $userId) a
// concurrent writer would hold, then prove each newly-wrapped method
// genuinely contends on it (423) instead of sailing through and deleting the
// row underneath the "in-use" lock.
//
// CACHE_STORE=array in phpunit.xml, so Cache::lock() here is a real
// in-process ArrayLock — the block(5, ...) wait in withConnectionLock() is
// genuine wall time, not mocked. That wall-clock cost per test IS the proof.

use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Phase 6: custom links live in the custom_links POOL.
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

function sessionA3User(string $h): User
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

// ── Shop (PWL-6) ────────────────────────────────────────────────────────────

it('shop forget (store-row delete + forgetConnection in ShopController::forget) is blocked by a held platform lock and the store and product rows survive', function () {
    // The survival assertion moved with the storage. It used to name the
    // site.shop_brands / site.shop_products rows forget() deleted; those tables
    // are gone, and forget() now reaches ShopContentWriter::retireStore(),
    // which deletes the content.storefronts + content.collections pair and
    // drops the collection_items links. Those are the rows that must survive a
    // lock-blocked call, so those are the rows this pins — the guarantee is
    // unchanged, only its subject moved.
    $user = sessionA3User('shoplock1');
    $store = new StoreRecord(
        externalRef: 'brand-x',
        provider: 'shopify',
        url: 'https://x.example.com',
    );
    $connection = app(ShopConnections::class)->anchor($user, $store->provider, $store->externalRef);
    app(ShopContentWriter::class)->upsertStore($store, (string) $user->id);

    $stored = app(ShopConnections::class)->store($user, $store->externalRef);
    makeShopStoreProduct($stored, ['productId' => 'p1']);

    $collectionId = (string) $stored->collectionId;

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('shop', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->deleteJson('/api/platforms/shop')->assertStatus(423);
    } finally {
        $lock->release();
    }

    // Untouched — proves the delete never got past the lock.
    expect(DB::table('content.storefronts')->where('collection_id', $collectionId)->exists())->toBeTrue();
    expect(DB::table('content.collections')->where('id', $collectionId)->exists())->toBeTrue();
    expect(DB::table('content.collection_items')->where('collection_id', $collectionId)->count())->toBe(1);
    $freshConnection = $connection->fresh();
    expect($freshConnection)->not->toBeNull();
    expect($freshConnection->deleted_at)->toBeNull();
});

// ── Custom Links (PWL-11) ───────────────────────────────────────────────────

// The custom-links / online-ordering halves of this file left with the
// pseudo-platform retirement (2026-08-19) — their endpoints are deleted;
// the shop half above is the surviving PWL-6 pin.
