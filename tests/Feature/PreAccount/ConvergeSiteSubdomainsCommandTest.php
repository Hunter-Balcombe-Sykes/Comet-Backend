<?php

// SIGNUP-1 backfill. Dry run is the DEFAULT; writing requires --apply. Writes
// bypass Eloquent (no observer, so no blanket SyncSubdomainToKvJob into the KV
// namespace that dev and prod SHARE) and therefore bust cache keys explicitly.

use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\SiteCacheService;
use Illuminate\Cache\Repository;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\QueueFake;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupHandleAliasesTable();
    Queue::fake();
});

/** A diverged row: handle_lc says one thing, sites.subdomain another. */
function divergedUser(string $handle, string $subdomain): User
{
    $user = User::factory()->create(['handle' => $handle, 'handle_lc' => $handle]);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => $subdomain]);

    // Site::factory fires SiteObserver (cache purge + KV sync). Re-fake so the
    // assertions below see only what the COMMAND dispatched.
    Queue::fake();

    return $user;
}

it('defaults to a dry run and writes nothing', function () {
    $user = divergedUser('errols', 'errol-s');

    $this->artisan('partna:converge-site-subdomains')
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('old subdomain: errol-s')
        ->expectsOutputToContain('new subdomain: errols')
        ->assertSuccessful();

    expect(DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('subdomain'))
        ->toBe('errol-s');
    Queue::assertNothingPushed();
});

it('converges the row and busts both old and new cache keys under --apply', function () {
    $user = divergedUser('errols', 'errol-s');

    Cache::put(CacheKeyGenerator::handleResolve('errol-s'), ['stale' => true], 600);
    Cache::put(CacheKeyGenerator::handleResolve('errols'), ['stale' => true], 600);
    Cache::put(CacheKeyGenerator::professionalModel($user->id), ['stale' => true], 600);

    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])
        ->expectsOutputToContain('APPLY')
        ->assertSuccessful();

    expect(DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('subdomain'))
        ->toBe('errols')
        ->and(Cache::get(CacheKeyGenerator::handleResolve('errol-s')))->toBeNull()
        ->and(Cache::get(CacheKeyGenerator::handleResolve('errols')))->toBeNull()
        ->and(Cache::get(CacheKeyGenerator::professionalModel($user->id)))->toBeNull();
});

it('does not touch KV for a user with no active handle aliases', function () {
    divergedUser('errols', 'errol-s');

    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])
        ->expectsOutputToContain('KV: none')
        ->assertSuccessful();

    Queue::assertNotPushed(SyncSubdomainToKvJob::class);
});

// The alias KV entry embeds `redirect: <partna_url>`, and partna_url is derived
// from sites.subdomain via compute_user_url() — so it, and only it, changes.
it('dispatches a KV sync only for a user holding an active handle alias', function () {
    $user = divergedUser('errols', 'errol-s');
    DB::connection('pgsql')->table('core.user_handle_aliases')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'handle' => 'errol-s-old',
        'expires_at' => now()->addDays(90),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])
        ->expectsOutputToContain('errol-s-old')
        ->assertSuccessful();

    Queue::assertPushed(SyncSubdomainToKvJob::class, fn ($job) => $job->userId === $user->id);
});

it('skips a row whose target subdomain is held by another site', function () {
    $blocker = User::factory()->create(['handle' => 'blocker', 'handle_lc' => 'blocker']);
    Site::factory()->create(['user_id' => $blocker->id, 'subdomain' => 'errols']);
    $user = divergedUser('errols', 'errol-s');

    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])
        ->expectsOutputToContain('SKIP')
        ->assertSuccessful();

    expect(DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('subdomain'))
        ->toBe('errol-s');
});

// A data repair must not spend the user's 30-day rename cooldown
// (RenameSubdomainAction reads subdomain_changed_at to enforce it).
it('leaves subdomain_changed_at untouched', function () {
    $user = divergedUser('errols', 'errol-s');
    $changedAt = now()->subDays(3)->startOfSecond();
    DB::connection('pgsql')->table('site.sites')
        ->where('user_id', $user->id)
        ->update(['subdomain_changed_at' => $changedAt]);

    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])->assertSuccessful();

    $row = DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->first();
    expect($row->subdomain)->toBe('errols')
        ->and(Carbon::parse($row->subdomain_changed_at)->equalTo($changedAt))->toBeTrue();
});

// Converging onto a reserved name would park a site on a subdomain that
// ResolvesSubdomainFromHost rejects — the repair there is a handle rename by a human.
it('skips a row whose target subdomain is reserved', function () {
    $user = divergedUser('support', 'tobiasindarwin-fableqa1');

    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])
        ->expectsOutputToContain('reserved subdomain')
        ->assertSuccessful();

    expect(DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('subdomain'))
        ->toBe('tobiasindarwin-fableqa1');
});

// core_site_subdomain_aliases_subdomain_lower_unique is GLOBAL, so an active alias
// makes the target unusable even though site.sites has no row for it.
it('skips a row whose target subdomain is held by an active alias, but not an expired one', function () {
    $holder = User::factory()->create(['handle' => 'holder', 'handle_lc' => 'holder']);
    $holderSite = Site::factory()->create(['user_id' => $holder->id, 'subdomain' => 'holder']);
    $user = divergedUser('errols', 'errol-s');

    $aliasId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.site_subdomain_aliases')->insert([
        'id' => $aliasId,
        'site_id' => $holderSite->id,
        'subdomain' => 'errols',
        'expires_at' => now()->addDays(90),
        'created_at' => now(),
    ]);

    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])
        ->expectsOutputToContain('active alias')
        ->assertSuccessful();
    expect(DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('subdomain'))
        ->toBe('errol-s');

    // Expire it — the name is back in the pool and the row converges.
    DB::connection('pgsql')->table('site.site_subdomain_aliases')
        ->where('id', $aliasId)->update(['expires_at' => now()->subDay()]);

    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])->assertSuccessful();
    expect(DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('subdomain'))
        ->toBe('errols');
});

it('is idempotent — a second run finds nothing', function () {
    divergedUser('errols', 'errol-s');

    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])->assertSuccessful();
    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])
        ->expectsOutputToContain('Nothing to converge')
        ->assertSuccessful();
});

it('ignores soft-deleted users', function () {
    $user = divergedUser('errols', 'errol-s');
    DB::connection('pgsql')->table('core.users')->where('id', $user->id)->update(['deleted_at' => now()]);

    $this->artisan('partna:converge-site-subdomains')
        ->expectsOutputToContain('0 diverged row(s)')
        ->assertSuccessful();
});

// ---------------------------------------------------------------------------
// #PGR-11: invalidation-failure containment (change 1) + resolve-floor raise
// (change 2). Rows are ordered by `s.subdomain` (old_subdomain) ascending, so
// with old subdomains 'brian-b' and 'errol-s', brianb's row is processed
// FIRST — that's the row these tests force to fail, proving the SECOND row
// (errols) still converges.
// ---------------------------------------------------------------------------

/** Cache decorator whose deleteMultiple() throws exactly once, then delegates normally. */
function cacheThatThrowsOnceOnDeleteMultiple(): Repository
{
    return new class(Cache::getFacadeRoot()->store()->getStore()) extends Repository
    {
        private bool $thrown = false;

        public function deleteMultiple($keys): bool
        {
            if (! $this->thrown) {
                $this->thrown = true;

                throw new RuntimeException('forced cache failure');
            }

            return parent::deleteMultiple($keys);
        }
    };
}

it('continues past a cache-invalidation failure, converges the remaining row, and exits non-zero', function () {
    $failingUser = divergedUser('brianb', 'brian-b');
    $okUser = divergedUser('errols', 'errol-s');

    $originalRoot = Cache::getFacadeRoot();
    Cache::swap(cacheThatThrowsOnceOnDeleteMultiple());

    $exitCode = Artisan::call('partna:converge-site-subdomains', ['--apply' => true]);
    $output = Artisan::output();

    Cache::swap($originalRoot);

    expect($exitCode)->toBe(Command::FAILURE);
    expect($output)->toContain('WRITTEN BUT NOT INVALIDATED — subdomain is now brianb');
    expect($output)->toContain('retry KV:    php artisan partna:backfill-subdomain-kv '.$failingUser->id);
    expect(substr_count($output, "\n  written.\n"))->toBe(1);

    // Row 1 (brianb): the raw UPDATE happens BEFORE the try block, so the
    // write itself is unaffected by the invalidation failure — WRITTEN BUT
    // NOT INVALIDATED, not un-written.
    expect(DB::connection('pgsql')->table('site.sites')->where('user_id', $failingUser->id)->value('subdomain'))
        ->toBe('brianb');

    // Row 2 (errols): proves `continue`, not an abort of the whole run.
    expect(DB::connection('pgsql')->table('site.sites')->where('user_id', $okUser->id)->value('subdomain'))
        ->toBe('errols');
});

it('continues past a KV-dispatch failure, converges the remaining row, and exits non-zero', function () {
    $failingUser = divergedUser('brianb', 'brian-b');
    DB::connection('pgsql')->table('core.user_handle_aliases')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $failingUser->id,
        'handle' => 'brian-b-old',
        'expires_at' => now()->addDays(90),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $okUser = divergedUser('errols', 'errol-s');

    // Bypass the queue subsystem entirely and force the Bus dispatcher itself
    // to throw — SyncSubdomainToKvJob::dispatch() resolves this contract
    // directly (see PendingDispatch::__destruct), so this reaches exactly the
    // call the command makes without needing to fight ShouldBeUniqueUntilProcessing
    // lock machinery or QueueFake internals.
    $throwingDispatcher = new class implements BusDispatcher
    {
        public function dispatch($command)
        {
            throw new RuntimeException('forced dispatch failure');
        }

        public function dispatchSync($command, $handler = null)
        {
            throw new RuntimeException('forced dispatch failure');
        }

        public function dispatchNow($command, $handler = null)
        {
            throw new RuntimeException('forced dispatch failure');
        }

        public function hasCommandHandler($command)
        {
            return false;
        }

        public function getCommandHandler($command)
        {
            return null;
        }

        public function pipeThrough(array $pipes)
        {
            return $this;
        }

        public function map(array $map)
        {
            return $this;
        }
    };
    $originalDispatcher = app(BusDispatcher::class);
    app()->instance(BusDispatcher::class, $throwingDispatcher);

    $exitCode = Artisan::call('partna:converge-site-subdomains', ['--apply' => true]);
    $output = Artisan::output();

    app()->instance(BusDispatcher::class, $originalDispatcher);

    expect($exitCode)->toBe(Command::FAILURE);
    expect($output)->toContain('WRITTEN BUT NOT INVALIDATED — subdomain is now brianb');
    expect($output)->toContain('retry KV:    php artisan partna:backfill-subdomain-kv '.$failingUser->id);
    expect(substr_count($output, "\n  written.\n"))->toBe(1);

    expect(DB::connection('pgsql')->table('site.sites')->where('user_id', $failingUser->id)->value('subdomain'))
        ->toBe('brianb');
    expect(DB::connection('pgsql')->table('site.sites')->where('user_id', $okUser->id)->value('subdomain'))
        ->toBe('errols');
});

// Pins "the dispatch is not inside a transaction" as a test-enforced
// invariant (the plan's rejected-transaction reasoning), so nobody re-adds a
// DB::transaction() wrapper around the per-row write + invalidation later.
it('never dispatches SyncSubdomainToKvJob from inside an open transaction', function () {
    $user = divergedUser('errols', 'errol-s');
    DB::connection('pgsql')->table('core.user_handle_aliases')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'handle' => 'errol-s-old',
        'expires_at' => now()->addDays(90),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $fakeQueue = new class(app()) extends QueueFake
    {
        public ?int $transactionLevelAtPush = null;

        public function push($job, $data = '', $queue = null)
        {
            $this->transactionLevelAtPush = DB::connection('pgsql')->transactionLevel();

            return parent::push($job, $data, $queue);
        }
    };
    Queue::swap($fakeQueue);

    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])->assertSuccessful();

    Queue::assertPushed(SyncSubdomainToKvJob::class);
    expect($fakeQueue->transactionLevelAtPush)->toBe(0);
});

// Proves the command calls the REAL SiteCacheService::raiseResolveFloor —
// not a local reimplementation — for the NEW subdomain specifically (the
// user's unchanged handle_lc, the only one that should still resolve to this
// site going forward). If the command ever stopped calling this method (e.g.
// someone "simplified" it back out, or reimplemented the Cache::put logic
// inline), this mock expectation fails.
it('raises the resolve floor via SiteCacheService for the NEW subdomain, not the old one', function () {
    divergedUser('errols', 'errol-s');

    $this->partialMock(SiteCacheService::class, function ($mock) {
        $mock->shouldReceive('raiseResolveFloor')
            ->once()
            ->withArgs(fn (string $handle, ?int $ts) => $handle === 'errols' && $ts !== null && $ts > 0);
    });

    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])->assertSuccessful();
});

// Exercises the REAL (unmocked) SiteCacheService::raiseResolveFloor and
// reproduces IndividualProfileController::show's read-side formula
// (ts = max(resolved.updated_at_ts, floor)) against the same cache keys the
// command and that controller share. This proves the floor mechanically
// closes the race — a stale, lower timestamp re-installed into handle.resolve
// after the command's delete cannot win the max().
//
// NOT proven here: the full HTTP round trip through IndividualProfileController
// itself. That route needs the design-kit/media/services/content fixture
// stack carried by tests/Feature/Api/PublicSite/IndividualProfileControllerTest.php,
// which this command's test file deliberately does not pull in — the formula
// reproduction below is the honest substitute for that in this lane.
it('writes a resolve floor that out-maxes a stale re-put of handle.resolve', function () {
    divergedUser('errols', 'errol-s');
    $floorKey = CacheKeyGenerator::handleResolveFloor('errols');

    expect(Cache::get($floorKey))->toBeNull();

    $before = now()->timestamp;
    $this->artisan('partna:converge-site-subdomains', ['--apply' => true])->assertSuccessful();

    $floor = (int) Cache::get($floorKey);
    expect($floor)->toBeGreaterThanOrEqual($before);

    // Simulate the race: an in-flight reader that queried the DB pre-commit
    // re-puts a STALE (lower) timestamp into handle.resolve after the
    // command's Cache::deleteMultiple() already ran.
    $staleReinstalledTs = $floor - 100;
    $effectiveTs = max($staleReinstalledTs, (int) Cache::get($floorKey, 0));

    expect($effectiveTs)->toBe($floor);
    expect($effectiveTs)->not->toBe($staleReinstalledTs);
});
