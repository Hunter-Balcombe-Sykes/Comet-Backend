<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\FeatureAvailability\FeatureAvailability;
use App\Services\Platforms\FreshaScraper;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Strategies\Fetch\FetchShapeException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

// CA-W6 — wires DefersBespokeConnect onto Fresha's TEAM-mode connect only.
// Storewide (CA-W7) stays synchronous throughout — see the "storewide"-tagged
// tests below, which pin that boundary.
//
// The central wrinkle this unit adds beyond Skool/Apple/Eventbrite: Fresha's
// existing refresh strategy (FreshaFetch) can't drive a fresh connect (it's
// refresh-only and 304s a selection-less row), so a NEW FreshaConnectFetch is
// selected via PlatformDescriptor::connectFetchStrategy() instead of
// fetchStrategy(). And because the fetched menu was never persisted by the
// synchronous path, this unit introduces a private payload.teamMenu snapshot
// (the poll's only source for the `ready` body) plus a payload.connectPendingAt
// marker (how the poll tells a genuine ConnectFetchJob completion apart from
// the hourly refresh cron flipping a stranded pending row to 'ok').
//
// Per ConnectFetchJob's own docblock: never rely on the sync queue driver to
// prove pending/queued behaviour. Queue::fake() proves dispatch;
// ConnectFetchJob::handle() is called directly to prove completion.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // The storewide fallthrough path (still synchronous under the flag —
    // CA-W7's scope) projects into site.services via FreshaServiceProjector.
    setupServicesTable();
    shimPgAdvisoryLockForSqlite();
});

function freshaAsyncUser(string $h, string $accountType = 'partna', ?string $sector = null): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => $accountType,
        'sector' => $sector,
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function freshaTeamMember(): array
{
    return ['employeeId' => 'e1', 'displayName' => 'Jo', 'jobTitle' => null, 'avatarUrl' => null, 'rating' => null];
}

function freshaAsyncService(): array
{
    return ['serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null, 'price' => '$50', 'priceValue' => null, 'currency' => null, 'category' => 'Cuts', 'hasVariants' => false];
}

// ── Flag OFF = byte-identical synchronous response (dark-merge proof) ───────

it('DELIBERATELY VACUOUS — flag off leaves a team-mode fresha connect byte-identical (rollout safety guard)', function () {
    // Passes against both fixed and unfixed code — config('partna.connect.deferred')
    // defaults to [] in every test (no PARTNA_CONNECT_DEFERRED in phpunit.xml), so
    // this proves nothing about the NEW branch. It exists as the safety net a
    // regression in the flag-off default would trip.
    config(['partna.connect.deferred' => []]);
    $user = freshaAsyncUser('froff1');

    $this->mock(FreshaScraper::class, function ($m) {
        $m->shouldReceive('stripLocale')->once()->andReturnUsing(fn ($u) => $u);
        $m->shouldReceive('fetchMenu')->once()->andReturn([
            'storeName' => 'Ollies',
            'team' => [freshaTeamMember()],
            'services' => [freshaAsyncService()],
        ]);
    });

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertOk()
        ->assertExactJson([
            'url' => 'https://www.fresha.com/a/ollies-salon',
            'mode' => 'team',
            'storeName' => 'Ollies',
            'team' => [freshaTeamMember()],
            'services' => [freshaAsyncService()],
        ]);

    Queue::assertNothingPushed();

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->first();
    expect($row->last_refresh_status)->toBe('ok');
    expect($row->last_refreshed_at)->not->toBeNull();
    // Content assertion, not a mere non-null check — proves no teamMenu/
    // connectMode/connectPendingAt leaked into the synchronous path.
    expect(array_keys($row->payload))->toBe(['url', 'selection']);
});

it('DELIBERATELY VACUOUS — flag off leaves a storewide fresha connect byte-identical (rollout safety guard)', function () {
    config(['partna.connect.deferred' => []]);
    $user = freshaAsyncUser('froff2', 'business', 'barber'); // non-food business => can_book_storewide

    $this->mock(FreshaScraper::class, function ($m) {
        $m->shouldReceive('stripLocale')->once()->andReturnUsing(fn ($u) => $u);
        $m->shouldReceive('fetchMenu')->once()->andReturn(['storeName' => 'Ollies', 'team' => [], 'services' => [freshaAsyncService()]]);
    });

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertOk()
        ->assertJsonPath('mode', 'storewide')
        ->assertJsonPath('url', 'https://www.fresha.com/a/ollies-salon');

    Queue::assertNothingPushed();

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->first();
    expect($row->last_refresh_status)->toBe('ok');
    expect(array_keys($row->payload))->toBe(['url', 'selection', 'raw']);
});

it('DELIBERATELY VACUOUS — fresha is not registered as a deferredConnect descriptor', function () {
    // Pins that the flag-vs-message split is deliberate: fresha's connect is
    // bespoke (never routed through ConnectResolver/GenericPlatformController),
    // so ->deferredConnect() must never be called on it — RegistryConnectCoverageTest
    // would red if it were.
    $registry = app(PlatformRegistry::class);
    $fresha = $registry->get('fresha');

    expect($fresha->supportsDeferredConnect())->toBeFalse();
    expect($fresha->connectFetchErrorMessage())->toBe("We couldn't read that Fresha page just then — please try again.");
});

// ── Flag ON → 202 (team mode only) ──────────────────────────────────────────

it('flag on: team-mode connect returns 202 with the exact contract body and never touches the vendor', function () {
    config(['partna.connect.deferred' => ['fresha']]);
    $user = freshaAsyncUser('fron1');

    // stripLocale() is pure regex — stays synchronous either way. fetchMenu()
    // must NEVER be called on the deferred path — no expectation set for it,
    // so any call throws (Mockery's strict-mock BadMethodCallException).
    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldReceive('stripLocale')->once()->andReturnUsing(fn ($u) => $u));

    Queue::fake();

    $response = actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertStatus(202)
        ->assertExactJson([
            'status' => 'pending',
            'url' => 'https://www.fresha.com/a/ollies-salon',
            'mode' => 'team',
            'statusUrl' => url('/api/platforms/fresha/connect/status'),
        ]);

    expect($response->json())->not->toHaveKey('id');
    expect($response->json())->not->toHaveKey('storeName');

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->first();
    expect($row->resource_id)->toBe('fresha'); // single default row, no acct- hash
    expect($row->last_refresh_status)->toBe('pending');
    expect($row->last_refreshed_at)->toBeNull();
    expect($row->is_active)->toBeTrue();
    // Content assertions, not a mere non-null check (payload is NOT NULL
    // DEFAULT '{}' in Postgres while tests run SQLite).
    expect($row->payload['url'])->toBe('https://www.fresha.com/a/ollies-salon');
    expect($row->payload['selection'])->toBeNull();
    expect($row->payload['connectMode'])->toBe('team');
    expect($row->payload['teamMenu'])->toBeNull();
    expect($row->payload['connectPendingAt'])->toBeString()->not->toBeEmpty();

    Queue::assertPushed(ConnectFetchJob::class, fn ($job) => $job->connectionId === $row->id && $job->platform === 'fresha');
});

it('flag on: reconnect MERGES — an existing selection and raw survive the pending write, a stale teamMenu does not, connectPendingAt refreshes', function () {
    config(['partna.connect.deferred' => ['fresha']]);
    $user = freshaAsyncUser('fron2');

    $existing = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/old-salon',
            'selection' => [
                'url' => 'https://www.fresha.com/a/old-salon', 'storeName' => 'Old Salon', 'mode' => 'employee',
                'employee' => ['employeeId' => 'e0', 'displayName' => 'Original'], 'services' => [], 'hiddenServiceIds' => [],
            ],
            'raw' => ['services' => [['serviceId' => 's:0', 'name' => 'Old Service']]],
            'teamMenu' => ['storeName' => 'Old Salon', 'team' => [], 'services' => []],
            'connectPendingAt' => null,
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now()->subDay(),
    ]);

    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldReceive('stripLocale')->once()->andReturnUsing(fn ($u) => $u));
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/new-salon'])
        ->assertStatus(202);

    $fresh = $existing->fresh();
    expect($fresh->payload['url'])->toBe('https://www.fresha.com/a/new-salon');
    expect($fresh->payload['selection'])->toBe($existing->payload['selection']); // carried forward verbatim
    expect($fresh->payload['raw'])->toBe($existing->payload['raw']); // carried forward verbatim
    expect($fresh->payload['teamMenu'])->toBeNull(); // explicitly cleared, never merged forward
    expect($fresh->payload['connectPendingAt'])->not->toBeNull();
    expect($fresh->last_refresh_status)->toBe('pending');
});

it('flag on: statusUrl carries no ?account= segment (fresha is single-selection)', function () {
    config(['partna.connect.deferred' => ['fresha']]);
    $user = freshaAsyncUser('fron3');
    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldReceive('stripLocale')->once()->andReturnUsing(fn ($u) => $u));
    Queue::fake();

    $response = actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertStatus(202);

    expect($response->json('statusUrl'))->not->toContain('account=');
});

it('flag on: storewide still returns a synchronous 200 (CA-W7 scope) and pushes nothing', function () {
    config(['partna.connect.deferred' => ['fresha']]);
    $user = freshaAsyncUser('fron4', 'business', 'barber');

    $this->mock(FreshaScraper::class, function ($m) {
        $m->shouldReceive('stripLocale')->once()->andReturnUsing(fn ($u) => $u);
        $m->shouldReceive('fetchMenu')->once()->andReturn(['storeName' => 'Ollies', 'team' => [], 'services' => [freshaAsyncService()]]);
    });
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertOk()
        ->assertJsonPath('mode', 'storewide');

    Queue::assertNothingPushed();
    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->first();
    expect($row->last_refresh_status)->toBe('ok');
});

// ── Guards run before dispatch (flag on) — constraint 1 ─────────────────────

it('flag on: capability 403 is still synchronous and nothing is queued', function () {
    config(['partna.connect.deferred' => ['fresha']]);
    $user = freshaAsyncUser('fron5', 'business', 'restaurant'); // food business => can_use_booking false

    $this->mock(FreshaScraper::class, function ($m) {
        $m->shouldNotReceive('stripLocale');
        $m->shouldNotReceive('fetchMenu');
    });
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertStatus(403)
        ->assertJsonPath('message', 'Booking is not available for your account.');

    Queue::assertNothingPushed();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
});

it('flag on: Square XOR 409 is still synchronous and nothing is queued', function () {
    config(['partna.connect.deferred' => ['fresha']]);
    $user = freshaAsyncUser('fron6');

    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'square', 'resource_id' => 'square',
        'payload' => ['url' => 'https://book.squareup.com/appointments/x'], 'is_active' => true,
    ]);

    $this->mock(FreshaScraper::class, function ($m) {
        $m->shouldNotReceive('stripLocale');
        $m->shouldNotReceive('fetchMenu');
    });
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://www.fresha.com/a/ollies-salon'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'Disconnect Square before connecting Fresha — only one booking provider can be active at a time.');

    Queue::assertNothingPushed();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
});

it('DELIBERATELY VACUOUS — flag on: a non-Fresha URL still 422s inline, parse failures never reach the queue', function () {
    // The registry regex (connectInput()) validates before the controller
    // action even runs — this stays true regardless of the flag. Proven
    // rather than assumed.
    config(['partna.connect.deferred' => ['fresha']]);
    $user = freshaAsyncUser('fron7');
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', ['url' => 'https://example.com/not-fresha'])
        ->assertStatus(422);

    Queue::assertNothingPushed();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
});

it('flag on: a held platform lock still 423s and leaves the existing row untouched, and nothing is queued', function () {
    config(['partna.connect.deferred' => ['fresha']]);
    $user = freshaAsyncUser('fron8');

    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldReceive('stripLocale')->once()->andReturnUsing(fn ($u) => $u));

    $existing = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/original', 'selection' => null],
        'is_active' => true,
        'last_refresh_status' => 'ok',
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

    // Untouched — proves the dispatch is genuinely after, and conditional on,
    // the lock closure succeeding.
    expect($existing->fresh()->payload['url'])->toBe('https://www.fresha.com/a/original');
    Queue::assertNothingPushed();
});

// ── Job / strategy behaviour — handle() called directly, no sync driver ────

it('job success: FreshaConnectFetch fills teamMenu, flips the row to ok, clears connectPendingAt, and fires the cache purge', function () {
    $user = freshaAsyncUser('frjob1');
    // Raw-insert the site so IntegrationConnectionObserver's purge (fired on
    // wasChanged('payload')) has a subdomain to resolve.
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => 'frjob1',
    ]);

    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null,
            'connectMode' => 'team', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String(),
        ],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
    ]);

    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldReceive('fetchMenu')->once()->andReturn([
        'storeName' => 'Ollies', 'team' => [freshaTeamMember()], 'services' => [freshaAsyncService()],
    ]));

    Queue::fake();

    app()->call([new ConnectFetchJob($connection->id, 'fresha'), 'handle']);

    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('ok');
    expect($fresh->last_refresh_error)->toBeNull();
    expect($fresh->last_refreshed_at)->not->toBeNull();
    expect($fresh->payload['teamMenu'])->toBe(['storeName' => 'Ollies', 'team' => [freshaTeamMember()], 'services' => [freshaAsyncService()]]);
    expect($fresh->payload['connectPendingAt'])->toBeNull();
    expect($fresh->payload['url'])->toBe('https://www.fresha.com/a/ollies-salon');
    expect($fresh->payload['selection'])->toBeNull();

    // Proves the write was NOT saveQuietly()'d — a first content fill must
    // trigger the edge-cache purge (constraint 7 of the design).
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('job: a transport failure (ConnectionException) resolves to a terminal unavailable row with the contract failure string', function () {
    $user = freshaAsyncUser('frjob2');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'team', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
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
    expect($fresh->last_refresh_error)->toBe("We couldn't read that Fresha page just then — please try again.");
});

it('job: a scraper 502 (HttpException) resolves to the same terminal unavailable state, not a 500', function () {
    $user = freshaAsyncUser('frjob3');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'team', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
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
    expect($fresh->last_refresh_error)->toBe("We couldn't read that Fresha page just then — please try again.");
    // The internal vendor-status message must never leak onto the stored row.
    expect($fresh->last_refresh_error)->not->toContain('404');
});

it('job: an empty menu resolves to failed rather than a ready row with nothing in it', function () {
    $user = freshaAsyncUser('frjob4');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'team', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldReceive('fetchMenu')->once()->andReturn(['storeName' => null, 'team' => [], 'services' => []]));

    app()->call([new ConnectFetchJob($connection->id, 'fresha'), 'handle']);

    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('unavailable');
    expect($fresh->last_refresh_error)->toBe("We couldn't read that Fresha page just then — please try again.");
    expect($fresh->payload['teamMenu'])->toBeNull(); // failure write never touches payload
});

it('job: a staff-disabled platform mid-flight does not write content and resolves terminally', function () {
    setupFeatureAvailabilityTable();
    FeatureAvailability::flush();

    $user = freshaAsyncUser('frjob5');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'team', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    // Staff disable landing AFTER the 202 but BEFORE this job runs.
    FeatureAvailabilityRule::query()->create(['feature_key' => 'integration.fresha', 'mode' => 'disabled']);
    FeatureAvailability::flush();

    // The vendor is still reached — ConnectFetchJob's re-check happens at
    // WRITE time, not before the fetch — but its result must never land.
    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldReceive('fetchMenu')->once()->andReturn([
        'storeName' => 'Ollies', 'team' => [freshaTeamMember()], 'services' => [],
    ]));

    app()->call([new ConnectFetchJob($connection->id, 'fresha'), 'handle']);

    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('unavailable');
    // A staff disable is not a vendor miss — the shared generic infra string,
    // not fresha's connectFetchErrorMessage().
    expect($fresh->last_refresh_error)->toBe('We could not load that account. Please try again.');
    expect($fresh->payload['teamMenu'])->toBeNull(); // fetched content never landed
});

it('job: a lock timeout resolves the row terminally with the infrastructure string, never pending forever', function () {
    Exceptions::fake();
    $user = freshaAsyncUser('frjob6');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'team', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldReceive('fetchMenu')->once()->andReturn([
        'storeName' => 'Ollies', 'team' => [freshaTeamMember()], 'services' => [],
    ]));

    // Defect C's write-time re-check (FeatureAvailability::for()) touches
    // Cache too — stubbed inert so the ONLY Cache::lock() call left is the
    // job's own contended write lock below (mirrors ConnectFetchJobTest's
    // identical bandcamp scenario).
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

    app()->call([new ConnectFetchJob($connection->id, 'fresha'), 'handle']);

    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('unavailable');
    expect($fresh->last_refresh_error)->not->toBeNull();
    // The message must not borrow the vendor-failure wording — this is our
    // own lock contention, not a vendor miss.
    expect($fresh->last_refresh_error)->not->toContain('Fresha page');
    expect($fresh->consecutive_failures)->toBe(1);
    expect($fresh->payload['teamMenu'])->toBeNull(); // the contended write never landed
    Exceptions::assertReported(LockTimeoutException::class);
});

it('job: a corrupt pending row (no url) reports and resolves to error, never silently ok', function () {
    Exceptions::fake();
    $user = freshaAsyncUser('frjob7');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['selection' => null, 'connectMode' => 'team'], // no 'url' key
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    app()->call([new ConnectFetchJob($connection->id, 'fresha'), 'handle']);

    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('error');
    expect($fresh->last_refresh_error)->toBe("We couldn't read that Fresha page just then — please try again.");
    Exceptions::assertReported(FetchShapeException::class);
});

it('job: a connectMode other than team is an unreachable canary in W6 — fails cleanly and terminally rather than silently proceeding (the CA-W7 storewide seam)', function () {
    Exceptions::fake();
    $user = freshaAsyncUser('frjob8');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'storewide', 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    // The vendor must never be reached for a mode this strategy doesn't implement.
    $this->mock(FreshaScraper::class, fn ($m) => $m->shouldNotReceive('fetchMenu'));

    $threw = false;
    try {
        app()->call([new ConnectFetchJob($connection->id, 'fresha'), 'handle']);
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('error');
    expect($fresh->payload)->not->toHaveKey('teamMenu'); // never even attempted
    Exceptions::assertReported(FetchShapeException::class);
});

// ── Poll: pending → ready → failed ──────────────────────────────────────────

it('poll: pending row reports exactly {status:"pending"}', function () {
    $user = freshaAsyncUser('frpoll1');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'team', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/connect/status')
        ->assertOk()
        ->assertExactJson(['status' => 'pending']);
});

it("poll: ready row returns the connection in the synchronous 200's shape, with no id key", function () {
    $user = freshaAsyncUser('frpoll2');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'team',
            'teamMenu' => ['storeName' => 'Ollies', 'team' => [freshaTeamMember()], 'services' => [freshaAsyncService()]],
            'connectPendingAt' => null,
        ],
        'is_active' => true, 'last_refresh_status' => 'ok', 'last_refreshed_at' => now(),
    ]);

    $response = actingAsUser($user)->getJson('/api/platforms/fresha/connect/status')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ready',
            'connection' => [
                'url' => 'https://www.fresha.com/a/ollies-salon',
                'mode' => 'team',
                'storeName' => 'Ollies',
                'team' => [freshaTeamMember()],
                'services' => [freshaAsyncService()],
            ],
        ]);

    expect($response->json())->not->toHaveKey('id');
    expect($response->json('connection'))->not->toHaveKey('id');
});

it('poll: failed row surfaces the stored error verbatim', function () {
    $user = freshaAsyncUser('frpoll3');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'team', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true,
        'last_refresh_status' => 'unavailable',
        'last_refresh_error' => "We couldn't read that Fresha page just then — please try again.",
        'last_refreshed_at' => null,
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/connect/status')
        ->assertOk()
        ->assertExactJson(['status' => 'failed', 'error' => "We couldn't read that Fresha page just then — please try again."]);
});

it('poll: stale pending (>5 minutes) reports failed, not pending forever — a fresh pending row is unaffected', function () {
    $user = freshaAsyncUser('frpoll4');
    $row = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/stale-salon', 'selection' => null, 'connectMode' => 'team', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);
    // Deliberately a query-builder mass update, NOT $row->save() — Eloquent's
    // save() re-touches updated_at to now(), silently discarding a manually
    // forceFill()'d value.
    IntegrationConnection::where('id', $row->id)->update(['updated_at' => now()->subMinutes(10)]);

    $response = actingAsUser($user)->getJson('/api/platforms/fresha/connect/status')
        ->assertOk()
        ->assertJsonPath('status', 'failed');
    expect($response->json('error'))->toBeString()->not->toBeEmpty();

    $user2 = freshaAsyncUser('frpoll4b');
    IntegrationConnection::create([
        'user_id' => $user2->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/fresh-salon', 'selection' => null, 'connectMode' => 'team', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);
    actingAsUser($user2)->getJson('/api/platforms/fresha/connect/status')
        ->assertOk()
        ->assertExactJson(['status' => 'pending']);
});

it('poll: an "ok" row whose connectPendingAt is still set (the hourly cron flipped a stranded pending row) reports failed, not a ready body with stale/missing content', function () {
    $user = freshaAsyncUser('frpoll5');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        // Exactly what PlatformRefresher::recordNotModified() (or a real
        // FreshaFetch refresh write) leaves behind: 'ok' + last_refreshed_at
        // bumped, but connectPendingAt untouched — that write never goes
        // through FreshaConnectFetch's success path.
        'payload' => [
            'url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'team',
            'teamMenu' => null, 'connectPendingAt' => now()->subMinutes(2)->toIso8601String(),
        ],
        'is_active' => true, 'last_refresh_status' => 'ok', 'last_refreshed_at' => now(),
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/connect/status')
        ->assertOk()
        ->assertExactJson(['status' => 'failed', 'error' => "We couldn't save your connection just then — please try again."]);
});

it('poll 404s (never 403) for another user, and for a user with no fresha connection at all', function () {
    $owner = freshaAsyncUser('frpollowner');
    $stranger = freshaAsyncUser('frpollstranger');
    $noneUser = freshaAsyncUser('frpollnone');

    IntegrationConnection::create([
        'user_id' => $owner->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/ollies-salon', 'selection' => null, 'connectMode' => 'team', 'teamMenu' => null, 'connectPendingAt' => now()->toIso8601String()],
        'is_active' => true, 'last_refresh_status' => 'pending', 'last_refreshed_at' => null,
    ]);

    actingAsUser($stranger)->getJson('/api/platforms/fresha/connect/status')
        ->assertStatus(404)
        ->assertJsonPath('message', 'Account not found.');

    actingAsUser($noneUser)->getJson('/api/platforms/fresha/connect/status')
        ->assertStatus(404)
        ->assertJsonPath('message', 'Account not found.');
});
