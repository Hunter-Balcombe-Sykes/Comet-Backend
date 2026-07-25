<?php

use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// R1 (PHPStan P0): RefreshController::refreshInstagram() imported the
// non-existent App\Services\Platforms\ApifyBudget instead of the real
// App\Services\Cache\ApifyBudget. Every OTHER path through refreshInstagram()
// returns early (no connection, no username, cooldown still active) before
// reaching `app(ApifyBudget::class)` — only an active connection PAST its
// cooldown drives execution into that call, so this is the one fixture shape
// that actually exercises the bug.
//
// Queue::fake() (same pattern as InstagramAsyncConnectTest's connect() tests)
// keeps this test scoped to the controller import bug: it proves dispatch()
// is reached without needing to run InstagramConnectJob's real scrape/seed
// pipeline (and the design-preset/cache-purge jobs IntegrationConnectionObserver
// chains off of it), which is already covered elsewhere.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function r1RefreshUser(string $handle): User
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

it('manual instagram refresh does not 500 once past cooldown (R1 regression)', function () {
    Queue::fake();

    $user = r1RefreshUser('r1refresh1');

    // Active connection with a real username — no cooldown cache key exists yet
    // in this fresh test, so the 6h cooldown gate is trivially "past".
    $connection = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => ['username' => 'someaccount', 'mode' => 'automatic'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)
        ->postJson('/api/platforms/instagram/refresh')
        ->assertStatus(202)
        ->assertJsonPath('status', 'pending')
        ->assertJsonStructure(['statusUrl']);

    Queue::assertPushed(InstagramConnectJob::class, function ($job) use ($user, $connection) {
        return $job->userId === $user->id
            && $job->username === 'someaccount'
            && $job->connectionId === $connection->id;
    });
});
