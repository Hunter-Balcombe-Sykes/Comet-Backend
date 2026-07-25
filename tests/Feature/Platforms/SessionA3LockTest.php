<?php

// PWL-6 / PWL-11 / PWL-12: ShopController::forget(), CustomLinksController::
// removeLink()/forget(), and OnlineOrderingController::removeEntry()/forget()
// deleted site.platform_connections rows (and, for Shop, the child
// shop_brands/shop_products rows) without taking ManagesIntegrationConnection::
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
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\Site\ShopProduct;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
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

it('shop forget (child-row delete + forgetConnection in ShopController::forget) is blocked by a held platform lock and the brand/product rows survive', function () {
    $user = sessionA3User('shoplock1');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'shop',
        'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
    $brand = ShopBrand::create([
        'connection_id' => $connection->id,
        'brand_id' => 'brand-x',
        'provider' => 'shopify',
        'url' => 'https://x.example.com',
        'position' => 0,
    ]);
    $product = ShopProduct::create([
        'brand_id' => $brand->id,
        'product_id' => 'p1',
        'position' => 0,
        'data' => ['productId' => 'p1'],
    ]);

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('shop', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->deleteJson('/api/platforms/shop')->assertStatus(423);
    } finally {
        $lock->release();
    }

    // Untouched — proves the delete never got past the lock.
    expect(ShopBrand::find($brand->id))->not->toBeNull();
    expect(ShopProduct::find($product->id))->not->toBeNull();
    $freshConnection = $connection->fresh();
    expect($freshConnection)->not->toBeNull();
    expect($freshConnection->deleted_at)->toBeNull();
});

// ── Custom Links (PWL-11) ───────────────────────────────────────────────────

it('custom links removeLink (forgetConnection write) is blocked by a held platform lock and the link survives', function () {
    $user = sessionA3User('cllock1');
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'custom',
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
        'platform' => 'custom',
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
        'platform' => 'online-ordering',
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
        'platform' => 'online-ordering',
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
        'platform' => 'online-ordering',
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
        'platform' => 'online-ordering',
        'resource_id' => $rid,
        'payload' => ['url' => $url, 'provider' => 'custom', 'source' => 'manual'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->deleteJson('/api/platforms/online-ordering')->assertStatus(200);

    Queue::assertPushed(MenuFetchJob::class, 1);
});
