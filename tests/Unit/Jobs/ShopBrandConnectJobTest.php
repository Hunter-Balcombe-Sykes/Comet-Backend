<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Platforms\ShopBrandConnectJob;
use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Platforms\ShopBrandProfiler;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
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

// W9 §4 Unit 3 — ShopBrandConnectJob completes a `pending` store that
// addBrand()'s deferred branch wrote synchronously. Re-home Task 9: the job
// carries a content.collections id and settles content.storefronts /
// content.collections — which, since the re-home dropped site.shop_brands, is
// the only place a store exists, so every assertion below reads content.*.
// These tests call handle() DIRECTLY, mirroring ConnectFetchJobTest's own
// idiom — never rely on the sync queue driver to prove queued behaviour.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Re-home Task 9: content.* is now the job's ONLY read and write target —
    // storeByCollection() resolves the store there and settle()/markTerminal()
    // update it there, so the stand-in schema is load-bearing for every test
    // in this file, not just the ones that assert on it.
    setupContentTables();
});

function sbcjUser(string $h): User
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
        'platform' => 'shopify.store',
        'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'],
        'is_active' => true,
    ], $overrides));
}

/**
 * A pending store exactly as addBrand()'s deferred branch would write it
 * (plan §3c).
 *
 * Was sbcjBrand(), which built a `site.shop_brands` row. That table is dropped
 * (20260819000210) and its model deleted, so the fixture builds the StoreRecord
 * upsertStore() consumes directly — $overrides keys are the record's camelCase
 * property names, not the legacy snake_case columns.
 *
 * `selection_mode`/`link_mode` are deliberately absent rather than translated:
 * they have no content.storefronts column at all (slice 5a fix round 1,
 * Finding 4 — selection_mode was always the default in practice, and link_mode
 * is one global site.sites.shop_link_mode setting).
 */
function sbcjRecord(array $overrides = []): StoreRecord
{
    return (new StoreRecord(
        externalRef: 'brand-'.Str::random(8),
        provider: 'shopify',
        position: 0,
        url: 'https://store.example.com',
        sourceUrl: null,
        referralQuery: '',
        isIndividual: false,
        connectStatus: 'pending',
        connectError: null,
    ))->with($overrides);
}

/**
 * One connected store as a deferred addBrand() leaves it: its own anchor
 * connection plus the content.collections + content.storefronts pair
 * upsertStore() writes — which is all the job reads and writes since re-home
 * Task 9, and, since the DROP, all a store is.
 *
 * The anchor's resource_id IS the store id (ShopConnections::anchor()), which
 * is how anchorFor() reaches the connection from a record that carries no
 * connection linkage column at all.
 *
 * Slot 0 is the record READ BACK off content.*, so it carries collectionId and
 * userId — not the record that was written, which carries neither.
 *
 * @return array{0: StoreRecord, 1: string, 2: IntegrationConnection}
 */
function sbcjStore(User $user, array $overrides = []): array
{
    $record = sbcjRecord($overrides);
    $connection = sbcjConnection($user, ['resource_id' => $record->externalRef]);
    $collectionId = app(ShopContentWriter::class)->upsertStore($record, (string) $user->id);

    return [
        app(ShopConnections::class)->storeByCollection($collectionId),
        $collectionId,
        $connection,
    ];
}

/** The store's content.storefronts row — where the settle/terminal writes land. */
function sbcjStorefront(string $collectionId): object
{
    return DB::table('content.storefronts')->where('collection_id', $collectionId)->first();
}

/**
 * The store's display NAME. It lives on the parent content.collections row's
 * `label`, never on the storefront — and `label` is NOT NULL, so a store with
 * no display name reads back as its own external_ref (upsertStore() writes
 * `name ?? externalRef`, ShopContentReader nulls it back out on read).
 */
function sbcjLabel(string $collectionId): ?string
{
    return DB::table('content.collections')->where('id', $collectionId)->value('label');
}

it('defines the required queue-hygiene properties, keyed on the store not the connection', function () {
    $job = new ShopBrandConnectJob('some-collection-id');

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([5, 20])
        ->and($job->maxExceptions)->toBe(2)
        ->and($job->timeout)->toBe(45)
        ->and($job->uniqueFor)->toBe(120)
        // Must exceed the job's own $timeout — same HorizonQueueCoverageTest invariant ConnectFetchJobTest pins.
        ->and($job->uniqueFor)->toBeGreaterThan($job->timeout)
        ->and($job->timeout)->toBeGreaterThan((int) config('partna.http_fetch.connect_budget_seconds', 20))
        ->and($job->middleware())->toBe([])
        ->and($job->uniqueId())->toBe('shop-store:some-collection-id')
        ->and($job->queue)->toBe(config('partna.queues.platform_connect'));
});

it('settles a pending store: profile written, connect_status cleared', function () {
    $user = sbcjUser('settle1');
    [, $collectionId] = sbcjStore($user, ['externalRef' => 'settle-brand']);

    $this->mock(ShopBrandProfiler::class, function ($m) use ($collectionId) {
        $m->shouldReceive('forRow')->once()
            ->with(Mockery::on(fn ($arg) => $arg instanceof StoreRecord && $arg->collectionId === $collectionId))
            ->andReturn(['id' => 'settle-brand', 'name' => 'Settle Store', 'currency' => 'AUD', 'favicon' => 'https://s/favicon.ico', 'logo' => 'https://s/logo.png']);
    });

    $job = new ShopBrandConnectJob($collectionId);
    app()->call([$job, 'handle']);

    $storefront = sbcjStorefront($collectionId);
    // The display name lives on the collection, every other profile field on
    // the storefront.
    expect(sbcjLabel($collectionId))->toBe('Settle Store');
    expect($storefront->currency)->toBe('AUD');
    expect($storefront->favicon_url)->toBe('https://s/favicon.ico');
    expect($storefront->logo_url)->toBe('https://s/logo.png');
    expect($storefront->connect_status)->toBeNull();
    expect($storefront->connect_error)->toBeNull();
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

it('a degraded null currency from the fetch does not clobber the currency already stored on the store', function () {
    $user = sbcjUser('currnull');
    [, $collectionId] = sbcjStore($user, ['externalRef' => 'currnull-brand', 'currency' => 'AUD']);

    $this->mock(ShopBrandProfiler::class, fn ($m) => $m->shouldReceive('forRow')->once()->andReturn([
        'id' => 'currnull-brand', 'name' => 'Curr Null Store', 'currency' => null, 'favicon' => null, 'logo' => null,
    ]));

    $job = new ShopBrandConnectJob($collectionId);
    app()->call([$job, 'handle']);

    expect(sbcjStorefront($collectionId)->currency)->toBe('AUD');
});

it('a genuine currency change from the fetch still overwrites the stored value', function () {
    $user = sbcjUser('currchange');
    [, $collectionId] = sbcjStore($user, ['externalRef' => 'currchange-brand', 'currency' => 'AUD']);

    $this->mock(ShopBrandProfiler::class, fn ($m) => $m->shouldReceive('forRow')->once()->andReturn([
        'id' => 'currchange-brand', 'name' => 'Curr Change Store', 'currency' => 'USD', 'favicon' => null, 'logo' => null,
    ]));

    $job = new ShopBrandConnectJob($collectionId);
    app()->call([$job, 'handle']);

    expect(sbcjStorefront($collectionId)->currency)->toBe('USD');
});

it('does not recompute external_ref when the profiler resolves a different id — no key-shift, no second row', function () {
    $user = sbcjUser('norecompute');
    [, $collectionId] = sbcjStore($user, ['externalRef' => 'original-id']);

    // Simulates Shopify's meta.json drifting between the synchronous addBrand()
    // detection and this job's re-fetch — forRow() re-derives from the STORED
    // url, and must never be allowed to key-shift the store it was dispatched for.
    $this->mock(ShopBrandProfiler::class, fn ($m) => $m->shouldReceive('forRow')->once()->andReturn([
        'id' => 'drifted-id', 'name' => 'Drift Store', 'currency' => null, 'favicon' => null, 'logo' => null,
    ]));

    $job = new ShopBrandConnectJob($collectionId);
    app()->call([$job, 'handle']);

    expect(sbcjStorefront($collectionId)->external_ref)->toBe('original-id');
    expect(DB::table('content.storefronts')->where('user_id', (string) $user->id)->count())->toBe(1);
});

// P1 review fix — the compare-and-set guard. With tries=3/backoff=[5,20]/
// timeout=45, a job's own worst-case retry span (~160s) can outlast
// uniqueFor's 120s dedupe TTL, letting a second dispatch settle the store
// before a stale first attempt's own locked write finally lands. Both tests
// below simulate "the store moved on since this attempt was dispatched" by
// pre-settling it before calling handle()/failed() directly — the same end
// state a genuinely stale attempt would find.
it('a stale job cannot clobber an already-settled store (P1 compare-and-set)', function () {
    $user = sbcjUserWithSite('staleclobber');
    [, $collectionId] = sbcjStore($user, [
        'externalRef' => 'settled-brand',
        'connectStatus' => null,
        'connectError' => null,
        'name' => 'Real Settled Store',
        'currency' => 'AUD',
        'faviconUrl' => 'https://real/favicon.ico',
        'logoUrl' => 'https://real/logo.png',
    ]);

    // A stale attempt still fetches (the budget/profiler call is unconditional)
    // but must not be allowed to WRITE a now-outdated profile over the store.
    $this->mock(ShopBrandProfiler::class, fn ($m) => $m->shouldReceive('forRow')->once()->andReturn([
        'id' => 'settled-brand', 'name' => 'Stale Old Name', 'currency' => 'USD', 'favicon' => 'https://stale/favicon.ico', 'logo' => 'https://stale/logo.png',
    ]));

    // Faked AFTER the fixture is built — IntegrationConnection::create() above
    // already dispatches its own CloudflareCachePurgeJob via wasRecentlyCreated
    // (mirrors ShopRelationalStorageTest's "purges on a SECOND mutation" idiom:
    // re-fake right before the action under test so that unrelated fixture-setup
    // dispatch doesn't leak into this assertion).
    Bus::fake();

    $job = new ShopBrandConnectJob($collectionId);
    app()->call([$job, 'handle']);

    $storefront = sbcjStorefront($collectionId);
    expect(sbcjLabel($collectionId))->toBe('Real Settled Store');
    expect($storefront->currency)->toBe('AUD');
    expect($storefront->favicon_url)->toBe('https://real/favicon.ico');
    expect($storefront->logo_url)->toBe('https://real/logo.png');
    expect($storefront->connect_status)->toBeNull();
    // No purge is owed — nothing about the store's public-facing content changed.
    Bus::assertNotDispatched(CloudflareCachePurgeJob::class);
});

it('a late failure cannot un-settle an already-settled store (P1 compare-and-set on markTerminal)', function () {
    $user = sbcjUser('lateunsettle');
    [, $collectionId] = sbcjStore($user, [
        'externalRef' => 'settled-brand2',
        'connectStatus' => null,
        'connectError' => null,
        'name' => 'Already Settled',
        'currency' => 'AUD',
    ]);

    $job = new ShopBrandConnectJob($collectionId);
    $job->failed(new RuntimeException('a stale retry finally exhausted its tries'));

    $storefront = sbcjStorefront($collectionId);
    expect($storefront->connect_status)->toBeNull();
    expect($storefront->connect_error)->toBeNull();
    expect(sbcjLabel($collectionId))->toBe('Already Settled');
});

it('a removed store is a silent no-op', function () {
    $user = sbcjUser('deletedbrand');
    [, $collectionId] = sbcjStore($user);
    // retireStore() is what removeBrand()/forget() run — it deletes the
    // storefront outright, which is the "the user removed the store while this
    // job sat in the queue" case storeByCollection()'s null covers. It is now
    // the WHOLE removal: the legacy site.shop_brands row this test also had to
    // delete by hand is gone with its table.
    app(ShopContentWriter::class)->retireStore((string) $user->id, $collectionId);

    // Non-vacuous: prove the store really left content.*, so the no-op below is
    // storeByCollection() answering null rather than the fixture never building
    // a store at all.
    expect(DB::table('content.storefronts')->where('collection_id', $collectionId)->exists())->toBeFalse();

    $this->mock(ShopBrandProfiler::class, fn ($m) => $m->shouldNotReceive('forRow'));

    $job = new ShopBrandConnectJob($collectionId);

    $threw = false;
    try {
        app()->call([$job, 'handle']);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
});

it('a soft-deleted anchor connection is a silent no-op', function () {
    $user = sbcjUser('softdelparent');
    [, $collectionId, $connection] = sbcjStore($user);
    $connection->delete(); // IntegrationConnection uses SoftDeletes

    $this->mock(ShopBrandProfiler::class, fn ($m) => $m->shouldNotReceive('forRow'));

    $job = new ShopBrandConnectJob($collectionId);

    $threw = false;
    try {
        app()->call([$job, 'handle']);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
    // No terminal write either — the store is left exactly as it was.
    expect(sbcjStorefront($collectionId)->connect_status)->toBe('pending');
});

it('a staff-disabled integration.shop terminally fails at write time without writing the profile', function () {
    setupFeatureAvailabilityTable();
    $user = sbcjUser('disabledshop');
    [, $collectionId] = sbcjStore($user, ['externalRef' => 'disabled-brand']);

    FeatureAvailabilityRule::query()->create(['feature_key' => 'integration.shop', 'mode' => 'disabled']);
    FeatureAvailability::flush();

    // The fetch itself still runs (plan §4 step 3 precedes step 4's re-check) —
    // only the write is gated. Profiler IS called; the write must not land.
    $this->mock(ShopBrandProfiler::class, fn ($m) => $m->shouldReceive('forRow')->once()->andReturn([
        'id' => 'disabled-brand', 'name' => 'Should Not Land', 'currency' => 'AUD', 'favicon' => null, 'logo' => null,
    ]));

    $job = new ShopBrandConnectJob($collectionId);
    app()->call([$job, 'handle']);

    $storefront = sbcjStorefront($collectionId);
    expect($storefront->connect_status)->toBe('failed');
    expect($storefront->connect_error)->toBe('This integration is currently unavailable.');
    // `label` is NOT NULL, so "no display name landed" reads as the store's own
    // external_ref — the fetched 'Should Not Land' never reached the collection.
    expect(sbcjLabel($collectionId))->toBe('disabled-brand');
});

it('a lock held by another writer terminally fails with the stale sentence — never left pending', function () {
    Exceptions::fake();
    // Log::spy() (not Log::shouldReceive), so unrelated Log:: calls elsewhere
    // in the request (e.g. FeatureAvailability's own failed-resolve warning)
    // don't need to be pre-declared — only the assertion below constrains
    // shape. Pins that the store's terminal state was actually PRODUCED BY the
    // LockTimeoutException catch specifically, not merely consistent with it:
    // 'shop.brand_connect_job.lock_timeout' with this exact context is logged
    // from nowhere else in the job, so a future refactor that reached the
    // same connect_status/connect_error via a different branch would fail
    // this assertion even though the two state assertions below still pass.
    Log::spy();
    $user = sbcjUser('lockheld');
    [, $collectionId, $connection] = sbcjStore($user, ['externalRef' => 'lock-brand']);

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

    $job = new ShopBrandConnectJob($collectionId);

    $threw = false;
    try {
        app()->call([$job, 'handle']);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();

    $storefront = sbcjStorefront($collectionId);
    expect($storefront->connect_status)->not->toBe('pending');
    expect($storefront->connect_status)->toBe('failed');
    expect($storefront->connect_error)->toBe("We couldn't save your connection just then — please try again.");
    Exceptions::assertReported(LockTimeoutException::class);
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'shop.brand_connect_job.lock_timeout'
            && ($context['collection_id'] ?? null) === $collectionId
            && ($context['connection_id'] ?? null) === $connection->id
            && ($context['user_id'] ?? null) === $connection->user_id);
});

it('the failed() callback terminally fails with the unknown-failure sentence', function () {
    Exceptions::fake();
    $user = sbcjUser('failedcb');
    [, $collectionId] = sbcjStore($user, ['externalRef' => 'failed-brand']);

    $job = new ShopBrandConnectJob($collectionId);
    $job->failed(new RuntimeException('boom'));

    $storefront = sbcjStorefront($collectionId);
    expect($storefront->connect_status)->toBe('failed');
    expect($storefront->connect_error)->toBe('We could not load that account. Please try again.');
    Exceptions::assertReported(RuntimeException::class);
});

it('fires all three cache lanes on the success write — the observer never watches content.storefronts', function () {
    Bus::fake();
    $user = sbcjUserWithSite('purgeuser');
    [, $collectionId] = sbcjStore($user, ['externalRef' => 'purge-brand']);
    // Backdate so the touch below is observable.
    DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)
        ->update(['updated_at' => now()->subDay()->toDateTimeString()]);
    $before = DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('updated_at');

    $this->mock(ShopBrandProfiler::class, fn ($m) => $m->shouldReceive('forRow')->once()->andReturn([
        'id' => 'purge-brand', 'name' => 'Purge Store', 'currency' => 'AUD', 'favicon' => null, 'logo' => null,
    ]));

    $job = new ShopBrandConnectJob($collectionId);
    app()->call([$job, 'handle']);

    Bus::assertDispatched(CloudflareCachePurgeJob::class);
    // Fix round 1, I5: the settle is a raw DB::table() write, so the edge
    // purge alone left the build state and the site row untouched — the two
    // lanes IntegrationConnectionCacheRefresher does not own.
    expect(DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('updated_at'))
        ->not->toBe($before);
    // Final review F5: the name promised three lanes and only two were
    // asserted — nothing in this file touched site.site_build_state, so
    // deleting bumpSiteCache()'s BuildState::bump() left the suite green.
    // Unlike ShopBackfillerTest, this path writes no items, so
    // writeManualItem() never runs and this assertion is the ONLY thing
    // holding lane 1 up.
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('id');
    expect(DB::connection('pgsql')->table('site.site_build_state')->where('site_id', $siteId)
        ->value('content_revision'))->toBeGreaterThan(0);
});

// Final review F4: markTerminal() fired lanes 1+2 but not the edge purge, on a
// transition that DOES change the public payload — filterPayload() rejects
// only 'pending', so pending→failed publishes the store.
it('fires the edge purge on a real pending→failed transition', function () {
    Exceptions::fake();
    Bus::fake();
    $user = sbcjUserWithSite('terminalpurge');
    [, $collectionId] = sbcjStore($user, ['externalRef' => 'terminal-brand', 'connectStatus' => 'pending']);

    // Creating the connection row purges via IntegrationConnectionObserver
    // (wasRecentlyCreated). Discard that and release the ShouldBeUnique lock it
    // took, or the assertion below would pass on the FIXTURE's dispatch —
    // exactly the failure mode this whole fix wave is closing.
    Cache::getStore()->locks = [];
    Bus::fake();

    $job = new ShopBrandConnectJob($collectionId);
    $job->failed(new RuntimeException('boom'));

    expect(sbcjStorefront($collectionId)->connect_status)->toBe('failed');
    Bus::assertDispatched(CloudflareCachePurgeJob::class);
});

// The compare-and-set half: a store already settled is a genuine no-op and
// owes no purge. Pins that the fix above did not start purging on every
// late/stale retry attempt.
//
// The settled state is connect_status NULL, which is what settle() itself
// writes. This fixture said 'connected' until the re-home — a value no write
// path has ever produced, and one that migration 20260819000120 now positively
// forbids on content.storefronts (CHECK: NULL | 'pending' | 'failed'). SQLite
// has no such CHECK in the stand-in, so it passed here while being a row
// Postgres would reject outright.
it('does not purge when the compare-and-set finds the store already settled', function () {
    Exceptions::fake();
    Bus::fake();
    $user = sbcjUserWithSite('terminalnoop');
    [, $collectionId] = sbcjStore($user, ['externalRef' => 'settled-brand', 'connectStatus' => null]);

    // See the note in the test above — the fixture's own connect purge must not
    // be mistaken for markTerminal()'s.
    Cache::getStore()->locks = [];
    Bus::fake();

    $job = new ShopBrandConnectJob($collectionId);
    $job->failed(new RuntimeException('boom'));

    // markTerminal() guards on connect_status = 'pending'; a settled (NULL)
    // store never matches, so it is left exactly as it was.
    expect(sbcjStorefront($collectionId)->connect_status)->toBeNull();
    Bus::assertNotDispatched(CloudflareCachePurgeJob::class);
});

// NEW BLOCKER 4 regression guard — the entire point of this job's signature.
// A connection-keyed uniqueId() ("shop:{$connectionId}", ConnectFetchJob's
// shape) would collapse two stores added within uniqueFor()'s 120s into ONE
// job, stranding the second in 'pending' forever. Re-home Task 9 keeps that
// property by keying on the collection id — one per store, stable across a
// rename — rather than on the legacy uuid PK.
it('uniqueId() differs for two stores of one user', function () {
    $user = sbcjUser('uniqcheck');
    [, $collectionA] = sbcjStore($user, ['externalRef' => 'brand-a']);
    [, $collectionB] = sbcjStore($user, ['externalRef' => 'brand-b']);

    $jobA = new ShopBrandConnectJob($collectionA);
    $jobB = new ShopBrandConnectJob($collectionB);

    expect($jobA->uniqueId())->not->toBe($jobB->uniqueId());
    expect($jobA->uniqueId())->toBe("shop-store:{$collectionA}");
    expect($jobB->uniqueId())->toBe("shop-store:{$collectionB}");
});
