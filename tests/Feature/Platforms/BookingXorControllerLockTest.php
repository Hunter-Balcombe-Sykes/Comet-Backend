<?php

// PWL-14 (booking half): BookingController::detect()'s custom-fallback path
// and forget() write/clear the single-slot booking-family state (fresha /
// square / custom) but, pre-fix, do so under either the wrong lock (detect
// used ManagesIntegrationConnection::withConnectionLock() — a per-PLATFORM
// key scoped to 'booking', which a concurrent Fresha/Square writer never
// takes) or no lock at all (forget). Only a user-scoped, platform-suffix-free
// lock — CacheKeyGenerator::bookingXorLock() — can serialize the cross-
// platform XOR invariant; see BuildsAutoSyncFindings::withBookingXorLock's
// docblock for the full argument.
//
// Mirrors SessionA2LockTest.php's proof shape: pre-acquire the exact key a
// concurrent writer would hold, then prove each newly-wrapped method
// genuinely contends on it (423, ~5s block) instead of sailing through and
// tearing down the existing booking connection underneath the "in-use" lock.
//
// CACHE_STORE=array in phpunit.xml, so Cache::lock() here is a real
// in-process ArrayLock — the block(5, ...) wait in withCrossPlatformLock() is
// genuine wall time, not mocked.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\LinkCardScraper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Hard-coded, not read from CacheKeyGenerator — this is the pre-existing key
// BuildsAutoSyncFindings::bookingXorLockKey() already returns (proven byte-
// identical by BookingXorLockTest.php). Hardcoding lets this test's
// fail-before run exercise the REAL pre-fix bug (lock ignored → 202/200)
// instead of an unrelated "undefined method" crash from CacheKeyGenerator::
// bookingXorLock() not existing yet before CHANGE 1 lands. Post-fix, this is
// exactly the string CacheKeyGenerator::bookingXorLock() returns and the
// controllers now lock on.
function bookingXorLockKeyFor(string $userId): string
{
    return "platforms:booking-xor:lock:{$userId}";
}

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function bookingXorUser(string $h): User
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

function seedFreshaConnection(User $user): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/doc-cuts', 'source' => 'manual'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
}

it('detect (custom fallback) is blocked by a held booking-XOR lock and leaves the existing fresha row untouched', function () {
    // phpunit.xml pins QUEUE_CONNECTION=sync (unfaked here would run
    // inline) — fake it so EnrichLinkCardJob's real scraper
    // methods (snapshot/snapshotOrMinimal, unmocked below) never execute.
    Queue::fake();

    // Mock BEFORE any request/IntegrationConnection write — a late-bound
    // scraper mock can capture the real scraper instead (see LinkCardScraper
    // gotcha in project memory). normalizeUrl/minimalCard are the two methods
    // detect() itself calls directly (network-free, but mocked for determinism
    // and to keep this test independent of LinkCardScraper's internals).
    $this->mock(LinkCardScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->andReturn('https://www.example.com/book');
        $m->shouldReceive('minimalCard')->andReturn([
            'url' => 'https://www.example.com/book',
            'name' => 'example.com',
            'description' => null,
            'favicon' => null,
            'logo' => null,
        ]);
    });

    $user = bookingXorUser('bxc1');
    $fresha = seedFreshaConnection($user);

    $lock = Cache::lock(bookingXorLockKeyFor((string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/booking/detect', [
            'url' => 'https://www.example.com/book',
        ])->assertStatus(423);
    } finally {
        $lock->release();
    }

    // Untouched — proves clearBooking() never ran under the held lock.
    $fresh = $fresha->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->deleted_at)->toBeNull();
});

it('forget is blocked by a held booking-XOR lock and the existing fresha row survives', function () {
    $user = bookingXorUser('bxc2');
    $fresha = seedFreshaConnection($user);

    $lock = Cache::lock(bookingXorLockKeyFor((string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->deleteJson('/api/platforms/booking')->assertStatus(423);
    } finally {
        $lock->release();
    }

    $fresh = $fresha->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->deleted_at)->toBeNull();
});
