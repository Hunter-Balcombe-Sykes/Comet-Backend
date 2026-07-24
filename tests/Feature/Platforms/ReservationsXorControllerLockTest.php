<?php

// PWL-15: ReservationsController::detect()'s custom-fallback path and
// forget() write/clear the single-slot reservations-family state (opentable /
// resdiary / nowbookit / custom) but, pre-fix, do so under either the wrong
// lock (detect used ManagesIntegrationConnection::withConnectionLock() — a
// per-PLATFORM key scoped to 'reservations', which a concurrent OpenTable/
// Resdiary/Nowbookit writer never takes) or no lock at all (forget). Only a
// user-scoped, platform-suffix-free lock — CacheKeyGenerator::
// reservationsXorLock() — can serialize the cross-platform XOR invariant.
// Mirrors BookingXorControllerLockTest.php's proof shape 1:1 for the
// reservations family.
//
// Mirrors SessionA2LockTest.php's proof shape: pre-acquire the exact key a
// concurrent writer would hold, then prove each newly-wrapped method
// genuinely contends on it (423, ~5s block) instead of sailing through and
// tearing down the existing opentable connection underneath the "in-use" lock.
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

// Hard-coded, not read from CacheKeyGenerator — matching the BookingXorControllerLockTest
// precedent: this is the pre-existing key CacheKeyGenerator::reservationsXorLock()
// already returns. Hardcoding lets this test's fail-before run exercise the
// REAL pre-fix bug (lock ignored → 202/200) rather than depending on the
// fix having landed.
function reservationsXorLockKeyFor(string $userId): string
{
    return "platforms:reservations-xor:lock:{$userId}";
}

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function reservationsXorUser(string $h): User
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

function seedOpenTableConnection(User $user): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'opentable',
        'resource_id' => 'opentable',
        'payload' => ['url' => 'https://www.opentable.com.au/restaurant/profile/266537', 'source' => 'manual'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
}

it('detect (custom fallback) is blocked by a held reservations-XOR lock and leaves the existing opentable row untouched', function () {
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
        $m->shouldReceive('normalizeUrl')->andReturn('https://www.example.com/reserve');
        $m->shouldReceive('minimalCard')->andReturn([
            'url' => 'https://www.example.com/reserve',
            'name' => 'example.com',
            'description' => null,
            'favicon' => null,
            'logo' => null,
        ]);
    });

    $user = reservationsXorUser('rxc1');
    $opentable = seedOpenTableConnection($user);

    $lock = Cache::lock(reservationsXorLockKeyFor((string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/reservations/detect', [
            'url' => 'https://www.example.com/reserve',
        ])->assertStatus(423);
    } finally {
        $lock->release();
    }

    // Untouched — proves clearReservations() never ran under the held lock.
    $fresh = $opentable->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->deleted_at)->toBeNull();
});

it('forget is blocked by a held reservations-XOR lock and the existing opentable row survives', function () {
    $user = reservationsXorUser('rxc2');
    $opentable = seedOpenTableConnection($user);

    $lock = Cache::lock(reservationsXorLockKeyFor((string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->deleteJson('/api/platforms/reservations')->assertStatus(423);
    } finally {
        $lock->release();
    }

    $fresh = $opentable->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->deleted_at)->toBeNull();
});
