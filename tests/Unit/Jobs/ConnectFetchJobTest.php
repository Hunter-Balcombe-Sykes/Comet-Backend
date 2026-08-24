<?php

use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Http\FetchBudget;
use App\Services\Notifications\Dispatchers\PlatformHealthNotifier;
use App\Services\Platforms\BandcampScraper;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Strategies\Fetch\FreshaConnectFetch;
use App\Services\Platforms\Strategies\Fetch\FreshaFetch;
use App\Services\Platforms\VimeoApi;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// Unit 11 W5 (LIFE-13..20 Phase 2) — ConnectFetchJob completes a pending row
// written by the (not-yet-wired, W6) deferred-connect controller path. These
// tests call handle() DIRECTLY (never rely on the sync queue driver to prove
// queued behaviour — see the job's own docblock on why that distinction
// matters for a job that must never throw on an expected upstream failure).

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function cfjUser(string $h): User
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

it('defines the required queue-hygiene properties', function () {
    $job = new ConnectFetchJob('id', 'bandcamp');

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([5, 20])
        ->and($job->maxExceptions)->toBe(2)
        ->and($job->timeout)->toBe(75)
        ->and($job->uniqueFor)->toBe(120)
        // Must exceed the job's own $timeout — a ShouldBeUnique job that can outrun
        // its own dedupe lock loses dedupe protection while still running (the
        // codebase-wide invariant HorizonQueueCoverageTest pins for other jobs).
        ->and($job->uniqueFor)->toBeGreaterThan($job->timeout)
        // Must exceed config('partna.http_fetch.connect_budget_seconds') (20s
        // default) with headroom — that budget bounds the vendor fetch itself.
        ->and($job->timeout)->toBeGreaterThan((int) config('partna.http_fetch.connect_budget_seconds', 20))
        ->and($job->middleware())->toBe([]) // deliberately no RateLimited — see the job's docblock
        ->and($job->uniqueId())->toBe('bandcamp:id')
        ->and($job->queue)->toBe(config('partna.queues.platform_connect'));
});

it('fills the pending payload via the platform FetchStrategy and marks the row ok', function () {
    $user = cfjUser('cfj1');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-cfj1',
        'canonical_key' => 'https://someartist.bandcamp.com',
        // A minimal pending row — exactly what BandcampConnect::identify() writes.
        'payload' => ['url' => 'https://someartist.bandcamp.com'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
    ]);

    $this->mock(BandcampScraper::class, function ($m) {
        $m->shouldReceive('fetchProfile')->once()->andReturn([
            'name' => 'Real Artist',
            'thumbnail' => 'https://f4.bcbits.com/img/artist.jpg',
            'items' => [
                ['itemId' => 'album-1', 'name' => 'Album One', 'thumbnail' => 't1', 'link' => 'https://someartist.bandcamp.com/album/one'],
            ],
        ]);
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn (array $items) => $items);
    });

    $job = new ConnectFetchJob($connection->id, 'bandcamp');
    app()->call([$job, 'handle']);

    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('ok');
    expect($fresh->last_refresh_error)->toBeNull();
    expect($fresh->last_refreshed_at)->not->toBeNull();
    expect($fresh->consecutive_failures)->toBe(0);
    // Content came from the FetchStrategy, not from anything the job invented.
    expect($fresh->payload['artist'])->toBe('Real Artist');
    expect($fresh->payload['latest']['itemId'])->toBe('album-1');
    // BandcampFetch warms `recent` itself; hasHighlights()'s warmInto() call is
    // exercised (as a no-op passthrough) rather than skipped.
    expect($fresh->payload['recent'][0]['itemId'])->toBe('album-1');
});

it('a FetchNotModifiedException (Bandcamp auto-sync-off, or a live 304) keeps the merged payload intact and marks ok', function () {
    $user = cfjUser('cfj2');
    // Simulates what a MERGED pending write already produced on a reconnect
    // (ManagesIntegrationConnection::upsertConnection's $mergePayload path):
    // real prior content is already sitting in `payload`, not a blank stub.
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-cfj2',
        'payload' => [
            'url' => 'https://artist.bandcamp.com',
            'artist' => 'Preserved Artist',
            'latest' => ['itemId' => 'album-1'],
        ],
        'display_settings' => ['auto_sync_latest' => false],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
    ]);

    // BandcampFetch checks display_settings.auto_sync_latest BEFORE touching
    // the scraper at all, so the vendor must never be called on this path.
    $this->mock(BandcampScraper::class, function ($m) {
        $m->shouldNotReceive('fetchProfile');
        $m->shouldNotReceive('enrichPrices');
    });

    $job = new ConnectFetchJob($connection->id, 'bandcamp');
    app()->call([$job, 'handle']);

    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('ok');
    expect($fresh->last_refresh_error)->toBeNull();
    expect($fresh->last_refreshed_at)->not->toBeNull();
    expect($fresh->consecutive_failures)->toBe(0);
    // The payload is BYTE-IDENTICAL to what was there before — a naive
    // implementation that treated 304 as a failure (or blanked the payload)
    // would fail one of these two assertions.
    expect($fresh->payload['artist'])->toBe('Preserved Artist');
    expect($fresh->payload['latest']['itemId'])->toBe('album-1');
});

it('an upstream miss (FetchUnavailableException) surfaces the descriptor frozen message on the row', function () {
    $user = cfjUser('cfj3');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'vimeo',
        'resource_id' => 'acct-cfj3',
        'payload' => ['apiPath' => 'someuser', 'url' => 'https://vimeo.com/someuser', 'link' => 'https://vimeo.com/someuser'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
    ]);

    // VimeoFetch throws FetchUnavailableException('vimeo_no_videos') when the
    // upstream returns zero videos.
    $this->mock(VimeoApi::class, fn ($m) => $m->shouldReceive('fetchVideos')->once()->andReturn([]));

    $job = new ConnectFetchJob($connection->id, 'vimeo');
    app()->call([$job, 'handle']);

    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('unavailable');
    // Verbatim from the registry's connectFetchError() wiring for vimeo — the
    // same wording resolve()'s synchronous fetch-stage failure returns today.
    expect($fresh->last_refresh_error)->toBe('Could not find that Vimeo profile.');
    expect($fresh->consecutive_failures)->toBe(1);
    // The identity payload survives — a future retry/reconnect still has it.
    expect($fresh->payload['apiPath'])->toBe('someuser');
});

it('never notifies PlatformHealthNotifier on a failed connect — a failed connect is not a failing connection', function () {
    $user = cfjUser('cfj4');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'vimeo',
        'resource_id' => 'acct-cfj4',
        'payload' => ['apiPath' => 'someuser2', 'url' => 'https://vimeo.com/someuser2', 'link' => 'https://vimeo.com/someuser2'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
        // One failure short of PlatformHealthNotifier::connectionRefreshFailing's
        // own trip threshold (config('partna.refresh.max_consecutive_failures'),
        // default 10) — load-bearing: if this job ever delegated to
        // PlatformRefresher::recordFailure() (the thing the plan explicitly
        // rejects), THIS is the count that would push it over the threshold and
        // make the notifier actually fire. Seeding 0 here would make the guard
        // below pass for the wrong reason even against a wrong implementation.
        'consecutive_failures' => 9,
    ]);

    $this->mock(VimeoApi::class, fn ($m) => $m->shouldReceive('fetchVideos')->once()->andReturn([]));
    $this->mock(PlatformHealthNotifier::class, fn ($m) => $m->shouldNotReceive('connectionRefreshFailing'));

    $job = new ConnectFetchJob($connection->id, 'vimeo');
    app()->call([$job, 'handle']);

    expect($connection->fresh()->last_refresh_status)->toBe('unavailable');
});

it('handle() does not throw when it meets an expected upstream failure (sync-driver correctness)', function () {
    // phpunit.xml pins queue.default=sync, so dispatch()->afterCommit()
    // executes handle() INLINE in the request — a throw here becomes a 500
    // with no failed() callback. Every Fetch*Exception must be swallowed.
    // Re-pointed off twitch 2026-08-16: it was standing in for "a platform with
    // a fetch strategy that can fail", and Phase 1.2's demotion made it
    // link-only — with no fetch strategy there is no upstream failure to meet.
    // bandcamp is what the rest of this file already uses.
    $user = cfjUser('cfj5');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-cfj5',
        'payload' => ['url' => 'https://someband.bandcamp.com'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
    ]);

    $this->mock(BandcampScraper::class, fn ($m) => $m->shouldReceive('fetchProfile')->once()->andReturn(null));

    $job = new ConnectFetchJob($connection->id, 'bandcamp');

    $threw = false;
    try {
        app()->call([$job, 'handle']);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
    expect($connection->fresh()->last_refresh_status)->toBe('unavailable');
});

it('a FetchShapeException (missing identity key) is reported and marks a terminal error with the descriptor message', function () {
    // Should be UNREACHABLE in production — identify() writes exactly the key
    // its FetchStrategy reads — so this pins the canary path: report() fires
    // AND the row still lands in a terminal, pollable state instead of hanging.
    $user = cfjUser('cfj6');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-cfj6',
        'payload' => [], // no 'url' key — BandcampFetch throws FetchShapeException
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
    ]);

    $job = new ConnectFetchJob($connection->id, 'bandcamp');
    app()->call([$job, 'handle']);

    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('error');
    expect($fresh->last_refresh_error)->toBe('Could not find releases on that Bandcamp page.');
    expect($fresh->consecutive_failures)->toBe(1);
});

it('marks unsupported_platform as a terminal error when the registry has no descriptor for the job platform', function () {
    $user = cfjUser('cfj7');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp', // must be a real registered platform to pass IntegrationConnection's own write guard
        'resource_id' => 'acct-cfj7',
        'payload' => ['url' => 'https://someartist.bandcamp.com'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
    ]);

    // The job resolves the descriptor from ITS OWN constructor arg, not the
    // row's stored platform column — 'not-a-real-platform' is unregistered.
    $job = new ConnectFetchJob($connection->id, 'not-a-real-platform');
    app()->call([$job, 'handle']);

    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('error');
    expect($fresh->last_refresh_error)->toBe('unsupported_platform');
});

it('a soft-deleted (disconnected-while-queued) connection is a silent no-op', function () {
    $user = cfjUser('cfj8');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-cfj8',
        'payload' => ['url' => 'https://someartist.bandcamp.com'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
    ]);
    $connection->delete(); // soft delete — user disconnected while the job was queued

    $this->mock(BandcampScraper::class, function ($m) {
        $m->shouldNotReceive('fetchProfile');
    });

    $job = new ConnectFetchJob($connection->id, 'bandcamp');

    $threw = false;
    try {
        app()->call([$job, 'handle']);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
});

it('a lock timeout does not throw, does not silently rely on release(), and marks the row a terminal state instead of leaving it pending forever', function () {
    Exceptions::fake();
    // Reviewer-caught defect: Illuminate\Queue\Jobs\Job::release() only flips
    // an internal flag; SyncQueue::executeJob() (tests' driver — phpunit.xml
    // pins QUEUE_CONNECTION=sync) reacts solely to a thrown Throwable and
    // never checks isReleased(). Fails unfixed: pre-fix code called $this->release(3) here
    // and returned — under a direct handle() call (no real queue Job context)
    // that release() is a no-op, so the row would still read 'pending' below
    // (this test's key assertion) even though handle() also didn't throw.
    $user = cfjUser('cfj10');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-cfj10',
        'payload' => ['url' => 'https://someartist.bandcamp.com'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
    ]);

    // The fetch succeeds normally — only the final locked write is contended,
    // simulating a concurrent scheduled refresh holding the
    // same per-user/platform lock past the job's 5s block().
    $this->mock(BandcampScraper::class, function ($m) {
        $m->shouldReceive('fetchProfile')->once()->andReturn([
            'name' => 'Real Artist',
            'thumbnail' => null,
            'items' => [
                ['itemId' => 'album-1', 'name' => 'Album One', 'thumbnail' => 't1', 'link' => 'https://someartist.bandcamp.com/album/one'],
            ],
        ]);
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn (array $items) => $items);
    });

    // Defect C's write-time re-check (FeatureAvailability::for()) touches Cache
    // too (its version/failopen keys, plus CacheLockService's own single-flight
    // lock) — none of that is what THIS test proves, so it's stubbed inert:
    // Cache::get() answers a permissive "nothing cached" for those reads, and
    // CacheLockService's locking is bypassed (straight to the callback) so the
    // ONLY Cache::lock() call left in this test is the job's own contended
    // write lock below.
    Cache::shouldReceive('get')->withAnyArgs()->andReturn(null);
    $this->app->instance(CacheLockService::class, new class extends CacheLockService
    {
        public function rememberLocked(string $key, $ttl, Closure $callback, int $lockSeconds = 10, int $blockSeconds = 5): mixed
        {
            return $callback();
        }

        public function rememberLockedNullable(string $key, $ttl, Closure $callback, $nullTtl = null, int $lockSeconds = 10, int $blockSeconds = 5): mixed
        {
            return $callback();
        }
    });

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);
    Cache::shouldReceive('lock')->once()->andReturn($lock);

    $job = new ConnectFetchJob($connection->id, 'bandcamp');

    $threw = false;
    try {
        app()->call([$job, 'handle']);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();

    $fresh = $connection->fresh();
    // NOT 'pending' — a client polling connect/status must eventually see a
    // terminal answer, not wait forever for a job that already ran and gave up.
    expect($fresh->last_refresh_status)->not->toBe('pending');
    expect($fresh->last_refresh_status)->toBe('unavailable');
    expect($fresh->last_refresh_error)->not->toBeNull();
    // The message must not borrow the vendor-failure wording — this is our
    // own lock contention, not a platform miss.
    expect($fresh->last_refresh_error)->not->toContain('Bandcamp page');
    expect($fresh->consecutive_failures)->toBe(1);
    Exceptions::assertReported(LockTimeoutException::class);
});

// Sanity check on the lock key computation the job shares with ScheduledRefresh
// and the platform write lane — not a behavioural test, just
// pins the formula so a future edit to any of the three sites is caught by a
// diff instead of silently drifting apart.
//
// 2026-07-21: replaces a test of the same name that pinned the OLD, buggy
// per-account suffix formula (CacheKeyGenerator::platformConnectionLock's
// third parameter, since REMOVED). That formula was the root cause of a
// lost-update bug: ScheduledRefresh/ConnectFetchJob suffixed by resource_id
// while the dashboard save never did, so a multi-account platform's writers built
// DIFFERENT key strings and never mutually excluded each other. See
// PlatformConnectionLockConvergenceTest.php for the behavioural (real lock
// contention) proof that the three sites now converge.
it('computes the same platform-wide lock key regardless of resource_id (no per-account suffix)', function () {
    $user = cfjUser('cfj9');
    $multiAccountRow = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-cfj9',
        'payload' => [],
        'is_active' => true,
    ]);
    $singleSelectionRow = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'strava',
        'resource_id' => 'strava',
        'payload' => [],
        'is_active' => true,
    ]);

    // Same (platform, userId) pair, different resource_id — must yield the
    // IDENTICAL key. platformConnectionLock() takes no resource_id/suffix
    // argument at all any more, so there is no parameter left to diverge by.
    expect(CacheKeyGenerator::platformConnectionLock($multiAccountRow->platform, $user->id))
        ->toBe("platforms:bandcamp:lock:{$user->id}");
    expect(CacheKeyGenerator::platformConnectionLock($singleSelectionRow->platform, $user->id))
        ->toBe("platforms:strava:lock:{$user->id}");
});

// ── E-1: FetchBudget around the vendor fetch ────────────────────────────────
// Before this fix, ConnectFetchJob's own comment claimed the vendor fetch was
// bounded by connect_budget_seconds when nothing in this path ever opened one
// (FetchBudget::remaining() is null — unbounded — until open() is called).

it('opens a FetchBudget bounded at connect_budget_seconds around the vendor fetch, and clears it afterwards', function () {
    config(['partna.http_fetch.connect_budget_seconds' => 20]);

    $user = cfjUser('cfjbudget');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-cfjbudget',
        'payload' => ['url' => 'https://someartist.bandcamp.com'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
    ]);

    // No budget open before the job runs — the scoped instance starts unbounded.
    expect(app(FetchBudget::class)->remaining())->toBeNull();

    $remainingDuringFetch = 'not-set';
    $this->mock(BandcampScraper::class, function ($m) use (&$remainingDuringFetch) {
        $m->shouldReceive('fetchProfile')->once()->andReturnUsing(function () use (&$remainingDuringFetch) {
            // Same container, same scoped instance the job's handle() was
            // given — this is what proves the fetch runs INSIDE the budget,
            // not just that open() was called somewhere before it.
            $remainingDuringFetch = app(FetchBudget::class)->remaining();

            return [
                'name' => 'Real Artist',
                'thumbnail' => null,
                'items' => [
                    ['itemId' => 'album-1', 'name' => 'Album One', 'thumbnail' => 't1', 'link' => 'https://someartist.bandcamp.com/album/one'],
                ],
            ];
        });
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn (array $items) => $items);
    });

    $job = new ConnectFetchJob($connection->id, 'bandcamp');
    app()->call([$job, 'handle']);

    expect($remainingDuringFetch)->not->toBe('not-set')
        ->and($remainingDuringFetch)->not->toBeNull()
        ->and($remainingDuringFetch)->toBeGreaterThan(0)
        ->and($remainingDuringFetch)->toBeLessThanOrEqual(20.0);

    // The finally in FetchBudget::open() clears the deadline unconditionally —
    // the SAME scoped instance must report unbounded again once handle() returns.
    expect(app(FetchBudget::class)->remaining())->toBeNull();
    expect(app(FetchBudget::class)->exhausted())->toBeFalse();

    expect($connection->fresh()->last_refresh_status)->toBe('ok');
});

// ── Defect C: staff kill switch re-checked at write time ────────────────────

it('a platform disabled between the 202 and this job running resolves to a terminal state without writing fetched content', function () {
    setupFeatureAvailabilityTable();
    FeatureAvailability::flush();

    $user = cfjUser('cfjkill');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-cfjkill',
        'canonical_key' => 'https://someartist.bandcamp.com',
        // Exactly the identity stub a 202 write leaves behind.
        'payload' => ['url' => 'https://someartist.bandcamp.com'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
    ]);

    // Staff disable landing AFTER the 202 but BEFORE this job runs.
    FeatureAvailabilityRule::query()->create(['feature_key' => 'integration.bandcamp', 'mode' => 'disabled']);
    FeatureAvailability::flush();

    // The vendor is still reached — the re-check happens at WRITE time, not
    // before the fetch — but its result must never reach the row. A non-empty
    // items array is essential here: an empty one throws FetchUnavailableException
    // BEFORE the fetch even returns, which would prove nothing about the
    // write-time re-check this test exists for.
    $this->mock(BandcampScraper::class, function ($m) {
        $m->shouldReceive('fetchProfile')->once()->andReturn([
            'name' => 'Real Artist',
            'thumbnail' => null,
            'items' => [
                ['itemId' => 'album-1', 'name' => 'Album One', 'thumbnail' => 't1', 'link' => 'https://someartist.bandcamp.com/album/one'],
            ],
        ]);
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn (array $items) => $items);
    });

    $job = new ConnectFetchJob($connection->id, 'bandcamp');
    app()->call([$job, 'handle']);

    $fresh = $connection->fresh();
    // Terminal, never left pending — a poller must not spin forever.
    expect($fresh->last_refresh_status)->not->toBe('pending');
    expect($fresh->last_refresh_status)->toBe('unavailable');
    // CA-SM review fix: a staff disable is not a vendor miss, so this must NOT
    // read the descriptor's Bandcamp-specific wording — the same generic,
    // already-established infra string ConnectFetchJob::failed() falls back to.
    expect($fresh->last_refresh_error)->toBe('We could not load that account. Please try again.');
    // The fetched content never landed — only the pre-existing identity stub survives.
    expect($fresh->payload)->not->toHaveKey('artist');
    expect($fresh->payload['url'])->toBe('https://someartist.bandcamp.com');
});

it('an available platform (no rule, or an explicit allow) is unaffected by the write-time re-check', function () {
    setupFeatureAvailabilityTable();
    FeatureAvailabilityRule::query()->create(['feature_key' => 'integration.bandcamp', 'mode' => 'enabled']);
    FeatureAvailability::flush();

    $user = cfjUser('cfjallow');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'bandcamp',
        'resource_id' => 'acct-cfjallow',
        'payload' => ['url' => 'https://someartist.bandcamp.com'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
    ]);

    $this->mock(BandcampScraper::class, function ($m) {
        $m->shouldReceive('fetchProfile')->once()->andReturn([
            'name' => 'Real Artist',
            'thumbnail' => null,
            'items' => [
                ['itemId' => 'album-1', 'name' => 'Album One', 'thumbnail' => 't1', 'link' => 'https://someartist.bandcamp.com/album/one'],
            ],
        ]);
        $m->shouldReceive('enrichPrices')->andReturnUsing(fn (array $items) => $items);
    });

    $job = new ConnectFetchJob($connection->id, 'bandcamp');
    app()->call([$job, 'handle']);

    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('ok');
    expect($fresh->payload['artist'])->toBe('Real Artist');
});

// ── CA-W6: connectFetchStrategy() seam ──────────────────────────────────────
// This job resolves its work via connectFetchStrategy(), not fetchStrategy()
// directly — the one line this unit changed. connectFetchStrategy() defaults
// to fetchStrategy() for every descriptor that hasn't called connectFetch(),
// so this is the single assertion protecting the seven already-armed deferred
// platforms (spotify, bandcamp, twitch, strava, vimeo,
// youtube-music, youtube) from that shared-infra change.

it('connectFetchStrategy() falls back to fetchStrategy() for every descriptor except fresha, which overrides it', function () {
    $registry = app(PlatformRegistry::class);

    foreach ($registry->all() as $key => $descriptor) {
        if ($key === 'fresha') {
            continue;
        }

        $fetch = $descriptor->fetchStrategy();
        $connectFetch = $descriptor->connectFetchStrategy();

        if ($fetch === null) {
            expect($connectFetch)->toBeNull("expected null connectFetchStrategy() for {$key}");

            continue;
        }

        expect($connectFetch)->toBeInstanceOf($fetch::class, "connectFetchStrategy()/fetchStrategy() class drift: {$key}");
    }

    $fresha = $registry->get('fresha');
    expect($fresha->connectFetchStrategy())->toBeInstanceOf(FreshaConnectFetch::class);
    expect($fresha->fetchStrategy())->toBeInstanceOf(FreshaFetch::class);
});
