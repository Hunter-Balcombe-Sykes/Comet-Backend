<?php

use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramAutoSync;
use App\Services\Platforms\InstagramConnectionSeeder;
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

// Defaults to 'partna' (these tests predate the partna/business split and
// don't exercise capability-gated paths). Pass 'business' when the test
// asserts the bio-social auto-sync seeding path — social seeds are gated on
// google_business_full_sync (RULING 1).
function igAsyncUser(string $h, string $accountType = 'partna'): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => $accountType,
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
        'partna.limits.apify.actors.instagram' => 1,
        'partna.limits.apify.global_daily_cap' => 1,
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
    $scraper->shouldReceive('bioLinks')->once()->andReturn([]);
    // The seeder resolves its own InstagramScraper from the container — bind the
    // mock so seed() (called inside handle()) uses it too, not a real scraper.
    app()->instance(InstagramScraper::class, $scraper);

    $job = new InstagramConnectJob($user->id, 'testuser', $connection->id);
    $job->handle($scraper, app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

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
    $scraper->shouldReceive('bioLinks')->once()->andReturn([]);
    // The seeder resolves its own InstagramScraper from the container — bind the
    // mock so seed() (called inside handle()) uses it too, not a real scraper.
    app()->instance(InstagramScraper::class, $scraper);

    (new InstagramConnectJob($user->id, 'testuser', $connection->id))->handle($scraper, app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

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
    $scraper->shouldReceive('bioLinks')->once()->andReturn([]);
    // The seeder resolves its own InstagramScraper from the container — bind the
    // mock so seed() (called inside handle()) uses it too, not a real scraper.
    app()->instance(InstagramScraper::class, $scraper);

    (new InstagramConnectJob($user->id, 'testuser', $connection->id))->handle($scraper, app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

    $connection->refresh();

    // The redirecting cover is refused → no image mirrored.
    expect($connection->payload['images'])->toBe([]);
    // The internal metadata endpoint must never have been requested.
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '169.254.169.254'));
});

// ── SEC-2: MAX_IMAGE_BYTES cap — oversized image dropped, job still completes ───

it('InstagramConnectJob drops an oversized cover image and completes the job without storing it', function () {
    Storage::fake('media');

    $bigUrl = 'https://scontent.cdninstagram.com/bigphoto.jpg';
    $picUrl = 'https://scontent.cdninstagram.com/pic.jpg';

    Http::fake([
        // Content-Length header declares the cover is over the MAX_IMAGE_BYTES cap.
        // The job must reject it before Storage::put (testing the Content-Length
        // precheck path; the hard strlen fallback is proven-correct by inspection
        // since both checks gate the same Storage::put call).
        'scontent.cdninstagram.com/bigphoto.jpg' => Http::response('tiny-sentinel', 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => 20_000_000, // 20 MB — over the 15 MB cap
        ]),
        'scontent.cdninstagram.com/pic.jpg' => Http::response('img-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $user = igAsyncUser('igcap_img1');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => [],
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->once()->andReturn(['fullName' => 'Cap User']);
    $scraper->shouldReceive('latestMedia')->once()->andReturn([
        'photo' => ['thumbnailUrl' => $bigUrl, 'shortCode' => 'big'],
        'video' => null,
    ]);
    $scraper->shouldReceive('profilePicUrl')->once()->andReturn($picUrl);
    $scraper->shouldReceive('bioLinks')->once()->andReturn([]);
    // The seeder resolves its own InstagramScraper from the container — bind the
    // mock so seed() (called inside handle()) uses it too, not a real scraper.
    app()->instance(InstagramScraper::class, $scraper);

    (new InstagramConnectJob($user->id, 'testuser', $connection->id))->handle($scraper, app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

    $connection->refresh();

    // Job must complete successfully — one dropped image must not fail the whole job.
    expect($connection->last_refresh_status)->toBe('ok');
    // The oversized cover was rejected before Storage::put; images[] is empty.
    expect($connection->payload['images'])->toBe([]);
    // The profile pic (no cap breach) was mirrored normally.
    expect($connection->payload['profilePicUrl'])->not->toBeNull();
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

    $job->handle($scraper, app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

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

// ── JOB-2: stale mirror reclaim within a reconnect run ───────────────────────

it('reconnect reclaims stale reel files when the account now leads with a photo only (JOB-2)', function () {
    Storage::fake('media');

    $photoUrl = 'https://scontent.cdninstagram.com/photo_new.jpg';

    Http::fake([
        'scontent.cdninstagram.com/*' => Http::response('img-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $user = igAsyncUser('igjob2a');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => null,
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);

    $folder = 'platforms/instagram/'.$connection->created_at->timestamp;

    // Pre-seed stale reel files left by a prior connect run.
    Storage::disk('media')->put("{$folder}/reel.mp4", 'old-video-bytes');
    Storage::disk('media')->put("{$folder}/reel-cover.jpg", 'old-cover-bytes');

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->once()->andReturn(['fullName' => 'Job2 User']);
    $scraper->shouldReceive('latestMedia')->once()->andReturn([
        'photo' => ['thumbnailUrl' => $photoUrl, 'shortCode' => 'abc'],
        'video' => null, // no reel this time — stale reel files must be reclaimed
    ]);
    $scraper->shouldReceive('profilePicUrl')->once()->andReturnNull();
    $scraper->shouldReceive('bioLinks')->once()->andReturn([]);
    // The seeder resolves its own InstagramScraper from the container — bind the
    // mock so seed() (called inside handle()) uses it too, not a real scraper.
    app()->instance(InstagramScraper::class, $scraper);

    (new InstagramConnectJob($user->id, 'job2user', $connection->id))->handle($scraper, app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

    // Fresh photo written.
    expect(Storage::disk('media')->exists("{$folder}/photo.jpg"))->toBeTrue();
    // Stale reel + cover must be deleted.
    expect(Storage::disk('media')->exists("{$folder}/reel.mp4"))->toBeFalse();
    expect(Storage::disk('media')->exists("{$folder}/reel-cover.jpg"))->toBeFalse();
});

it('first connect writes photo and does not delete any spurious files (JOB-2)', function () {
    Storage::fake('media');

    $photoUrl = 'https://scontent.cdninstagram.com/first_photo.jpg';

    Http::fake([
        'scontent.cdninstagram.com/*' => Http::response('img-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $user = igAsyncUser('igjob2b');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => null,
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);

    $folder = 'platforms/instagram/'.$connection->created_at->timestamp;

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->once()->andReturn(['fullName' => 'First User']);
    $scraper->shouldReceive('latestMedia')->once()->andReturn([
        'photo' => ['thumbnailUrl' => $photoUrl, 'shortCode' => 'first'],
        'video' => null,
    ]);
    $scraper->shouldReceive('profilePicUrl')->once()->andReturnNull();
    $scraper->shouldReceive('bioLinks')->once()->andReturn([]);
    // The seeder resolves its own InstagramScraper from the container — bind the
    // mock so seed() (called inside handle()) uses it too, not a real scraper.
    app()->instance(InstagramScraper::class, $scraper);

    // No pre-existing files — handle() must complete without exception.
    (new InstagramConnectJob($user->id, 'firstuser', $connection->id))->handle($scraper, app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

    expect(Storage::disk('media')->exists("{$folder}/photo.jpg"))->toBeTrue();
    $connection->refresh();
    expect($connection->last_refresh_status)->toBe('ok');
});

it('removed profile pic is reclaimed on reconnect when scraper returns null (JOB-2)', function () {
    Storage::fake('media');

    $photoUrl = 'https://scontent.cdninstagram.com/pic2.jpg';

    Http::fake([
        'scontent.cdninstagram.com/*' => Http::response('img-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $user = igAsyncUser('igjob2c');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => null,
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);

    $folder = 'platforms/instagram/'.$connection->created_at->timestamp;

    // Pre-seed a profile pic from a prior connect run.
    Storage::disk('media')->put("{$folder}/profile.jpg", 'old-profile-bytes');

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->once()->andReturn(['fullName' => 'Pic Gone User']);
    $scraper->shouldReceive('latestMedia')->once()->andReturn([
        'photo' => ['thumbnailUrl' => $photoUrl, 'shortCode' => 'pic2'],
        'video' => null,
    ]);
    // Profile pic no longer available on this run.
    $scraper->shouldReceive('profilePicUrl')->once()->andReturnNull();
    $scraper->shouldReceive('bioLinks')->once()->andReturn([]);
    // The seeder resolves its own InstagramScraper from the container — bind the
    // mock so seed() (called inside handle()) uses it too, not a real scraper.
    app()->instance(InstagramScraper::class, $scraper);

    (new InstagramConnectJob($user->id, 'picgoneuser', $connection->id))->handle($scraper, app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

    // Fresh photo still written.
    expect(Storage::disk('media')->exists("{$folder}/photo.jpg"))->toBeTrue();
    // Stale profile pic must be reclaimed.
    expect(Storage::disk('media')->exists("{$folder}/profile.jpg"))->toBeFalse();
});

// ── BE2: bio links captured + auto-synced, real scraper + real Apify fixture ─────

/** A realistic Apify dataset item WITH bio fields (biography/externalUrl/externalUrls). */
function igItemWithBio(): array
{
    return [
        'fullName' => 'Doc Pizza',
        'followersCount' => 500,
        'postsCount' => 42,
        'businessCategoryName' => 'Restaurant',
        'externalUrl' => 'https://docpizza.example.com',
        'externalUrls' => [['url' => 'https://www.facebook.com/docpizzabar']],
        'biography' => 'Wood-fired pizza. Linktree: https://linktr.ee/docpizza',
        'latestPosts' => [],   // no media — keeps this test focused on bio fields
    ];
}

/** Today's real Apify actor output — no bio fields at all. */
function igItemWithoutBio(): array
{
    return [
        'fullName' => 'Legacy User',
        'followersCount' => 10,
        'postsCount' => 2,
        'latestPosts' => [],
    ];
}

it('BE2: captures website + bioLinks and auto-syncs a bio social link, using the real scraper end-to-end', function () {
    Storage::fake('media');
    Queue::fake();
    config(['services.apify.token' => 'test-token']);
    Http::fake(['api.apify.com/*' => Http::response([igItemWithBio()], 201)]);

    // Business account: this test exercises the social SEEDING path, which is
    // gated on google_business_full_sync (partna accounts get unmatched instead).
    $user = igAsyncUser('igbio1', 'business');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => [], 'is_active' => false, 'last_refresh_status' => 'pending',
    ]);

    (new InstagramConnectJob($user->id, 'docpizza', $connection->id))
        ->handle(app(InstagramScraper::class), app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

    $connection->refresh();
    expect($connection->last_refresh_status)->toBe('ok');
    expect($connection->payload['website'])->toBe('https://docpizza.example.com');
    expect($connection->payload['bioLinks'])->toBe([
        'https://docpizza.example.com',
        'https://www.facebook.com/docpizzabar',
        'https://linktr.ee/docpizza',
    ]);

    // The bio's Facebook link auto-synced (mirrors the Google Business flow).
    expect($connection->payload['syncFindings'])->toHaveCount(1);
    expect($connection->payload['syncFindings'][0]['platform'])->toBe('facebook');
    expect($connection->payload['syncFindings'][0]['outcome'])->toBe('seeded');
    $fb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->firstOrFail()->payload;
    expect($fb['url'])->toBe('https://www.facebook.com/docpizzabar');
    expect($fb['source'])->toBe('instagram');

    // The generic website (externalUrl) isn't auto-syncable → surfaces as an
    // "add as custom link" suggestion (and, per A2.2, gets auto-saved as one).
    // The Linktree link is no longer routed to unmatched at all — A3.3/A3.4
    // detect it as a curated link-in-bio host and dispatch a scan instead.
    expect($connection->payload['unmatched'])->toBe([
        ['url' => 'https://docpizza.example.com', 'label' => 'docpizza.example.com'],
    ]);
    Queue::assertPushed(\App\Jobs\Platforms\LinkInBioScanJob::class, fn ($job) => $job->bioPageUrl === 'https://linktr.ee/docpizza');
});

// ── A1.4: InstagramIdentitySync wired into the connect job ──────────────────

it('applies instagram identity fields (sector/display_name) as part of the connect job, fill-if-empty', function () {
    Storage::fake('media');
    config(['services.apify.token' => 'test-token']);
    Http::fake(['api.apify.com/*' => Http::response([[
        'fullName' => 'Test Cafe',
        'businessCategoryName' => 'Cafe',
        'username' => 'test_cafe_ig',
        'latestPosts' => [],
    ]], 201)]);

    $user = User::create([
        'handle' => 'igidentity1', 'handle_lc' => 'igidentity1', 'display_name' => '',
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => 'igidentity1@example.com', 'sector' => null, 'sector_source' => null,
    ]);
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => [], 'is_active' => false, 'last_refresh_status' => 'pending',
    ]);

    (new InstagramConnectJob($user->id, 'test_cafe_ig', $connection->id))
        ->handle(app(InstagramScraper::class), app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

    $fresh = $user->fresh();
    expect($fresh->display_name)->toBe('Test Cafe');
    expect($fresh->sector)->toBe('cafe');
    expect($fresh->sector_source)->toBe('instagram');
});

it('does not overwrite already-set identity fields as part of the connect job', function () {
    Storage::fake('media');
    config(['services.apify.token' => 'test-token']);
    Http::fake(['api.apify.com/*' => Http::response([[
        'fullName' => 'Test Cafe', 'businessCategoryName' => 'Cafe', 'username' => 'test_cafe_ig', 'latestPosts' => [],
    ]], 201)]);

    $user = igAsyncUser('igidentity2'); // display_name defaults to 'Igidentity2' (non-blank)
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => [], 'is_active' => false, 'last_refresh_status' => 'pending',
    ]);

    (new InstagramConnectJob($user->id, 'test_cafe_ig', $connection->id))
        ->handle(app(InstagramScraper::class), app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

    expect($user->fresh()->display_name)->toBe('Igidentity2');
});

// ── A2.2: unmatched bio links auto-saved as custom links ────────────────────

it('auto-creates a custom link for each unmatched instagram bio link', function () {
    Storage::fake('media');
    Queue::fake();
    config(['services.apify.token' => 'test-token']);
    Http::fake(['api.apify.com/*' => Http::response([[
        'fullName' => 'Blog User',
        'externalUrl' => 'https://someblog.example/post',
        'latestPosts' => [],
    ]], 201)]);

    $user = igAsyncUser('igcustomlink1');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => [], 'is_active' => false, 'last_refresh_status' => 'pending',
    ]);

    (new InstagramConnectJob($user->id, 'testuser', $connection->id))
        ->handle(app(InstagramScraper::class), app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

    $connection->refresh();
    expect($connection->payload['unmatched'])->toBe([
        ['url' => 'https://someblog.example/post', 'label' => 'someblog.example'],
    ]);
    $custom = IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'custom'])->first();
    expect($custom)->not->toBeNull();
    expect($custom->payload['url'])->toBe('https://someblog.example/post');
});

it('does not auto-create a custom link for a bio link that WAS auto-synced as a platform connection', function () {
    Storage::fake('media');
    Queue::fake();
    config(['services.apify.token' => 'test-token']);
    Http::fake(['api.apify.com/*' => Http::response([[
        'fullName' => 'Doc Pizza',
        'externalUrls' => [['url' => 'https://www.facebook.com/docpizzabar']],
        'latestPosts' => [],
    ]], 201)]);

    // Business account: matched social links get seeded, not routed to unmatched.
    $user = igAsyncUser('igcustomlink2', 'business');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => [], 'is_active' => false, 'last_refresh_status' => 'pending',
    ]);

    (new InstagramConnectJob($user->id, 'docpizza', $connection->id))
        ->handle(app(InstagramScraper::class), app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

    expect(IntegrationConnection::where(['user_id' => $user->id, 'platform' => 'custom'])->exists())->toBeFalse();
});

it('BE2: an Apify response with none of the bio fields (older actor shape) leaves website/bioLinks/syncFindings/unmatched empty and does not break the job', function () {
    Storage::fake('media');
    config(['services.apify.token' => 'test-token']);
    Http::fake(['api.apify.com/*' => Http::response([igItemWithoutBio()], 201)]);

    $user = igAsyncUser('igbio2');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => [], 'is_active' => false, 'last_refresh_status' => 'pending',
    ]);

    (new InstagramConnectJob($user->id, 'legacyuser', $connection->id))
        ->handle(app(InstagramScraper::class), app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));

    $connection->refresh();
    expect($connection->last_refresh_status)->toBe('ok');
    expect($connection->payload['website'])->toBeNull();
    expect($connection->payload['bioLinks'])->toBe([]);
    expect($connection->payload['syncFindings'])->toBe([]);
    expect($connection->payload['unmatched'])->toBe([]);
    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->exists())->toBeFalse();
});
