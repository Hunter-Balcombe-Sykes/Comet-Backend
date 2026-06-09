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
});

// ── cooldown blocks a rapid second connect BEFORE dispatch ───────────────────

it('cooldown blocks a rapid second connect before the job is dispatched', function () {
    Queue::fake();
    config(['services.apify.token' => 'test-token']);

    $user = igAsyncUser('igasync2');

    // First connect: succeeds, queues one job.
    actingAsUser($user)
        ->postJson('/api/platforms/instagram/connect', ['username' => 'testuser'])
        ->assertStatus(202);

    Queue::assertPushed(InstagramConnectJob::class, 1);

    // Second connect within cooldown: rejected before dispatch.
    actingAsUser($user)
        ->postJson('/api/platforms/instagram/connect', ['username' => 'testuser'])
        ->assertStatus(429);

    // Still only 1 job in the queue.
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
    $scraper->shouldReceive('recentCoverImages')
        ->once()
        ->andReturn([$imgUrl1, $imgUrl2]);
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
    // Both images were successfully mirrored — the Http::fake returns 200 for all CDN requests.
    expect(count($connection->payload['images']))->toBe(2);
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
    $scraper->shouldReceive('recentCoverImages')->once()->andReturn([$okUrl, $redirectUrl]);
    $scraper->shouldReceive('profilePicUrl')->once()->andReturn(null);

    (new InstagramConnectJob($user->id, 'testuser', $connection->id))->handle($scraper);

    $connection->refresh();

    // Only the clean 200 image is mirrored; the redirect is counted as dropped.
    expect(count($connection->payload['images']))->toBe(1);
    expect($connection->payload['imagesDropped'])->toBe(1);
    // The internal metadata endpoint must never have been requested.
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '169.254.169.254'));
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
