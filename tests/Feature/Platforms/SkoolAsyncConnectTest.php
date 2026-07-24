<?php

use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Platforms\SkoolScraper;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// CA-W4 — wires DefersBespokeConnect (CA-W2, commit 904d51c7) onto Skool, the
// second bespoke consumer after Apple (CA-W3). Skool is single-selection (one
// row per user, no ?account=), so its 202/poll shapes carry no `id` — the
// pinterest/strava idiom in DeferredConnectTest.php, not Apple's.
//
// Per ConnectFetchJob's own docblock: never rely on the sync queue driver to
// prove pending/queued behaviour — on sync, a poll immediately after connect
// would return 'ready' (or a real vendor call), never 'pending'. Queue::fake()
// proves dispatch; ConnectFetchJob::handle() is called directly to prove
// completion.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function skoolAsyncUser(string $h): User
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

// ── Flag OFF = byte-identical synchronous response ─────────────────────────

it('DELIBERATELY VACUOUS — flag off leaves skool connect byte-identical (rollout safety guard)', function () {
    // Passes against both fixed and unfixed code — config('partna.connect.deferred')
    // defaults to [] in every test (no PARTNA_CONNECT_DEFERRED in phpunit.xml), so
    // this proves nothing about the NEW branch. It exists as the safety net a
    // regression in the flag-off default would trip: if connect() ever took the
    // deferred branch when the flag is empty, this would start asserting 202
    // instead of 200 and fail.
    config(['partna.connect.deferred' => []]);
    $user = skoolAsyncUser('flagoff1');

    $this->mock(SkoolScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->once()->andReturn('https://www.skool.com/some-community');
        $m->shouldReceive('fetchCommunity')->once()->andReturn([
            'url' => 'https://www.skool.com/some-community',
            'name' => 'Some Community',
            'image' => 'https://img.example/avatar.jpg',
            'description' => 'A great community',
        ]);
    });

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/some-community'])
        ->assertOk()
        ->assertJsonPath('name', 'Some Community')
        ->assertJsonPath('url', 'https://www.skool.com/some-community');

    Queue::assertNothingPushed();

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'skool')->first();
    expect($row->last_refresh_status)->toBe('ok');
});

// ── Flag ON → 202 ────────────────────────────────────────────────────────────

it('flag on: skool connect returns 202 with status/url/statusUrl (no id — single-selection), and the pending row carries ONLY {url}', function () {
    config(['partna.connect.deferred' => ['skool']]);
    $user = skoolAsyncUser('flagon1');

    // normalizeUrl() is pure (no network) and stays synchronous either way;
    // fetchCommunity() must NEVER be called on the deferred path — no
    // expectation set for it, so any call throws (BadMethodCallException).
    $this->mock(SkoolScraper::class, function ($m) {
        $m->shouldReceive('normalizeUrl')->once()->andReturn('https://www.skool.com/some-community');
    });

    Queue::fake();

    $response = actingAsUser($user)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/some-community'])
        ->assertStatus(202)
        ->assertExactJson([
            'status' => 'pending',
            'url' => 'https://www.skool.com/some-community',
            'statusUrl' => url('/api/platforms/skool/connect/status'),
        ]);

    expect($response->json())->not->toHaveKey('id');
    expect($response->json())->not->toHaveKey('name');
    expect($response->json())->not->toHaveKey('image');
    expect($response->json())->not->toHaveKey('description');

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'skool')->first();
    expect($row->resource_id)->toBe('skool'); // single default row, no acct- hash
    expect($row->last_refresh_status)->toBe('pending');
    expect($row->last_refreshed_at)->toBeNull();
    expect($row->is_active)->toBeTrue();
    // Content assertion, not a mere non-null check: payload is NOT NULL DEFAULT
    // '{}' in Postgres while tests run SQLite — a bug that wrote a null
    // placeholder would pass locally and 500 in production (SQLSTATE 23502).
    expect($row->payload)->toBe(['url' => 'https://www.skool.com/some-community']);

    Queue::assertPushed(ConnectFetchJob::class, fn ($job) => $job->connectionId === $row->id && $job->platform === 'skool');
});

it('DELIBERATELY VACUOUS — flag on: an unparseable skool URL still 422s inline, parse failures never reach the queue', function () {
    // The URL regex + reserved-slug blocklist (both inside normalizeUrl())
    // stay synchronous and inline regardless of the flag — this proves that
    // claim rather than the (already-passing-either-way) happy path.
    config(['partna.connect.deferred' => ['skool']]);
    $user = skoolAsyncUser('flagon2');
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/skool/connect', ['url' => 'https://example.com/not-skool'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Enter your Skool community URL (skool.com/your-community).');

    Queue::assertNothingPushed();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'skool')->exists())->toBeFalse();
});

// ── Poll: pending → ready → failed ──────────────────────────────────────────

it('poll: pending row reports pending, then handle() completing the job reports ready with the /selection-identical shape', function () {
    config(['partna.connect.deferred' => ['skool']]);
    $user = skoolAsyncUser('pollready1');

    $this->mock(SkoolScraper::class, fn ($m) => $m->shouldReceive('normalizeUrl')->once()->andReturn('https://www.skool.com/some-community'));
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/some-community'])
        ->assertStatus(202);

    actingAsUser($user)->getJson('/api/platforms/skool/connect/status')
        ->assertOk()
        ->assertExactJson(['status' => 'pending']);

    // /selection ALSO reports null while pending (CA-W4 reconciliation) —
    // proven here alongside the poll so both read the same in-flight state.
    actingAsUser($user)->getJson('/api/platforms/skool/selection')
        ->assertOk()
        ->assertExactJson(['selection' => null]);

    // Swap in the real fetch expectations only now — ConnectFetchJob resolves
    // SkoolScraper fresh from the container when it runs.
    $this->mock(SkoolScraper::class, fn ($m) => $m->shouldReceive('fetchCommunity')->once()->andReturn([
        'url' => 'https://www.skool.com/some-community',
        'name' => 'Some Community',
        'image' => 'https://img.example/avatar.jpg',
        'description' => 'A great community',
    ]));

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'skool')->firstOrFail();
    app()->call([new ConnectFetchJob($row->id, 'skool'), 'handle']);

    $selection = actingAsUser($user)->getJson('/api/platforms/skool/selection')->assertOk()->json('selection');
    expect($selection)->toBe([
        'url' => 'https://www.skool.com/some-community',
        'name' => 'Some Community',
        'image' => 'https://img.example/avatar.jpg',
        'description' => 'A great community',
    ]);

    actingAsUser($user)->getJson('/api/platforms/skool/connect/status')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('connection', $selection);

    // No 'id' anywhere in the ready body — single-selection contract.
    expect(actingAsUser($user)->getJson('/api/platforms/skool/connect/status')->json())->not->toHaveKey('id');
});

it('poll: a failed skool fetch (vendor miss) surfaces the exact frozen 404 message, deliberately AS a poll failure not an HTTP 404', function () {
    // Documented status-code change (contract §3): today a vendor miss on
    // /connect returns HTTP 404; deferred, the request itself is 202 and the
    // SAME message arrives as a poll `failed` instead.
    config(['partna.connect.deferred' => ['skool']]);
    $user = skoolAsyncUser('pollfail1');

    $this->mock(SkoolScraper::class, fn ($m) => $m->shouldReceive('normalizeUrl')->once()->andReturn('https://www.skool.com/ghost-community'));
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/skool/connect', ['url' => 'https://www.skool.com/ghost-community'])
        ->assertStatus(202);

    $this->mock(SkoolScraper::class, fn ($m) => $m->shouldReceive('fetchCommunity')->once()->andReturn(null));

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'skool')->firstOrFail();
    app()->call([new ConnectFetchJob($row->id, 'skool'), 'handle']);

    actingAsUser($user)->getJson('/api/platforms/skool/connect/status')
        ->assertOk()
        ->assertExactJson(['status' => 'failed', 'error' => 'Could not read that Skool community — check the URL.']);

    // /selection stays null for a failed row too — same reconciliation as pending.
    actingAsUser($user)->getJson('/api/platforms/skool/selection')
        ->assertOk()
        ->assertExactJson(['selection' => null]);
});

// ── 404, never 403 ───────────────────────────────────────────────────────────

it('poll 404s (never 403) for another user, and for a user with no skool connection at all', function () {
    $owner = skoolAsyncUser('pollowner1');
    $stranger = skoolAsyncUser('pollstranger1');

    IntegrationConnection::create([
        'user_id' => $owner->id,
        'platform' => 'skool',
        'resource_id' => 'skool',
        'payload' => ['url' => 'https://www.skool.com/some-community'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    actingAsUser($stranger)->getJson('/api/platforms/skool/connect/status')
        ->assertStatus(404)
        ->assertJsonPath('message', 'Account not found.');
});

// ── Stale pending ────────────────────────────────────────────────────────────

it('stale pending (worker vanished > 5 minutes ago) reports failed, not pending forever', function () {
    $user = skoolAsyncUser('stale1');

    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'skool',
        'resource_id' => 'skool',
        'payload' => ['url' => 'https://www.skool.com/stale-community'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);
    // Backdate updated_at past the 5-minute staleness window (create() sets it
    // to now()). Deliberately a query-builder mass update, NOT $row->save():
    // Eloquent's save() re-touches updated_at to now() whenever the model uses
    // timestamps, silently discarding a manually forceFill()'d value.
    IntegrationConnection::where('id', $row->id)->update(['updated_at' => now()->subMinutes(10)]);

    $response = actingAsUser($user)->getJson('/api/platforms/skool/connect/status')
        ->assertOk()
        ->assertJsonPath('status', 'failed');

    expect($response->json('error'))->toBeString()->not->toBeEmpty();
});

// ── selection() pending-vs-absent reconciliation ────────────────────────────

it("selection: an 'ok' row still renders through SkoolConnectionResource unchanged (flag-off shape preserved)", function () {
    $user = skoolAsyncUser('selok1');
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'skool',
        'resource_id' => 'skool',
        'payload' => [
            'url' => 'https://www.skool.com/some-community',
            'name' => 'Some Community',
            'image' => 'https://img.example/avatar.jpg',
            'description' => 'A great community',
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->getJson('/api/platforms/skool/selection')
        ->assertOk()
        ->assertExactJson(['selection' => [
            'url' => 'https://www.skool.com/some-community',
            'name' => 'Some Community',
            'image' => 'https://img.example/avatar.jpg',
            'description' => 'A great community',
        ]]);
});

it('selection: no connection at all still reports null (unaffected by the reconciliation)', function () {
    $user = skoolAsyncUser('selnone1');

    actingAsUser($user)->getJson('/api/platforms/skool/selection')
        ->assertOk()
        ->assertExactJson(['selection' => null]);
});

// ── The job's completion write takes the connection lock (PWL-16 update) ───

it('the job\'s completion write uses the SAME per-user platform lock key as every other deferred platform', function () {
    $user = skoolAsyncUser('lockkey1');

    expect(CacheKeyGenerator::platformConnectionLock('skool', $user->id))
        ->toBe("platforms:skool:lock:{$user->id}");
});

it('a contended lock on the completion write reports failed rather than clobbering the pending row (skool is no longer PWL-16-unlocked once deferred)', function () {
    Exceptions::fake();
    $user = skoolAsyncUser('joblock1');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'skool',
        'resource_id' => 'skool',
        'payload' => ['url' => 'https://www.skool.com/some-community'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
    ]);

    // The fetch succeeds normally — only the final locked write is contended,
    // simulating a concurrent dashboard action holding the same per-user/
    // platform lock past the job's 5s block() (mirrors
    // tests/Unit/Jobs/ConnectFetchJobTest.php's identical bandcamp scenario —
    // the lock mechanics are platform-agnostic; this proves Skool is wired
    // through the SAME generic path, not a bespoke one).
    $this->mock(SkoolScraper::class, fn ($m) => $m->shouldReceive('fetchCommunity')->once()->andReturn([
        'url' => 'https://www.skool.com/some-community',
        'name' => 'Some Community',
        'image' => 'https://img.example/avatar.jpg',
        'description' => 'A great community',
    ]));

    // Defect C's write-time re-check (FeatureAvailability::for()) touches Cache
    // too — stubbed inert so the ONLY Cache::lock() call left in this test is
    // the job's own contended write lock below.
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

    app()->call([new ConnectFetchJob($connection->id, 'skool'), 'handle']);

    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('unavailable');
    expect($fresh->last_refresh_error)->not->toBeNull();
    // The message must not borrow the vendor-failure wording — this is our
    // own lock contention, not a vendor miss.
    expect($fresh->last_refresh_error)->not->toContain('Skool community');
    expect($fresh->consecutive_failures)->toBe(1);
    // Payload untouched — the contended write never landed the fresh scrape.
    expect($fresh->payload)->toBe(['url' => 'https://www.skool.com/some-community']);
    Exceptions::assertReported(LockTimeoutException::class);
});
