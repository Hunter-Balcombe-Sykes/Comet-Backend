<?php

use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Content\ManualEventWriter;
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\HumanitixScraper;
use App\Services\Platforms\LinkCardScraper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// CA-W5 — the EventsCatalog organiser branch (POST /api/platforms/events/add),
// the sixth bespoke consumer of the deferred-connect mechanism and the only
// one that lives in a SERVICE rather than a controller (no
// DefersBespokeConnect — see EventsCatalog::shouldDefer()'s docblock).
//
// The event branch and the custom-link branch are OUT OF SCOPE — addEvent()'s
// resource_id is derived from the fetched page's own declared link, which can
// differ from the posted URL, so a pending row cannot be correctly keyed
// before the fetch (hard blocker, see the unit's brief). Cases 20/21 below
// make that boundary executable, not just asserted.
//
// Per ConnectFetchJob's own docblock: never rely on the sync queue driver to
// prove pending/queued behaviour. Queue::fake() proves dispatch;
// ConnectFetchJob::handle() is called directly to prove completion.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupItemSlugsTable();
    // Convergence Phase 6: the custom branch writes an `events` POOL item.
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
});

function catalogAsyncUser(string $h): User
{
    $user = User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);

    // A hand-added event is a pool item, which needs a section off the site.
    $site = new Site(['subdomain' => $h, 'is_published' => true, 'settings' => []]);
    $site->user()->associate($user);
    $site->save();

    return $user->refresh();
}

function catalogAsyncAcctId(string $url): string
{
    return 'acct-'.substr(sha1(strtolower(trim($url))), 0, 16);
}

function catalogAsyncEvent(string $link, string $start = '2099-01-01T10:00:00+10:00'): array
{
    return [
        'name' => 'Cool Show', 'venue' => 'The Venue', 'location' => 'Melbourne',
        'startDate' => $start, 'endDate' => null, 'price' => 'Free',
        'availability' => 'available', 'image' => 'https://img.example/e.jpg', 'link' => $link,
    ];
}

// ── Flag OFF = byte-identical, all three branches ───────────────────────────

it('DELIBERATELY VACUOUS — flag off leaves all three events/add branches byte-identical (rollout safety guard)', function () {
    config(['partna.connect.deferred' => []]);
    Queue::fake();

    $orgUrl = 'https://www.eventbrite.com/o/acme-org';
    $eventUrl = 'https://www.eventbrite.com/e/acme-show';
    $customUrl = 'https://example.com/party';

    // ONE EventbriteScraper mock, bound BEFORE any request. Route::getController()
    // caches the resolved controller (and the EventsCatalog/scraper it captured)
    // on the Route object itself — rebinding a fresh mock BETWEEN two calls to
    // the SAME route within one test would silently not apply to the second
    // call. Distinguish org vs event input by argument instead of by rebinding
    // (mirrors EventsCatalogTest.php's own multi-call "aggregates" test, which
    // binds every mock up front for the same reason).
    $eb = Mockery::mock(EventbriteScraper::class);
    $eb->shouldReceive('normalizeEventUrl')->with($orgUrl)->andReturn(null);
    $eb->shouldReceive('normalizeEventUrl')->with($eventUrl)->andReturn($eventUrl);
    $eb->shouldReceive('normalizeOrgUrl')->with($orgUrl)->andReturn($orgUrl);
    $eb->shouldReceive('fetchEvents')->with($orgUrl)->andReturn(['organiser' => 'Acme', 'events' => []]);
    $eb->shouldReceive('fetchSingleEvent')->with($eventUrl)->andReturn(catalogAsyncEvent($eventUrl));
    app()->instance(EventbriteScraper::class, $eb);

    $link = Mockery::mock(LinkCardScraper::class);
    $link->shouldReceive('normalizeUrl')->andReturn($customUrl);
    $link->shouldReceive('snapshotOrMinimal')->andReturn(['url' => $customUrl, 'name' => 'Party', 'description' => null, 'favicon' => null, 'logo' => null]);
    app()->instance(LinkCardScraper::class, $link);

    $orgUser = catalogAsyncUser('vacorg1');
    actingAsUser($orgUser)->postJson('/api/platforms/events/add', ['url' => $orgUrl])
        ->assertOk()
        ->assertJsonPath('selection.accounts.0.organiser', 'Acme');

    $eventUser = catalogAsyncUser('vacevent1');
    actingAsUser($eventUser)->postJson('/api/platforms/events/add', ['url' => $eventUrl])
        ->assertOk()
        ->assertJsonPath('selection.events.0.name', 'Cool Show');

    $customUser = catalogAsyncUser('vaccustom1');
    actingAsUser($customUser)->postJson('/api/platforms/events/add', ['url' => $customUrl])
        ->assertOk()
        ->assertJsonPath('selection.events.0.platform', 'events-custom');

    // ConnectFetchJob specifically, not assertNothingPushed(): convergence
    // Phase 6 made the custom branch a POOL write, and a pool write fires the
    // three cache lanes (purge / warm / KV). Those are correct and unrelated to
    // what this case is about — that no DEFERRED CONNECT was scheduled.
    Queue::assertNotPushed(ConnectFetchJob::class);
    expect(IntegrationConnection::query()->pluck('last_refresh_status')->unique()->all())->toBe(['ok']);
});

// ── Flag ON, organiser branch → 202 ──────────────────────────────────────────

it('flag on: the ORGANISER branch returns 202 with status/selection/statusUrl and no top-level id', function () {
    config(['partna.connect.deferred' => ['eventbrite']]);
    $user = catalogAsyncUser('catorg1');
    $orgUrl = 'https://www.eventbrite.com/o/acme-async';

    $eb = Mockery::mock(EventbriteScraper::class);
    $eb->shouldReceive('normalizeEventUrl')->andReturn(null);
    $eb->shouldReceive('normalizeOrgUrl')->andReturn($orgUrl);
    // fetchEvents must NEVER be called on the deferred path — no expectation.
    app()->instance(EventbriteScraper::class, $eb);

    Queue::fake();

    $id = catalogAsyncAcctId($orgUrl);
    $response = actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $orgUrl])
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('statusUrl', url('/api/platforms/eventbrite/connect/status').'?account='.$id);

    expect($response->json())->not->toHaveKey('id');

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'eventbrite')->firstOrFail();
    expect($row->resource_id)->toBe($id);
    expect($row->last_refresh_status)->toBe('pending');
    expect($row->payload)->toBe(['url' => $orgUrl]);

    Queue::assertPushed(ConnectFetchJob::class, fn ($job) => $job->connectionId === $row->id && $job->platform === 'eventbrite');
});

it('flag on: the 202\'s selection ALREADY contains the new pending account, and nothing pre-existing is lost', function () {
    config(['partna.connect.deferred' => ['eventbrite']]);
    $user = catalogAsyncUser('catsel1');

    // Pre-existing content that must survive the connect untouched.
    $existingUrl = 'https://www.eventbrite.com/o/already-connected';
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'eventbrite',
        'resource_id' => catalogAsyncAcctId($existingUrl),
        'canonical_key' => strtolower(trim($existingUrl)),
        'payload' => ['url' => $existingUrl, 'organiser' => 'Already Here', 'next' => null, 'upcoming' => [], 'hiddenEventIds' => []],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $newUrl = 'https://www.eventbrite.com/o/brand-new';
    $eb = Mockery::mock(EventbriteScraper::class);
    $eb->shouldReceive('normalizeEventUrl')->andReturn(null);
    $eb->shouldReceive('normalizeOrgUrl')->andReturn($newUrl);
    app()->instance(EventbriteScraper::class, $eb);

    Queue::fake();

    $newId = catalogAsyncAcctId($newUrl);
    $response = actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $newUrl])
        ->assertStatus(202)
        ->json();

    $accounts = $response['selection']['accounts'];
    expect($accounts)->toHaveCount(2);

    $pendingEntry = collect($accounts)->firstWhere('id', $newId);
    expect($pendingEntry)->not->toBeNull();
    expect($pendingEntry['platform'])->toBe('eventbrite');
    expect($pendingEntry['url'])->toBe($newUrl);
    expect($pendingEntry['organiser'])->toBeNull();
    expect($pendingEntry['upcoming'])->toBe([]);
    expect($pendingEntry['removePath'])->toBe("/platforms/eventbrite/accounts/{$newId}");

    // The pre-existing account is still present, unaffected by the new connect.
    $existingEntry = collect($accounts)->firstWhere('id', catalogAsyncAcctId($existingUrl));
    expect($existingEntry)->not->toBeNull();
    expect($existingEntry['organiser'])->toBe('Already Here');
});

// ── The event and custom branches are OUT OF SCOPE ───────────────────────────

it('DELIBERATELY VACUOUS — flag on: the EVENT branch is still a synchronous 200 and pushes nothing', function () {
    // Hard blocker made executable: resource_id is derived from the fetched
    // page's OWN declared link, which can differ from the posted URL, so a
    // pending row cannot be correctly keyed before the fetch runs.
    config(['partna.connect.deferred' => ['eventbrite']]);
    $user = catalogAsyncUser('evbranch1');
    $eventUrl = 'https://www.eventbrite.com/e/still-sync';

    $eb = Mockery::mock(EventbriteScraper::class);
    $eb->shouldReceive('normalizeEventUrl')->andReturn($eventUrl);
    $eb->shouldReceive('fetchSingleEvent')->with($eventUrl)->andReturn(catalogAsyncEvent($eventUrl));
    app()->instance(EventbriteScraper::class, $eb);

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $eventUrl])
        ->assertOk()
        ->assertJsonPath('selection.events.0.name', 'Cool Show');

    // ConnectFetchJob specifically, not assertNothingPushed(): convergence
    // Phase 6 made the custom branch a POOL write, and a pool write fires the
    // three cache lanes (purge / warm / KV). Those are correct and unrelated to
    // what this case is about — that no DEFERRED CONNECT was scheduled.
    Queue::assertNotPushed(ConnectFetchJob::class);

    // Slice 7 Phase 4: the EVENT branch writes a POOL item, not a connection —
    // so there is no row to inspect. What this case pins is unchanged: the
    // branch is synchronous and never enters the deferred-connect lane.
    expect(IntegrationConnection::where('user_id', $user->id)->exists())->toBeFalse();
    expect(app(ManualEventWriter::class)->cards($user->fresh()))->toHaveCount(1);
});

it('DELIBERATELY VACUOUS — flag on: the CUSTOM branch is still a synchronous 200 and pushes nothing', function () {
    config(['partna.connect.deferred' => ['eventbrite', 'humanitix']]);
    $user = catalogAsyncUser('custbranch1');
    $customUrl = 'https://example.com/still-sync-custom';

    $link = Mockery::mock(LinkCardScraper::class);
    $link->shouldReceive('normalizeUrl')->andReturn($customUrl);
    $link->shouldReceive('snapshotOrMinimal')->andReturn(['url' => $customUrl, 'name' => 'Still Sync', 'description' => null, 'favicon' => null, 'logo' => null]);
    app()->instance(LinkCardScraper::class, $link);

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $customUrl])
        ->assertOk()
        ->assertJsonPath('selection.events.0.platform', 'events-custom');

    // ConnectFetchJob specifically, not assertNothingPushed(): convergence
    // Phase 6 made the custom branch a POOL write, and a pool write fires the
    // three cache lanes (purge / warm / KV). Those are correct and unrelated to
    // what this case is about — that no DEFERRED CONNECT was scheduled.
    Queue::assertNotPushed(ConnectFetchJob::class);

    // Convergence Phase 6: no connection at all — the custom branch writes an
    // `events` POOL item. What this case pins is unchanged: the branch is
    // synchronous and never enters the deferred-connect lane.
    expect(IntegrationConnection::where('user_id', $user->id)
        ->where('surface_key', 'partna.manual_event')->exists())->toBeFalse();
    expect(app(ManualEventWriter::class)->cards($user->fresh()))->toHaveCount(1);
});

// ── Cap + lock stay synchronous ──────────────────────────────────────────────

it('flag on: the catalogue\'s own 5-organiser cap still 422s synchronously and queues nothing', function () {
    config(['partna.connect.deferred' => ['eventbrite']]);
    $user = catalogAsyncUser('catcap1');

    for ($i = 0; $i < 5; $i++) {
        $existingUrl = "https://www.eventbrite.com/o/existing-{$i}";
        IntegrationConnection::create([
            'user_id' => $user->id,
            'platform' => 'eventbrite',
            'resource_id' => catalogAsyncAcctId($existingUrl),
            'canonical_key' => strtolower(trim($existingUrl)),
            'payload' => ['url' => $existingUrl, 'organiser' => 'Existing', 'next' => null, 'upcoming' => [], 'hiddenEventIds' => []],
            'is_active' => true,
            'last_refresh_status' => 'ok',
        ]);
    }

    $sixthUrl = 'https://www.eventbrite.com/o/sixth-organiser';
    $eb = Mockery::mock(EventbriteScraper::class);
    $eb->shouldReceive('normalizeEventUrl')->andReturn(null);
    $eb->shouldReceive('normalizeOrgUrl')->andReturn($sixthUrl);
    app()->instance(EventbriteScraper::class, $eb);

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $sixthUrl])
        ->assertStatus(422)
        // Different wording from the controller's 'You can connect up to 5
        // accounts.' — both are frozen contract, deliberately preserved.
        ->assertJsonPath('message', 'You can connect up to 5 organisers per platform.');

    Queue::assertNothingPushed();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'eventbrite')->count())->toBe(5);
});

it('flag on: a held platform lock still 423s and queues nothing', function () {
    config(['partna.connect.deferred' => ['eventbrite']]);
    $orgUrl = 'https://www.eventbrite.com/o/locked-catalogue';

    $eb = Mockery::mock(EventbriteScraper::class);
    $eb->shouldReceive('normalizeEventUrl')->andReturn(null);
    $eb->shouldReceive('normalizeOrgUrl')->andReturn($orgUrl);
    // fetchAccount must NEVER be called on the deferred path — no expectation.
    app()->instance(EventbriteScraper::class, $eb);

    $user = catalogAsyncUser('catlock1');

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('eventbrite', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    Queue::fake();

    try {
        actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $orgUrl])
            ->assertStatus(423)
            ->assertJson(['message' => 'Another change is still saving — please retry in a moment.']);
    } finally {
        $lock->release();
    }

    Queue::assertNothingPushed();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'eventbrite')->count())->toBe(0);
});

// ── Cross-endpoint identity ──────────────────────────────────────────────────

it('flag on: humanitix organiser via events/add writes the SAME row id the humanitix connect endpoint would', function () {
    config(['partna.connect.deferred' => ['humanitix']]);
    $hostUrl = 'https://events.humanitix.com/host/acme-cross';
    $expectedId = 'acct-'.substr(sha1(strtolower(trim($hostUrl))), 0, 16);

    Queue::fake();

    // Via POST /events/add (EventsCatalog::storeAccountDeferred).
    $hxCatalog = Mockery::mock(HumanitixScraper::class);
    $hxCatalog->shouldReceive('normalizeEventUrl')->andReturn(null);
    $hxCatalog->shouldReceive('resolveHostUrl')->andReturn($hostUrl);
    app()->instance(HumanitixScraper::class, $hxCatalog);

    $catalogUser = catalogAsyncUser('crosscat1');
    actingAsUser($catalogUser)->postJson('/api/platforms/events/add', ['url' => $hostUrl])
        ->assertStatus(202);

    $catalogRow = IntegrationConnection::where('user_id', $catalogUser->id)->where('platform', 'humanitix')->firstOrFail();
    expect($catalogRow->resource_id)->toBe($expectedId);

    // Via POST /platforms/humanitix/connect (EventsPlatformController::addAccountDeferred).
    $hxController = Mockery::mock(HumanitixScraper::class);
    $hxController->shouldReceive('resolveHostUrl')->andReturn($hostUrl);
    app()->instance(HumanitixScraper::class, $hxController);

    $controllerUser = catalogAsyncUser('crosscon1');
    actingAsUser($controllerUser)->postJson('/api/platforms/humanitix/connect', ['url' => $hostUrl])
        ->assertStatus(202);

    $controllerRow = IntegrationConnection::where('user_id', $controllerUser->id)->where('platform', 'humanitix')->firstOrFail();
    expect($controllerRow->resource_id)->toBe($expectedId);

    expect($controllerRow->resource_id)->toBe($catalogRow->resource_id);
});

// ── FetchBudget nesting proxy ────────────────────────────────────────────────

it('flag on: no FetchBudget nesting — the job\'s own budget is opened only after the request\'s has closed', function () {
    // The real invariant (PHP cannot observe budget nesting directly): the
    // dispatch in EventsController::add() sits AFTER $this->budget->open()
    // (wrapping addByUrl()) has already returned, so on the sync driver
    // ConnectFetchJob::handle()'s own FetchBudget::open() runs strictly
    // SEQUENTIALLY after the request's, never nested inside it. The cheap
    // enforceable proxy: with Queue::fake(), the job is PUSHED (not executed
    // inline) by the request, and only runs — and completes — when handle()
    // is invoked separately below. A future edit that moved the dispatch call
    // into EventsCatalog (still inside the request's open budget) would nest
    // the job's budget the moment QUEUE_CONNECTION=sync ran it inline —
    // caught only by reviewing the dispatch call site, which this comment
    // flags for exactly that review.
    config(['partna.connect.deferred' => ['eventbrite']]);
    $user = catalogAsyncUser('nonest1');
    $orgUrl = 'https://www.eventbrite.com/o/acme-nonest';

    $eb = Mockery::mock(EventbriteScraper::class);
    $eb->shouldReceive('normalizeEventUrl')->andReturn(null);
    $eb->shouldReceive('normalizeOrgUrl')->andReturn($orgUrl);
    app()->instance(EventbriteScraper::class, $eb);

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/events/add', ['url' => $orgUrl])->assertStatus(202);

    Queue::assertPushed(ConnectFetchJob::class);

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'eventbrite')->firstOrFail();
    expect($row->last_refresh_status)->toBe('pending');

    $eb2 = Mockery::mock(EventbriteScraper::class);
    $eb2->shouldReceive('fetchEvents')->once()->andReturn(['organiser' => 'Acme', 'events' => []]);
    app()->instance(EventbriteScraper::class, $eb2);

    app()->call([new ConnectFetchJob($row->id, 'eventbrite'), 'handle']);

    expect($row->fresh()->last_refresh_status)->toBe('ok');
});
