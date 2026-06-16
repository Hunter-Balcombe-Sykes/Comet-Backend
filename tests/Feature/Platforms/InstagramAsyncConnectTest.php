<?php

use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramScraper;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function igAsyncUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'individual',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

// ── connect() → 202 + dispatches job ─────────────────────────────────────────

it('connect() returns 202 with a poll URL and dispatches InstagramConnectJob', function () {
    Queue::fake();
    config(['services.apify.token' => 'test-token']);

    $user = igAsyncUser('igasync1');

    actingAsUser($user)
        ->postJson('/api/platforms/instagram/connect', ['username' => 'testuser'])
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        ->assertJsonStructure(['statusUrl']);

    Queue::assertPushed(InstagramConnectJob::class, function ($job) use ($user) {
        return $job->userId === $user->id
            && $job->username === 'testuser';
    });

    // A pending placeholder row must exist before the job runs.
    $conn = IntegrationConnection::where('user_id', $user->id)->where('platform', 'instagram')->first();
    expect($conn)->not->toBeNull();
    expect($conn->last_refresh_status)->toBe('pending');
    expect($conn->is_active)->toBeFalsy();
    // Regression (SQLSTATE 23502): the placeholder payload must be a non-null
    // empty array. platform_connections.payload is NOT NULL in Postgres, so a
    // null here 500s the connect on the real DB — the test DB's nullable payload
    // column (tests/Pest.php) hid this until it shipped to dev-api.
    expect($conn->payload)->toBe([]);
});

// ── no per-user cooldown: rapid re-connect is allowed ────────────────────────

it('allows a rapid second connect (no per-user cooldown)', function () {
    Queue::fake();
    config(['services.apify.token' => 'test-token']);

    $user = igAsyncUser('igasync2');

    // First connect: succeeds, queues one job.
    actingAsUser($user)
        ->postJson('/api/platforms/instagram/connect', ['username' => 'testuser'])
        ->assertStatus(202);

    // Second connect immediately after: also succeeds — there is no per-user
    // cooldown, so re-connecting / switching accounts is friction-free.
    actingAsUser($user)
        ->postJson('/api/platforms/instagram/connect', ['username' => 'othername'])
        ->assertStatus(202);

    // Both connects queued a job.
    Queue::assertPushed(InstagramConnectJob::class, 2);
});

// ── global daily cap still 429s (the one remaining cost guard) ────────────────

it('429s once the global daily Apify cap is exceeded', function () {
    Queue::fake();
    config([
        'services.apify.token' => 'test-token',
        'partna.limits.platforms.instagram.apify_daily_cap' => 1,
    ]);

    // First connect consumes the single daily slot.
    actingAsUser(igAsyncUser('igcap1'))
        ->postJson('/api/platforms/instagram/connect', ['username' => 'testuser'])
        ->assertStatus(202);

    // A different user's connect tips over the global cap → 429, no dispatch.
    actingAsUser(igAsyncUser('igcap2'))
        ->postJson('/api/platforms/instagram/connect', ['username' => 'testuser'])
        ->assertStatus(429);

    Queue::assertPushed(InstagramConnectJob::class, 1);
});

// ── job mirrors images via Http::pool and writes payload ─────────────────────

it('InstagramConnectJob mirrors images and writes the connection payload', function () {
    Storage::fake('media');

    // Image URLs that pass the CDN host allowlist.
    $imgUrl1 = 'https://scontent.cdninstagram.com/img1.jpg';
    $imgUrl2 = 'https://scontent.cdninstagram.com/img2.jpg';
    $picUrl = 'https://scontent.cdninstagram.com/pic.jpg';

    Http::fake([
        // Apify is not called by the job directly — it goes through InstagramScraper
        // which we mock below. Http::fake here covers the image CDN calls.
        'scontent.cdninstagram.com/*' => Http::response('img-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $user = igAsyncUser('igasync3');

    // Create the pending connection row (normally done by connect()).
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => null,
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')
        ->once()
        ->andReturn(['fullName' => 'Test User', 'followersCount' => 100, 'postsCount' => 10]);
    $scraper->shouldReceive('latestMedia')
        ->once()
        ->andReturn(['photo' => ['thumbnailUrl' => $imgUrl1, 'shortCode' => 'abc'], 'video' => null]);
    $scraper->shouldReceive('profilePicUrl')
        ->once()
        ->andReturn($picUrl);

    $job = new InstagramConnectJob($user->id, 'testuser', $connection->id);
    $job->handle($scraper);

    $connection->refresh();

    expect($connection->last_refresh_status)->toBe('ok');
    expect($connection->is_active)->toBeTruthy();
    expect($connection->payload['mode'])->toBe('automatic');
    expect($connection->payload['username'])->toBe('testuser');
    expect($connection->payload['images'])->toBeArray();
    // Latest photo mirrored, no reel.
    expect(count($connection->payload['images']))->toBe(1);
    expect($connection->payload['videoUrl'])->toBeNull();
    expect($connection->payload['videoPoster'])->toBeNull();
});

it('InstagramConnectJob mirrors both the latest photo and the latest reel (mp4 + poster)', function () {
    Storage::fake('media');

    $photoUrl = 'https://scontent.cdninstagram.com/photo.jpg';
    $coverUrl = 'https://scontent.cdninstagram.com/cover.jpg';
    $videoUrl = 'https://scontent.cdninstagram.com/reel.mp4';

    Http::fake([
        // reel.mp4 first (specific) so the broad image rule below doesn't claim it.
        'scontent.cdninstagram.com/reel.mp4' => Http::response('video-bytes', 200, ['Content-Type' => 'video/mp4']),
        'scontent.cdninstagram.com/*' => Http::response('img-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $user = igAsyncUser('igreel1');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => [],
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->once()->andReturn(['fullName' => 'Reel User']);
    $scraper->shouldReceive('latestMedia')->once()->andReturn([
        'photo' => ['thumbnailUrl' => $photoUrl, 'shortCode' => 'pic'],
        'video' => ['thumbnailUrl' => $coverUrl, 'videoUrl' => $videoUrl, 'shortCode' => 'reel'],
    ]);
    $scraper->shouldReceive('profilePicUrl')->once()->andReturn(null);

    (new InstagramConnectJob($user->id, 'testuser', $connection->id))->handle($scraper);

    $connection->refresh();

    expect($connection->last_refresh_status)->toBe('ok');
    // Photo mirrored into images[0]; reel mp4 + its poster mirrored to R2.
    expect(count($connection->payload['images']))->toBe(1);
    expect($connection->payload['videoUrl'])->not->toBeNull();
    expect($connection->payload['videoPoster'])->not->toBeNull();
});

// ── job drops images whose CDN URL responds with a redirect (SSRF guard) ──────

it('InstagramConnectJob drops a CDN image that responds with a redirect and never fetches the target', function () {
    Storage::fake('media');

    $okUrl = 'https://scontent.cdninstagram.com/ok.jpg';
    $redirectUrl = 'https://scontent.cdninstagram.com/redirect.jpg';

    Http::fake([
        'scontent.cdninstagram.com/ok.jpg' => Http::response('img-bytes', 200, ['Content-Type' => 'image/jpeg']),
        // An allow-listed CDN URL that 30x-redirects must be dropped, not followed
        // to its (here internal cloud-metadata) target.
        'scontent.cdninstagram.com/redirect.jpg' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        '169.254.169.254/*' => Http::response('SECRET', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $user = igAsyncUser('igasync9');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => null,
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->once()->andReturn(['fullName' => 'Test User']);
    $scraper->shouldReceive('latestMedia')->once()->andReturn(['photo' => ['thumbnailUrl' => $redirectUrl, 'shortCode' => 'r'], 'video' => null]);
    $scraper->shouldReceive('profilePicUrl')->once()->andReturn(null);

    (new InstagramConnectJob($user->id, 'testuser', $connection->id))->handle($scraper);

    $connection->refresh();

    // The redirecting cover is refused → no image mirrored.
    expect($connection->payload['images'])->toBe([]);
    // The internal metadata endpoint must never have been requested.
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '169.254.169.254'));
});

// ── JOB-4: an empty scrape hard-fails the job instead of silently "succeeding" ──

it('InstagramConnectJob hard-fails (does not silently succeed) when the scrape returns no profile (JOB-4)', function () {
    $user = igAsyncUser('igfail1');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => null,
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->once()->andReturnNull();

    // Partial mock runs the real constructor + handle() but spies only on fail():
    // a null profile must call $this->fail() so Horizon records a failure (previously
    // it markFailed()+returned, so Horizon marked the job "succeeded" and hid the
    // broken connect). The Class[method] form applies partialness before the
    // constructor runs, so the constructor's onQueue() call passes through.
    $job = Mockery::mock(
        InstagramConnectJob::class.'[fail]',
        [$user->id, 'testuser', $connection->id]
    );
    $job->shouldReceive('fail')->once();

    $job->handle($scraper);

    // The happy path must NOT have run — the connection is never marked 'ok'.
    expect($connection->fresh()->last_refresh_status)->not->toBe('ok');
});

it('InstagramConnectJob.failed() marks the connection unavailable for the user', function () {
    $user = igAsyncUser('igfail2');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => null,
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);

    (new InstagramConnectJob($user->id, 'testuser', $connection->id))
        ->failed(new RuntimeException('apify down'));

    $connection->refresh();
    expect($connection->last_refresh_status)->toBe('unavailable');
    expect($connection->last_refresh_error)->toBe('job_failed');
    expect((int) $connection->consecutive_failures)->toBe(1);
});

// ── status endpoint: pending state ───────────────────────────────────────────

it('connectStatus returns pending when the job has not finished yet', function () {
    $user = igAsyncUser('igasync4');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => null,
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);

    actingAsUser($user)
        ->getJson('/api/platforms/instagram/connect/status')
        ->assertOk()
        ->assertJsonPath('status', 'pending');
});

// ── status endpoint: ready state ─────────────────────────────────────────────

it('connectStatus returns ready with the connection payload after the job completes', function () {
    $user = igAsyncUser('igasync5');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => ['username' => 'testuser', 'mode' => 'automatic', 'images' => []],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)
        ->getJson('/api/platforms/instagram/connect/status')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('connection.username', 'testuser');
});

// ── status endpoint: failed state ────────────────────────────────────────────

it('connectStatus returns failed when the job recorded an error', function () {
    $user = igAsyncUser('igasync6');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => null,
        'is_active' => false,
        'last_refresh_status' => 'unavailable',
        'last_refresh_error' => 'apify_fetch_failed',
    ]);

    actingAsUser($user)
        ->getJson('/api/platforms/instagram/connect/status')
        ->assertOk()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('error', 'apify_fetch_failed');
});

// ── status endpoint: 404 for non-owned / missing connection ──────────────────

it('connectStatus returns 404 when no connection exists for the caller', function () {
    $user = igAsyncUser('igasync7');

    actingAsUser($user)
        ->getJson('/api/platforms/instagram/connect/status')
        ->assertStatus(404);
});

it('connectStatus returns 404 when the connection belongs to another user', function () {
    $owner = igAsyncUser('igasync8a');
    $other = igAsyncUser('igasync8b');

    // Only $owner has a connection.
    IntegrationConnection::create([
        'user_id' => $owner->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => ['username' => 'owner'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    // $other has no connection — must see 404, not $owner's data.
    actingAsUser($other)
        ->getJson('/api/platforms/instagram/connect/status')
        ->assertStatus(404);
});
