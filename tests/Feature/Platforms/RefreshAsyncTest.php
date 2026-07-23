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
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

/** @return array{0: User, 1: IntegrationConnection} */
function refreshAsyncConnectedUser(string $handle, string $platform = 'pinterest', bool $active = true): array
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

    $response = actingAsUser($user)->postJson('/api/platforms/pinterest/refresh')
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
        'user_id' => $user->id, 'platform' => 'pinterest', 'resource_id' => 'pinterest-a',
        'payload' => ['username' => 'a'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $b = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'pinterest', 'resource_id' => 'pinterest-b',
        'payload' => ['username' => 'b'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->postJson('/api/platforms/pinterest/refresh')
        ->assertStatus(202)
        ->assertJsonPath('refreshed', 2);

    Queue::assertPushed(RefreshConnectionJob::class, 2);
    foreach ([$a, $b] as $row) {
        Queue::assertPushed(RefreshConnectionJob::class, fn (RefreshConnectionJob $job) => $job->connectionId === $row->id
            && $job->platform === 'pinterest'
            && $job->manual === true);
    }
});

it('excludes inactive rows from both the count and the dispatch', function () {
    Queue::fake();
    $user = refreshAsyncUser('rv8inactive');
    $active = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'pinterest', 'resource_id' => 'pinterest-active',
        'payload' => ['username' => 'a'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $inactive = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'pinterest', 'resource_id' => 'pinterest-inactive',
        'payload' => ['username' => 'b'], 'is_active' => false, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->postJson('/api/platforms/pinterest/refresh')
        ->assertStatus(202)
        ->assertJsonPath('refreshed', 1);

    Queue::assertPushed(RefreshConnectionJob::class, 1);
    Queue::assertPushed(RefreshConnectionJob::class, fn (RefreshConnectionJob $job) => $job->connectionId === $active->id);
    Queue::assertNotPushed(RefreshConnectionJob::class, fn (RefreshConnectionJob $job) => $job->connectionId === $inactive->id);
});

it('stamps the row pending quietly — no model observer event, no cache purge', function () {
    Queue::fake();
    [$user] = refreshAsyncConnectedUser('rv8quiet');

    $observerFired = false;
    IntegrationConnection::saved(function () use (&$observerFired) {
        $observerFired = true;
    });

    actingAsUser($user)->postJson('/api/platforms/pinterest/refresh')->assertStatus(202);

    expect($observerFired)->toBeFalse();
    Queue::assertNotPushed(CloudflareCachePurgeJob::class);
});

it('never queues another user\'s connection for the same platform', function () {
    Queue::fake();
    [$owner, $ownerRow] = refreshAsyncConnectedUser('rv8own');
    [, $otherRow] = refreshAsyncConnectedUser('rv8other');

    actingAsUser($owner)->postJson('/api/platforms/pinterest/refresh')
        ->assertStatus(202)
        ->assertJsonPath('refreshed', 1);

    Queue::assertPushed(RefreshConnectionJob::class, fn (RefreshConnectionJob $job) => $job->connectionId === $ownerRow->id);
    Queue::assertNotPushed(RefreshConnectionJob::class, fn (RefreshConnectionJob $job) => $job->connectionId === $otherRow->id);
});

it('429 cooldown still fires on a rapid second POST after the queue swap', function () {
    Queue::fake();
    [$user] = refreshAsyncConnectedUser('rv8cooldown');

    actingAsUser($user)->postJson('/api/platforms/pinterest/refresh')->assertStatus(202);
    actingAsUser($user)->postJson('/api/platforms/pinterest/refresh')->assertStatus(429);
});

// ── GET /refresh/status — poll states ────────────────────────────────────────

it('polls pending while a row is still pending, then ready once all rows read ok', function () {
    $user = refreshAsyncUser('rv8pending');
    $connection = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'pinterest', 'resource_id' => 'pinterest',
        'payload' => ['username' => 'a'], 'is_active' => true, 'last_refresh_status' => 'pending',
    ]);

    actingAsUser($user)->getJson('/api/platforms/pinterest/refresh/status')
        ->assertOk()
        ->assertExactJson(['status' => 'pending']);

    $connection->updateQuietly(['last_refresh_status' => 'ok']);

    actingAsUser($user)->getJson('/api/platforms/pinterest/refresh/status')
        ->assertOk()
        ->assertJsonPath('status', 'ready')
        ->assertJsonPath('refreshed', 1)
        ->assertJsonPath('ok', 1);
});

it('polls failed when no row ends ok', function () {
    $user = refreshAsyncUser('rv8failed');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'pinterest', 'resource_id' => 'pinterest',
        'payload' => ['username' => 'a'], 'is_active' => true, 'last_refresh_status' => 'error',
    ]);

    actingAsUser($user)->getJson('/api/platforms/pinterest/refresh/status')
        ->assertOk()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('refreshed', 1)
        ->assertJsonPath('ok', 0);
});

it('resolves a stale pending row (6 minutes old) to failed via the escape hatch', function () {
    $user = refreshAsyncUser('rv8stale');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'pinterest', 'resource_id' => 'pinterest',
        'payload' => ['username' => 'a'], 'is_active' => true, 'last_refresh_status' => 'pending',
    ]);

    $this->travel(6)->minutes();

    actingAsUser($user)->getJson('/api/platforms/pinterest/refresh/status')
        ->assertOk()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('refreshed', 1)
        ->assertJsonPath('ok', 0);
});

it('404s the poll endpoint when the caller has no active row for that platform', function () {
    $user = refreshAsyncUser('rv8pollnone');

    actingAsUser($user)->getJson('/api/platforms/pinterest/refresh/status')
        ->assertStatus(404)
        ->assertJsonPath('message', 'Nothing connected to refresh.');
});

it('never resolves another user\'s row when polling status', function () {
    [, $otherRow] = refreshAsyncConnectedUser('rv8pollother');
    $caller = refreshAsyncUser('rv8pollcaller');

    actingAsUser($caller)->getJson('/api/platforms/pinterest/refresh/status')
        ->assertStatus(404);
});
