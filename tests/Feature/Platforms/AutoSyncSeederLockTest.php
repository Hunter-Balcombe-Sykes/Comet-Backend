<?php

// PWL-9: the auto-sync "seeder" side of platform write-locking. Earlier PWL
// units locked the dashboard/controller writes to site.platform_connections;
// this closes the matching gap on GoogleBusinessAutoSync's own writes so a
// concurrent dashboard save and an in-flight Google Business auto-sync can no
// longer lost-update the same row. Three write paths gained a lock:
//   - seedReservation()  → BuildsAutoSyncFindings::withReservationsXorLock
//     (cross-platform XOR across opentable/resdiary/nowbookit/reservations,
//     structurally identical to the existing booking-XOR lock).
//   - seedOrdering()     → BuildsAutoSyncFindings::withPlatformSeedLock keyed
//     'online-ordering' (same key ManagesIntegrationConnection::
//     withConnectionLock() would build for a dashboard write to that platform).
//   - dispatchInstagram() → withPlatformSeedLock keyed 'instagram' — but ONLY
//     around the placeholder write; the job dispatch stays outside the lock
//     because InstagramConnectJob (via InstagramConnectionSeeder::seed) takes
//     the SAME key and runs INLINE under the sync queue driver, so holding the
//     lock across the dispatch would self-deadlock.
//
// Each test below pre-acquires the real lock the seed path now takes and
// holds it, proving: (1) the write is actually gated behind the lock, not
// just decorated with one that's never contended; (2) contention causes a
// skip (no row, no downstream dispatch), not a stale double-write; (3) the
// skip is logged so a real production contention burst is observable.
// Contention tests block ~3s by design (BuildsAutoSyncFindings::SEED_LOCK_BLOCK)
// — slow on purpose, not flaky.

use App\Jobs\Platforms\InstagramConnectJob;
use App\Jobs\Platforms\MenuFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\GoogleBusinessAutoSync;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

/**
 * Business + food-sector account — the combination that unlocks
 * can_use_reservations AND can_use_online_ordering (AccountCapabilities:
 * isBusiness ? isFood : true / isBusiness && isFood) while can_use_booking
 * stays false, so seedBooking never runs and can't interfere with the
 * probes below. google_business_full_sync (isBusiness) also unlocks the
 * socials tier the Instagram test exercises.
 */
function pwl9User(string $h, string $accountType = 'business', ?string $sector = 'restaurant'): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => $accountType,
        'sector' => $sector,
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

// ── seedReservation() — reservations-XOR lock ───────────────────────────

it('a concurrent holder of the reservations-XOR lock makes seedReservation skip (not write) and log a timeout warning', function () {
    Log::spy();
    $user = pwl9User('pwlres1');
    $key = "platforms:reservations-xor:lock:{$user->id}"; // hard-coded, not read from the trait

    $held = Cache::lock($key, 10);
    expect($held->get())->toBeTrue();

    try {
        // Blocks up to SEED_LOCK_BLOCK (3s) before giving up — slow by design.
        app(GoogleBusinessAutoSync::class)->seed(
            (string) $user->id,
            ['reservation' => ['url' => 'https://booking.resdiary.com/widget/Standard/Ollies', 'provider' => 'ResDiary']],
            'Ollies',
        );
    } finally {
        $held->release();
    }

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'resdiary')->exists())->toBeFalse();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'platforms.auto_sync.reservations_lock_timeout'
            && ($context['user_id'] ?? null) === (string) $user->id);
});

it('holds the reservations-XOR lock across BOTH the hasAnyReservation check and the write', function () {
    $user = pwl9User('pwlres2');
    $key = "platforms:reservations-xor:lock:{$user->id}";

    $checkLocked = false;
    DB::listen(function ($query) use (&$checkLocked, $key) {
        if (! str_contains($query->sql, 'select exists(') || ! str_contains($query->sql, 'platform_connections')) {
            return;
        }
        $probe = Cache::lock($key, 5);
        $heldNow = ! $probe->get();
        if (! $heldNow) {
            $probe->release(); // MANDATORY — an ArrayLock left held would deadlock the write this test observes.
        }
        $checkLocked = $checkLocked || $heldNow;
    });

    $writeLocked = false;
    IntegrationConnection::created(function (IntegrationConnection $conn) use (&$writeLocked, $key) {
        if ($conn->platform !== 'resdiary') {
            return;
        }
        $probe = Cache::lock($key, 5);
        $writeLocked = ! $probe->get();
        if (! $writeLocked) {
            $probe->release();
        }
    });

    app(GoogleBusinessAutoSync::class)->seed(
        (string) $user->id,
        ['reservation' => ['url' => 'https://booking.resdiary.com/widget/Standard/Ollies', 'provider' => 'ResDiary']],
        'Ollies',
    );

    expect($checkLocked)->toBeTrue();
    expect($writeLocked)->toBeTrue();

    $resdiary = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'resdiary')->firstOrFail();
    expect($resdiary->payload['source'])->toBe('google-business');
});

// ── seedOrdering() — per-platform seed lock ('online-ordering') ─────────

it('a concurrent holder of the online-ordering platform lock makes seedOrdering skip (no row, no MenuFetchJob) and log a timeout warning', function () {
    Log::spy();
    Bus::fake([MenuFetchJob::class]);
    $user = pwl9User('pwlord1');
    $key = "platforms:online-ordering:lock:{$user->id}"; // hard-coded, not read from CacheKeyGenerator

    $held = Cache::lock($key, 10);
    expect($held->get())->toBeTrue();

    try {
        app(GoogleBusinessAutoSync::class)->seed(
            (string) $user->id,
            ['order' => ['providers' => [
                ['name' => 'UberEats', 'url' => 'https://www.ubereats.com/au/store/ollies/abc?diningMode=PICKUP', 'type' => 'pickup'],
            ]]],
            'Ollies',
        );
    } finally {
        $held->release();
    }

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'online-ordering')->exists())->toBeFalse();
    Bus::assertNotDispatched(MenuFetchJob::class); // dropped seed → nothing changed → nothing to re-derive
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'platforms.auto_sync.platform_lock_timeout'
            && ($context['user_id'] ?? null) === (string) $user->id
            && ($context['platform'] ?? null) === 'online-ordering');
});

it('seedOrdering still dispatches MenuFetchJob when the lock is free (contention suppression is the exception, not the default)', function () {
    Bus::fake([MenuFetchJob::class]);
    $user = pwl9User('pwlord2');

    app(GoogleBusinessAutoSync::class)->seed(
        (string) $user->id,
        ['order' => ['providers' => [
            ['name' => 'UberEats', 'url' => 'https://www.ubereats.com/au/store/ollies/abc?diningMode=PICKUP', 'type' => 'pickup'],
        ]]],
        'Ollies',
    );

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'online-ordering')->exists())->toBeTrue();
    Bus::assertDispatched(MenuFetchJob::class, fn ($job) => $job->userId === (string) $user->id);
});

// ── dispatchInstagram() — per-platform seed lock ('instagram') ──────────

it('a concurrent holder of the instagram platform lock makes the GB instagram seed skip (no row, no InstagramConnectJob) and log a timeout warning', function () {
    config(['services.apify.token' => 'apify-token']);
    Log::spy();
    Bus::fake([InstagramConnectJob::class]);
    $user = pwl9User('pwlig1', 'business', null); // sector irrelevant to socials — google_business_full_sync only needs isBusiness
    $key = "platforms:instagram:lock:{$user->id}"; // hard-coded, not read from CacheKeyGenerator

    $held = Cache::lock($key, 10);
    expect($held->get())->toBeTrue();

    try {
        app(GoogleBusinessAutoSync::class)->seed(
            (string) $user->id,
            ['socials' => ['instagram' => 'https://instagram.com/fadelab']],
            'Fade Lab',
        );
    } finally {
        $held->release();
    }

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'instagram')->exists())->toBeFalse();
    Bus::assertNotDispatched(InstagramConnectJob::class); // lock timeout → skip: no card, no dispatch
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'platforms.auto_sync.platform_lock_timeout'
            && ($context['user_id'] ?? null) === (string) $user->id
            && ($context['platform'] ?? null) === 'instagram');
});

it('seeds the Instagram placeholder and dispatches the scrape when the lock is free', function () {
    config(['services.apify.token' => 'apify-token']);
    Bus::fake([InstagramConnectJob::class]);
    $user = pwl9User('pwlig2', 'business', null);

    app(GoogleBusinessAutoSync::class)->seed(
        (string) $user->id,
        ['socials' => ['instagram' => 'https://instagram.com/fadelab']],
        'Fade Lab',
    );

    $ig = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'instagram')->firstOrFail();
    expect($ig->last_refresh_status)->toBe('pending');
    expect($ig->payload['source'])->toBe('google-business');
    Bus::assertDispatched(InstagramConnectJob::class, fn ($job) => $job->username === 'fadelab' && $job->userId === (string) $user->id);
});
