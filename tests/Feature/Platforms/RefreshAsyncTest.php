<?php

// RV-8: RefreshController::refresh() moved from an inline scrape loop to
// dispatching RefreshConnectionJob per row and returning 202 immediately.
// This file exercises the new contract end to end; the 422/429/404/Instagram
// branches are unchanged and stay covered by IntegrationsDomainsV2Test and
// RefreshControllerInstagramApifyBudgetTest.

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Platforms\RefreshConnectionJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\PlatformRefresher;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function refreshAsyncUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

// Platform choice is NOT arbitrary: this whole file needs a *refreshable*
// platform, and nothing more — it only ever borrowed one as a fixture. It used
// to borrow `strava`, which was demoted to link-only (no fetch strategy, so
// RefreshController's isRefreshable() gate now 422s it). Re-pointed to
// `bandcamp`: refreshable, same generic refresh contract, no bespoke branch in
// RefreshController. Any other refreshable platform (vimeo, youtube) would do.
/** @return array{0: User, 1: IntegrationConnection} */
function refreshAsyncConnectedUser(string $handle, string $platform = 'bandcamp', bool $active = true): array
{
    $user = refreshAsyncUser($handle);
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => $platform,
        'resource_id' => $platform,
        'payload' => ['username' => 'someone'],
        'is_active' => $active,
        'last_refresh_status' => 'ok',
    ]);

    return [$user, $connection];
}

// ── POST /refresh — 202 shape ────────────────────────────────────────────────

it('returns 202 with status/refreshed/statusUrl and omits ok', function () {
    Queue::fake();
    [$user] = refreshAsyncConnectedUser('rv8shape');

    $response = actingAsUser($user)->postJson('/api/platforms/bandcamp/refresh')
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('refreshed', 1)
        ->assertJsonStructure(['statusUrl']);

    // 'ok' is unknowable at 202 time — must be absent, never a misleading 0.
    expect($response->json())->not->toHaveKey('ok');
});

it('dispatches one manual RefreshConnectionJob per active row', function () {
    Queue::fake();
    $user = refreshAsyncUser('rv8multi');
    $a = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'bandcamp', 'resource_id' => 'bandcamp-a',
        'payload' => ['username' => 'a'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $b = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'bandcamp', 'resource_id' => 'bandcamp-b',
        'payload' => ['username' => 'b'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->postJson('/api/platforms/bandcamp/refresh')
        ->assertStatus(202)
        ->assertJsonPath('refreshed', 2);

    Queue::assertPushed(RefreshConnectionJob::class, 2);
    foreach ([$a, $b] as $row) {
        Queue::assertPushed(RefreshConnectionJob::class, fn (RefreshConnectionJob $job) => $job->connectionId === $row->id
            && $job->platform === 'bandcamp'
            && $job->manual === true);
    }
});

it('excludes inactive rows from both the count and the dispatch', function () {
    Queue::fake();
    $user = refreshAsyncUser('rv8inactive');
    $active = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'bandcamp', 'resource_id' => 'bandcamp-active',
        'payload' => ['username' => 'a'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $inactive = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'bandcamp', 'resource_id' => 'bandcamp-inactive',
        'payload' => ['username' => 'b'], 'is_active' => false, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->postJson('/api/platforms/bandcamp/refresh')
        ->assertStatus(202)
        ->assertJsonPath('refreshed', 1);

    Queue::assertPushed(RefreshConnectionJob::class, 1);
    Queue::assertPushed(RefreshConnectionJob::class, fn (RefreshConnectionJob $job) => $job->connectionId === $active->id);
    Queue::assertNotPushed(RefreshConnectionJob::class, fn (RefreshConnectionJob $job) => $job->connectionId === $inactive->id);
});

// CA-SM review fix: every other test in this file (deliberately) uses
// Queue::fake(), which never runs RefreshConnectionJob::handle() and so never
// exercised the controller→job seam — precisely why the reviewer caught that
// the controller writes 'pending' at line 92 above and the job used to bail
// out on exactly that status, making the manual refresh button's happy path
// unreachable 100% of the time. QUEUE_CONNECTION=sync in tests (phpunit.xml),
// so with NO Queue::fake() the POST's dispatch() runs the real job inline,
// in-process, right here — end to end through the same seam production hits.
it('actually runs the refresh after the controller marks the row pending — the seam Queue::fake() never exercises', function () {
    [$user, $connection] = refreshAsyncConnectedUser('rv8seam');

    $refresher = Mockery::mock(PlatformRefresher::class);
    $refresher->shouldReceive('refresh')->once()
        ->with(Mockery::on(fn (IntegrationConnection $c) => $c->id === $connection->id))
        ->andReturn($connection);
    app()->instance(PlatformRefresher::class, $refresher);

    actingAsUser($user)->postJson('/api/platforms/bandcamp/refresh')->assertStatus(202);
});

it('stamps the row pending quietly — no model observer event, no cache purge', function () {
    Queue::fake();
    [$user] = refreshAsyncConnectedUser('rv8quiet');

    $observerFired = false;
    IntegrationConnection::saved(function () use (&$observerFired) {
        $observerFired = true;
    });

    actingAsUser($user)->postJson('/api/platforms/bandcamp/refresh')->assertStatus(202);

    expect($observerFired)->toBeFalse();
    Queue::assertNotPushed(CloudflareCachePurgeJob::class);
});

it('never queues another user\'s connection for the same platform', function () {
    Queue::fake();
    [$owner, $ownerRow] = refreshAsyncConnectedUser('rv8own');
    [, $otherRow] = refreshAsyncConnectedUser('rv8other');

    actingAsUser($owner)->postJson('/api/platforms/bandcamp/refresh')
        ->assertStatus(202)
        ->assertJsonPath('refreshed', 1);

    Queue::assertPushed(RefreshConnectionJob::class, fn (RefreshConnectionJob $job) => $job->connectionId === $ownerRow->id);
    Queue::assertNotPushed(RefreshConnectionJob::class, fn (RefreshConnectionJob $job) => $job->connectionId === $otherRow->id);
});

it('429 cooldown still fires on a rapid second POST after the queue swap', function () {
    Queue::fake();
    [$user] = refreshAsyncConnectedUser('rv8cooldown');

    actingAsUser($user)->postJson('/api/platforms/bandcamp/refresh')->assertStatus(202);
    actingAsUser($user)->postJson('/api/platforms/bandcamp/refresh')->assertStatus(429);
});

// W6-1: RefreshController::refresh() used to select every active row
// regardless of status, so it could pick up a row a deferred connect owns
// (already 'pending') and hand it to RefreshConnectionJob — whose refresh
// strategy rewrites the payload wholesale on a reconnect, wiping
// connectPendingAt/connectMode/teamMenu. Selection must exclude 'pending' rows.
it('excludes an already-pending row from both the count and the dispatch', function () {
    Queue::fake();
    $user = refreshAsyncUser('rv8alreadypending');
    $pending = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'bandcamp', 'resource_id' => 'bandcamp-pending',
        'payload' => ['username' => 'a'], 'is_active' => true, 'last_refresh_status' => 'pending',
    ]);
    $ok = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'bandcamp', 'resource_id' => 'bandcamp-ok',
        'payload' => ['username' => 'b'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->postJson('/api/platforms/bandcamp/refresh')
        ->assertStatus(202)
        ->assertJsonPath('refreshed', 1);

    Queue::assertPushed(RefreshConnectionJob::class, 1);
    Queue::assertPushed(RefreshConnectionJob::class, fn (RefreshConnectionJob $job) => $job->connectionId === $ok->id);
    Queue::assertNotPushed(RefreshConnectionJob::class, fn (RefreshConnectionJob $job) => $job->connectionId === $pending->id);
});

// The NULL-safe form is the one most likely to be got wrong: a naive
// `!= 'pending'` predicate evaluates NULL (never true), so it would silently
// drop every legacy NULL-status row from manual refresh.
it('still selects a NULL-status row and an "ok" row for manual refresh', function () {
    Queue::fake();
    $user = refreshAsyncUser('rv8nullstatus');
    $nullStatus = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'bandcamp', 'resource_id' => 'bandcamp-null',
        'payload' => ['username' => 'a'], 'is_active' => true, 'last_refresh_status' => null,
    ]);
    $ok = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'bandcamp', 'resource_id' => 'bandcamp-ok',
        'payload' => ['username' => 'b'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->postJson('/api/platforms/bandcamp/refresh')
        ->assertStatus(202)
        ->assertJsonPath('refreshed', 2);

    Queue::assertPushed(RefreshConnectionJob::class, 2);
    foreach ([$nullStatus, $ok] as $row) {
        Queue::assertPushed(RefreshConnectionJob::class, fn (RefreshConnectionJob $job) => $job->connectionId === $row->id);
    }
});

// ── GET /refresh/status — poll states ────────────────────────────────────────

it('polls pending while a row is still pending, then ready once all rows read ok', function () {
    $user = refreshAsyncUser('rv8pending');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'bandcamp', 'resource_id' => 'bandcamp',
        'payload' => ['username' => 'a'], 'is_active' => true, 'last_refresh_status' => 'pending',
    ]);

    actingAsUser($user)->getJson('/api/platforms/bandcamp/refresh/status')
        ->assertOk()
        ->assertExactJson(['status' => 'pending']);

    $connection->updateQuietly(['last_refresh_status' => 'ok']);

    actingAsUser($user)->getJson('/api/platforms/bandcamp/refresh/status')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('refreshed', 1)
        ->assertJsonPath('ok', 1);
});

it('polls failed when no row ends ok', function () {
    $user = refreshAsyncUser('rv8failed');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'bandcamp', 'resource_id' => 'bandcamp',
        'payload' => ['username' => 'a'], 'is_active' => true, 'last_refresh_status' => 'error',
    ]);

    actingAsUser($user)->getJson('/api/platforms/bandcamp/refresh/status')
        ->assertOk()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('refreshed', 1)
        ->assertJsonPath('ok', 0);
});

// Documented semantic: "any fresh-pending -> pending; else any ok -> ready; else
// failed". A mixed batch (one row ok, the rest failed) is intentionally 'ready',
// not 'failed' — pinned here so that aggregation can't silently drift to
// "failed if ANY row failed" (which would flip this outcome).
it('polls ready when a mixed ok+failed batch has at least one ok row', function () {
    $user = refreshAsyncUser('rv8mixed');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'bandcamp', 'resource_id' => 'bandcamp-ok',
        'payload' => ['username' => 'a'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'bandcamp', 'resource_id' => 'bandcamp-error-1',
        'payload' => ['username' => 'b'], 'is_active' => true, 'last_refresh_status' => 'error',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'bandcamp', 'resource_id' => 'bandcamp-error-2',
        'payload' => ['username' => 'c'], 'is_active' => true, 'last_refresh_status' => 'error',
    ]);

    actingAsUser($user)->getJson('/api/platforms/bandcamp/refresh/status')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('refreshed', 3)
        ->assertJsonPath('ok', 1);
});

it('resolves a stale pending row (6 minutes old) to failed via the escape hatch', function () {
    $user = refreshAsyncUser('rv8stale');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'bandcamp', 'resource_id' => 'bandcamp',
        'payload' => ['username' => 'a'], 'is_active' => true, 'last_refresh_status' => 'pending',
    ]);

    $this->travel(6)->minutes();

    actingAsUser($user)->getJson('/api/platforms/bandcamp/refresh/status')
        ->assertOk()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('refreshed', 1)
        ->assertJsonPath('ok', 0);
});

it('404s the poll endpoint when the caller has no active row for that platform', function () {
    $user = refreshAsyncUser('rv8pollnone');

    actingAsUser($user)->getJson('/api/platforms/bandcamp/refresh/status')
        ->assertStatus(404)
        ->assertJsonPath('message', 'Nothing connected to refresh.');
});

it('never resolves another user\'s row when polling status', function () {
    [, $otherRow] = refreshAsyncConnectedUser('rv8pollother');
    $caller = refreshAsyncUser('rv8pollcaller');

    actingAsUser($caller)->getJson('/api/platforms/bandcamp/refresh/status')
        ->assertStatus(404);
});

// Whole-branch review, finding 1: PlatformRefresher only catches the three
// Fetch*Exception subclasses (see its own docblock) — anything else propagates
// uncaught. Before this fix, RefreshConnectionJob::failed() wrote no terminal
// status, so the row RefreshController stamped 'pending' just before dispatch
// stayed 'pending' forever: scopeDueForRefresh() and this same controller's own
// selection both deliberately exclude 'pending' rows now, so nothing would ever
// pick it back up, and the refresh button would 404 ("Nothing connected to
// refresh.") on a platform the user can plainly see is connected.
it('resolves a stranded pending row to a terminal status when the refresher throws an exception type it never catches, so a later manual refresh can select it again', function () {
    [$user, $connection] = refreshAsyncConnectedUser('rv8uncaught');
    // Mirrors RefreshController::refresh()'s own stamp, immediately before dispatch.
    $connection->updateQuietly(['last_refresh_status' => 'pending']);

    $refresher = Mockery::mock(PlatformRefresher::class);
    $refresher->shouldReceive('refresh')->once()
        ->andThrow(new RuntimeException('unexpected fetch failure — not a Fetch*Exception'));
    app()->instance(PlatformRefresher::class, $refresher);

    // QUEUE_CONNECTION=sync (phpunit.xml): dispatch() runs handle() inline, and
    // SyncQueue::handleException() calls the job's failed() BEFORE rethrowing —
    // this exercises the exact seam production hits, not a simulated call to
    // failed().
    try {
        RefreshConnectionJob::dispatch($connection->id, 'bandcamp', manual: true);
        $this->fail('Expected the uncaught exception to propagate.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('unexpected fetch failure — not a Fetch*Exception');
    }

    expect($connection->refresh()->last_refresh_status)->toBe('error');
    expect($connection->last_refresh_error)->toBe('unexpected fetch failure — not a Fetch*Exception');

    // No longer stranded: RefreshController's manual-refresh selection excludes
    // ONLY 'pending' rows, so this now-'error' row is selectable again.
    app()->forgetInstance(PlatformRefresher::class);
    Queue::fake();
    actingAsUser($user)->postJson('/api/platforms/bandcamp/refresh')
        ->assertStatus(202)
        ->assertJsonPath('refreshed', 1);
    Queue::assertPushed(RefreshConnectionJob::class, fn (RefreshConnectionJob $job) => $job->connectionId === $connection->id);
});
