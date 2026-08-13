<?php

// CA-W7 — wires DefersBespokeConnect onto Fresha's STOREWIDE-mode connect,
// building directly on CA-W6 (team mode, FreshaAsyncConnectTest.php). Kept in
// its own file so each unit's proof stays reviewable in isolation — see that
// file's case pinning the W6/W7 boundary (storewide flag-on inverting from a
// synchronous 200 to a 202).
//
// Storewide is genuinely harder than team mode: team's write never depended
// on the fetch (the row was written first, content snapshotted separately),
// but storewide's DOES — FreshaServiceProjector::sync() and the composed
// `selection` both need the scraped menu, so the whole write moves into
// FreshaConnectFetch's storewide branch, guarded by the SAME booking-XOR lock
// forget()/clearBooking() already take (see that class's docblock for why).
//
// Per ConnectFetchJob's own docblock: never rely on the sync queue driver to
// prove pending/queued behaviour. Queue::fake() proves dispatch;
// ConnectFetchJob::handle() is called directly to prove completion.

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\Service;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\FreshaServiceProjector;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Strategies\Fetch\FetchShapeException;
use App\Services\Platforms\Strategies\Fetch\FetchUnavailableException;
use App\Services\Site\AdvisoryLockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

const FRESHA_STOREWIDE_FAIL = "We couldn't read that Fresha page just then — please try again.";
// Non-vendor failures (staff disable, XOR conflict, mid-flight disconnect, lock
// timeout) never asked Fresha anything, so they must NOT report a vendor miss.
// Pinned as a literal, not as FetchUnavailableException::GENERIC_USER_MESSAGE —
// a silent re-wording of that constant has to fail here.
const FRESHA_STOREWIDE_NON_VENDOR_FAIL = 'We could not load that account. Please try again.';

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    // Fix round 1, Finding 3: FreshaSelectionResource's services[] now reads
    // content.* for real inside an authenticated request (the storewide
    // POST /connect and GET /connect/status success paths below construct
    // it with a real user id) — without this the query 500s on "no such
    // table: content.items".
    setupContentTables();
    shimPgAdvisoryLockForSqlite();
});

// Non-food business => can_use_booking + can_book_storewide both true.
function freshaStorewideUser(string $h, string $sector = 'barber'): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'business',
        'sector' => $sector,
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function freshaStorewideService(string $id = 's:1', string $name = 'Cut'): array
{
    return ['serviceId' => $id, 'name' => $name, 'duration' => '30min', 'description' => null, 'price' => '$50', 'priceValue' => 50, 'currency' => 'AUD', 'category' => 'Cuts', 'hasVariants' => false];
}

/**
 * Lands one Fresha-shaped content.* service row matching freshaStorewideService()'s
 * default field values exactly — Task 12 fix round 2: FreshaSelectionResource's
 * services[] reads content.* now (FreshaServiceItems), so an exact-json
 * assertion against freshaStorewideService() needs a real landed row, not a
 * stale stored-blob passthrough. 5000 minor + 'AUD' -> displayPrice() renders
 * '$50'; 1800 seconds -> displayDuration() renders '30min'; no description ->
 * null. File-local (not shared with FreshaBookingSurfaceTest.php's
 * identically-purposed helper) because `pest` invoked against a single file
 * path does not parse sibling test files.
 */
function landFreshaStorewideContentService(string $userId, string $serviceId = 's:1', string $name = 'Cut'): void
{
    $now = now();
    $sourceId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'kind' => 'connection',
        'connection_id' => null, 'label' => 'Fresha', 'priority' => 100,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    $itemId = addItem($userId, 'service', $name);

    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => "fresha:store:{$serviceId}", 'record_key' => $serviceId,
        'item_id' => $itemId, 'kind' => 'service', 'projector_version' => 1,
        'first_seen_at' => $now, 'last_seen_at' => $now,
    ]);

    DB::table('content.offers')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
        'channel' => 'fresha', 'qualifier' => 'exact', 'amount_minor' => 5000,
        'currency' => 'AUD', 'updated_at' => $now,
    ]);

    DB::table('content.f_duration')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId, 'seconds' => 1800, 'updated_at' => $now,
    ]);

    $collectionId = (string) Str::uuid();
    DB::table('content.collections')->insert([
        'id' => $collectionId, 'user_id' => $userId, 'parent_id' => null,
        'label' => 'Cuts', 'kind' => 'service_category', 'external_ref' => $serviceId.'-cat',
        'position' => 0, 'is_user_created' => 0, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('content.collection_items')->insert([
        'collection_id' => $collectionId, 'item_id' => $itemId, 'source_id' => $sourceId, 'position' => 0,
    ]);
}

// ── Flag OFF = byte-identical synchronous response (dark-merge proof) ───────

it('DELIBERATELY VACUOUS — flag off leaves a storewide fresha connect byte-identical (rollout safety guard)', function () {
    config(['partna.connect.deferred' => []]);
    $user = freshaStorewideUser('swoff1');
    // Fix round 2: services[] on the response now comes from content.*, not
    // the legacy synchronous FreshaServiceProjector::sync() the mocked
    // fetchMenu() below still feeds — this is what makes freshaStorewideService()
    // (asserted below) real instead of stale.
    landFreshaStorewideContentService($user->id);

    $this->mock(FreshaScraper::class, function ($m) {
        $m->shouldReceive('stripLocale')->once()->andReturnUsing(fn ($u) => $u);
        $m->shouldReceive('fetchMenu')->once()->andReturn([
            'storeName' => 'Ollies', 'team' => [], 'services' => [freshaStorewideService()],
        ]);
    });
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertOk()
        ->assertExactJson([
            'url' => 'https://www.fresha.com/a/ollies-salon',
            'mode' => 'storewide',
            'selection' => [
                'url' => 'https://www.fresha.com/a/ollies-salon',
                'storeName' => 'Ollies',
                'mode' => 'storewide',
                'employee' => null,
                'services' => [freshaStorewideService()],
                'hiddenServiceIds' => [],
            ],
        ]);

    Queue::assertNothingPushed();

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->first();
    expect($row->last_refresh_status)->toBe('ok');
    // Content assertion, not a mere non-null check — proves no connectMode /
    // connectPendingAt / teamMenu leaked into the synchronous path.
    expect(array_keys($row->payload))->toBe(['url', 'selection', 'raw']);
});

it('DELIBERATELY VACUOUS — flag off still projects services synchronously for storewide', function () {
    config(['partna.connect.deferred' => []]);
    $user = freshaStorewideUser('swoff2');

    $this->mock(FreshaScraper::class, function ($m) {
        $m->shouldReceive('stripLocale')->once()->andReturnUsing(fn ($u) => $u);
        $m->shouldReceive('fetchMenu')->once()->andReturn(['storeName' => 'Ollies', 'team' => [], 'services' => [freshaStorewideService()]]);
    });

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertOk();

    expect(Service::where('user_id', $user->id)->where('source', 'fresha')->count())->toBe(1);
});

it('DELIBERATELY VACUOUS — fresha is still not registered as a deferredConnect descriptor', function () {
    $registry = app(PlatformRegistry::class);
    $fresha = $registry->get('fresha');

    expect($fresha->supportsDeferredConnect())->toBeFalse();
    expect($fresha->connectFetchErrorMessage())->toBe(FRESHA_STOREWIDE_FAIL);
});

// ── 202 path (flag on) ───────────────────────────────────────────────────────

it('flag on: storewide connect returns 202 with the exact contract body and never touches the vendor', function () {
    config(['partna.connect.deferred' => ['fresha']]);
    $user = freshaStorewideUser('swon1');

    // No FreshaScraper mock at all: stripLocale() is pure regex (safe to run
    // for real); Http::fake() with nothing recorded proves fetchMenu was
    // never reached (a mocked stripLocale-only double would prove the same
    // thing less directly).
    Http::fake();
    Queue::fake();

    $response = actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertStatus(202)
        ->assertExactJson([
            'status' => 'pending',
            'url' => 'https://www.fresha.com/a/ollies-salon',
            'mode' => 'storewide',
            'statusUrl' => url('/api/platforms/fresha/connect/status'),
        ]);

    Http::assertNothingSent();

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->first();
    expect($row->last_refresh_status)->toBe('pending');
    expect($row->last_refreshed_at)->toBeNull();
    expect($row->is_active)->toBeTrue();
    expect($row->payload['url'])->toBe('https://www.fresha.com/a/ollies-salon');
    expect($row->payload['connectMode'])->toBe('storewide');
    expect($row->payload['teamMenu'])->toBeNull();
    expect($row->payload['connectPendingAt'])->toBeString()->not->toBeEmpty();

    Queue::assertPushed(ConnectFetchJob::class, fn ($job) => $job->connectionId === $row->id && $job->platform === 'fresha');
    expect($response->json())->not->toHaveKey('id');
});

it('flag on: the 202 writes NO services rows — the projection is genuinely deferred', function () {
    config(['partna.connect.deferred' => ['fresha']]);
    $user = freshaStorewideUser('swon2');
    Http::fake();
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertStatus(202);

    expect(Service::where('user_id', $user->id)->where('source', 'fresha')->count())->toBe(0);
});

it("flag on: a storewide reconnect keeps the previous salon's selection rendered while pending", function () {
    config(['partna.connect.deferred' => ['fresha']]);
    $user = freshaStorewideUser('swon3');

    $existing = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/salon-a',
            'selection' => [
                'url' => 'https://www.fresha.com/a/salon-a', 'storeName' => 'Salon A', 'mode' => 'storewide',
                'employee' => null, 'services' => [freshaStorewideService()], 'hiddenServiceIds' => [],
            ],
            'raw' => ['services' => [freshaStorewideService()]],
            'connectMode' => 'storewide',
            'teamMenu' => null,
            'connectPendingAt' => null,
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now()->subDay(),
    ]);

    Http::fake();
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/salon-b'])
        ->assertStatus(202);

    $fresh = $existing->fresh();
    expect($fresh->payload['url'])->toBe('https://www.fresha.com/a/salon-b');
    expect($fresh->payload['selection'])->toBe($existing->payload['selection']); // carried forward verbatim
    expect($fresh->payload['raw'])->toBe($existing->payload['raw']); // carried forward verbatim
    expect($fresh->payload['connectPendingAt'])->not->toBeNull();
    expect($fresh->last_refresh_status)->toBe('pending');
});

it('flag on: statusUrl carries no ?account= segment and the body has no id key', function () {
    config(['partna.connect.deferred' => ['fresha']]);
    $user = freshaStorewideUser('swon4');
    Http::fake();
    Queue::fake();

    $response = actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertStatus(202);

    expect($response->json('statusUrl'))->not->toContain('account=');
    expect($response->json())->not->toHaveKey('id');
});

it('flag on: mode is read from the capability, not the vendor — a partna account on the same endpoint gets mode team', function () {
    config(['partna.connect.deferred' => ['fresha']]);
    $user = User::create([
        'handle' => 'swon5', 'handle_lc' => 'swon5', 'display_name' => 'Swon5',
        'first_name' => 'Swon5',
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(), 'primary_email' => 'swon5@example.com',
    ]);
    Http::fake();
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertStatus(202)
        ->assertJsonPath('mode', 'team');
});

// ── Guards run before dispatch (flag on) — constraint 1 ─────────────────────

it('flag on: the storewide capability 403 is still synchronous and nothing is queued', function () {
    config(['partna.connect.deferred' => ['fresha']]);
    $user = freshaStorewideUser('swon6', 'restaurant'); // food business => can_use_booking false
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertStatus(403)
        ->assertJsonPath('message', 'Booking is not available for your account.');

    Queue::assertNothingPushed();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
});

it('flag on: the Square XOR 409 is still synchronous and nothing is queued', function () {
    config(['partna.connect.deferred' => ['fresha']]);
    $user = freshaStorewideUser('swon7');

    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'square', 'resource_id' => 'square',
        'payload' => ['url' => 'https://book.squareup.com/appointments/x'], 'is_active' => true,
    ]);
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'Disconnect Square before connecting Fresha — only one booking provider can be active at a time.');

    Queue::assertNothingPushed();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
});

it('flag on: a non-Fresha URL still 422s inline, parse failures never reach the queue', function () {
    config(['partna.connect.deferred' => ['fresha']]);
    $user = freshaStorewideUser('swon8');
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://example.com/not-fresha'])
        ->assertStatus(422);

    Queue::assertNothingPushed();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
});

it('flag on: a held platform lock still 423s, leaves the existing row untouched, and queues nothing', function () {
    config(['partna.connect.deferred' => ['fresha']]);
    $user = freshaStorewideUser('swon9');

    $existing = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/original', 'selection' => null],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('fresha', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    Queue::fake();

    try {
        actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
            ->assertStatus(423);
    } finally {
        $lock->release();
    }

    expect($existing->fresh()->payload['url'])->toBe('https://www.fresha.com/a/original');
    Queue::assertNothingPushed();
});

it('flag on: the pending row itself 409s a subsequent Square connect', function () {
    // This is the XOR mitigation's real proof: deferral moves the row-creating
    // write BEFORE the 202, not after a ~20s scrape, so it narrows (never
    // widens) the window a Square connect could slip through.
    config(['partna.connect.deferred' => ['fresha']]);
    $user = freshaStorewideUser('swon10');
    Http::fake();
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertStatus(202);

    actingAsUser($user)->postJson('/api/platforms/square/connect', ['url' => 'https://book.squareup.com/appointments/x'])
        ->assertStatus(409);
});

// ── Job / strategy behaviour — handle() called directly, no sync driver ────

it('job success: the storewide strategy projects services, writes the selection, flips to ok, and fires the purge', function () {
    $user = freshaStorewideUser('swjob1');
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $user->id, 'subdomain' => 'swjob1',
    ]);

    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null,
            'connectMode' => 'storewide', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String(),
        ],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldReceive('fetchMenu')->once()->andReturn([
        'storeName' => 'Ollies', 'team' => [], 'services' => [freshaStorewideService()],
    ]));

    Queue::fake();

    app()->call([new ConnectFetchJob($connection->id, 'fresha'), 'handle']);

    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('ok');
    expect($fresh->last_refresh_error)->toBeNull();
    expect($fresh->last_refreshed_at)->not->toBeNull();
    expect($fresh->payload['selection']['mode'])->toBe('storewide');
    expect($fresh->payload['selection']['employee'])->toBeNull();
    expect($fresh->payload['selection']['services'])->not->toBeEmpty();
    expect($fresh->payload['raw']['services'])->not->toBeEmpty();
    expect($fresh->payload)->not->toHaveKey('connectPendingAt');
    expect(Service::where('user_id', $user->id)->where('source', 'fresha')->count())->toBe(1);

    // Proves the write was NOT saveQuietly()'d — a first content fill must
    // trigger the edge-cache purge.
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('job: the XOR is re-asserted — a square row landing mid-flight blocks the write AND the projection', function () {
    Exceptions::fake();
    $user = freshaStorewideUser('swjob2');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'storewide', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldReceive('fetchMenu')->once()->andReturn([
        'storeName' => 'Ollies', 'team' => [], 'services' => [freshaStorewideService()],
    ]));

    // A Square connect lands between the pending write and this job.
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'square', 'resource_id' => 'square',
        'payload' => ['url' => 'https://book.squareup.com/appointments/x'], 'is_active' => true,
    ]);

    $threw = false;
    try {
        app()->call([new ConnectFetchJob($connection->id, 'fresha'), 'handle']);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('unavailable');
    // A Square conflict is OUR refusal, not a vendor miss — the user must never
    // be told we couldn't read their Fresha page when we read it fine.
    expect($fresh->last_refresh_error)->toBe(FRESHA_STOREWIDE_NON_VENDOR_FAIL);
    expect($fresh->payload['selection'])->toBeNull(); // no new selection landed
    expect(Service::where('user_id', $user->id)->where('source', 'fresha')->count())->toBe(0);
    Exceptions::assertReported(FetchUnavailableException::class);
});

it('job: a disconnect landing BEFORE the job starts short-circuits at find() — the vendor is never even asked', function () {
    // The cheap case: handle()'s own ::find() misses the soft-deleted row and
    // returns immediately. This proves the pre-flight short-circuit ONLY — the
    // in-lock guard is not reached here, which is why the mid-scrape case
    // below exists as a separate test.
    $user = freshaStorewideUser('swjob3');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'storewide', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    actingAsUser($user)->deleteJson('/api/platforms/fresha')->assertOk();

    // Without this the test would silently reach the REAL scraper if the
    // short-circuit ever regressed.
    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldNotReceive('fetchMenu'));

    $threw = false;
    try {
        app()->call([new ConnectFetchJob($connection->id, 'fresha'), 'handle']);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
    expect(Service::where('user_id', $user->id)->where('source', 'fresha')->whereNull('deleted_at')->count())->toBe(0);
});

it('job: a disconnect landing MID-SCRAPE is caught inside the booking-XOR lock — the projector never runs', function () {
    // The case the lock actually exists for. handle() is already PAST its
    // ::find() and the strategy is mid-fetch when the row disappears, so the
    // only thing that can stop FreshaServiceProjector::sync() resurrecting the
    // teardown is the re-check inside the booking-XOR lock.
    $user = freshaStorewideUser('swjob3b');

    // A projected row from the prior connect — forget() tombstones it with
    // deleted_origin='sync', which is precisely what sync() resurrects.
    $user->services()->create([
        'title' => 'Cut', 'price_cents' => 5000, 'currency_code' => 'AUD', 'duration_minutes' => 30,
        'is_active' => true, 'sort_order' => 0, 'source' => 'fresha', 'is_manual' => false, 'external_id' => 's:1',
    ]);

    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'storewide', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    // The disconnect happens INSIDE the scrape — the scrape runs outside every
    // lock, so forget() takes and releases the booking-XOR lock freely here,
    // exactly as a real concurrent request would.
    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldReceive('fetchMenu')->once()->andReturnUsing(function () use ($user) {
        actingAsUser($user)->deleteJson('/api/platforms/fresha')->assertOk();

        return ['storeName' => 'Ollies', 'team' => [], 'services' => [freshaStorewideService()]];
    }));

    // THE assertion: sync() is the resurrecting call, and reaching it at all
    // would mean the guard never fired. forget() itself never touches the
    // projector, so this expectation is not satisfied by the disconnect above.
    $this->mock(FreshaServiceProjector::class, fn ($m) => $m->shouldNotReceive('sync'));

    // Captures the message rather than a bare bool so a regression reads as
    // "Mockery: sync() should not have been received", not "true is not false".
    $failure = null;
    try {
        app()->call([new ConnectFetchJob($connection->id, 'fresha'), 'handle']);
    } catch (Throwable $e) {
        $failure = $e->getMessage();
    }

    expect($failure)->toBeNull();

    // withTrashed(): the row is soft-deleted now, so ->fresh() would be null.
    $fresh = IntegrationConnection::withTrashed()->find($connection->id);
    expect($fresh->last_refresh_status)->toBe('unavailable');
    expect($fresh->last_refresh_error)->toBe(FRESHA_STOREWIDE_NON_VENDOR_FAIL);
    expect($fresh->payload['selection'])->toBeNull(); // the scraped menu was discarded
    expect(Service::where('user_id', $user->id)->where('source', 'fresha')->whereNull('deleted_at')->count())->toBe(0);
});

it('job: a held booking-XOR lock resolves terminally instead of stranding the row', function () {
    $user = freshaStorewideUser('swjob4');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'storewide', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldReceive('fetchMenu')->once()->andReturn([
        'storeName' => 'Ollies', 'team' => [], 'services' => [freshaStorewideService()],
    ]));

    // Pre-hold the SAME booking-XOR lock the strategy will try to acquire.
    // CACHE_STORE=array in phpunit.xml, so this is a real in-process lock —
    // the strategy's block(5) wait below is genuine wall time.
    $lock = Cache::lock(CacheKeyGenerator::bookingXorLock((string) $user->id), 30);
    expect($lock->get())->toBeTrue();

    try {
        $threw = false;
        try {
            app()->call([new ConnectFetchJob($connection->id, 'fresha'), 'handle']);
        } catch (Throwable) {
            $threw = true;
        }
    } finally {
        $lock->release();
    }

    expect($threw)->toBeFalse();
    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('unavailable');
    // Our own lock contention, not a vendor miss.
    expect($fresh->last_refresh_error)->toBe(FRESHA_STOREWIDE_NON_VENDOR_FAIL);
    expect($fresh->payload['selection'])->toBeNull();
    expect(Service::where('user_id', $user->id)->where('source', 'fresha')->count())->toBe(0);
});

/**
 * U2 — mirrors the booking-XOR lock timeout test above, but for the INNER
 * lock: FreshaServiceProjector::sync()'s own bounded pg_advisory_xact_lock
 * (services:{user_id}) timing out instead of the booking-XOR lock. Real
 * Postgres contention can't be reproduced under SQLite (see AdvisoryLock's
 * docblock), so this proves FreshaConnectFetch::fetchStorewide()'s NEW
 * catch (AdvisoryLockTimeoutException) branch by making sync() throw it
 * directly — a genuine exercise of that catch clause and of ConnectFetchJob's
 * existing FetchUnavailableException terminal path, not a Postgres proof.
 */
it('job: a services-lock timeout inside sync() resolves terminally instead of stranding the row', function () {
    $user = freshaStorewideUser('swjob4b');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'storewide', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldReceive('fetchMenu')->once()->andReturn([
        'storeName' => 'Ollies', 'team' => [], 'services' => [freshaStorewideService()],
    ]));
    $this->mock(FreshaServiceProjector::class, fn ($m) => $m->shouldReceive('sync')->once()
        ->andThrow(new AdvisoryLockTimeoutException("services:{$user->id}")));

    $threw = false;
    try {
        app()->call([new ConnectFetchJob($connection->id, 'fresha'), 'handle']);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
    $fresh = $connection->fresh();
    // NOT 'pending' — a stranded row would poll forever (the whole point of U2).
    expect($fresh->last_refresh_status)->toBe('unavailable');
    // Our own lock contention, not a vendor miss.
    expect($fresh->last_refresh_error)->toBe(FRESHA_STOREWIDE_NON_VENDOR_FAIL);
    expect($fresh->payload['selection'])->toBeNull();
});

it('job: a transport failure resolves to a terminal unavailable row with the contract failure string', function () {
    $user = freshaStorewideUser('swjob5');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'storewide', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldReceive('fetchMenu')->once()->andThrow(new ConnectionException('connection refused')));

    $threw = false;
    try {
        app()->call([new ConnectFetchJob($connection->id, 'fresha'), 'handle']);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('unavailable');
    expect($fresh->last_refresh_error)->toBe(FRESHA_STOREWIDE_FAIL);
    expect(Service::where('user_id', $user->id)->where('source', 'fresha')->count())->toBe(0);
});

it('job: a scraper 502 (HttpException) resolves to the same terminal state, not a 500, and never leaks the internal message', function () {
    $user = freshaStorewideUser('swjob6');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'storewide', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldReceive('fetchMenu')->once()->andThrow(new HttpException(502, 'Fresha returned HTTP 404')));

    $threw = false;
    try {
        app()->call([new ConnectFetchJob($connection->id, 'fresha'), 'handle']);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('unavailable');
    expect($fresh->last_refresh_error)->toBe(FRESHA_STOREWIDE_FAIL);
    expect($fresh->last_refresh_error)->not->toContain('404');
});

it('job: an empty storewide menu resolves to failed rather than an ok row with an empty selection', function () {
    $user = freshaStorewideUser('swjob7');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'storewide', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldReceive('fetchMenu')->once()->andReturn(['storeName' => 'Ollies', 'team' => [], 'services' => []]));

    app()->call([new ConnectFetchJob($connection->id, 'fresha'), 'handle']);

    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('unavailable');
    expect($fresh->last_refresh_error)->toBe(FRESHA_STOREWIDE_FAIL);
    expect($fresh->payload['selection'])->toBeNull();
    expect(Service::where('user_id', $user->id)->where('source', 'fresha')->count())->toBe(0);
});

it('job: a staff-disabled platform mid-flight writes no content and no services, and resolves terminally', function () {
    setupFeatureAvailabilityTable();
    FeatureAvailability::flush();

    $user = freshaStorewideUser('swjob8');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'storewide', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    // Staff disable landing AFTER the 202 but BEFORE this job runs.
    FeatureAvailabilityRule::query()->create(['feature_key' => 'integration.fresha', 'mode' => 'disabled']);
    FeatureAvailability::flush();

    // Storewide checks availability BEFORE the scrape (unlike team mode) —
    // the vendor must never be reached once staff has killed the platform.
    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldNotReceive('fetchMenu'));

    app()->call([new ConnectFetchJob($connection->id, 'fresha'), 'handle']);

    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('unavailable');
    // A staff kill switch is not a vendor miss — the vendor was never asked.
    expect($fresh->last_refresh_error)->toBe(FRESHA_STOREWIDE_NON_VENDOR_FAIL);
    expect($fresh->payload['selection'])->toBeNull();
    expect(Service::where('user_id', $user->id)->where('source', 'fresha')->count())->toBe(0);
});

it('job: a lock timeout on the connection write resolves terminally with the infrastructure string', function () {
    $user = freshaStorewideUser('swjob9');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'storewide', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldReceive('fetchMenu')->once()->andReturn([
        'storeName' => 'Ollies', 'team' => [], 'services' => [freshaStorewideService()],
    ]));

    // Pre-hold ConnectFetchJob's OWN per-platform write lock (distinct from
    // the booking-XOR lock the strategy takes for the projection).
    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('fresha', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        app()->call([new ConnectFetchJob($connection->id, 'fresha'), 'handle']);
    } finally {
        $lock->release();
    }

    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('unavailable');
    expect($fresh->last_refresh_error)->toBe("We couldn't save your connection just then — please try again.");
});

it('job: a corrupt pending row (no url) reports and resolves to error, never silently ok', function () {
    Exceptions::fake();
    $user = freshaStorewideUser('swjob10');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['selection' => null, 'connectMode' => 'storewide'], // no 'url' key
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    app()->call([new ConnectFetchJob($connection->id, 'fresha'), 'handle']);

    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('error');
    expect($fresh->last_refresh_error)->toBe(FRESHA_STOREWIDE_FAIL);
    Exceptions::assertReported(FetchShapeException::class);
});

// ── Poll: pending → ready → failed ──────────────────────────────────────────

it('poll: a pending storewide row reports exactly {status:"pending"}', function () {
    $user = freshaStorewideUser('swpoll1');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'storewide', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/connect/status')
        ->assertOk()
        ->assertExactJson(['status' => 'pending']);
});

it("poll: a ready storewide row returns the synchronous 200's connection shape and no id key", function () {
    $user = freshaStorewideUser('swpoll2');
    // Fix round 2: services[] on the response now comes from content.*, not
    // the hand-seeded payload.selection.services below — this is what makes
    // freshaStorewideService() (asserted below) real instead of stale.
    landFreshaStorewideContentService($user->id);
    $selection = [
        'url' => 'https://www.fresha.com/a/ollies-salon', 'storeName' => 'Ollies', 'mode' => 'storewide',
        'employee' => null, 'services' => [freshaStorewideService()], 'hiddenServiceIds' => [],
    ];
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => $selection,
            'connectMode' => 'storewide', 'raw' => ['services' => [freshaStorewideService()]],
        ],
        'is_active' => true, 'last_refresh_status' => 'ok', 'last_refreshed_at' => now(),
    ]);

    $response = actingAsUser($user)->getJson('/api/platforms/fresha/connect/status')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ready',
            'connection' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'mode' => 'storewide', 'selection' => $selection],
        ]);

    expect($response->json())->not->toHaveKey('id');
    expect($response->json('connection'))->not->toHaveKey('id');
});

it('poll: a failed storewide row surfaces the stored error verbatim', function () {
    $user = freshaStorewideUser('swpoll3');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'storewide', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true,
        'last_refresh_status' => 'unavailable',
        'last_refresh_error' => FRESHA_STOREWIDE_FAIL,
        'last_refreshed_at' => null,
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/connect/status')
        ->assertOk()
        ->assertExactJson(['status' => 'failed', 'error' => FRESHA_STOREWIDE_FAIL]);
});

it('poll: stale pending (>5 minutes) reports failed, not pending forever — a fresh pending row is unaffected', function () {
    $user = freshaStorewideUser('swpoll4');
    $row = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/stale-salon', 'selection' => null, 'connectMode' => 'storewide', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);
    // Query-builder mass update, NOT $row->save() — Eloquent's save() re-touches
    // updated_at, silently discarding a manually forceFill()'d value.
    IntegrationConnection::where('id', $row->id)->update(['updated_at' => now()->subMinutes(10)]);

    $response = actingAsUser($user)->getJson('/api/platforms/fresha/connect/status')
        ->assertOk()
        ->assertJsonPath('status', 'failed');
    expect($response->json('error'))->toBeString()->not->toBeEmpty();

    $user2 = freshaStorewideUser('swpoll4b');
    IntegrationConnection::create([
        'user_id' => $user2->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/fresh-salon', 'selection' => null, 'connectMode' => 'storewide', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);
    actingAsUser($user2)->getJson('/api/platforms/fresha/connect/status')
        ->assertOk()
        ->assertExactJson(['status' => 'pending']);
});

it('poll: a cron-flipped storewide row (ok with connectPendingAt still set) reports failed, not a ready body — first connect and reconnect variants', function () {
    // Exactly what PlatformRefresher::recordNotModified() leaves behind: 'ok'
    // + last_refreshed_at bumped, but connectPendingAt untouched — that write
    // never goes through FreshaConnectFetch's success path.
    $first = freshaStorewideUser('swpoll5a');
    $firstRow = IntegrationConnection::create([
        'user_id' => $first->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'storewide', 'teamMenu' => null, 'connectPendingAt' => now()->subMinutes(2)->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);
    $firstRow->forceFill(['last_refreshed_at' => now(), 'last_refresh_status' => 'ok', 'last_refresh_error' => null, 'consecutive_failures' => 0])->saveQuietly();

    actingAsUser($first)->getJson('/api/platforms/fresha/connect/status')
        ->assertOk()
        ->assertExactJson(['status' => 'failed', 'error' => "We couldn't save your connection just then — please try again."]);

    // Reconnect variant: a STALE selection carried forward from a prior
    // connect — the case an absence-only ("ok without selection") guard
    // would get wrong.
    $reconnect = freshaStorewideUser('swpoll5b');
    $staleSelection = ['url' => 'https://www.fresha.com/a/old-salon', 'storeName' => 'Old', 'mode' => 'storewide', 'employee' => null, 'services' => [], 'hiddenServiceIds' => []];
    $reconnectRow = IntegrationConnection::create([
        'user_id' => $reconnect->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/new-salon', 'selection' => $staleSelection, 'connectMode' => 'storewide', 'teamMenu' => null, 'connectPendingAt' => now()->subMinutes(2)->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);
    $reconnectRow->forceFill(['last_refreshed_at' => now(), 'last_refresh_status' => 'ok', 'last_refresh_error' => null, 'consecutive_failures' => 0])->saveQuietly();

    actingAsUser($reconnect)->getJson('/api/platforms/fresha/connect/status')
        ->assertOk()
        ->assertExactJson(['status' => 'failed', 'error' => "We couldn't save your connection just then — please try again."]);
});

it('poll 404s (never 403) for a user with no fresha connection, and for a stranger', function () {
    $owner = freshaStorewideUser('swpollowner');
    $stranger = freshaStorewideUser('swpollstranger');
    $noneUser = freshaStorewideUser('swpollnone');

    IntegrationConnection::create([
        'user_id' => $owner->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'storewide', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    actingAsUser($stranger)->getJson('/api/platforms/fresha/connect/status')
        ->assertStatus(404)
        ->assertJsonPath('message', 'Account not found.');

    actingAsUser($noneUser)->getJson('/api/platforms/fresha/connect/status')
        ->assertStatus(404)
        ->assertJsonPath('message', 'Account not found.');
});
