<?php

use App\Jobs\Platforms\DeleteMirroredMediaJob;
use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Observers\Core\IntegrationConnectionObserver;
use App\Services\Platforms\InstagramAutoSync;
use App\Services\Platforms\InstagramScraper;
use Illuminate\Support\Facades\Exceptions;
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
        'platform' => 'shop',
        'resource_id' => 'shop',
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

// ── _folder is persisted by the async connect job ───────────────────────────

it('never exposes the internal _folder on the instagram status or selection endpoints', function () {
    $user = r2CleanupUser('igfolder');
    makeIgConnection($user, [
        'username' => 'acme', 'images' => ['https://r2/0.jpg'], 'mode' => 'automatic',
        '_folder' => 'platforms/instagram/123', 'source' => 'google-business',
    ])->update(['is_active' => true, 'last_refresh_status' => 'ok']);

    $status = actingAsUser($user)->getJson('/api/platforms/instagram/connect/status')->assertOk()->json();
    $selection = actingAsUser($user)->getJson('/api/platforms/instagram/selection')->assertOk()->json();

    expect($status['data']['connection'] ?? $status['connection'] ?? [])->not->toHaveKey('_folder');
    expect($status['data']['connection'] ?? $status['connection'] ?? [])->not->toHaveKey('source');
    expect(data_get($selection, 'data.selection') ?? data_get($selection, 'selection') ?? [])->not->toHaveKey('_folder');
});

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
    $scraper->shouldReceive('latestMedia')->once()->andReturn(['photo' => ['thumbnailUrl' => 'https://scontent.cdninstagram.com/i.jpg', 'shortCode' => 'i'], 'video' => null]);
    $scraper->shouldReceive('profilePicUrl')->once()->andReturn(null);
    $scraper->shouldReceive('bioLinks')->once()->andReturn([]);

    (new InstagramConnectJob($user->id, 'creator', $conn->id))->handle($scraper, app(InstagramAutoSync::class));
    $conn->refresh();

    expect($conn->payload['_folder'])->toBe('platforms/instagram/'.$conn->created_at->timestamp);
});

// ── JOB-4: observer swallows + reports a dispatch failure instead of 500ing the write ──

it('reports (and does not throw) when the mirrored-media cleanup dispatch fails', function () {
    Exceptions::fake();
    $conn = makeIgConnection(r2CleanupUser('r2obsdispatch'), ['username' => 'x', '_folder' => 'platforms/instagram/OLD']);

    // Dirty the payload in-memory (not saved) so getOriginal() still holds the
    // OLD folder while ->payload holds the NEW one — isolates the dispatch
    // failure path without depending on a real DB update.
    $conn->payload = ['username' => 'x', '_folder' => 'platforms/instagram/NEW'];

    // Force DeleteMirroredMediaJob::dispatch() to blow up synchronously
    // (PendingDispatch::__destruct fires within this statement) instead of
    // mocking the job class directly.
    config(['queue.default' => 'invalid-connection-xyz']);

    expect(fn () => (new IntegrationConnectionObserver)->updated($conn))->not->toThrow(Throwable::class);

    // assertReported() matches on the closure's exact parameter type, not
    // Throwable — the queue manager throws InvalidArgumentException for an
    // unconfigured connection name.
    Exceptions::assertReported(fn (InvalidArgumentException $e) => str_contains($e->getMessage(), 'invalid-connection-xyz'));
});
