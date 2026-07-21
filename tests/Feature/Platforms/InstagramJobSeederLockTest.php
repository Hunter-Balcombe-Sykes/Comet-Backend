<?php

// PWL-7 (job/seeder half): the controller half (InstagramControllerLockTest.php)
// already proves connect()/forget()/applySync() serialize on
// CacheKeyGenerator::platformConnectionLock('instagram', $userId). A
// controller-only fix is inert unless the JOB and SEEDER that write the SAME
// row — InstagramConnectionSeeder::seed()'s success write and
// InstagramConnectJob::markFailed()'s terminal write — take the SAME key.
//
// Mirrors BookingXorLockTest.php / InstagramControllerLockTest.php's proof
// shape: pre-acquire the SAME key a concurrent writer would hold, then prove
// each newly-wrapped write genuinely contends on it instead of sailing
// through underneath the "in-use" lock.
//
// CACHE_STORE=array in phpunit.xml, so Cache::lock() here is a real
// in-process ArrayLock — the block(5, ...) wait is genuine wall time, not
// mocked. That wall-clock cost per test IS the proof.

use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\InstagramConnectionSeeder;
use App\Services\Platforms\InstagramScraper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function igJobLockUser(string $h): User
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

// ── InstagramConnectionSeeder::seed() success write ──────────────────────────

it('seed() success write is blocked by a held platform lock and writes a terminal unavailable state instead of ok', function () {
    Storage::fake('media');
    Log::spy();

    $user = igJobLockUser('igjoblock1');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => [],
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);

    // No media/profile pic/bio links needed — this test is only about the
    // final locked write, not the mirror pipeline (already covered by
    // InstagramAsyncConnectTest.php).
    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('latestMedia')->once()->andReturn(['photo' => null, 'video' => null]);
    $scraper->shouldReceive('profilePicUrl')->once()->andReturn(null);
    $scraper->shouldReceive('bioLinks')->once()->andReturn([]);
    app()->instance(InstagramScraper::class, $scraper);

    $profile = ['fullName' => 'Test User', 'followersCount' => 100, 'postsCount' => 10];

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('instagram', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    $start = microtime(true);
    try {
        app(InstagramConnectionSeeder::class)->seed($connection, 'testuser', (string) $user->id, $profile);
    } finally {
        $lock->release();
    }
    $elapsed = microtime(true) - $start;

    // block(5, ...) must have genuinely waited out the contention — proves the
    // write is really inside the lock, not skipping past a held one.
    expect($elapsed)->toBeGreaterThanOrEqual(4.5);

    $connection->refresh();
    // Pre-fix: seed() wrote 'ok' unconditionally regardless of the held lock.
    // Post-fix: a lock timeout must produce the terminal 'unavailable' state.
    expect($connection->last_refresh_status)->toBe('unavailable');
    expect($connection->last_refresh_error)->not->toBeNull();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'instagram.connect_seeder.lock_timeout'
            && ($context['connection_id'] ?? null) === $connection->id
            && ($context['user_id'] ?? null) === (string) $user->id);
});

// ── InstagramConnectJob::markFailed() terminal write under contention ────────

it('markFailed still records the terminal state under contention via the best-effort fallback write', function () {
    $user = igJobLockUser('igjoblock2');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => null,
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('instagram', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    $job = new InstagramConnectJob($user->id, 'testuser', $connection->id);

    try {
        // failed() calls markFailed() internally.
        $job->failed(new RuntimeException('apify down'));
    } finally {
        $lock->release();
    }

    // Even with the lock held throughout, the fallback bare write must still
    // land — a failure path must never fail to record itself.
    $connection->refresh();
    expect($connection->last_refresh_status)->toBe('unavailable');
    expect($connection->last_refresh_error)->toBe('job_failed');
    expect((int) $connection->consecutive_failures)->toBe(1);
});
