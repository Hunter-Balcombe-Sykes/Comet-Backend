<?php

use App\Jobs\Platforms\DeleteMirroredMediaJob;
use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function r2CleanupUser(string $h): User
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

function makeIgConnection(User $user, ?array $payload): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => $payload,
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
}

// ── disconnect (soft-delete) cleanup ─────────────────────────────────────────

it('dispatches R2 cleanup when an Instagram connection with a stored folder is disconnected', function () {
    Queue::fake();
    $conn = makeIgConnection(r2CleanupUser('r2del1'), ['username' => 'x', '_folder' => 'platforms/instagram/111']);

    $conn->delete();

    Queue::assertPushed(DeleteMirroredMediaJob::class, fn ($job) => $job->folder === 'platforms/instagram/111');
});

it('does not dispatch cleanup when a disconnected Instagram connection has no stored folder', function () {
    Queue::fake();
    $conn = makeIgConnection(r2CleanupUser('r2del2'), ['username' => 'x']); // no _folder

    $conn->delete();

    Queue::assertNotPushed(DeleteMirroredMediaJob::class);
});

it('does not dispatch cleanup for a non-Instagram platform on delete', function () {
    Queue::fake();
    $user = r2CleanupUser('r2del3');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'shopify',
        'resource_id' => 'shopify',
        'payload' => ['_folder' => 'platforms/instagram/should-be-ignored'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $conn->delete();

    Queue::assertNotPushed(DeleteMirroredMediaJob::class);
});

// ── overwrite (re-save to a new folder) cleanup ──────────────────────────────

it('dispatches cleanup of the OLD folder when the stored folder changes', function () {
    Queue::fake();
    $conn = makeIgConnection(r2CleanupUser('r2up1'), ['username' => 'x', '_folder' => 'platforms/instagram/AAA']);

    $conn->update(['payload' => ['username' => 'x', '_folder' => 'platforms/instagram/BBB']]);

    Queue::assertPushed(DeleteMirroredMediaJob::class, fn ($job) => $job->folder === 'platforms/instagram/AAA');
});

it('does not dispatch cleanup when the folder is unchanged across an update', function () {
    Queue::fake();
    $conn = makeIgConnection(r2CleanupUser('r2up2'), ['username' => 'x', '_folder' => 'platforms/instagram/SAME']);

    $conn->update(['payload' => ['username' => 'y', '_folder' => 'platforms/instagram/SAME']]);

    Queue::assertNotPushed(DeleteMirroredMediaJob::class);
});

it('does not dispatch cleanup on the pending→ready transition (null → folder)', function () {
    Queue::fake();
    $conn = makeIgConnection(r2CleanupUser('r2up3'), null); // pending placeholder

    $conn->update(['payload' => ['username' => 'x', '_folder' => 'platforms/instagram/NEW']]);

    Queue::assertNotPushed(DeleteMirroredMediaJob::class);
});

// ── _folder is persisted by BOTH write paths ─────────────────────────────────

it('the async connect job stores the R2 _folder in the payload', function () {
    Queue::fake();
    Storage::fake('media');
    Http::fake(['scontent.cdninstagram.com/*' => Http::response('bytes', 200, ['Content-Type' => 'image/jpeg'])]);

    $user = r2CleanupUser('r2async');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => null,
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->once()->andReturn(['fullName' => 'X']);
    $scraper->shouldReceive('recentCoverImages')->once()->andReturn(['https://scontent.cdninstagram.com/i.jpg']);
    $scraper->shouldReceive('profilePicUrl')->once()->andReturn(null);

    (new InstagramConnectJob($user->id, 'creator', $conn->id))->handle($scraper);
    $conn->refresh();

    expect($conn->payload['_folder'])->toBe('platforms/instagram/'.$conn->created_at->timestamp);
});

it('a manual save stores the R2 _folder in the payload', function () {
    Queue::fake();
    Storage::fake('media');
    config(['services.apify.token' => 'test-token']);

    $user = r2CleanupUser('r2manual');

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->andReturn(['fullName' => 'X', 'followersCount' => 1, 'postsCount' => 1]);
    $scraper->shouldReceive('profilePicUrl')->andReturn(null);
    app()->instance(InstagramScraper::class, $scraper);

    // Empty image set keeps the test HTTP-free; _folder is set regardless.
    actingAsUser($user)
        ->postJson('/api/platforms/instagram/selection', ['username' => 'creator', 'images' => []])
        ->assertOk();

    $conn = IntegrationConnection::where('user_id', $user->id)->where('platform', 'instagram')->first();
    expect($conn->payload['_folder'])->toStartWith('platforms/instagram/');
});
