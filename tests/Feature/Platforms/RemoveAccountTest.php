<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;

// DELETE /api/platforms/{platform}/accounts/{id} — the one multi-account
// endpoint with no direct HTTP coverage before the connect convergence.
// Twitch reads are already registry-driven, so this pins GenericPlatformController.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

it('removes one twitch account and returns the remaining list', function () {
    $user = User::factory()->create();

    foreach ([['a', 'AlphaChan'], ['b', 'BetaChan']] as [$suffix, $name]) {
        IntegrationConnection::create([
            'user_id' => $user->id,
            'platform' => 'twitch',
            'resource_id' => "acct-{$suffix}0000000000000000",
            'payload' => ['url' => "https://www.twitch.tv/{$suffix}", 'login' => $suffix, 'name' => $name, 'image' => null, 'description' => null],
            'is_active' => true,
        ]);
    }

    $res = actingAsUser($user)->deleteJson('/api/platforms/twitch/accounts/acct-a0000000000000000');

    $res->assertOk();
    expect(collect($res->json('accounts'))->pluck('id')->all())->toBe(['acct-b0000000000000000']);
});

it('404s when removing an account the user does not own', function () {
    $user = User::factory()->create();

    $res = actingAsUser($user)->deleteJson('/api/platforms/twitch/accounts/acct-missing000000000');

    $res->assertNotFound();
});
