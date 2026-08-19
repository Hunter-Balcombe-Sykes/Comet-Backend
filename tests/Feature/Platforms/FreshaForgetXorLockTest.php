<?php

// PWL-15 (Fresha forget() half, deferred from PWL-5/PWL-14): FreshaController::
// forget() deletes the fresha connection row under the booking-XOR lock —
// the single cross-platform key every booking-family writer (Fresha + Square
// connects, auto-sync applies) serializes on. "The Fresha delete has exactly
// one owner": forget() must contend on CacheKeyGenerator::bookingXorLock(),
// not the per-platform 'fresha' lock it used pre-fix. (The booking category
// controller whose clearBooking() shared this key was retired 2026-08-19;
// the lock discipline it proved lives on here.) Proof shape: pre-acquire the
// exact key a concurrent booking-family writer would hold, then prove
// forget() genuinely contends on it (423) instead of sailing through.
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
        'first_name' => ucfirst($h),
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
    $service = createServiceFor($user, [
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
