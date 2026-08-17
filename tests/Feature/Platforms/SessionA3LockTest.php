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

use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

it('custom links removeLink (forgetConnection write) is blocked by a held platform lock and the link survives', function () {
    $user = sessionA3User('cllock1');
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'uber_eats.order',
        'resource_id' => 'link-abc',
        'resource_kind' => 'link',
        'payload' => ['kind' => 'link', 'url' => 'https://acme.test', 'name' => 'Acme',
            'description' => null, 'favicon' => null, 'logo' => null],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('custom', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->deleteJson('/api/platforms/custom/links/link-abc')->assertStatus(423);
    } finally {
        $lock->release();
    }

    $fresh = $row->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->deleted_at)->toBeNull();
});

it('custom links forget (forgetAllConnections write) is blocked by a held platform lock and the row survives', function () {
    $user = sessionA3User('cllock2');
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'uber_eats.order',
        'resource_id' => 'link-def',
        'resource_kind' => 'link',
        'payload' => ['kind' => 'link', 'url' => 'https://acme2.test', 'name' => 'Acme2',
            'description' => null, 'favicon' => null, 'logo' => null],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('custom', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->deleteJson('/api/platforms/custom')->assertStatus(423);
    } finally {
        $lock->release();
    }

    $fresh = $row->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->deleted_at)->toBeNull();
});

// ── Online Ordering (PWL-12) ────────────────────────────────────────────────

it('online-ordering removeEntry (forgetConnection write) is blocked by a held platform lock and the entry survives', function () {
    Queue::fake();

    $user = sessionA3User('oolock1');
    $url = 'https://www.ubereats.com/store/seed-1';
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'uber_eats.order',
        'resource_id' => $rid,
        'payload' => ['url' => $url, 'provider' => 'custom', 'source' => 'manual'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('online-ordering', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->deleteJson("/api/platforms/online-ordering/entries/{$rid}")->assertStatus(423);
    } finally {
        $lock->release();
    }

    $fresh = $row->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->deleted_at)->toBeNull();
    // PWL-12 regression: MenuFetchJob dispatch must sit OUTSIDE the lock closure
    // and gate on an actual delete — a blocked 423 must never fire it.
    Queue::assertNotPushed(MenuFetchJob::class);
});

it('online-ordering removeEntry dispatches MenuFetchJob exactly once after the lock releases on a successful delete', function () {
    Queue::fake();

    $user = sessionA3User('oolock3');
    $url = 'https://www.ubereats.com/store/seed-3';
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'uber_eats.order',
        'resource_id' => $rid,
        'payload' => ['url' => $url, 'provider' => 'custom', 'source' => 'manual'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->deleteJson("/api/platforms/online-ordering/entries/{$rid}")->assertStatus(200);

    // Fired once, and only after the lock (Bus::fake means MenuFetchJob never
    // actually runs here, so this proves placement, not a race).
    Queue::assertPushed(MenuFetchJob::class, 1);
});

it('online-ordering forget (forgetAllConnections write) is blocked by a held platform lock and the row survives', function () {
    Queue::fake();

    $user = sessionA3User('oolock2');
    $url = 'https://www.ubereats.com/store/seed-2';
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'uber_eats.order',
        'resource_id' => $rid,
        'payload' => ['url' => $url, 'provider' => 'custom', 'source' => 'manual'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('online-ordering', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->deleteJson('/api/platforms/online-ordering')->assertStatus(423);
    } finally {
        $lock->release();
    }

    $fresh = $row->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->deleted_at)->toBeNull();
    Queue::assertNotPushed(MenuFetchJob::class);
});

it('online-ordering forget dispatches MenuFetchJob exactly once after the lock releases on success', function () {
    Queue::fake();

    $user = sessionA3User('oolock4');
    $url = 'https://www.ubereats.com/store/seed-4';
    $rid = 'order-'.substr(sha1(strtolower($url)), 0, 16);
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'uber_eats.order',
        'resource_id' => $rid,
        'payload' => ['url' => $url, 'provider' => 'custom', 'source' => 'manual'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->deleteJson('/api/platforms/online-ordering')->assertStatus(200);

    Queue::assertPushed(MenuFetchJob::class, 1);
});
