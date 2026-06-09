<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function platformContractUser(string $h): User
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

// Seed a stored selection row for the given platform/payload.
function seedPlatformConnection(User $user, string $platform, array $payload, ?string $resourceId = null): IntegrationConnection
{
    return IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => $platform,
        'resource_id' => $resourceId ?? $platform,
        'payload' => $payload,
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
}

// ── Facebook / TikTok (LinkConnectionResource) ───────────────────────────────

it('facebook connect returns exactly {username,url}', function () {
    actingAsUser(platformContractUser('fb1'))
        ->postJson('/api/platforms/facebook/connect', ['username' => 'jane.doe'])
        ->assertOk()
        ->assertExactJson([
            'username' => 'jane.doe',
            'url' => 'https://www.facebook.com/jane.doe',
        ]);
});

it('facebook selection wraps the stored payload and strips unknown keys', function () {
    $user = platformContractUser('fb2');
    seedPlatformConnection($user, 'facebook', [
        'username' => 'jane.doe',
        'url' => 'https://www.facebook.com/jane.doe',
        '_internal' => 'leak', // not on the allowlist
    ]);

    actingAsUser($user)->getJson('/api/platforms/facebook/selection')
        ->assertOk()
        ->assertExactJson(['selection' => [
            'username' => 'jane.doe',
            'url' => 'https://www.facebook.com/jane.doe',
        ]]);
});

it('tiktok connect returns exactly {username,url}', function () {
    actingAsUser(platformContractUser('tk1'))
        ->postJson('/api/platforms/tiktok/connect', ['username' => '@dancer'])
        ->assertOk()
        ->assertExactJson([
            'username' => 'dancer',
            'url' => 'https://www.tiktok.com/@dancer',
        ]);
});

it('tiktok selection wraps the stored payload and strips unknown keys', function () {
    $user = platformContractUser('tk2');
    seedPlatformConnection($user, 'tiktok', [
        'username' => 'dancer',
        'url' => 'https://www.tiktok.com/@dancer',
        '_internal' => 'leak',
    ]);

    actingAsUser($user)->getJson('/api/platforms/tiktok/selection')
        ->assertOk()
        ->assertExactJson(['selection' => [
            'username' => 'dancer',
            'url' => 'https://www.tiktok.com/@dancer',
        ]]);
});
