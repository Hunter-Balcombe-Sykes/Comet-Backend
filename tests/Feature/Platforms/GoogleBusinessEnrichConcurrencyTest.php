<?php

// Concurrency + idempotency coverage for GoogleBusinessEnrichJob (LIFE-10 lock +
// re-read-and-merge, JOB-2 paid-scrape idempotency, OBS-6 soft-failure logging).
//
// CACHE_STORE=array in phpunit.xml, so Cache::lock() here is a REAL ArrayLock — lock
// acquisition, contention, and (most importantly) KEY IDENTITY across the two write
// paths (GoogleBusinessEnrichJob + ScheduledRefresh) are genuinely proven by these
// tests, not mocked. What is NOT proven: true cross-process contention (the array
// store is one PHP process) or Redis lock semantics (prod uses the redis store) —
// those remain an inference from the shared Cache::lock() API, not a direct test.
//
// The three ~5s tests (lock-honoured / ScheduledRefresh-shares-the-key / failed()
// does-not-release-on-terminal-lock-timeout) block on a real
// Cache::lock()->block(5, ...) held by a pre-acquired lock in the SAME process.
// That wall-clock cost is the point — it proves the lock actually blocks rather
// than silently no-op'ing. Don't "optimize" it into a faked/instant wait.

use App\Jobs\Platforms\GoogleBusinessEnrichJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\GoogleBusinessApifyScraper;
use App\Services\Platforms\GoogleBusinessAutoSync;
use App\Services\Platforms\Strategies\Contracts\FetchStrategy;
use App\Services\Platforms\Strategies\Refresh\ScheduledRefresh;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// ── Helpers ──────────────────────────────────────────────────────────────────
// HELPER COLLISION WARNING: emptyHarvester()/gbApifyUser()/gbApifyConnection() are
// global functions already declared in GoogleBusinessApifyTest.php. Pest loads every
// test file into ONE process, so redeclaring any of them is a fatal — every helper
// here is uniquely prefixed gbEnrich*.

/** Harvester stub returning nothing, so every test below forces needsApify()=true. */
function gbEnrichHarvester(): WebsiteLinkHarvester
{
    return new class(app(SafeUrlFetcher::class)) extends WebsiteLinkHarvester
    {
        public function harvest(?string $websiteUrl): array
        {
            return [];
        }
    };
}

function gbEnrichUser(string $h, string $accountType = 'business'): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => $accountType,
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

/** Seed the post-connect row: Place Details already merged, Apify pending. */
function gbEnrichConnection(User $user, string $placeId = 'ChIJtest'): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'payload' => [
            'url' => 'https://maps.google.com/?cid=1',
            'placeId' => $placeId,
            'name' => 'Fade Lab Barbers',
            'rating' => 4.8,
        ],
        'place_id' => $placeId,
        'apify_status' => 'pending',
        'last_refreshed_at' => now(),
    ]);
}

/** Counting scraper stub — always returns a fixed non-null enrichment. */
function gbEnrichCountingScraper(array $enrichment = ['socials' => ['facebook' => 'https://facebook.com/count-test']]): GoogleBusinessApifyScraper
{
    return new class($enrichment) extends GoogleBusinessApifyScraper
    {
        public int $calls = 0;

        public function __construct(private readonly array $enrichment) {}

        public function fetch(string $placeId, ?string $userId = null): ?array
        {
            $this->calls++;

            return $this->enrichment;
        }
    };
}

/** Always-null scraper stub — simulates a genuine "Apify ran, found nothing". */
function gbEnrichNullScraper(): GoogleBusinessApifyScraper
{
    return new class extends GoogleBusinessApifyScraper
    {
        public function fetch(string $placeId, ?string $userId = null): ?array
        {
            return null;
        }
    };
}

/**
 * Scraper stub whose fetch() side-effects a CONCURRENT write to the same row
 * (simulating a dashboard save landing while the paid Apify call is in flight)
 * before returning a normal enrichment. withoutEvents() keeps that side-write
 * from firing observer side-effects that would muddy the assertions.
 */
function gbEnrichConcurrentWriterScraper(array $enrichment): GoogleBusinessApifyScraper
{
    return new class($enrichment) extends GoogleBusinessApifyScraper
    {
        public function __construct(private readonly array $enrichment) {}

        public function fetch(string $placeId, ?string $userId = null): ?array
        {
            IntegrationConnection::withoutEvents(function () use ($placeId) {
                $original = IntegrationConnection::query()->where('place_id', $placeId)->firstOrFail()->payload;
                IntegrationConnection::query()->where('place_id', $placeId)->update([
                    'payload' => json_encode([...$original, 'phone' => '+61400000000']),
                ]);
            });

            return $this->enrichment;
        }
    };
}

/** Scraper stub whose fetch() side-effects a reconnect to a DIFFERENT place mid-scrape. */
function gbEnrichReconnectScraper(array $enrichment): GoogleBusinessApifyScraper
{
    return new class($enrichment) extends GoogleBusinessApifyScraper
    {
        public function __construct(private readonly array $enrichment) {}

        public function fetch(string $placeId, ?string $userId = null): ?array
        {
            IntegrationConnection::withoutEvents(function () use ($placeId) {
                IntegrationConnection::query()->where('place_id', $placeId)->update(['place_id' => 'ChIJother']);
            });

            return $this->enrichment;
        }
    };
}

/**
 * GoogleBusinessAutoSync double whose seed() throws on its FIRST invocation and
 * delegates to a REAL instance thereafter — composition (not inheritance of the
 * real constructor) so no DI wiring is duplicated here. Never calls
 * parent::__construct(), which is fine: seed() is fully overridden below and
 * never touches the parent's (otherwise-uninitialized) readonly properties.
 */
function gbEnrichFlakySeedAutoSync(): GoogleBusinessAutoSync
{
    return new class(app(GoogleBusinessAutoSync::class)) extends GoogleBusinessAutoSync
    {
        public int $calls = 0;

        public function __construct(private readonly GoogleBusinessAutoSync $real) {}

        public function seed(string $userId, array $enrichment, ?string $businessName, ?array $gbPayload = null, bool $autoConnectBooking = false, bool $instagramDirectDispatch = false): array
        {
            $this->calls++;
            if ($this->calls === 1) {
                throw new RuntimeException('seed boom');
            }

            return $this->real->seed($userId, $enrichment, $businessName, $gbPayload, $autoConnectBooking, $instagramDirectDispatch);
        }
    };
}

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();   // A.13: the unclaimed enrich routes intents now
});

// ── LIFE-10: lock + re-read-and-merge ───────────────────────────────────────

it('does not clobber a concurrent write landing mid-scrape (LIFE-10)', function () {
    // Why this is not vacuous: today the final write rebuilds the payload from
    // the connection instance read at the TOP of handle() (before the multi-
    // second harvest/Apify work runs). A concurrent write landing in that
    // window is silently overwritten (last-write-wins). Re-reading INSIDE the
    // lock right before the write means the concurrent write survives.
    config(['services.apify.token' => 'apify-token']);
    $user = gbEnrichUser('gbenrich1');
    $conn = gbEnrichConnection($user);

    $scraper = gbEnrichConcurrentWriterScraper(['socials' => ['facebook' => 'https://facebook.com/fadelab']]);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle($scraper, app(GoogleBusinessAutoSync::class), gbEnrichHarvester());

    $fresh = $conn->fresh();
    expect($fresh->payload['phone'])->toBe('+61400000000');    // the concurrent write survived
    expect($fresh->payload['name'])->toBe('Fade Lab Barbers'); // this job's own write also landed
    expect($fresh->apify_status)->toBe('ok');
});

it('abandons the write when the connected place changed mid-scrape (LIFE-10 CAS)', function () {
    // Why this is not vacuous: today saveQuietly() writes by primary key
    // regardless of place_id, so a reconnect to a DIFFERENT place mid-scrape
    // gets silently clobbered with data describing the OLD place. The re-read
    // inside persist() re-applies the place_id predicate as an implicit
    // compare-and-swap — a mismatch must abandon, never write.
    config(['services.apify.token' => 'apify-token']);
    Log::spy();
    $user = gbEnrichUser('gbenrich2');
    $conn = gbEnrichConnection($user);

    $scraper = gbEnrichReconnectScraper(['socials' => ['facebook' => 'https://facebook.com/fadelab']]);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle($scraper, app(GoogleBusinessAutoSync::class), gbEnrichHarvester());

    $fresh = $conn->fresh();
    expect($fresh->apify_status)->toBe('pending');   // untouched — the write was abandoned
    expect($fresh->place_id)->toBe('ChIJother');      // the reconnect's write stands

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $m, array $c) => $m === 'google_business.enrich_job.stale_connection'
            && ($c['place_id'] ?? null) === 'ChIJtest');
});

// ── JOB-2: paid-scrape idempotency ──────────────────────────────────────────

it('a retry after a mid-flow exception does not re-bill Apify (JOB-2)', function () {
    // Why this is not vacuous: without the result cache, ANY retry (rate-limit
    // release, exception backoff) re-runs scraper->fetch() from scratch — a
    // second Apify charge for work that already happened. The second half of
    // this assertion (non-empty syncFindings) blocks a degenerate "fix" that
    // just skips re-running seed() on retry instead of genuinely reusing the
    // cached Apify result.
    $user = gbEnrichUser('gbenrich3');
    gbEnrichConnection($user);

    $scraper = gbEnrichCountingScraper(['socials' => ['facebook' => 'https://facebook.com/gbenrich3']]);
    $flakyAutoSync = gbEnrichFlakySeedAutoSync();
    $job = new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest');

    expect(fn () => $job->handle($scraper, $flakyAutoSync, gbEnrichHarvester()))
        ->toThrow(RuntimeException::class, 'seed boom');

    $job->handle($scraper, $flakyAutoSync, gbEnrichHarvester());

    expect($scraper->calls)->toBe(1); // NOT re-billed on the retry

    // 2026-09-06: a Google Business social link now proposes rather than
    // connects, so seed() returns no synced-modal finding for it any more —
    // syncFindings is no longer the right proxy for "seed() actually ran".
    // The proposed intent is: seed() reached the routing pipeline and wrote
    // its decision, which is exactly what this retry-idempotency test needs
    // to prove happened (not skipped, not silently swallowed).
    expect(DB::table('routing.source_intents')
        ->where('user_id', $user->id)
        ->where('surface_key', 'facebook.profile')
        ->exists())->toBeTrue();
});

it('an orphaned inflight marker (no cached result) refuses to re-bill Apify (JOB-2)', function () {
    // Why this is not vacuous: today there is no inflight marker at all, so a
    // job that died DURING the paid call (worker kill/timeout) leaves no trace
    // — a retry sees a "clean" state and calls fetch() unconditionally,
    // re-billing for a run whose data is already unrecoverable.
    $user = gbEnrichUser('gbenrich4');
    gbEnrichConnection($user);
    Log::spy();

    // Simulate a prior attempt that set the marker but died before caching a result.
    Cache::put(
        CacheKeyGenerator::googleBusinessApifyInflight((string) $user->id, 'ChIJtest'),
        now()->subMinutes(3)->toIso8601String(),
        900,
    );

    $scraper = gbEnrichCountingScraper();

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle($scraper, app(GoogleBusinessAutoSync::class), gbEnrichHarvester());

    expect($scraper->calls)->toBe(0); // refused to call — no re-bill

    $conn = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'google-business')->firstOrFail();
    expect($conn->apify_status)->toBe('unavailable');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $m, array $c) => $m === 'google_business.enrich_job.apify_orphaned_run'
            && ($c['place_id'] ?? null) === 'ChIJtest');
});

// ── OBS-6: silent soft-failure made observable ──────────────────────────────

it('logs the discriminating context when Apify runs and returns nothing (OBS-6)', function () {
    // Why this is not vacuous: today this exact branch (empty harvest AND a
    // null Apify result) emits ZERO log calls — a silent soft-failure. Using
    // Log::spy() (not shouldReceive) so unrelated Log:: calls elsewhere in
    // handle() don't fail this mock.
    config(['services.apify.token' => 'apify-token']);
    $user = gbEnrichUser('gbenrich5');
    gbEnrichConnection($user);
    Log::spy();

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(gbEnrichNullScraper(), app(GoogleBusinessAutoSync::class), gbEnrichHarvester());

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $m, array $c) => $m === 'google_business.enrich_job.enrichment_unavailable'
            && $c['apify_attempted'] === true
            && $c['apify_returned_null'] === true
            && $c['place_id'] === 'ChIJtest');
});

// ── LIFE-10: lock actually blocks (real wall-clock contention) ─────────────

it('refuses to write when the connection lock is held by someone else (LIFE-10)', function () {
    // ~5s wall time: Cache::lock()->block(5, ...) genuinely blocks against the
    // pre-acquired lock below before throwing LockTimeoutException. This IS
    // the proof the lock does something — don't shortcut it.
    config(['services.apify.token' => null]); // fetch() short-circuits to null, no HTTP needed
    Exceptions::fake();
    Log::spy();
    $user = gbEnrichUser('gbenrich6');
    $conn = gbEnrichConnection($user);

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('google-business', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
            ->handle(app(GoogleBusinessApifyScraper::class), app(GoogleBusinessAutoSync::class), gbEnrichHarvester());
    } finally {
        $lock->release();
    }

    expect($conn->fresh()->apify_status)->toBe('pending'); // never written — lock timed out

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $m, array $c) => $m === 'google_business.enrich_job.lock_timeout'
            && ($c['place_id'] ?? null) === 'ChIJtest');
    Exceptions::assertReported(LockTimeoutException::class);
});

// ── LIFE-10: the two write paths share one key ──────────────────────────────

it('ScheduledRefresh takes the SAME connection lock key as the enrich job (LIFE-10)', function () {
    // ~5s wall time (same reasoning as above). This is the only test proving
    // the two write paths (GoogleBusinessEnrichJob + ScheduledRefresh) compute
    // the IDENTICAL key string — without it, a typo in either suffix rule
    // would ship a lock that serializes against nothing and this whole fix
    // would be silently inert.
    Log::spy();
    $user = gbEnrichUser('gbenrich7');
    $conn = gbEnrichConnection($user);

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('google-business', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    $fetch = new class implements FetchStrategy
    {
        public function fetch(IntegrationConnection $connection): array
        {
            return [...$connection->payload, 'name' => 'Changed By Refresh'];
        }
    };

    try {
        (new ScheduledRefresh($fetch))->run($conn);
    } finally {
        $lock->release();
    }

    expect($conn->fresh()->payload['name'])->toBe('Fade Lab Barbers'); // unchanged — lock timed out

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $m, array $c) => $m === 'platform.scheduled_refresh.lock_timeout'
            && ($c['user_id'] ?? null) === (string) $user->id);
});

// ── Regression: failed() must not resurrect a terminally-failed job ────────

it('does not release the job when failed()\'s terminal mark() write times out on the lock', function () {
    // Reproduces the defect this test guards against: failed() clears the JOB-2
    // cache markers, then calls mark() -> persist(), which can hit
    // LockTimeoutException (another writer — e.g. a concurrent ScheduledRefresh —
    // holds platforms:google-business:lock:{userId}). By the time our failed()
    // callback runs, Laravel has ALREADY called Job::fail() on the driver job
    // (deleted=true, failed=true — see vendor/laravel/framework/.../Jobs/Job.php
    // fail()). InteractsWithQueue::release() checks NEITHER flag: it forwards
    // unconditionally to the driver, which would RESURRECT this already-
    // terminally-failed job with clean (just-cleared) cache markers — causing a
    // second paid Apify charge on the resurrected run. withFakeQueueInteractions()
    // binds a real Illuminate\Queue\Jobs\FakeJob via setJob() under the hood,
    // mirroring how CallQueuedHandler::failed() binds the real driver job before
    // invoking this callback — so a release() call is observable without a real
    // queue driver. ~5s wall time: the same genuine Cache::lock()->block(5, ...)
    // contention as the sibling lock-timeout tests above.
    config(['services.apify.token' => null]);
    Exceptions::fake();
    Log::spy();
    $user = gbEnrichUser('gbenrich8');
    gbEnrichConnection($user);

    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('google-business', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    $job = (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->withFakeQueueInteractions();

    try {
        $job->failed(new RuntimeException('boom'));
    } finally {
        $lock->release();
    }

    $job->assertNotReleased(); // the regression: pre-fix, release(30) fires here

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $m, array $c) => $m === 'google_business.enrich_job.lock_timeout'
            && ($c['place_id'] ?? null) === 'ChIJtest'
            && ($c['stage'] ?? null) === 'mark:unavailable');
    Exceptions::assertReported(LockTimeoutException::class);
});

// ── PRIV-2: pre-claim data minimisation ─────────────────────────────────────

it('does not persist syncFindings for an unclaimed pre-account owner (PRIV-2)', function () {
    // GoogleBusinessSourceGenerator dispatches this job for a provisional build,
    // and the persist write below records syncFindings unconditionally — internal
    // bookkeeping (including apply.write.payload, the prospect's own links) held
    // against someone who has agreed to nothing with us. Instagram already strips
    // the same key pre-claim; Google never did. Not a leak — the key is served
    // only by GoogleBusinessController behind dashboard auth and is absent from
    // PublicIntegrationConnectionResource — but it is data we do not need.
    $user = gbEnrichUser('gbenrich10');
    $user->status = 'unclaimed';
    $user->auth_user_id = null;   // a provisional row has no auth user and no email
    $user->primary_email = null;
    $user->save();
    gbEnrichConnection($user);

    $scraper = gbEnrichCountingScraper(['socials' => ['facebook' => 'https://facebook.com/gbenrich10']]);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle($scraper, app(GoogleBusinessAutoSync::class), gbEnrichHarvester());

    $conn = IntegrationConnection::query()
        ->where('user_id', $user->id)->where('platform', 'google-business')->firstOrFail();

    expect(array_key_exists('syncFindings', $conn->payload))->toBeFalse();
    // This skips ONE key, not the write and not the scan: the rest of the enrich
    // result still lands, and the seeded connection — the finding's real output,
    // and what actually renders — is still persisted.
    expect($conn->apify_status)->toBe('ok');
    expect($conn->payload)->toHaveKey('apifyFetchedAt');
    expect($scraper->calls)->toBe(1);
    // A.13 (2026-09-03): an unclaimed signup owner's listing links no longer
    // seed connections — they route as banded intents for the setup dialog.
    // The enrich still produced its output; it just lands one lane over.
    expect(IntegrationConnection::query()
        ->where('user_id', $user->id)
        ->where('platform', '!=', 'google-business')
        ->exists())->toBeFalse();
    expect(DB::table('routing.source_intents')
        ->where('user_id', $user->id)
        ->where('surface_key', 'facebook.profile')
        ->exists())->toBeTrue();
});

it('still persists syncFindings for a claimed owner (PRIV-2 mirror)', function () {
    // Identical setup, status='active' the only difference — so this doubles as
    // the control proving the guard above is not passing vacuously on a run that
    // produced no findings. A guard widened to skip everyone deletes the
    // dashboard's found-platforms list and its Change-to action for real users.
    $user = gbEnrichUser('gbenrich11');
    // ->fresh(): User::create() leaves status null on the in-memory instance —
    // the 'active' default is applied by the column, not the model. (This pins
    // the fixture's status only; the guard's live-read behaviour is covered by
    // the mid-scrape claim test below, which is where it can actually be seen.)
    expect($user->fresh()->status)->toBe('active');
    gbEnrichConnection($user);

    $scraper = gbEnrichCountingScraper(['socials' => ['facebook' => 'https://facebook.com/gbenrich11']]);

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle($scraper, app(GoogleBusinessAutoSync::class), gbEnrichHarvester());

    IntegrationConnection::query()
        ->where('user_id', $user->id)->where('platform', 'google-business')->firstOrFail();

    // 2026-09-06: a Google Business social link proposes rather than
    // connects, so syncFindings is no longer the right proxy here — the
    // proposed intent proves the same thing (seed() actually ran and is not
    // vacuously passing on a run that found nothing).
    expect(DB::table('routing.source_intents')
        ->where('user_id', $user->id)
        ->where('surface_key', 'facebook.profile')
        ->exists())->toBeTrue();
});

it('clears a syncFindings already stored against an unclaimed owner (PRIV-2)', function () {
    // The rows already in the wild were enriched before this guard existed, and
    // $businessInfo is rebuilt from the STORED payload — which carries the stale
    // key straight back through. Omitting the new write is therefore not enough:
    // the guard has to remove it, so an affected row self-cleans on its next
    // enrich instead of holding the findings until the owner claims or expires.
    $user = gbEnrichUser('gbenrich12');
    $user->status = 'unclaimed';
    $user->auth_user_id = null;
    $user->primary_email = null;
    $user->save();

    $conn = gbEnrichConnection($user);
    $conn->forceFill(['payload' => [
        ...$conn->payload,
        'syncFindings' => [[
            'platform' => 'fresha', 'resourceId' => 'main', 'category' => 'booking',
            'label' => 'Fresha', 'foundUrl' => 'https://www.fresha.com/a/stale',
            'outcome' => 'conflict', 'apply' => ['remove' => ['fresha'], 'write' => []],
        ]],
    ]])->saveQuietly();

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle(
            gbEnrichCountingScraper(['socials' => ['facebook' => 'https://facebook.com/gbenrich12']]),
            app(GoogleBusinessAutoSync::class),
            gbEnrichHarvester(),
        );

    expect(array_key_exists('syncFindings', $conn->fresh()->payload))->toBeFalse();
});

it('reads owner status at WRITE time, so a claim mid-scrape keeps the findings (PRIV-2)', function () {
    // The guard reads status AFTER the scrape, not once at the top of handle().
    // That distinction is invisible in every other test here because the fixture
    // never changes state mid-run. Hoisting the read above the multi-second scrape
    // would be an easy "optimisation" (one fewer SELECT) that silently discards the
    // findings of anyone who claims while their build is still enriching; this fails
    // if so. It does NOT pin the read's position relative to the lock — moving it to
    // just before persist() keeps this green.
    $user = gbEnrichUser('gbenrich13');
    $user->status = 'unclaimed';
    $user->auth_user_id = null;
    $user->primary_email = null;
    $user->save();
    gbEnrichConnection($user);

    // Claims the account DURING the paid call, exactly as ClaimSiteService would,
    // while the $user instance above still reads 'unclaimed'. (withoutEvents mirrors
    // the sibling stubs above for consistency; a builder update fires no model events
    // either way.)
    $scraper = new class((string) $user->id) extends GoogleBusinessApifyScraper
    {
        public function __construct(private readonly string $userId) {}

        public function fetch(string $placeId, ?string $userId = null): ?array
        {
            User::withoutEvents(function () {
                User::query()->where('id', $this->userId)->update(['status' => 'active']);
            });

            return ['socials' => ['facebook' => 'https://facebook.com/gbenrich13']];
        }
    };

    (new GoogleBusinessEnrichJob((string) $user->id, 'ChIJtest'))
        ->handle($scraper, app(GoogleBusinessAutoSync::class), gbEnrichHarvester());

    IntegrationConnection::query()
        ->where('user_id', $user->id)->where('platform', 'google-business')->firstOrFail();

    expect($user->status)->toBe('unclaimed');             // the stale in-memory view
    expect($user->fresh()->status)->toBe('active');       // the live row the guard must see
    // 2026-09-06: same swap as the sibling PRIV-2 tests above — a proposed
    // intent proves seed() ran, since a social link no longer produces a
    // synced-modal finding.
    expect(DB::table('routing.source_intents')
        ->where('user_id', $user->id)
        ->where('surface_key', 'facebook.profile')
        ->exists())->toBeTrue();
});
