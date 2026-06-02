<?php

use App\Models\Core\Site\PlatformConnection;
use App\Models\Core\User\User;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable(); // also creates site.platform_connections
});

function makePlatformUser(string $handle = 'jane'): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => $handle,
        'display_name' => ucfirst($handle),
        'account_type' => 'individual',
    ]);
}

it('persists a per-user platform connection with a jsonb payload', function () {
    $user = makePlatformUser();

    $conn = PlatformConnection::create([
        'user_id' => $user->id,
        'platform' => 'shopify',
        'resource_id' => 'brand-abc',
        'payload' => ['brandName' => 'Acme', 'productIds' => ['p1', 'p2']],
        'last_refresh_status' => 'ok',
    ]);

    $fresh = PlatformConnection::find($conn->id);

    expect($fresh->payload)->toBe(['brandName' => 'Acme', 'productIds' => ['p1', 'p2']]);
    expect($fresh->is_active)->toBeTrue();
    expect($fresh->consecutive_failures)->toBe(0);
    expect($fresh->user->is($user))->toBeTrue();
    expect($user->platformConnections()->count())->toBe(1);
});

it('soft deletes a connection', function () {
    $user = makePlatformUser('sam');
    $conn = PlatformConnection::create([
        'user_id' => $user->id,
        'platform' => 'stan',
        'resource_id' => 'sam-store',
        'payload' => [],
    ]);

    $conn->delete();

    expect(PlatformConnection::find($conn->id))->toBeNull();
    expect(PlatformConnection::withTrashed()->find($conn->id))->not->toBeNull();
});

it('scopes to active connections', function () {
    $user = makePlatformUser('lee');
    PlatformConnection::create(['user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'a', 'payload' => [], 'is_active' => true]);
    PlatformConnection::create(['user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'b', 'payload' => [], 'is_active' => false]);

    expect(PlatformConnection::active()->count())->toBe(1);
});
