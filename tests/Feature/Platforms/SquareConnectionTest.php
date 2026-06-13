<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function squareUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('requires auth on the square selection route', function () {
    $this->getJson('/api/platforms/square/selection')->assertUnauthorized();
});

it('connects a Square booking URL and reads it back', function () {
    $user = squareUser('sq1');

    actingAsUser($user)->postJson('/api/platforms/square/connect', [
        'url' => 'https://book.squareup.com/appointments/abc123/location/L1/services',
    ])->assertOk();

    $saved = IntegrationConnection::where('user_id', $user->id)->where('platform', 'square')->first();
    expect($saved)->not->toBeNull();
    expect($saved->payload['url'])->toContain('squareup.com');

    actingAsUser($user)->getJson('/api/platforms/square/selection')
        ->assertOk()
        ->assertJsonPath('url', $saved->payload['url']);
});

it('rejects a non-Square URL', function () {
    actingAsUser(squareUser('sq2'))->postJson('/api/platforms/square/connect', [
        'url' => 'https://example.com/book',
    ])->assertStatus(422);
});

it('blocks connecting Square while Fresha is connected (XOR)', function () {
    $user = squareUser('sqx');

    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/acme'], 'is_active' => true,
    ]);

    actingAsUser($user)->postJson('/api/platforms/square/connect', [
        'url' => 'https://book.squareup.com/appointments/x/services',
    ])->assertStatus(409);

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'square')->exists())->toBeFalse();
});

it('blocks connecting Fresha while Square is connected (XOR, no scrape)', function () {
    $user = squareUser('frx');

    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'square', 'resource_id' => 'square',
        'payload' => ['url' => 'https://book.squareup.com/appointments/y/services'], 'is_active' => true,
    ]);

    actingAsUser($user)->postJson('/api/platforms/fresha/connect', [
        'url' => 'https://www.fresha.com/a/acme',
    ])->assertStatus(409);
});

it('forgets a Square connection', function () {
    $user = squareUser('sqf');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'square', 'resource_id' => 'square',
        'payload' => ['url' => 'https://book.squareup.com/appointments/z/services'], 'is_active' => true,
    ]);

    actingAsUser($user)->deleteJson('/api/platforms/square')->assertOk();

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'square')->whereNull('deleted_at')->exists())->toBeFalse();
});
