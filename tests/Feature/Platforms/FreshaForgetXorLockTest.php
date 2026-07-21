<?php

// PWL-15 (Fresha forget() half, deferred from PWL-5/PWL-14): FreshaController::
// forget() deletes the fresha connection row — the SAME delete BookingController::
// clearBooking() performs under the booking-XOR lock as part of the single-slot
// booking-family clear. "The Fresha delete has exactly one owner": both call
// sites must serialize on the SAME cross-platform key (CacheKeyGenerator::
// bookingXorLock()), not the per-platform 'fresha' lock forget() used pre-fix
// (or, pre-fix, no lock at all). Mirrors BookingXorControllerLockTest.php /
// FreshaConnectLockTest.php's proof shape: pre-acquire the exact key a
// concurrent booking-family writer would hold, then prove forget() genuinely
// contends on it (423) instead of sailing through and deleting the connection
// underneath the "in-use" lock.
//
// CACHE_STORE=array in phpunit.xml, so Cache::lock() here is a real
// in-process ArrayLock — the block(5, ...) wait in withCrossPlatformLock() is
// genuine wall time, not mocked.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

// Hard-coded, not read from CacheKeyGenerator — matching the
// BookingXorControllerLockTest precedent: this is the pre-existing key
// CacheKeyGenerator::bookingXorLock() already returns.
function bookingXorLockKeyForFreshaForget(string $userId): string
{
    return "platforms:booking-xor:lock:{$userId}";
}

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // forget() soft-deletes synced site.services rows — needed regardless of
    // the lock outcome once the fix lands (post-fix the request never reaches
    // that code, blocked at 423, but the table must still exist for the model
    // query to run without a schema error).
    setupServicesTable();
    shimPgAdvisoryLockForSqlite();
});

function freshaForgetXorUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('fresha forget is blocked by a held booking-XOR lock and the existing fresha connection survives', function () {
    $user = freshaForgetXorUser('ffx1');

    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/doc-cuts', 'selection' => null],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    // A fresha-sourced synced service row — would be soft-deleted by forget()
    // if the lock failed to block it.
    $service = $user->services()->create([
        'title' => 'Haircut',
        'source' => 'fresha',
        'is_manual' => false,
        'external_id' => 'ext-1',
        'is_active' => true,
    ]);

    $lock = Cache::lock(bookingXorLockKeyForFreshaForget((string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->deleteJson('/api/platforms/fresha')->assertStatus(423);
    } finally {
        $lock->release();
    }

    // Untouched — proves forgetConnection() + the synced-service purge loop
    // never ran under the held lock.
    $freshConnection = $connection->fresh();
    expect($freshConnection)->not->toBeNull();
    expect($freshConnection->deleted_at)->toBeNull();

    $freshService = $service->fresh();
    expect($freshService)->not->toBeNull();
    expect($freshService->deleted_at)->toBeNull();
});
