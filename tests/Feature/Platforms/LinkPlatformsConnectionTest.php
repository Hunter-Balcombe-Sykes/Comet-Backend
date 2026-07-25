<?php

use App\Models\Core\Site\IntegrationConnection;
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
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
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

    $conn = IntegrationConnection::where('user_id', $user->id)->where('platform', 'tiktok')->first();
    expect($conn->payload['username'])->toBe('dancer');
});

it('connects a Facebook legacy page link scoped to the authenticated user', function () {
    $user = makeLinkUser('fb');

    actingAsUser($user)->postJson('/api/platforms/facebook/connect', ['username' => 'https://www.facebook.com/pages/Some-Cafe/123'])
        ->assertOk()
        ->assertJsonPath('url', 'https://www.facebook.com/pages/Some-Cafe/123');

    expect(IntegrationConnection::where('user_id', $user->id)->where('platform', 'facebook')->exists())->toBeTrue();
});

it("keeps two users' connections separate (per-user isolation)", function () {
    $jane = makeLinkUser('jane');
    $bob = makeLinkUser('bob');

    actingAsUser($jane)->postJson('/api/platforms/tiktok/connect', ['username' => 'janedance'])->assertOk();
    actingAsUser($bob)->postJson('/api/platforms/tiktok/connect', ['username' => 'bobdance'])->assertOk();

    actingAsUser($jane)->getJson('/api/platforms/tiktok/selection')->assertJsonPath('selection.username', 'janedance');
    actingAsUser($bob)->getJson('/api/platforms/tiktok/selection')->assertJsonPath('selection.username', 'bobdance');

    expect(IntegrationConnection::where('platform', 'tiktok')->count())->toBe(2);
});

it('connects each of the five 2026-07-23 link-only platforms and exposes username+url on the public endpoint (TEST-3)', function () {
    // Closes the normalizer↔allowlist loop: this is the test that would catch a
    // key-name mismatch between a normalizer's output and
    // PublicIntegrationConnectionResource::ALLOWLIST — the exact failure class
    // TEST-3 was about. Inputs satisfy each normalizer's own regex (Telegram's
    // minimum is 5 chars, the others 2-3).
    $user = makeLinkUser('link5');
    $inputs = [
        'snapchat' => 'snapper',
        'discord' => 'abc123',
        'telegram' => 'tguser',
        'kick' => 'kicker',
        'medium' => 'writer',
    ];
    $expectedUrls = [
        'snapchat' => 'https://snapchat.com/add/snapper',
        'discord' => 'https://discord.gg/abc123',
        'telegram' => 'https://t.me/tguser',
        'kick' => 'https://kick.com/kicker',
        'medium' => 'https://medium.com/@writer',
    ];

    foreach ($inputs as $platform => $input) {
        actingAsUser($user)->postJson("/api/platforms/{$platform}/connect", ['username' => $input])
            ->assertOk()
            ->assertJsonPath('username', $input)
            ->assertJsonPath('url', $expectedUrls[$platform]);
    }

    $platforms = $this->getJson("/api/public/profiles/{$user->handle}/integrations")
        ->assertOk()
        ->json('data.platforms');

    foreach ($inputs as $platform => $input) {
        expect($platforms[$platform][0]['payload'])->toBe([
            'username' => $input,
            'url' => $expectedUrls[$platform],
        ]);
    }
});
