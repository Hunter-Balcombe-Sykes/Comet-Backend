<?php

// PWL-7 (controller half): InstagramController wrote to site.platform_connections
// on three paths — connect()'s placeholder write, forget()'s soft-delete, and
// applySync()'s mark-seeded write — without taking
// ManagesIntegrationConnection::withConnectionLock()/the raw lock primitive.
// Those unlocked writers raced a sibling locked writer (or each other), risking
// a last-write-wins clobber of a just-saved row.
//
// Mirrors SessionA2LockTest.php's proof shape: pre-acquire the SAME key formula
// CacheKeyGenerator::platformConnectionLock($platform, $userId) a concurrent
// writer would hold, then prove each newly-wrapped method genuinely contends on
// it (423, ~5s block) instead of sailing through and clobbering the row
// underneath the "in-use" lock.
//
// CACHE_STORE=array in phpunit.xml, so Cache::lock() here is a real in-process
// ArrayLock — the block(5, ...) wait is genuine wall time, not mocked. That
// wall-clock cost per test IS the proof.

use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // /synced now also folds new-pipeline Hold intents (SyncFindingsBridge),
    // so the routing tables must exist even for payload-only cases.
    setupRoutingTables();
});

function igLockUser(string $h): User
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

// ── connect() ────────────────────────────────────────────────────────────────

it('connect (placeholder write) is blocked by a held platform lock, creates no row, and never dispatches the job', function () {
    Queue::fake();
    config(['services.apify.token' => 'test-token']);

    $user = igLockUser('iglock1');

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('instagram', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/instagram/connect', ['username' => 'someuser'])
            ->assertStatus(423);
    } finally {
        $lock->release();
    }

    // The placeholder write never got past the lock — no row created.
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'instagram')->exists())->toBeFalse();

    // The job dispatch is gated behind the lock — a 423 must mean it never fired.
    Queue::assertNotPushed(InstagramConnectJob::class);
});

// ── forget() ─────────────────────────────────────────────────────────────────

it('forget (soft-delete) is blocked by a held platform lock and the row survives', function () {
    $user = igLockUser('iglock2');
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => ['username' => 'testuser', 'mode' => 'automatic', 'images' => []],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('instagram', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->deleteJson('/api/platforms/instagram')->assertStatus(423);
    } finally {
        $lock->release();
    }

    $fresh = $row->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->deleted_at)->toBeNull();
});

// ── applySync() ──────────────────────────────────────────────────────────────

it('applySync (mark-seeded write) is blocked by a held platform lock and the finding stays conflict', function () {
    $user = igLockUser('iglock3');
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => ['syncFindings' => [[
            'platform' => 'facebook', 'resourceId' => 'facebook', 'category' => 'social',
            'label' => 'Facebook', 'foundUrl' => 'https://www.facebook.com/docpizzabar',
            'outcome' => 'conflict',
            'apply' => ['remove' => ['facebook'], 'write' => [
                'platform' => 'facebook', 'resourceId' => 'facebook',
                'payload' => ['username' => 'docpizzabar', 'url' => 'https://www.facebook.com/docpizzabar', 'source' => 'instagram'],
            ]],
        ]], 'unmatched' => []],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'facebook',
        'resource_id' => 'facebook',
        'payload' => ['username' => 'mine', 'url' => 'https://facebook.com/mine', 'source' => 'manual'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('instagram', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/instagram/synced/apply', ['platform' => 'facebook'])
            ->assertStatus(423);
    } finally {
        $lock->release();
    }

    // The lock blocked the mark-seeded write — the finding is still 'conflict'.
    $ig = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'instagram')->firstOrFail();
    expect($ig->payload['syncFindings'][0]['outcome'])->toBe('conflict');
});
