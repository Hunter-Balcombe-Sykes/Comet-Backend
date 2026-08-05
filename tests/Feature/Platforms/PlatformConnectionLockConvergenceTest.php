<?php

// 2026-07-21 fix: per-platform connection locking is now the ONLY convention.
// Before this fix, ScheduledRefresh::run() / ConnectFetchJob::handle() /
// GoogleBusinessEnrichJob::persist() suffixed CacheKeyGenerator::
// platformConnectionLock() by the connection's resource_id, while
// the dashboard save path (via ManagesIntegrationConnection::
// withConnectionLock()) never did. For a MULTI-ACCOUNT platform (an account
// row's resource_id is 'acct-<hash>', never equal to the platform slug) those
// two writer groups therefore built DIFFERENT key strings and never mutually
// excluded each other — a background scheduled refresh (or a deferred connect
// completion) could silently clobber a user's just-saved connection write.
//
// CacheKeyGenerator::platformConnectionLock() no longer accepts a suffix at
// all (see its docblock + ManagesIntegrationConnection::withConnectionLock's),
// so every writer now builds the exact same platform-wide string. This file
// proves that convergence for bandcamp (confirmed multiAccount(true) in
// PlatformRegistryServiceProvider, alongside youtube/vimeo/youtube-music/
// spotify/soundcloud) the same way GoogleBusinessEnrichConcurrencyTest proves
// it for google-business: pre-acquire the lock a THIRD party (an unrelated
// probe) would hold, using ONLY the un-suffixed formula
// CacheKeyGenerator::platformConnectionLock($platform, $userId) — the one
// formula every call site is left with — then prove each real writer,
// operating on an account row whose resource_id differs from the platform
// slug, genuinely contends against it instead of sailing through on a
// different string.
//
// CACHE_STORE=array in phpunit.xml, so Cache::lock() here is a REAL ArrayLock
// in-process — lock acquisition, contention, and key IDENTITY across writers
// are genuinely proven, not mocked. The three lock-contention tests below
// block on a real Cache::lock()->block(5, ...) (~5s wall time each) — that
// wall-clock cost IS the proof the lock actually blocks; don't shortcut it.
//
// Against the PRE-FIX code (ScheduledRefresh/ConnectFetchJob computing a
// suffixed key for this multi-account row), the first two tests below FAIL
// FAST (no 5s wait) — the differently-keyed lock never contends, so the
// writer's own Cache::lock()->block() acquires immediately and overwrites the
// row despite the pre-held "someone else is writing" lock. The third test
// (the since-removed Featured save) was never on the buggy side — it never
// passed a suffix — so it passed both before and after;
// it exists here to establish the ground-truth key the other two must match.

use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\BandcampScraper;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\Strategies\Refresh\ScheduledRefresh;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

function plcUser(string $h): User
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

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('bandcamp/youtube/vimeo/youtube-music are registered multiAccount(true)', function () {
    // Ground truth for why this file matters: the bug only bites a
    // multi-account platform, where an account row's resource_id ('acct-...')
    // differs from the platform slug — a single-selection platform's
    // resource_id always equals its platform, so the old suffix formula
    // collapsed to null there and was never actually divergent.
    $registry = app(PlatformRegistry::class);
    foreach (['bandcamp', 'youtube', 'vimeo', 'youtube-music'] as $platform) {
        expect($registry->get($platform)?->multiAccount())->toBeTrue();
    }
});

it("ScheduledRefresh's write takes the SAME lock key as an account-scoped writer for a multi-account platform (LIFE-10 convergence)", function () {
    $user = plcUser('plc1');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        // 'acct-...' — deliberately NOT equal to the platform slug, the exact
        // shape that made the old suffix formula diverge from the unsuffixed one.
        'resource_id' => 'acct-plc1',
        'payload' => ['artist' => 'Original Artist'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);
    Log::spy();

    // The ONLY formula left after the fix — no suffix parameter exists to
    // diverge with. Simulates a concurrent writer (e.g. a dashboard save)
    // already holding the platform-wide lock for this user+platform.
    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('bandcamp', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    $fetch = new class implements FetchStrategy
    {
        public function fetch(IntegrationConnection $connection): array
        {
            return [...$connection->payload, 'artist' => 'Changed By Refresh'];
        }
    };

    try {
        (new ScheduledRefresh($fetch))->run($connection);
    } finally {
        $lock->release();
    }

    // Unchanged — proves ScheduledRefresh's write genuinely contended on the
    // SAME key the pre-held lock used, rather than acquiring a differently
    // suffixed key and clobbering this row underneath the "in-use" lock.
    expect($connection->fresh()->payload['artist'])->toBe('Original Artist');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $m, array $c) => $m === 'platform.scheduled_refresh.lock_timeout'
            && ($c['user_id'] ?? null) === (string) $user->id
            && ($c['platform'] ?? null) === 'bandcamp');
});

it("ConnectFetchJob's write takes the SAME lock key as an account-scoped writer for a multi-account platform (LIFE-10 convergence)", function () {
    $user = plcUser('plc2');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-plc2',
        'payload' => ['url' => 'https://someartist.bandcamp.com', 'artist' => 'Original Artist'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
    ]);
    Log::spy();

    // The fetch runs OUTSIDE the lock (see the job's own docblock), so it
    // must succeed normally — only the final locked write is contended.
    $this->mock(BandcampScraper::class, function ($m) {
        $m->shouldReceive('fetchProfile')->once()->andReturn([
            'name' => 'Changed By Connect',
            'thumbnail' => null,
            'items' => [
                ['itemId' => 'album-1', 'name' => 'Album One', 'thumbnail' => 't1', 'link' => 'https://someartist.bandcamp.com/album/one'],
            ],
        ]);
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn (array $items) => $items);
    });

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('bandcamp', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    $job = new ConnectFetchJob($connection->id, 'bandcamp');
    try {
        app()->call([$job, 'handle']);
    } finally {
        $lock->release();
    }

    $fresh = $connection->fresh();
    // Terminal failure, not the fetched content — proves the job's write
    // genuinely contended on the pre-held lock's key.
    expect($fresh->last_refresh_status)->toBe('unavailable');
    expect($fresh->payload['artist'])->toBe('Original Artist');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $m, array $c) => $m === 'platform.connect_job.lock_timeout'
            && ($c['connection_id'] ?? null) === $connection->id
            && ($c['platform'] ?? null) === 'bandcamp');
});
