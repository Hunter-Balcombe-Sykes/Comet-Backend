<?php

// #TEST-4 — proves the ordering property the finding actually names: the
// deferred-connect controllers dispatch their completion job AFTER releasing
// the platform lock, not from inside the lock closure. Moving the dispatch
// back inside would self-deadlock under a sync queue, because the dispatched
// job takes the SAME platformConnectionLock key the controller just held
// (GenericPlatformController::connectDeferred / InstagramController::connect
// — see their own docblocks).
//
// These tests deliberately do NOT fake the queue. phpunit.xml pins
// QUEUE_CONNECTION=sync and CACHE_STORE=array, so the dispatched job runs
// INLINE, right here in the request, and contends for a REAL in-process
// ArrayLock on the same key (CacheKeyGenerator::platformConnectionLock) the
// controller just released. That contention is the mechanism under test.
// Queue::fake() ANYWHERE in this file makes every assertion in it vacuous:
// under a faked queue the job never runs and never contends for the lock, so
// DeferredConnectTest.php's existing Queue::assertPushed() checks pass
// byte-identically whether the dispatch sits inside or outside the lock
// closure — see that file's :128/:315 and the cross-reference comment added
// there. This file exists specifically because that assertion cannot catch
// the bug the finding names.

use App\Jobs\Platforms\ConnectFetchJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\OEmbedService;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

function ddlUser(string $h): User
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

it('releases the platform lock before dispatching ConnectFetchJob, so the inline job can take the same lock', function () {
    config(['partna.connect.deferred' => ['spotify']]);
    $user = ddlUser('ddlgeneric1');
    $key = CacheKeyGenerator::platformConnectionLock('spotify', (string) $user->id);

    Notification::fake();
    Http::fake();
    $this->mock(OEmbedService::class, fn ($m) => $m->shouldReceive('resolve')->andReturn([
        'name' => 'Artist', 'thumbnail' => 'https://i.scdn.co/t.jpg', 'embedUrl' => null,
    ]));

    // The probe: fires when SyncQueue::executeJob() raises JobProcessing for
    // the inline job. If the controller's lock is genuinely released by then,
    // this probe can itself acquire it. Initialised to null (not false) so
    // "the probe never ran at all" is distinguishable from "the lock was
    // held" — both would otherwise read as a failure the same way.
    $lockFreeAtDispatch = null;
    Queue::before(function (JobProcessing $event) use ($key, &$lockFreeAtDispatch) {
        $probe = Cache::lock($key, 5);
        $lockFreeAtDispatch = $probe->get();
        // MANDATORY: a held probe lock would itself deadlock the job's own
        // locked write, which is exactly what this test then asserts on.
        if ($lockFreeAtDispatch) {
            $probe->release();
        }
    });

    actingAsUser($user)->postJson('/api/platforms/spotify/connect', ['url' => 'https://open.spotify.com/artist/abc123'])
        ->assertStatus(202);

    expect($lockFreeAtDispatch)->not->toBeNull();
    expect($lockFreeAtDispatch)->toBeTrue();

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'spotify')->first();
    expect($row->last_refresh_status)->toBe('ok');
    expect($row->last_refresh_error)->toBeNull();
    expect($row->payload['name'])->toBe('Artist');
});

it('releases the instagram lock before dispatching InstagramConnectJob, so the seeder can take the same lock', function () {
    config(['services.apify.token' => 'test-token']);
    $user = ddlUser('ddlinstagram1');
    $key = CacheKeyGenerator::platformConnectionLock('instagram', (string) $user->id);

    Storage::fake('media');
    Http::fake(['scontent.cdninstagram.com/*' => Http::response('img-bytes', 200, ['Content-Type' => 'image/jpeg'])]);
    Notification::fake();

    // InstagramConnectionSeeder resolves its own scraper from the container —
    // a bare Mockery::mock() passed only to a job's handle() call would not
    // reach it (InstagramAsyncConnectTest.php's own note on this).
    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->andReturn(['fullName' => 'Test User', 'followersCount' => 100, 'postsCount' => 10]);
    $scraper->shouldReceive('latestMedia')->andReturn([
        'photo' => ['thumbnailUrl' => 'https://scontent.cdninstagram.com/img1.jpg', 'shortCode' => 'abc'],
        'video' => null,
    ]);
    $scraper->shouldReceive('profilePicUrl')->andReturn('https://scontent.cdninstagram.com/pic.jpg');
    $scraper->shouldReceive('bioLinks')->andReturn([]);
    app()->instance(InstagramScraper::class, $scraper);

    $lockFreeAtDispatch = null;
    Queue::before(function (JobProcessing $event) use ($key, &$lockFreeAtDispatch) {
        $probe = Cache::lock($key, 5);
        $lockFreeAtDispatch = $probe->get();
        if ($lockFreeAtDispatch) {
            $probe->release();
        }
    });

    actingAsUser($user)->postJson('/api/platforms/instagram/connect', ['username' => 'testuser'])
        ->assertStatus(202);

    expect($lockFreeAtDispatch)->not->toBeNull();
    expect($lockFreeAtDispatch)->toBeTrue();

    $row = IntegrationConnection::where('user_id', $user->id)->where('platform', 'instagram')->first();
    expect($row->last_refresh_status)->toBe('ok');
    expect($row->last_refresh_error)->toBeNull();
    expect($row->is_active)->toBeTruthy();
    expect($row->payload['username'])->toBe('testuser');
});

it('423s a deferred spotify connect while the platform lock is held, writing no row and queueing nothing', function () {
    // Correct here to fake the queue — this test is about the SHORT-CIRCUIT
    // when withConnectionLock itself times out; nothing should ever reach
    // the queue on this path, and Queue::assertNotPushed is exactly what
    // pins that.
    config(['partna.connect.deferred' => ['spotify']]);
    Queue::fake();
    Http::fake();
    $user = ddlUser('ddlgeneric423');

    // Blocks ~5s by design (withConnectionLock's block(5)) — slow on purpose,
    // proving the contention is real, not mocked.
    $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('spotify', (string) $user->id), 10);
    expect($lock->get())->toBeTrue();

    try {
        actingAsUser($user)->postJson('/api/platforms/spotify/connect', ['url' => 'https://open.spotify.com/artist/abc123'])
            ->assertStatus(423);
    } finally {
        $lock->release();
    }

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'spotify')->exists())->toBeFalse();
    Queue::assertNotPushed(ConnectFetchJob::class);
});
