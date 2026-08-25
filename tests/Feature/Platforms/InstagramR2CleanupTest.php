<?php

use App\Jobs\Platforms\DeleteMirroredMediaJob;
use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Observers\Core\IntegrationConnectionObserver;
use App\Services\Platforms\InstagramAutoSync;
use App\Services\Platforms\InstagramConnectionSeeder;
use App\Services\Platforms\InstagramScraper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

/** payload is NOT NULL in prod — the pending placeholder is an empty array, never null. */
function makeIgConnection(User $user, array $payload): IntegrationConnection
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

/**
 * SEM-11 pin support. A plain Closure passed to IntegrationConnection::updated()
 * is NOT afterCommit-aware — it always fires inline regardless of transaction
 * depth, so it can't stand in for the real observer's deferral behaviour.
 * $afterCommit = true on a class-based listener is what makes Laravel defer it
 * via DatabaseTransactionsManager::addCallback() the same way
 * IntegrationConnectionObserver::updated() is deferred.
 *
 * Model::observe() only ever keeps the class NAME — HasEvents::registerObserver()
 * converts any instance to "ClassName@event" — and the dispatcher re-resolves a
 * fresh instance from the container on every single fire (Dispatcher::
 * createClassCallable(), `$listener = $this->container->make($class);`). An
 * instance property set on the object we `new`'d up would just be thrown away,
 * so the captured level has to live on a static property instead.
 */
class InstagramFolderTransactionLevelSpy
{
    public bool $afterCommit = true;

    public static ?int $capturedLevel = null;

    public function updated(IntegrationConnection $connection): void
    {
        if ($connection->platform === 'instagram') {
            self::$capturedLevel = DB::transactionLevel();
        }
    }
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
        'platform' => 'shopify.store',
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
    $conn = makeIgConnection(r2CleanupUser('r2up3'), []); // pending placeholder

    $conn->update(['payload' => ['username' => 'x', '_folder' => 'platforms/instagram/NEW']]);

    Queue::assertNotPushed(DeleteMirroredMediaJob::class);
});

// ── SEM-11 (refuted) — pin the precondition the refutation rests on ─────────

it('writes the mirrored-media folder outside any DB transaction — the getOriginal() precondition', function () {
    Queue::fake();
    InstagramFolderTransactionLevelSpy::$capturedLevel = null;
    IntegrationConnection::observe(InstagramFolderTransactionLevelSpy::class);
    $conn = makeIgConnection(r2CleanupUser('r2txlevel1'), ['username' => 'x', '_folder' => 'platforms/instagram/AAA']);

    // Mirrors the real write shape (InstagramConnectionSeeder:284-295): a
    // Cache::lock()-guarded update(), never a DB::transaction(). At level 0,
    // the afterCommit listener runs inline, before finishSave() calls
    // syncOriginal() — that ordering is what keeps getOriginal() holding the
    // pre-update payload for the observer's old/new diff.
    Cache::lock('r2-cleanup-test-lock-'.$conn->id, 10)->block(5, function () use ($conn) {
        $conn->update(['payload' => ['username' => 'x', '_folder' => 'platforms/instagram/BBB']]);
    });

    expect(InstagramFolderTransactionLevelSpy::$capturedLevel)->toBe(0);
    Queue::assertPushed(DeleteMirroredMediaJob::class, fn ($job) => $job->folder === 'platforms/instagram/AAA');
});

it('never deletes the NEW folder when the folder change lands inside a transaction', function () {
    Queue::fake();
    $conn = makeIgConnection(r2CleanupUser('r2txlevel2'), ['username' => 'x', '_folder' => 'platforms/instagram/AAA']);

    // If a future writer ever moved the _folder change inside a DB::transaction,
    // the afterCommit callback would defer past syncOriginal(), so
    // getOriginal('payload') would already read as the NEW value and old===new
    // — the diff fails safe (skips cleanup) rather than deleting the live folder.
    DB::transaction(function () use ($conn) {
        $conn->update(['payload' => ['username' => 'x', '_folder' => 'platforms/instagram/BBB']]);
    });

    Queue::assertNotPushed(DeleteMirroredMediaJob::class, fn ($job) => $job->folder === 'platforms/instagram/BBB');
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
        'payload' => [],
        'is_active' => false,
        'last_refresh_status' => 'pending',
    ]);

    $scraper = Mockery::mock(InstagramScraper::class);
    $scraper->shouldReceive('fetchProfile')->once()->andReturn(['fullName' => 'X']);
    $scraper->shouldReceive('latestMedia')->once()->andReturn(['photo' => ['thumbnailUrl' => 'https://scontent.cdninstagram.com/i.jpg', 'shortCode' => 'i'], 'video' => null]);
    $scraper->shouldReceive('profilePicUrl')->once()->andReturn(null);
    $scraper->shouldReceive('bioLinks')->once()->andReturn([]);
    // The seeder resolves its own InstagramScraper from the container — bind the
    // mock so seed() (called inside handle()) uses it too, not a real scraper.
    app()->instance(InstagramScraper::class, $scraper);

    (new InstagramConnectJob($user->id, 'creator', $conn->id))->handle($scraper, app(InstagramConnectionSeeder::class), app(InstagramAutoSync::class));
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
