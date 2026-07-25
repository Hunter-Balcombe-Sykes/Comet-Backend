<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function setupStateUser(string $handle): User
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

function seedFresha(User $user, array $payload, bool $isActive = true): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => $payload,
        'is_active' => $isActive,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);
}

it('reports a harvested fresha row as connected but incomplete, naming the source', function () {
    $user = setupStateUser('harvested');
    seedFresha($user, [
        'url' => 'https://www.fresha.com/a/anseo-studio',
        'selection' => null,
        'source' => 'instagram',
    ]);

    actingAsUser($user)->getJson('/api/platforms/booking/status')
        ->assertOk()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('provider', 'fresha')
        ->assertJsonPath('url', 'https://www.fresha.com/a/anseo-studio')
        ->assertJsonPath('setup.complete', false)
        ->assertJsonPath('setup.reason', 'awaiting_selection')
        ->assertJsonPath('setup.seededFrom', 'instagram');
});

it('reports a google-seeded fresha row with its own source', function () {
    $user = setupStateUser('gbseeded');
    seedFresha($user, [
        'url' => 'https://www.fresha.com/a/anseo-studio',
        'selection' => null,
        'source' => 'google-business',
    ]);

    actingAsUser($user)->getJson('/api/platforms/booking/status')
        ->assertOk()
        ->assertJsonPath('setup.complete', false)
        ->assertJsonPath('setup.seededFrom', 'google-business');
});

it('reports a completed fresha row as complete with a null reason', function () {
    $user = setupStateUser('completed');
    seedFresha($user, [
        'url' => 'https://www.fresha.com/a/anseo-studio',
        'selection' => [
            'url' => 'https://www.fresha.com/a/anseo-studio',
            'storeName' => 'Anseo Studio',
            'mode' => 'employee',
            'employee' => ['employeeId' => 'e1', 'displayName' => 'Simon'],
            'services' => [],
            'hiddenServiceIds' => [],
        ],
    ]);

    actingAsUser($user)->getJson('/api/platforms/booking/status')
        ->assertOk()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('name', 'Anseo Studio')
        ->assertJsonPath('setup.complete', true)
        ->assertJsonPath('setup.reason', null)
        ->assertJsonPath('setup.seededFrom', null);
});

it('treats a staff-disabled fresha row as not connected', function () {
    $user = setupStateUser('disabled');
    seedFresha($user, ['url' => 'https://www.fresha.com/a/x', 'selection' => null], isActive: false);

    actingAsUser($user)->getJson('/api/platforms/booking/status')
        ->assertOk()
        ->assertJsonPath('connected', false)
        ->assertJsonPath('provider', null)
        ->assertJsonPath('setup', null);
});

it('reports a square row as complete — a url is the whole integration', function () {
    $user = setupStateUser('squared');
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'square',
        'resource_id' => 'square',
        'payload' => ['url' => 'https://squareup.com/appointments/book/abc'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'last_refreshed_at' => now(),
    ]);

    actingAsUser($user)->getJson('/api/platforms/booking/status')
        ->assertOk()
        ->assertJsonPath('provider', 'square')
        ->assertJsonPath('setup.complete', true);
});

it('returns setup null when nothing is connected', function () {
    actingAsUser(setupStateUser('empty'))->getJson('/api/platforms/booking/status')
        ->assertOk()
        ->assertJsonPath('connected', false)
        ->assertJsonPath('setup', null);
});
