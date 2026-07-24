<?php

use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Platforms\EventbriteScraper;
use App\Services\Platforms\HumanitixScraper;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// CA-W5 — wires DefersBespokeConnect onto the shared EventsPlatformController
// base (Eventbrite + Humanitix), the fourth+fifth bespoke consumers after
// Apple (CA-W3) and Skool (CA-W4). Both are multi-account (?account=), so
// their 202/poll shapes carry an `id`, mirroring Apple's idiom.
//
// Humanitix is the one platform in the programme whose URL-normalisation
// (resolveHostUrl) is ITSELF a live fetch and IS the row's identity — it
// stays inline/budget-wrapped even with the flag on; only the account-events
// scrape defers. See EventsPlatformController::addAccountDeferred()'s docblock.
//
// Per ConnectFetchJob's own docblock: never rely on the sync queue driver to
// prove pending/queued behaviour. Queue::fake() proves dispatch;
// ConnectFetchJob::handle() is called directly to prove completion.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function eventsAsyncUser(string $h): User
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

function eventsAsyncAcctId(string $url): string
{
    return 'acct-'.substr(sha1(strtolower(trim($url))), 0, 16);
}

// ── Flag OFF = byte-identical synchronous response ─────────────────────────

it('DELIBERATELY VACUOUS — flag off leaves eventbrite connect byte-identical (rollout safety guard)', function () {
    // Passes against both fixed and unfixed code — config('partna.connect.deferred')
    // defaults to [] in every test (no PARTNA_CONNECT_DEFERRED in phpunit.xml), so
    // this proves nothing about the NEW branch. It exists as the safety net a
    // regression in the flag-off default would trip.
    config(['partna.connect.deferred' => []]);
    $user = eventsAsyncUser('eboff1');
    $url = 'https://www.eventbrite.com/o/acme-1';

    $this->mock(EventbriteScraper::class, function ($m) use ($url) {
        $m->shouldReceive('normalizeOrgUrl')->once()->andReturn($url);
        $m->shouldReceive('fetchEvents')->once()->andReturn(['organiser' => 'Acme', 'events' => []]);
    });

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/eventbrite/connect', ['url' => $url])
        ->assertOk()
        ->assertJsonPath('organiser', 'Acme');

    Queue::assertNothingPushed();

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'eventbrite')->first();
    expect($row->last_refresh_status)->toBe('ok');
    expect($row->last_refreshed_at)->not->toBeNull();
});

it('DELIBERATELY VACUOUS — flag off leaves humanitix connect byte-identical (rollout safety guard)', function () {
    config(['partna.connect.deferred' => []]);
    $user = eventsAsyncUser('hxoff1');
    $url = 'https://events.humanitix.com/host/acme';

    $this->mock(HumanitixScraper::class, function ($m) use ($url) {
        $m->shouldReceive('resolveHostUrl')->once()->andReturn($url);
        $m->shouldReceive('fetchEvents')->once()->andReturn(['organiser' => 'Acme', 'events' => []]);
    });

    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/humanitix/connect', ['url' => $url])
        ->assertOk()
        ->assertJsonPath('organiser', 'Acme');

    Queue::assertNothingPushed();

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'humanitix')->first();
    expect($row->last_refresh_status)->toBe('ok');
    expect($row->last_refreshed_at)->not->toBeNull();
});

// ── Flag ON → 202 ────────────────────────────────────────────────────────────

it('flag on: eventbrite connect returns 202 with status/id/url/statusUrl, and the pending row carries ONLY {url}', function () {
    config(['partna.connect.deferred' => ['eventbrite']]);
    $user = eventsAsyncUser('ebon1');
    $url = 'https://www.eventbrite.com/o/acme-1';

    // normalizeOrgUrl() is pure regex — stays synchronous either way.
    // fetchEvents() must NEVER be called on the deferred path — no
    // expectation set for it, so any call throws BadMethodCallException.
    $this->mock(EventbriteScraper::class, fn ($m) => $m->shouldReceive('normalizeOrgUrl')->once()->andReturn($url));

    Queue::fake();

    $id = eventsAsyncAcctId($url);
    $response = actingAsUser($user)->postJson('/api/platforms/eventbrite/connect', ['url' => $url])
        ->assertStatus(202)
        ->assertExactJson([
            'status' => 'pending',
            'id' => $id,
            'url' => $url,
            'statusUrl' => url('/api/platforms/eventbrite/connect/status').'?account='.$id,
        ]);

    expect($response->json())->not->toHaveKey('organiser');
    expect($response->json())->not->toHaveKey('next');
    expect($response->json())->not->toHaveKey('upcoming');

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'eventbrite')->first();
    expect($row->resource_id)->toBe($id);
    expect($row->last_refresh_status)->toBe('pending');
    expect($row->last_refreshed_at)->toBeNull();
    expect($row->is_active)->toBeTrue();
    expect($row->canonical_key)->toBe(strtolower(trim($url)));
    // Content assertion, not a mere non-null check: payload is NOT NULL DEFAULT
    // '{}' in Postgres while tests run SQLite (constraint 6/9).
    expect($row->payload)->toBe(['url' => $url]);

    Queue::assertPushed(ConnectFetchJob::class, fn ($job) => $job->connectionId === $row->id && $job->platform === 'eventbrite');
});

it('flag on: humanitix connect resolves the host URL INLINE (one live fetch) and defers only the events scrape', function () {
    config(['partna.connect.deferred' => ['humanitix']]);
    $user = eventsAsyncUser('hxon1');
    $postedUrl = 'https://events.humanitix.com/some-event';
    $resolvedHostUrl = 'https://events.humanitix.com/host/acme';

    // resolveHostUrl() is the fetch that determines identity — must run
    // exactly once. fetchEvents() must NEVER be called — no expectation set.
    $this->mock(HumanitixScraper::class, fn ($m) => $m->shouldReceive('resolveHostUrl')->once()->andReturn($resolvedHostUrl));

    Queue::fake();

    $id = eventsAsyncAcctId($resolvedHostUrl);
    actingAsUser($user)->postJson('/api/platforms/humanitix/connect', ['url' => $postedUrl])
        ->assertStatus(202)
        ->assertExactJson([
            'status' => 'pending',
            'id' => $id,
            'url' => $resolvedHostUrl,
            'statusUrl' => url('/api/platforms/humanitix/connect/status').'?account='.$id,
        ]);

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'humanitix')->first();
    // The row is keyed on the RESOLVED host url, not the posted event url.
    expect($row->resource_id)->toBe($id);
    expect($row->payload)->toBe(['url' => $resolvedHostUrl]);
});

it('DELIBERATELY VACUOUS — flag on: an unresolvable organiser URL still 422s inline, resolution failures never reach the queue', function () {
    config(['partna.connect.deferred' => ['eventbrite', 'humanitix']]);

    $this->mock(EventbriteScraper::class, fn ($m) => $m->shouldReceive('normalizeOrgUrl')->once()->andReturn(null));
    Queue::fake();

    $user = eventsAsyncUser('ebbad1');
    actingAsUser($user)->postJson('/api/platforms/eventbrite/connect', ['url' => 'https://example.com/not-eventbrite'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Enter your Eventbrite organiser URL (eventbrite.com/o/...).');

    $this->mock(HumanitixScraper::class, fn ($m) => $m->shouldReceive('resolveHostUrl')->once()->andReturn(null));
    $user2 = eventsAsyncUser('hxbad1');
    actingAsUser($user2)->postJson('/api/platforms/humanitix/connect', ['url' => 'https://example.com/not-humanitix'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Enter your Humanitix host URL (events.humanitix.com/host/...).');

    Queue::assertNothingPushed();
    expect(IntegrationConnection::query()->count())->toBe(0);
});

it('flag on: the 5-account cap still 422s SYNCHRONOUSLY, before the 202 and before any dispatch', function () {
    config(['partna.connect.deferred' => ['eventbrite']]);
    $user = eventsAsyncUser('ebcap1');

    for ($i = 0; $i < 5; $i++) {
        IntegrationConnection::create([
            'user_id' => $user->id,
            'platform' => 'eventbrite',
            'resource_id' => 'acct-'.substr(sha1("existing-{$i}"), 0, 16),
            'canonical_key' => "existing-{$i}",
            'payload' => ['url' => "https://www.eventbrite.com/o/existing-{$i}", 'organiser' => 'Existing', 'next' => null, 'upcoming' => [], 'hiddenEventIds' => []],
            'is_active' => true,
            'last_refresh_status' => 'ok',
        ]);
    }

    $url = 'https://www.eventbrite.com/o/sixth';
    $this->mock(EventbriteScraper::class, fn ($m) => $m->shouldReceive('normalizeOrgUrl')->once()->andReturn($url));
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/eventbrite/connect', ['url' => $url])
        ->assertStatus(422)
        ->assertJsonPath('message', 'You can connect up to 5 accounts.');

    Queue::assertNothingPushed();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'eventbrite')->count())->toBe(5);
});

it('flag on: a held platform lock still 423s synchronously and queues nothing', function () {
    config(['partna.connect.deferred' => ['eventbrite']]);
    $user = eventsAsyncUser('eblock1');
    $url = 'https://www.eventbrite.com/o/acme-locked';

    $this->mock(EventbriteScraper::class, fn ($m) => $m->shouldReceive('normalizeOrgUrl')->once()->andReturn($url));
    Queue::fake();

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('eventbrite', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/eventbrite/connect', ['url' => $url])
            ->assertStatus(423)
            ->assertJsonPath('message', 'Another change is still saving — please retry in a moment.');
    } finally {
        $lock->release();
    }

    Queue::assertNothingPushed();
    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'eventbrite')->exists())->toBeFalse();
});

it('flag on: reconnecting an already-connected organiser MERGES — hiddenEventIds and the stored upcoming survive the pending window', function () {
    config(['partna.connect.deferred' => ['eventbrite']]);
    $user = eventsAsyncUser('ebmerge1');
    $url = 'https://www.eventbrite.com/o/acme-merge';
    $id = eventsAsyncAcctId($url);

    $existingPayload = [
        'url' => $url,
        'organiser' => 'Acme Org',
        'next' => ['id' => 'ev1', 'name' => 'Gig', 'link' => 'https://www.eventbrite.com/e/1', 'startDate' => '2099-01-01T00:00:00+00:00'],
        'upcoming' => [['id' => 'ev1', 'name' => 'Gig', 'link' => 'https://www.eventbrite.com/e/1', 'startDate' => '2099-01-01T00:00:00+00:00']],
        'hiddenEventIds' => ['abc'],
    ];
    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'eventbrite',
        'resource_id' => $id,
        'canonical_key' => strtolower(trim($url)),
        'payload' => $existingPayload,
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now()->subDay(),
    ]);

    $this->mock(EventbriteScraper::class, fn ($m) => $m->shouldReceive('normalizeOrgUrl')->once()->andReturn($url));
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/eventbrite/connect', ['url' => $url])
        ->assertStatus(202);

    $fresh = $row->fresh();
    expect($fresh->id)->toBe($row->id);
    expect($fresh->last_refresh_status)->toBe('pending');
    expect($fresh->payload)->toBe($existingPayload);
});

// ── Poll: pending → ready → failed ──────────────────────────────────────────

it('poll: pending reports pending, then handle() completing the job reports ready with the connect-200-identical shape', function () {
    config(['partna.connect.deferred' => ['eventbrite']]);
    $user = eventsAsyncUser('pollready1');
    $url = 'https://www.eventbrite.com/o/acme-ready';
    $id = eventsAsyncAcctId($url);

    $this->mock(EventbriteScraper::class, fn ($m) => $m->shouldReceive('normalizeOrgUrl')->once()->andReturn($url));
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/eventbrite/connect', ['url' => $url])->assertStatus(202);

    actingAsUser($user)->getJson("/api/platforms/eventbrite/connect/status?account={$id}")
        ->assertOk()
        ->assertExactJson(['status' => 'pending']);

    $event = ['name' => 'Gig', 'startDate' => '2099-01-01T00:00:00+00:00', 'endDate' => null, 'link' => 'https://www.eventbrite.com/e/1'];
    $this->mock(EventbriteScraper::class, fn ($m) => $m->shouldReceive('fetchEvents')->once()->andReturn([
        'organiser' => 'Acme Org',
        'events' => [$event],
    ]));

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'eventbrite')->firstOrFail();
    app()->call([new ConnectFetchJob($row->id, 'eventbrite'), 'handle']);

    $status = actingAsUser($user)->getJson("/api/platforms/eventbrite/connect/status?account={$id}")
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('id', $id)
        ->assertJsonPath('connection.organiser', 'Acme Org')
        ->json();

    expect($status['connection'])->toHaveKeys(['url', 'organiser', 'next', 'upcoming']);
    expect($status['connection']['upcoming'])->toHaveCount(1);

    $fresh = $row->fresh();
    expect($fresh->last_refresh_status)->toBe('ok');
    expect($fresh->last_refreshed_at)->not->toBeNull();
});

it('poll: a failed eventbrite fetch surfaces "Could not load that Eventbrite page." verbatim', function () {
    config(['partna.connect.deferred' => ['eventbrite']]);
    $user = eventsAsyncUser('pollfaileb1');
    $url = 'https://www.eventbrite.com/o/acme-fail';

    $this->mock(EventbriteScraper::class, fn ($m) => $m->shouldReceive('normalizeOrgUrl')->once()->andReturn($url));
    Queue::fake();
    actingAsUser($user)->postJson('/api/platforms/eventbrite/connect', ['url' => $url])->assertStatus(202);

    $this->mock(EventbriteScraper::class, fn ($m) => $m->shouldReceive('fetchEvents')->once()->andReturn(null));

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'eventbrite')->firstOrFail();
    app()->call([new ConnectFetchJob($row->id, 'eventbrite'), 'handle']);

    actingAsUser($user)->getJson("/api/platforms/eventbrite/connect/status?account={$row->resource_id}")
        ->assertOk()
        ->assertExactJson(['status' => 'failed', 'error' => 'Could not load that Eventbrite page.']);

    expect($row->fresh()->last_refresh_status)->toBe('unavailable');
});

it('poll: a failed humanitix fetch surfaces "Could not load that Humanitix page." verbatim', function () {
    config(['partna.connect.deferred' => ['humanitix']]);
    $user = eventsAsyncUser('pollfailhx1');
    $url = 'https://events.humanitix.com/host/acme-fail';

    $this->mock(HumanitixScraper::class, fn ($m) => $m->shouldReceive('resolveHostUrl')->once()->andReturn($url));
    Queue::fake();
    actingAsUser($user)->postJson('/api/platforms/humanitix/connect', ['url' => $url])->assertStatus(202);

    $this->mock(HumanitixScraper::class, fn ($m) => $m->shouldReceive('fetchEvents')->once()->andReturn(null));

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'humanitix')->firstOrFail();
    app()->call([new ConnectFetchJob($row->id, 'humanitix'), 'handle']);

    actingAsUser($user)->getJson("/api/platforms/humanitix/connect/status?account={$row->resource_id}")
        ->assertOk()
        ->assertExactJson(['status' => 'failed', 'error' => 'Could not load that Humanitix page.']);

    expect($row->fresh()->last_refresh_status)->toBe('unavailable');
});

// ── 404, never 403 ───────────────────────────────────────────────────────────

it('poll 404s (never 403) for another user\'s account and for an unknown account id', function () {
    $owner = eventsAsyncUser('pollowner1');
    $stranger = eventsAsyncUser('pollstranger1');
    $url = 'https://www.eventbrite.com/o/owner-only';
    $id = eventsAsyncAcctId($url);

    IntegrationConnection::create([
        'user_id' => $owner->id,
        'platform' => 'eventbrite',
        'resource_id' => $id,
        'payload' => ['url' => $url],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    actingAsUser($stranger)->getJson("/api/platforms/eventbrite/connect/status?account={$id}")
        ->assertStatus(404)
        ->assertJsonPath('message', 'Account not found.');

    actingAsUser($owner)->getJson('/api/platforms/eventbrite/connect/status?account=acct-doesnotexist0')
        ->assertStatus(404)
        ->assertJsonPath('message', 'Account not found.');
});

// ── Stale pending ────────────────────────────────────────────────────────────

it('stale pending (worker vanished > 5 minutes ago) reports failed, not pending forever', function () {
    $user = eventsAsyncUser('stale1');
    $url = 'https://www.eventbrite.com/o/stale-org';
    $id = eventsAsyncAcctId($url);

    $row = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'eventbrite',
        'resource_id' => $id,
        'payload' => ['url' => $url],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);
    // create() sets updated_at to now() — a manual query-builder update (NOT
    // $row->save(), which would re-touch it to now()) backdates past the
    // 5-minute staleness window.
    IntegrationConnection::where('id', $row->id)->update(['updated_at' => now()->subMinutes(10)]);

    $response = actingAsUser($user)->getJson("/api/platforms/eventbrite/connect/status?account={$id}")
        ->assertOk()
        ->assertJsonPath('status', 'failed');

    expect($response->json('error'))->toBeString()->not->toBeEmpty();
    // The verdict is synthetic — the stored row is untouched, so a late
    // worker can still complete it after this poll.
    expect($row->fresh()->last_refresh_status)->toBe('pending');
});

// ── ?account= row selection ──────────────────────────────────────────────────

it('poll: ?account= selects the right row among several, and omitting it falls back to the first', function () {
    $user = eventsAsyncUser('multi1');
    $first = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'eventbrite',
        'resource_id' => 'acct-first00000000',
        'sort_order' => 0,
        'payload' => ['url' => 'https://www.eventbrite.com/o/first', 'organiser' => 'First Org', 'next' => null, 'upcoming' => [], 'hiddenEventIds' => []],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
    $second = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'eventbrite',
        'resource_id' => 'acct-second0000000',
        'sort_order' => 1,
        'payload' => ['url' => 'https://www.eventbrite.com/o/second'],
        'is_active' => true,
        'last_refresh_status' => 'pending',
    ]);

    actingAsUser($user)->getJson('/api/platforms/eventbrite/connect/status')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('id', $first->resource_id);

    actingAsUser($user)->getJson("/api/platforms/eventbrite/connect/status?account={$second->resource_id}")
        ->assertOk()
        ->assertExactJson(['status' => 'pending']);
});

// ── The job's completion write takes the connection lock ────────────────────

it("the job's completion write takes the SAME per-user platform lock key the controller used", function () {
    $user = eventsAsyncUser('lockkey1');

    expect(CacheKeyGenerator::platformConnectionLock('eventbrite', (string) $user->id))
        ->toBe("platforms:eventbrite:lock:{$user->id}");
    expect(CacheKeyGenerator::platformConnectionLock('humanitix', (string) $user->id))
        ->toBe("platforms:humanitix:lock:{$user->id}");
});

it('a contended lock on the completion write reports failed rather than clobbering the pending row', function () {
    Exceptions::fake();
    $user = eventsAsyncUser('joblock1');
    $url = 'https://www.eventbrite.com/o/acme-joblock';
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'eventbrite',
        'resource_id' => eventsAsyncAcctId($url),
        'payload' => ['url' => $url],
        'is_active' => true,
        'last_refresh_status' => 'pending',
        'last_refreshed_at' => null,
    ]);

    // The fetch succeeds normally — only the final locked write is contended.
    $this->mock(EventbriteScraper::class, fn ($m) => $m->shouldReceive('fetchEvents')->once()->andReturn([
        'organiser' => 'Acme Org',
        'events' => [['name' => 'Gig', 'startDate' => '2099-01-01T00:00:00+00:00', 'link' => 'https://www.eventbrite.com/e/1']],
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

    app()->call([new ConnectFetchJob($connection->id, 'eventbrite'), 'handle']);

    $fresh = $connection->fresh();
    expect($fresh->last_refresh_status)->toBe('unavailable');
    expect($fresh->last_refresh_error)->not->toBeNull();
    // The message must not borrow the vendor-failure wording — this is our
    // own lock contention, not a vendor miss.
    expect($fresh->last_refresh_error)->not->toContain('Eventbrite page');
    expect($fresh->consecutive_failures)->toBe(1);
    // Payload untouched — the contended write never landed the fresh scrape.
    expect($fresh->payload)->toBe(['url' => $url]);
    Exceptions::assertReported(LockTimeoutException::class);
});

// ── Public render of a pending row (constraint 4) ───────────────────────────

it('a pending account row is publicly active and renders {url} only — never a null organiser or a null upcoming', function () {
    config(['partna.connect.deferred' => ['eventbrite']]);
    $user = eventsAsyncUser('pub1');
    $url = 'https://www.eventbrite.com/o/acme-public';

    $this->mock(EventbriteScraper::class, fn ($m) => $m->shouldReceive('normalizeOrgUrl')->once()->andReturn($url));
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/eventbrite/connect', ['url' => $url])->assertStatus(202);

    $res = $this->getJson("/api/public/profiles/{$user->handle}/platforms")->assertOk();

    $payload = $res->json('data.platforms.eventbrite.0.payload');
    expect($payload)->toBe(['url' => $url]);
    expect($payload)->not->toHaveKey('organiser');
    expect($payload)->not->toHaveKey('next');
    expect($payload)->not->toHaveKey('upcoming');
    expect($res->json('data.platforms.eventbrite.0.lastRefreshedAt'))->toBeNull();
});

// ── auto_sync_latest OFF — the events-form Bandcamp-304 trap ────────────────

it('auto_sync_latest OFF on an existing row: the deferred connect 304s and reports ready with the PRESERVED payload', function () {
    $user = eventsAsyncUser('nosync1');
    $url = 'https://www.eventbrite.com/o/acme-nosync';
    $id = eventsAsyncAcctId($url);

    $existingPayload = [
        'url' => $url,
        'organiser' => 'Acme Org',
        'next' => ['id' => 'ev1', 'name' => 'Gig', 'link' => 'https://www.eventbrite.com/e/1', 'startDate' => '2099-01-01T00:00:00+00:00'],
        'upcoming' => [['id' => 'ev1', 'name' => 'Gig', 'link' => 'https://www.eventbrite.com/e/1', 'startDate' => '2099-01-01T00:00:00+00:00']],
        'hiddenEventIds' => [],
    ];
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'eventbrite',
        'resource_id' => $id,
        'canonical_key' => strtolower(trim($url)),
        'payload' => $existingPayload,
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now()->subDay(),
        'display_settings' => ['auto_sync_latest' => false],
    ]);

    config(['partna.connect.deferred' => ['eventbrite']]);
    // fetchEvents must NEVER be called — the 304 short-circuit in EventbriteFetch
    // happens before the url/fetchEvents branch. No expectation set for it.
    $this->mock(EventbriteScraper::class, fn ($m) => $m->shouldReceive('normalizeOrgUrl')->once()->andReturn($url));
    Queue::fake();

    actingAsUser($user)->postJson('/api/platforms/eventbrite/connect', ['url' => $url])->assertStatus(202);

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'eventbrite')->where('resource_id', $id)->firstOrFail();
    expect($row->last_refresh_status)->toBe('pending');
    expect($row->payload)->toBe($existingPayload);

    app()->call([new ConnectFetchJob($row->id, 'eventbrite'), 'handle']);

    $fresh = $row->fresh();
    expect($fresh->last_refresh_status)->toBe('ok');

    actingAsUser($user)->getJson("/api/platforms/eventbrite/connect/status?account={$id}")
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('connection.organiser', 'Acme Org')
        ->assertJsonPath('connection.upcoming.0.name', 'Gig');
});
