<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Platforms\ShopBrandConnectJob;
use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Platforms\ShopBrandProfiler;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// W9 §4 Unit 3 — ShopBrandConnectJob completes a `pending` site.shop_brands
// row addBrand() wrote synchronously (Unit 4, not yet wired). These tests call
// handle() DIRECTLY, mirroring ConnectFetchJobTest's own idiom — never rely on
// the sync queue driver to prove queued behaviour.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function sbcjUser(string $h): User
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

/** sbcjUser() + a site.sites row, so the cache refresher can resolve a subdomain to purge. */
function sbcjUserWithSite(string $h): User
{
    $user = sbcjUser($h);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => $h,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

function sbcjConnection(User $user, array $overrides = []): IntegrationConnection
{
    return IntegrationConnection::create(array_merge([
        'user_id' => $user->id,
        'platform' => 'shop',
        'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'],
        'is_active' => true,
    ], $overrides));
}

/** A pending brand row exactly as addBrand()'s deferred branch would write it (plan §3c). */
function sbcjBrand(IntegrationConnection $connection, array $overrides = []): ShopBrand
{
    return ShopBrand::create(array_merge([
        'connection_id' => $connection->id,
        'brand_id' => 'brand-'.Str::random(8),
        'provider' => 'shopify',
        'url' => 'https://store.example.com',
        'source_url' => null,
        'connect_status' => 'pending',
        'connect_error' => null,
        'is_individual' => false,
        'position' => 0,
        'selection_mode' => 'manual',
        'link_mode' => 'product',
        'referral_query' => '',
    ], $overrides));
}

it('defines the required queue-hygiene properties, keyed on the brand row not the connection', function () {
    $job = new ShopBrandConnectJob('some-brand-row-id');

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([5, 20])
        ->and($job->maxExceptions)->toBe(2)
        ->and($job->timeout)->toBe(45)
        ->and($job->uniqueFor)->toBe(120)
        // Must exceed the job's own $timeout — same HorizonQueueCoverageTest invariant ConnectFetchJobTest pins.
        ->and($job->uniqueFor)->toBeGreaterThan($job->timeout)
        ->and($job->timeout)->toBeGreaterThan((int) config('partna.http_fetch.connect_budget_seconds', 20))
        ->and($job->middleware())->toBe([])
        ->and($job->uniqueId())->toBe('shop-brand:some-brand-row-id')
        ->and($job->queue)->toBe(config('partna.queues.platform_connect'));
});

it('settles a pending row: profile written, connect_status cleared', function () {
    $user = sbcjUser('settle1');
    $connection = sbcjConnection($user);
    $brand = sbcjBrand($connection, ['brand_id' => 'settle-brand']);

    $this->mock(ShopBrandProfiler::class, function ($m) use ($brand) {
        $m->shouldReceive('forRow')->once()
            ->with(Mockery::on(fn ($arg) => $arg->id === $brand->id))
            ->andReturn(['id' => 'settle-brand', 'name' => 'Settle Store', 'currency' => 'AUD', 'favicon' => 'https://s/favicon.ico', 'logo' => 'https://s/logo.png']);
    });

    $job = new ShopBrandConnectJob($brand->id);
    app()->call([$job, 'handle']);

    $fresh = $brand->fresh();
    expect($fresh->name)->toBe('Settle Store');
    expect($fresh->currency)->toBe('AUD');
    expect($fresh->favicon)->toBe('https://s/favicon.ico');
    expect($fresh->logo)->toBe('https://s/logo.png');
    expect($fresh->connect_status)->toBeNull();
    expect($fresh->connect_error)->toBeNull();
});

// ── Finding 4 (nit review fix) — a degraded null currency must not clobber
// a truthful one already on the row ──
//
// ShopBrandProfiler::forRow() degrades to a null currency (rather than
// throwing) when its own meta.json re-read misses — the scrapers degrade to
// nulls on a transient upstream blip. A blind overwrite would destroy a
// currency the deferred addBrand() write already stored correctly (e.g.
// Shopify's carried meta.json at 202 time). A genuine currency CHANGE must
// still win.

it('a degraded null currency from the fetch does not clobber the currency already stored on the row', function () {
    $user = sbcjUser('currnull');
    $connection = sbcjConnection($user);
    $brand = sbcjBrand($connection, ['brand_id' => 'currnull-brand', 'currency' => 'AUD']);

    $this->mock(ShopBrandProfiler::class, fn ($m) => $m->shouldReceive('forRow')->once()->andReturn([
        'id' => 'currnull-brand', 'name' => 'Curr Null Store', 'currency' => null, 'favicon' => null, 'logo' => null,
    ]));

    $job = new ShopBrandConnectJob($brand->id);
    app()->call([$job, 'handle']);

    expect($brand->fresh()->currency)->toBe('AUD');
});

it('a genuine currency change from the fetch still overwrites the stored value', function () {
    $user = sbcjUser('currchange');
    $connection = sbcjConnection($user);
    $brand = sbcjBrand($connection, ['brand_id' => 'currchange-brand', 'currency' => 'AUD']);

    $this->mock(ShopBrandProfiler::class, fn ($m) => $m->shouldReceive('forRow')->once()->andReturn([
        'id' => 'currchange-brand', 'name' => 'Curr Change Store', 'currency' => 'USD', 'favicon' => null, 'logo' => null,
    ]));

    $job = new ShopBrandConnectJob($brand->id);
    app()->call([$job, 'handle']);

    expect($brand->fresh()->currency)->toBe('USD');
});

it('does not recompute brand_id when the profiler resolves a different id — no key-shift, no second row', function () {
    $user = sbcjUser('norecompute');
    $connection = sbcjConnection($user);
    $brand = sbcjBrand($connection, ['brand_id' => 'original-id']);

    // Simulates Shopify's meta.json drifting between the synchronous addBrand()
    // detection and this job's re-fetch — forRow() re-derives from the STORED
    // url, and must never be allowed to key-shift the row it was dispatched for.
    $this->mock(ShopBrandProfiler::class, fn ($m) => $m->shouldReceive('forRow')->once()->andReturn([
        'id' => 'drifted-id', 'name' => 'Drift Store', 'currency' => null, 'favicon' => null, 'logo' => null,
    ]));

    $job = new ShopBrandConnectJob($brand->id);
    app()->call([$job, 'handle']);

    expect($brand->fresh()->brand_id)->toBe('original-id');
    expect(ShopBrand::where('connection_id', $connection->id)->count())->toBe(1);
});

// P1 review fix — the compare-and-set guard. With tries=3/backoff=[5,20]/
// timeout=45, a job's own worst-case retry span (~160s) can outlast
// uniqueFor's 120s dedupe TTL, letting a second dispatch settle the row
// before a stale first attempt's own locked write finally lands. Both tests
// below simulate "the row moved on since this attempt was dispatched" by
// pre-settling the row before calling handle()/failed() directly — the same
// end state a genuinely stale attempt would find.
it('a stale job cannot clobber an already-settled row (P1 compare-and-set)', function () {
    $user = sbcjUserWithSite('staleclobber');
    $connection = sbcjConnection($user);
    $brand = sbcjBrand($connection, [
        'brand_id' => 'settled-brand',
        'connect_status' => null,
        'connect_error' => null,
        'name' => 'Real Settled Store',
        'currency' => 'AUD',
        'favicon' => 'https://real/favicon.ico',
        'logo' => 'https://real/logo.png',
    ]);

    // A stale attempt still fetches (the budget/profiler call is unconditional)
    // but must not be allowed to WRITE a now-outdated profile over the row.
    $this->mock(ShopBrandProfiler::class, fn ($m) => $m->shouldReceive('forRow')->once()->andReturn([
        'id' => 'settled-brand', 'name' => 'Stale Old Name', 'currency' => 'USD', 'favicon' => 'https://stale/favicon.ico', 'logo' => 'https://stale/logo.png',
    ]));

    // Faked AFTER the fixture is built — IntegrationConnection::create() above
    // already dispatches its own CloudflareCachePurgeJob via wasRecentlyCreated
    // (mirrors ShopRelationalStorageTest's "purges on a SECOND mutation" idiom:
    // re-fake right before the action under test so that unrelated fixture-setup
    // dispatch doesn't leak into this assertion).
    Bus::fake();

    $job = new ShopBrandConnectJob($brand->id);
    app()->call([$job, 'handle']);

    $fresh = $brand->fresh();
    expect($fresh->name)->toBe('Real Settled Store');
    expect($fresh->currency)->toBe('AUD');
    expect($fresh->favicon)->toBe('https://real/favicon.ico');
    expect($fresh->logo)->toBe('https://real/logo.png');
    expect($fresh->connect_status)->toBeNull();
    // No purge is owed — nothing about the row's public-facing content changed.
    Bus::assertNotDispatched(CloudflareCachePurgeJob::class);
});

it('a late failure cannot un-settle an already-settled row (P1 compare-and-set on markTerminal)', function () {
    $user = sbcjUser('lateunsettle');
    $connection = sbcjConnection($user);
    $brand = sbcjBrand($connection, [
        'brand_id' => 'settled-brand2',
        'connect_status' => null,
        'connect_error' => null,
        'name' => 'Already Settled',
        'currency' => 'AUD',
    ]);

    $job = new ShopBrandConnectJob($brand->id);
    $job->failed(new RuntimeException('a stale retry finally exhausted its tries'));

    $fresh = $brand->fresh();
    expect($fresh->connect_status)->toBeNull();
    expect($fresh->connect_error)->toBeNull();
    expect($fresh->name)->toBe('Already Settled');
});

it('a deleted ShopBrand row is a silent no-op', function () {
    $user = sbcjUser('deletedbrand');
    $connection = sbcjConnection($user);
    $brand = sbcjBrand($connection);
    $brandId = $brand->id;
    $brand->delete(); // site.shop_brands is hard-delete-only — no soft-delete column

    $this->mock(ShopBrandProfiler::class, fn ($m) => $m->shouldNotReceive('forRow'));

    $job = new ShopBrandConnectJob($brandId);

    $threw = false;
    try {
        app()->call([$job, 'handle']);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
});

it('a soft-deleted parent connection is a silent no-op', function () {
    $user = sbcjUser('softdelparent');
    $connection = sbcjConnection($user);
    $brand = sbcjBrand($connection);
    $connection->delete(); // IntegrationConnection uses SoftDeletes

    $this->mock(ShopBrandProfiler::class, fn ($m) => $m->shouldNotReceive('forRow'));

    $job = new ShopBrandConnectJob($brand->id);

    $threw = false;
    try {
        app()->call([$job, 'handle']);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
    // No terminal write either — the brand is left exactly as it was.
    expect($brand->fresh()->connect_status)->toBe('pending');
});

it('a staff-disabled integration.shop terminally fails at write time without writing the profile', function () {
    setupFeatureAvailabilityTable();
    $user = sbcjUser('disabledshop');
    $connection = sbcjConnection($user);
    $brand = sbcjBrand($connection, ['brand_id' => 'disabled-brand']);

    FeatureAvailabilityRule::query()->create(['feature_key' => 'integration.shop', 'mode' => 'disabled']);
    FeatureAvailability::flush();

    // The fetch itself still runs (plan §4 step 3 precedes step 4's re-check) —
    // only the write is gated. Profiler IS called; the write must not land.
    $this->mock(ShopBrandProfiler::class, fn ($m) => $m->shouldReceive('forRow')->once()->andReturn([
        'id' => 'disabled-brand', 'name' => 'Should Not Land', 'currency' => 'AUD', 'favicon' => null, 'logo' => null,
    ]));

    $job = new ShopBrandConnectJob($brand->id);
    app()->call([$job, 'handle']);

    $fresh = $brand->fresh();
    expect($fresh->connect_status)->toBe('failed');
    expect($fresh->connect_error)->toBe('This integration is currently unavailable.');
    expect($fresh->name)->toBeNull();
});

it('a lock held by another writer terminally fails with the stale sentence — never left pending', function () {
    Exceptions::fake();
    // Log::spy() (not Log::shouldReceive), so unrelated Log:: calls elsewhere
    // in the request (e.g. FeatureAvailability's own failed-resolve warning)
    // don't need to be pre-declared — only the assertion below constrains
    // shape. Pins that the row's terminal state was actually PRODUCED BY the
    // LockTimeoutException catch specifically, not merely consistent with it:
    // 'shop.brand_connect_job.lock_timeout' with this exact context is logged
    // from nowhere else in the job, so a future refactor that reached the
    // same connect_status/connect_error via a different branch would fail
    // this assertion even though the two state assertions below still pass.
    Log::spy();
    $user = sbcjUser('lockheld');
    $connection = sbcjConnection($user);
    $brand = sbcjBrand($connection, ['brand_id' => 'lock-brand']);

    $this->mock(ShopBrandProfiler::class, fn ($m) => $m->shouldReceive('forRow')->once()->andReturn([
        'id' => 'lock-brand', 'name' => 'Lock Store', 'currency' => null, 'favicon' => null, 'logo' => null,
    ]));

    // Cache::get() is stubbed to always return a truthy sentinel BEFORE
    // Cache::lock() is stubbed below. FeatureAvailability::for() checks
    // Cache::get("feature-availability:failopen:{$user->id}") !== null as its
    // very first line — returning non-null here makes it take its own
    // fail-open short-circuit (allowed) WITHOUT ever calling Cache::lock()
    // itself (CacheLockService's single-flight lock, reached because this
    // file's SQLite mirror has no feature_availability table). Without this,
    // that internal call would consume the mocked lock meant for the job's
    // own platform-connection lock below, and the job's real Cache::lock()
    // call would hit an unexpected-invocation Mockery error instead of the
    // timeout this test means to exercise.
    Cache::shouldReceive('get')->andReturn('primed');

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);
    Cache::shouldReceive('lock')->once()->andReturn($lock);

    $job = new ShopBrandConnectJob($brand->id);

    $threw = false;
    try {
        app()->call([$job, 'handle']);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();

    $fresh = $brand->fresh();
    expect($fresh->connect_status)->not->toBe('pending');
    expect($fresh->connect_status)->toBe('failed');
    expect($fresh->connect_error)->toBe("We couldn't save your connection just then — please try again.");
    Exceptions::assertReported(LockTimeoutException::class);
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'shop.brand_connect_job.lock_timeout'
            && ($context['brand_row_id'] ?? null) === $brand->id
            && ($context['connection_id'] ?? null) === $connection->id
            && ($context['user_id'] ?? null) === $connection->user_id);
});

it('the failed() callback terminally fails with the unknown-failure sentence', function () {
    Exceptions::fake();
    $user = sbcjUser('failedcb');
    $connection = sbcjConnection($user);
    $brand = sbcjBrand($connection, ['brand_id' => 'failed-brand']);

    $job = new ShopBrandConnectJob($brand->id);
    $job->failed(new RuntimeException('boom'));

    $fresh = $brand->fresh();
    expect($fresh->connect_status)->toBe('failed');
    expect($fresh->connect_error)->toBe('We could not load that account. Please try again.');
    Exceptions::assertReported(RuntimeException::class);
});

it('fires the cache refresher (edge purge) on the success write — the observer never watches ShopBrand', function () {
    Bus::fake();
    $user = sbcjUserWithSite('purgeuser');
    $connection = sbcjConnection($user);
    $brand = sbcjBrand($connection, ['brand_id' => 'purge-brand']);

    $this->mock(ShopBrandProfiler::class, fn ($m) => $m->shouldReceive('forRow')->once()->andReturn([
        'id' => 'purge-brand', 'name' => 'Purge Store', 'currency' => 'AUD', 'favicon' => null, 'logo' => null,
    ]));

    $job = new ShopBrandConnectJob($brand->id);
    app()->call([$job, 'handle']);

    Bus::assertDispatched(CloudflareCachePurgeJob::class);
});

// NEW BLOCKER 4 regression guard — the entire point of this job's signature.
// A connection-keyed uniqueId() ("shop:{$connectionId}", ConnectFetchJob's
// shape) would collapse two brands added under one connection within
// uniqueFor()'s 120s into ONE job, stranding the second in 'pending' forever.
it('uniqueId() differs for two brands under one connection', function () {
    $user = sbcjUser('uniqcheck');
    $connection = sbcjConnection($user);
    $brandA = sbcjBrand($connection, ['brand_id' => 'brand-a']);
    $brandB = sbcjBrand($connection, ['brand_id' => 'brand-b']);

    $jobA = new ShopBrandConnectJob($brandA->id);
    $jobB = new ShopBrandConnectJob($brandB->id);

    expect($jobA->uniqueId())->not->toBe($jobB->uniqueId());
    expect($jobA->uniqueId())->toBe("shop-brand:{$brandA->id}");
    expect($jobB->uniqueId())->toBe("shop-brand:{$brandB->id}");
});
