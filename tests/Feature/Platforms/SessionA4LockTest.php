<?php

// Fourth locking session on this branch (platform write-path locking).
//
// PWL-D2 — OnlineOrderingController::addEntry() dispatched EnrichLinkCardJob +
// MenuFetchJob from inside withConnectionLock()'s closure. Under
// QUEUE_CONNECTION=sync both jobs run INLINE as part of dispatch() — holding
// the 10s lock across that inline work risks the lock's TTL expiring mid
// operation. Fixed to mirror the PWL-12 fix already shipped in this file's
// removeEntry()/forget(): dispatch only after the lock releases, gated on the
// write having actually succeeded (202, never a 422 cap or a 423 timeout).
//
// CACHE_STORE=array in phpunit.xml, so Cache::lock() here is a REAL in-process
// ArrayLock — pre-acquiring the same key a concurrent writer would use
// genuinely contends, and block(5, ...) is real wall time (~5s per
// lock-contention test below).

use App\Jobs\Platforms\EnrichLinkCardJob;
use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function a4User(string $h): User
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

// Online ordering is gated can_use_online_ordering = business && food sector
// (2026-07-15 sector gating) — addEntry() needs this persona or every request
// 403s before ever reaching the lock.
function a4FoodBusinessUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'business',
        'sector' => 'restaurant',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

// ── PWL-D2: OnlineOrderingController::addEntry() ────────────────────────────

it('online-ordering addEntry is blocked by a held platform lock and dispatches neither job', function () {
    Queue::fake();
    Http::fake();

    $user = a4FoodBusinessUser('a4oo1');

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('online-ordering', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)
            ->postJson('/api/platforms/online-ordering/entries', ['url' => 'https://www.ubereats.com/store/lockheld'])
            ->assertStatus(423);
    } finally {
        $lock->release();
    }

    // Proves no dispatch happened from the blocked closure — a blocked add
    // must never fire either job.
    Queue::assertNotPushed(EnrichLinkCardJob::class);
    Queue::assertNotPushed(MenuFetchJob::class);
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'online-ordering')->count())->toBe(0);
});

it('online-ordering addEntry dispatches EnrichLinkCardJob + MenuFetchJob exactly once after the lock releases on success', function () {
    Queue::fake();
    Http::fake();

    $user = a4FoodBusinessUser('a4oo2');

    actingAsUser($user)
        ->postJson('/api/platforms/online-ordering/entries', ['url' => 'https://www.ubereats.com/store/ok'])
        ->assertStatus(202);

    // Fired once each, and only after the write succeeded — proving dispatch
    // is preserved on the success path once moved outside the lock.
    Queue::assertPushed(EnrichLinkCardJob::class, 1);
    Queue::assertPushed(MenuFetchJob::class, 1);
});

it('online-ordering addEntry never dispatches on the 422 MAX_ENTRIES cap', function () {
    Queue::fake();
    Http::fake();

    $user = a4FoodBusinessUser('a4oo3');
    for ($i = 1; $i <= 10; $i++) {
        $url = "https://www.ubereats.com/store/cap-{$i}";
        IntegrationConnection::create([
            'user_id' => $user->id,
            'platform' => 'online-ordering',
            'resource_id' => 'order-'.substr(sha1(strtolower($url)), 0, 16),
            'payload' => ['url' => $url, 'provider' => 'custom', 'source' => 'manual'],
            'is_active' => true,
            'last_refresh_status' => 'ok',
        ]);
    }

    actingAsUser($user)
        ->postJson('/api/platforms/online-ordering/entries', ['url' => 'https://www.ubereats.com/store/overflow'])
        ->assertStatus(422);

    Queue::assertNotPushed(EnrichLinkCardJob::class);
    Queue::assertNotPushed(MenuFetchJob::class);
});
