<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function freshaPayloadUser(string $h): User
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

it('fresha GET /url returns the stored connected url via the typed DTO', function () {
    $user = freshaPayloadUser('frp1');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/acme', 'selection' => null],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/url')
        ->assertOk()
        ->assertExactJson(['url' => 'https://www.fresha.com/a/acme']);
});

it('fresha GET /url returns a null url when nothing is connected', function () {
    actingAsUser(freshaPayloadUser('frp2'))->getJson('/api/platforms/fresha/url')
        ->assertOk()
        ->assertExactJson(['url' => null]);
});

it('fresha selection returns the stored url with a null selection for a pending row', function () {
    $user = freshaPayloadUser('frp3');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/acme', 'selection' => null],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->getJson('/api/platforms/fresha/selection')
        ->assertOk()
        ->assertExactJson(['selection' => null, 'url' => 'https://www.fresha.com/a/acme']);
});

it('fresha service-visibility preserves the inner blob shape verbatim (no canonical-null leak)', function () {
    $user = freshaPayloadUser('frp4');
    // Seed an inner blob WITHOUT `mode` (a legacy-shaped row). The write-back must
    // NOT add a mode:null key — the public endpoint passes `selection` verbatim.
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/acme',
            'selection' => [
                'url' => 'https://www.fresha.com/a/acme',
                'storeName' => 'Acme',
                'employee' => ['employeeId' => 'e1'],
                'services' => [['serviceId' => 's:1', 'name' => 'Cut']],
                'hiddenServiceIds' => [],
            ],
        ],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->postJson('/api/platforms/fresha/service-visibility', ['serviceId' => 's:1', 'hidden' => true])
        ->assertOk()
        ->assertJsonPath('hiddenServiceIds', ['s:1']);

    $stored = IntegrationConnection::where('user_id', $user->id)->where('platform', 'fresha')->firstOrFail()->payload;
    // Inner blob keys unchanged (no `mode` injected) — only hiddenServiceIds mutated.
    expect(array_keys($stored['selection']))->toBe(['url', 'storeName', 'employee', 'services', 'hiddenServiceIds']);
    expect($stored['selection']['hiddenServiceIds'])->toBe(['s:1']);
});
