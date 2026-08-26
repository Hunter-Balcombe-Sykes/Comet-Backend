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
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // #LIFE-13: a QueryException in the observer's ingest-source seam is now
    // reported + warned instead of silently Log::debug'd, so a missing ingest
    // mirror turns every Eloquent-created connection into a spurious report.
    // Provision it; Bus::fake() because provisioning switches the eager-run
    // dispatch on, and these tests drive jobs via ->handle() directly.
    setupIngestTables();
    Bus::fake();
});

function igJobLockUser(string $h): User
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
        'payload' => [],
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

// ── PRIV-2: the strip belongs to the WRITER, not one caller ──────────────────
// The original strip lived in InstagramSourceGenerator, i.e. per-GENERATOR,
// while the write lives here, per-WRITER. Every other caller of seed() therefore
// bypassed it — proven on dev by an unclaimed row with all three keys whose build
// was source_type='google_business', seeded via
// GoogleBusinessAutoSync::dispatchInstagram() -> InstagramConnectJob -> seed().

/** Mirror-pipeline stubs: this section is about the payload keys, not the media. */
function igPriv2Scraper(): void
{
    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('latestMedia')->andReturn(['photo' => null, 'video' => null]);
    $scraper->shouldReceive('profilePicUrl')->andReturn(null);
    $scraper->shouldReceive('bioLinks')->andReturn(['https://example.com/booking']);
    app()->instance(InstagramScraper::class, $scraper);
}

function igPriv2Connection(User $user, array $payload = []): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => $payload,
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);
}

it('strips the three internal keys for an unclaimed owner regardless of which caller reached seed()', function () {
    Storage::fake('media');
    Queue::fake();

    $user = igJobLockUser('igpriv2a');
    $user->status = 'unclaimed';
    $user->auth_user_id = null;   // a provisional row has no auth user and no email
    $user->primary_email = null;
    $user->save();
    $connection = igPriv2Connection($user);
    igPriv2Scraper();

    app(InstagramConnectionSeeder::class)
        ->seed($connection, 'testuser', (string) $user->id, ['fullName' => 'Test User']);

    $payload = $connection->fresh()->payload;
    expect($payload)->not->toHaveKey('bioLinks')
        ->and($payload)->not->toHaveKey('syncFindings')
        ->and($payload)->not->toHaveKey('unmatched');
    // The strip is deliberately NARROW. seed() writes all of these unconditionally,
    // regardless of what the media stubs return, so this pins the invariant the old
    // generator test used to hold: minimisation of internal bookkeeping only, never
    // of what the unclaimed sitepage renders from.
    expect($payload)->toHaveKeys(['username', 'images', 'videoUrl', 'videoPoster', 'website', '_folder']);
});

it('keeps all three keys for a claimed owner (PRIV-2 control)', function () {
    Storage::fake('media');
    Queue::fake();

    // igJobLockUser() creates a claimed row (auth_user_id + email, status default
    // 'active'). Identical setup otherwise, so this is the control proving the
    // guard above is scoped to unclaimed and not stripping for everyone.
    $user = igJobLockUser('igpriv2b');
    $connection = igPriv2Connection($user);
    igPriv2Scraper();

    app(InstagramConnectionSeeder::class)
        ->seed($connection, 'testuser', (string) $user->id, ['fullName' => 'Test User']);

    $payload = $connection->fresh()->payload;
    // Keys are written unconditionally by seed() (syncFindings/unmatched are set
    // from autoSync's return even when empty), so presence is deterministic here
    // and does not depend on how the bio link happens to classify.
    expect($payload)->toHaveKeys(['bioLinks', 'syncFindings', 'unmatched'])
        ->and($payload['bioLinks'])->toBe(['https://example.com/booking']);
});

it('strips for an unclaimed owner on a GOOGLE-sourced build, preserving the source tag (the gap)', function () {
    // The exact shape GoogleBusinessAutoSync::dispatchInstagram() creates before
    // dispatching InstagramConnectJob: a pending placeholder tagged
    // source='google-business'. Pre-fix this row kept all three keys, because
    // InstagramSourceGenerator — where the strip lived — is never on this path.
    Storage::fake('media');
    Queue::fake();

    $user = igJobLockUser('igpriv2c');
    $user->status = 'unclaimed';
    $user->auth_user_id = null;
    $user->primary_email = null;
    $user->save();
    $connection = igPriv2Connection($user, ['source' => 'google-business']);
    igPriv2Scraper();

    app(InstagramConnectionSeeder::class)
        ->seed($connection, 'testuser', (string) $user->id, ['fullName' => 'Test User']);

    $payload = $connection->fresh()->payload;
    expect($payload)->not->toHaveKey('bioLinks')
        ->and($payload)->not->toHaveKey('syncFindings')
        ->and($payload)->not->toHaveKey('unmatched');
    // source must SURVIVE the strip — it drives the /synced "Change to" flow and
    // the seeder goes out of its way to preserve it across a re-scrape.
    expect($payload['source'])->toBe('google-business');
});
