<?php

use App\Models\Core\Site\PlatformConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function makeLinkUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'account_type' => 'individual',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$handle}@example.com",
    ]);
}

it('requires auth on the tiktok + facebook dashboard routes', function () {
    $this->getJson('/api/platforms/tiktok/selection')->assertUnauthorized();
    $this->getJson('/api/platforms/facebook/selection')->assertUnauthorized();
});

it('connects a TikTok link scoped to the authenticated user', function () {
    $user = makeLinkUser('tk');

    actingAsUser($user)->postJson('/api/platforms/tiktok/connect', ['username' => '@dancer'])
        ->assertOk()
        ->assertJsonPath('username', 'dancer')
        ->assertJsonPath('url', 'https://www.tiktok.com/@dancer');

    $conn = PlatformConnection::where('user_id', $user->id)->where('platform', 'tiktok')->first();
    expect($conn->payload['username'])->toBe('dancer');
});

it('connects a Facebook legacy page link scoped to the authenticated user', function () {
    $user = makeLinkUser('fb');

    actingAsUser($user)->postJson('/api/platforms/facebook/connect', ['username' => 'https://www.facebook.com/pages/Some-Cafe/123'])
        ->assertOk()
        ->assertJsonPath('url', 'https://www.facebook.com/pages/Some-Cafe/123');

    expect(PlatformConnection::where('user_id', $user->id)->where('platform', 'facebook')->exists())->toBeTrue();
});

it("keeps two users' connections separate (per-user isolation)", function () {
    $jane = makeLinkUser('jane');
    $bob = makeLinkUser('bob');

    actingAsUser($jane)->postJson('/api/platforms/tiktok/connect', ['username' => 'janedance'])->assertOk();
    actingAsUser($bob)->postJson('/api/platforms/tiktok/connect', ['username' => 'bobdance'])->assertOk();

    actingAsUser($jane)->getJson('/api/platforms/tiktok/selection')->assertJsonPath('selection.username', 'janedance');
    actingAsUser($bob)->getJson('/api/platforms/tiktok/selection')->assertJsonPath('selection.username', 'bobdance');

    expect(PlatformConnection::where('platform', 'tiktok')->count())->toBe(2);
});
