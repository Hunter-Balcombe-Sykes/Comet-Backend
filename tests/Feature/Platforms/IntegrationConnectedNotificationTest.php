<?php

/** @phpstan-ignore-all */

// End-to-end wiring of the integration-connected bell notice at both emit points:
// the controller trait (synchronous connects) and ConnectFetchJob (deferred ones).

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\OEmbedService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupNotificationsTable();

    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS notifications.notifications_dedupe_key_per_pro_uq
         ON notifications (user_id, dedupe_key) WHERE dedupe_key IS NOT NULL'
    );
});

function icwUser(string $handle): User
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

function icwRows(User $user)
{
    return DB::table('notifications.notifications')
        ->where('user_id', $user->id)
        ->where('category', 'integration_connected')
        ->get();
}

it('notifies on a synchronous dashboard connect', function () {
    config(['partna.connect.deferred' => []]);
    $user = icwUser('icw1');

    $this->mock(OEmbedService::class, fn ($m) => $m->shouldReceive('resolve')->once()->andReturn([
        'name' => 'Artist', 'thumbnail' => 'https://i.scdn.co/t.jpg', 'embedUrl' => null,
    ]));

    actingAsUser($user)
        ->postJson('/api/platforms/spotify/connect', ['url' => 'https://open.spotify.com/artist/abc123'])
        ->assertOk();

    $rows = icwRows($user);
    expect($rows)->toHaveCount(1);
    expect($rows->first()->title)->toContain('Spotify');
});

it('does not notify when a custom link is added', function () {
    $user = icwUser('icw2');

    actingAsUser($user)
        ->postJson('/api/platforms/custom/links', ['url' => 'https://example.com', 'label' => 'My site'])
        ->assertSuccessful();

    expect(icwRows($user))->toHaveCount(0);
});

it('does not notify for a connection created outside the dashboard trait', function () {
    // Seeders (pre-account builds, auto-sync) write the model directly and never
    // reach upsertConnection() — the user did not connect these.
    $user = icwUser('icw3');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => ['handle' => 'seeded'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    expect(icwRows($user))->toHaveCount(0);
});
