<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Platforms\DeleteMirroredMediaJob;
use App\Jobs\Platforms\ReconcilePlatformTakedownJob;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Segments\UserSegmentMember;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\IdentitySync;
use App\Services\Segments\SegmentResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSegmentsTables();
    setupFeatureAvailabilityTable();
    DB::connection('pgsql')->statement('DELETE FROM core.user_segments');
});

function takedownUser(string $h): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

function skoolConn(User $u): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $u->id, 'platform' => 'skool', 'resource_id' => 'c-'.Str::random(5),
        'payload' => ['url' => 'https://www.skool.com/demo', 'name' => 'Demo'], 'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
}

/**
 * Insert a site.sites row directly (bypasses Eloquent) so the takedown job's
 * subdomain join has a row to find.
 */
function takedownSite(User $u, string $subdomain): void
{
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $u->id,
        'subdomain' => $subdomain,
    ]);
}

it('globally flips is_active=false without deleting data', function () {
    $a = skoolConn(takedownUser('tka'));
    $b = skoolConn(takedownUser('tkb'));

    (new ReconcilePlatformTakedownJob('skool'))->handle(app(SegmentResolver::class));

    $a->refresh();
    $b->refresh();
    expect($a->is_active)->toBeFalse()
        ->and($b->is_active)->toBeFalse()
        ->and($a->payload)->toBe(['url' => 'https://www.skool.com/demo', 'name' => 'Demo']) // data intact
        ->and($a->deleted_at)->toBeNull(); // not soft-deleted
});

it('leaves other platforms untouched', function () {
    $user = takedownUser('tkother');
    $ig = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'ig-1',
        'payload' => ['username' => 'x'], 'is_active' => true,
    ]);

    (new ReconcilePlatformTakedownJob('skool'))->handle(app(SegmentResolver::class));

    expect($ig->refresh()->is_active)->toBeTrue();
});

it('scopes a segment takedown to that segment members only', function () {
    $member = takedownUser('tkmember');
    $outsider = takedownUser('tkoutsider');
    $mConn = skoolConn($member);
    $oConn = skoolConn($outsider);

    $segment = UserSegment::query()->create(['name' => 'seg-'.Str::random(4), 'filters' => []]);
    UserSegmentMember::query()->create(['segment_id' => $segment->id, 'user_id' => $member->id]);

    (new ReconcilePlatformTakedownJob('skool', $segment->id))->handle(app(SegmentResolver::class));

    expect($mConn->refresh()->is_active)->toBeFalse()
        ->and($oConn->refresh()->is_active)->toBeTrue();
});

// R3-CACHE-1: the query-builder bulk update skips Eloquent's auto-bump — SQLite
// has no equivalent of Postgres' DINT-10 updated_at trigger, so this test is the
// STRICTER environment for this assertion (an inversion of the usual seam).
it('bumps updated_at explicitly on the bulk flip', function () {
    Queue::fake();
    $conn = skoolConn(takedownUser('tkupdated'));
    $before = $conn->updated_at;

    $this->travel(1)->minutes();
    (new ReconcilePlatformTakedownJob('skool'))->handle(app(SegmentResolver::class));
    $this->travelBack();

    expect($conn->refresh()->updated_at->gt($before))->toBeTrue();
});

it('dispatches exactly one bulk purge per distinct subdomain, on the cloudflare_bulk lane', function () {
    Queue::fake();

    // Site row inserted AFTER the connection: IntegrationConnectionObserver's
    // saved() fires synchronously on create() (wasRecentlyCreated), and would
    // dispatch its own real-time (non-bulk) purge if a site already existed —
    // a confound unrelated to what this test is proving.
    $userA = takedownUser('tkpa');
    skoolConn($userA);
    takedownSite($userA, 'subda');

    $userB = takedownUser('tkpb');
    skoolConn($userB);
    takedownSite($userB, 'subdb');

    (new ReconcilePlatformTakedownJob('skool'))->handle(app(SegmentResolver::class));

    Queue::assertPushed(CloudflareCachePurgeJob::class, 2);
    foreach (['subda', 'subdb'] as $subdomain) {
        Queue::assertPushed(CloudflareCachePurgeJob::class, function (CloudflareCachePurgeJob $job) use ($subdomain) {
            return $job->handle === $subdomain
                && $job->bulk === true
                && $job->queue === config('partna.queues.cloudflare_bulk');
        });
    }
});

it('dedupes several connections held by the same user to a single purge', function () {
    Queue::fake();

    // Site inserted after both connections — see the comment on the previous
    // test for why order matters here.
    $user = takedownUser('tkdup');
    skoolConn($user);
    skoolConn($user); // a second row, same user, same platform
    takedownSite($user, 'subdup');

    (new ReconcilePlatformTakedownJob('skool'))->handle(app(SegmentResolver::class));

    Queue::assertPushed(CloudflareCachePurgeJob::class, 1);
});

it('dispatches no purge and does not throw when the owner has no site row', function () {
    Queue::fake();

    skoolConn(takedownUser('tknosite'));

    (new ReconcilePlatformTakedownJob('skool'))->handle(app(SegmentResolver::class));

    Queue::assertNotPushed(CloudflareCachePurgeJob::class);
});

it('staggers dispatch delay across the run using a run-global index, capped', function () {
    Queue::fake();
    config([
        'partna.cache.bulk_purge_stagger_seconds' => 10,
        'partna.cache.bulk_purge_max_delay_seconds' => 15,
    ]);

    foreach (['s0', 's1', 's2', 's3'] as $h) {
        $user = takedownUser('tkstag'.$h);
        skoolConn($user);
        takedownSite($user, $h);
    }

    $start = now();
    (new ReconcilePlatformTakedownJob('skool'))->handle(app(SegmentResolver::class));

    $delays = Queue::pushed(CloudflareCachePurgeJob::class)
        ->map(fn (CloudflareCachePurgeJob $job) => $job->delay === null ? 0 : (int) round($start->diffInSeconds($job->delay)))
        ->sort()
        ->values()
        ->all();

    // min(index * 10, 15) across 4 distinct-subdomain dispatches: 0, 10, then
    // both the 3rd (20) and 4th (30) requested delays land on the 15s cap —
    // the deliberate tail-flood (harmless: cloudflare_bulk is lowest priority).
    expect($delays)->toBe([0, 10, 15, 15]);
});

// R3-CACHE-1: the enumeration guard. Pins that a takedown's bulk update reaches
// ONLY the cache purge — not the identity fold, the Instagram auto-sync flip, or
// the mirrored-media cleanup — so this test fails the day a future
// IntegrationConnectionObserver arm gets gated on is_active and the takedown
// ought to run it too. (Slice 7 unit E retired the two content-selection arms
// this guard also used to cover.) Seed rows via a raw insert (not
// IntegrationConnection::create()) so the seed itself doesn't fire the
// CREATE-time observer arms this test isn't about. Sites + payloads are seeded
// so each of the five arms below WOULD fire if the bulk update ever started
// triggering Eloquent events — an absent side effect is then a real guard, not
// a no-op by omission (e.g. a missing site would make GB seed/IG auto-enable
// trivially pass regardless of whether the arm ran).
it('bypasses every reachable observer side effect except the purge (enumeration guard)', function () {
    Queue::fake();

    $gbUser = takedownUser('tkgb');
    takedownSite($gbUser, 'tkgbsite');
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $gbUser->id,
        'surface_key' => 'google_business.listing',
        'routing_class' => 'content',
        'resource_id' => 'gb-1',
        'payload' => json_encode([
            'name' => "Jane's Salon",
            'category' => 'Hair Salon',
            'photos' => [['ref' => 'photo-1', 'url' => 'https://example.com/1.jpg']],
        ]),
        'is_active' => 1,
    ]);

    $igUser = takedownUser('tkig');
    takedownSite($igUser, 'tkigsite');
    $igConnectionId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => $igConnectionId,
        'user_id' => $igUser->id,
        'surface_key' => 'instagram.profile',
        'routing_class' => 'social',
        'resource_id' => 'ig-1',
        'payload' => json_encode([
            '_folder' => 'platforms/instagram/old',
            'images' => ['https://example.com/ig-1.jpg'],
        ]),
        // An EXPLICIT false is what enableContentInstagramAuto() would strip —
        // it surviving below is the observable proving that arm never ran.
        'display_settings' => json_encode(['auto_sync_latest' => false]),
        'is_active' => 1,
    ]);

    $this->mock(IdentitySync::class, function ($mock) {
        $mock->shouldNotReceive('applyFromGooglePayload');
    });

    (new ReconcilePlatformTakedownJob('google-business'))->handle(app(SegmentResolver::class));
    (new ReconcilePlatformTakedownJob('instagram'))->handle(app(SegmentResolver::class));

    Queue::assertNotPushed(DeleteMirroredMediaJob::class);

    // IG auto-sync flip never ran: the explicit false is still on the row
    // (enableContentInstagramAuto strips it whenever that arm fires).
    $igSettings = DB::connection('pgsql')->table('site.platform_connections')
        ->where('id', $igConnectionId)
        ->value('display_settings');
    expect(json_decode((string) $igSettings, true))->toBe(['auto_sync_latest' => false]);
});
