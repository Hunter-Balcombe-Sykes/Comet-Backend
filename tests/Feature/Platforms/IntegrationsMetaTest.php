<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function integrationsMetaUser(string $h): User
{
    return User::create([
        'handle' => $h, 'handle_lc' => strtolower($h), 'display_name' => ucfirst($h),
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('returns sync metadata per platform in one call', function () {
    $user = integrationsMetaUser('metauser');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'chan-1',
        'payload' => ['handle' => 'metauser'], 'is_active' => true,
        'last_refreshed_at' => now()->subHours(2), 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'spotify', 'resource_id' => 'artist-1',
        'payload' => ['name' => 'Meta'], 'is_active' => true,
        'last_refresh_status' => 'error', 'last_refresh_error' => 'internal scraper detail',
    ]);

    $response = actingAsUser($user)->getJson('/api/platforms/meta')
        ->assertOk()
        ->assertJsonPath('platforms.youtube.last_refresh_status', 'ok')
        ->assertJsonPath('platforms.youtube.has_refresh_error', false)
        ->assertJsonPath('platforms.spotify.has_refresh_error', true);

    // Error text stays server-side — only the boolean/status crosses the API.
    expect($response->json('platforms.spotify'))->not->toHaveKey('last_refresh_error');
});

it('keeps the most recently refreshed row per platform', function () {
    $user = integrationsMetaUser('multirow');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'store-old',
        'payload' => ['name' => 'Old'], 'is_active' => true,
        'last_refreshed_at' => now()->subDays(3), 'last_refresh_status' => 'error',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'store-new',
        'payload' => ['name' => 'New'], 'is_active' => true,
        'last_refreshed_at' => now()->subHour(), 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->getJson('/api/platforms/meta')
        ->assertOk()
        ->assertJsonPath('platforms.shop.last_refresh_status', 'ok');
});

// SEM-1: two never-refreshed connections on the same multi-account platform
// (e.g. two YouTube channels) both land in the NULLS-LAST tail with no
// timestamp to order by. The old code kept an arbitrary "first" row, so
// has_refresh_error could flip between requests depending on scan order.
// The fix aggregates ANY-across-connections for is_active/has_refresh_error
// and MAX for last_refreshed_at, so the result no longer depends on which
// row the DB happens to return first.
it('deterministically aggregates status across tied never-refreshed connections', function () {
    $user = integrationsMetaUser('multiacct');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'chan-a',
        'payload' => ['handle' => 'a'], 'is_active' => true,
        'last_refreshed_at' => null, 'last_refresh_status' => null,
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'chan-b',
        'payload' => ['handle' => 'b'], 'is_active' => false,
        'last_refreshed_at' => null, 'last_refresh_status' => 'error',
    ]);

    // Run it a few times — with the old first-row-wins logic this would be
    // nondeterministic; the aggregate must be stable every time.
    foreach (range(1, 3) as $_) {
        actingAsUser($user)->getJson('/api/platforms/meta')
            ->assertOk()
            ->assertJsonPath('platforms.youtube.is_active', true) // ANY: chan-a is active
            ->assertJsonPath('platforms.youtube.has_refresh_error', true) // ANY: chan-b errored
            ->assertJsonPath('platforms.youtube.last_refreshed_at', null); // MAX of two nulls
    }
});

it('scopes metadata to the authenticated user', function () {
    $user = integrationsMetaUser('lonely');
    $other = integrationsMetaUser('busy');
    IntegrationConnection::create([
        'user_id' => $other->id, 'platform' => 'youtube', 'resource_id' => 'chan-x',
        'payload' => ['handle' => 'busy'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $response = actingAsUser($user)->getJson('/api/platforms/meta')->assertOk();

    expect($response->json('platforms'))->toBe([]);
});
