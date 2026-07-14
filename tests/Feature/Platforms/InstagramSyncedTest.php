<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

// BE2 wire contract (FE9 builds against this verbatim):
//   GET  /api/platforms/instagram/synced       -> {"synced":[{platform,category,label,status,foundUrl,removePath}], "unmatched":[{url,label}]}
//   POST /api/platforms/instagram/synced/apply {platform} -> same payload, conflict resolved
// Mirrors GoogleBusinessController::synced()/applySync() semantics exactly.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function igSyncedUser(string $h): User
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

// ── GET /synced ────────────────────────────────────────────────────────────────

it('returns empty synced + unmatched when the user has no Instagram connection at all', function () {
    $user = igSyncedUser('igsy1');

    actingAsUser($user)->getJson('/api/platforms/instagram/synced')
        ->assertOk()
        ->assertExactJson(['synced' => [], 'unmatched' => []]);
});

it('returns empty synced + unmatched for an old connection that predates bio-sync (no syncFindings/unmatched keys at all)', function () {
    $user = igSyncedUser('igsy2');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        // Pre-BE2 shape — no website/bioLinks/syncFindings/unmatched keys.
        'payload' => ['username' => 'docpizza', 'mode' => 'automatic', 'images' => []],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->getJson('/api/platforms/instagram/synced')
        ->assertOk()
        ->assertExactJson(['synced' => [], 'unmatched' => []]);
});

it('shapes a seeded finding as synced when its connection row is ok, with the exact contract keys', function () {
    $user = igSyncedUser('igsy3');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => [
            'username' => 'docpizza', 'mode' => 'automatic', 'images' => [],
            'syncFindings' => [[
                'platform' => 'facebook', 'resourceId' => 'facebook', 'category' => 'social',
                'label' => 'Facebook', 'foundUrl' => 'https://www.facebook.com/docpizzabar',
                'outcome' => 'seeded', 'apply' => null,
            ]],
            'unmatched' => [['url' => 'https://linktr.ee/docpizza', 'label' => 'linktr.ee']],
        ],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'facebook', 'resource_id' => 'facebook',
        'payload' => ['username' => 'docpizzabar', 'url' => 'https://www.facebook.com/docpizzabar', 'source' => 'instagram'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $response = actingAsUser($user)->getJson('/api/platforms/instagram/synced')->assertOk();

    $response->assertExactJson(['synced' => [[
        'platform' => 'facebook', 'category' => 'social', 'label' => 'Facebook',
        'status' => 'synced', 'foundUrl' => 'https://www.facebook.com/docpizzabar',
        'removePath' => '/platforms/facebook',
    ]], 'unmatched' => [['url' => 'https://linktr.ee/docpizza', 'label' => 'linktr.ee']]]);
});

it('shapes a seeded finding as syncing while its connection row is still pending', function () {
    $user = igSyncedUser('igsy4');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['syncFindings' => [[
            'platform' => 'fresha', 'resourceId' => 'fresha', 'category' => 'booking',
            'label' => 'Fresha', 'foundUrl' => 'https://www.fresha.com/a/doc-cuts',
            'outcome' => 'seeded', 'apply' => null,
        ]], 'unmatched' => []],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'fresha', 'resource_id' => 'fresha',
        'payload' => ['url' => 'https://www.fresha.com/a/doc-cuts', 'source' => 'instagram'],
        'is_active' => false, 'last_refresh_status' => 'pending',
    ]);

    $synced = actingAsUser($user)->getJson('/api/platforms/instagram/synced')->assertOk()->json('synced');

    expect($synced[0]['status'])->toBe('syncing');
});

it('drops a seeded finding whose connection row the user already removed', function () {
    $user = igSyncedUser('igsy5');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['syncFindings' => [[
            'platform' => 'tiktok', 'resourceId' => 'tiktok', 'category' => 'social',
            'label' => 'TikTok', 'foundUrl' => 'https://www.tiktok.com/@docpizza',
            'outcome' => 'seeded', 'apply' => null,
        ]], 'unmatched' => []],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    // No tiktok row exists — user removed it after it seeded.

    actingAsUser($user)->getJson('/api/platforms/instagram/synced')
        ->assertOk()->assertJsonPath('synced', []);
});

it('shapes a conflict finding with a null removePath and does not require an existing row', function () {
    $user = igSyncedUser('igsy6');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['syncFindings' => [[
            'platform' => 'facebook', 'resourceId' => 'facebook', 'category' => 'social',
            'label' => 'Facebook', 'foundUrl' => 'https://www.facebook.com/docpizzabar',
            'outcome' => 'conflict',
            'apply' => ['remove' => ['facebook'], 'write' => [
                'platform' => 'facebook', 'resourceId' => 'facebook',
                'payload' => ['username' => 'docpizzabar', 'url' => 'https://www.facebook.com/docpizzabar', 'source' => 'instagram'],
            ]],
        ]], 'unmatched' => []],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'facebook', 'resource_id' => 'facebook',
        'payload' => ['username' => 'mine', 'url' => 'https://facebook.com/mine', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $synced = actingAsUser($user)->getJson('/api/platforms/instagram/synced')->assertOk()->json('synced');

    expect($synced[0])->toBe([
        'platform' => 'facebook', 'category' => 'social', 'label' => 'Facebook',
        'status' => 'conflict', 'foundUrl' => 'https://www.facebook.com/docpizzabar',
        'removePath' => null,
    ]);
});

// ── POST /synced/apply ────────────────────────────────────────────────────────

it('applySync swaps a conflict in, flips outcome to seeded, and returns fresh synced()', function () {
    $user = igSyncedUser('igsy7');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['syncFindings' => [[
            'platform' => 'facebook', 'resourceId' => 'facebook', 'category' => 'social',
            'label' => 'Facebook', 'foundUrl' => 'https://www.facebook.com/docpizzabar',
            'outcome' => 'conflict',
            'apply' => ['remove' => ['facebook'], 'write' => [
                'platform' => 'facebook', 'resourceId' => 'facebook',
                'payload' => ['username' => 'docpizzabar', 'url' => 'https://www.facebook.com/docpizzabar', 'source' => 'instagram'],
            ]],
        ]], 'unmatched' => []],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'facebook', 'resource_id' => 'facebook',
        'payload' => ['username' => 'mine', 'url' => 'https://facebook.com/mine', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $response = actingAsUser($user)->postJson('/api/platforms/instagram/synced/apply', ['platform' => 'facebook'])->assertOk();

    $response->assertJsonPath('synced.0.status', 'synced');
    $response->assertJsonPath('synced.0.platform', 'facebook');

    $fb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'facebook')->get();
    expect($fb)->toHaveCount(1);
    expect($fb->first()->payload['source'])->toBe('instagram');
    expect($fb->first()->payload['url'])->toBe('https://www.facebook.com/docpizzabar');

    // The instagram connection's persisted syncFindings reflect the flip.
    $ig = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'instagram')->firstOrFail();
    expect($ig->payload['syncFindings'][0]['outcome'])->toBe('seeded');
});

it('applySync 404s when there is nothing to change for that platform', function () {
    $user = igSyncedUser('igsy8');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['syncFindings' => [], 'unmatched' => []],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->postJson('/api/platforms/instagram/synced/apply', ['platform' => 'facebook'])
        ->assertStatus(404);
});

it('applySync 404s when the user has no Instagram connection at all', function () {
    $user = igSyncedUser('igsy9');

    actingAsUser($user)->postJson('/api/platforms/instagram/synced/apply', ['platform' => 'facebook'])
        ->assertStatus(404);
});

it('applySync rejects a platform not registered in the platform registry', function () {
    $user = igSyncedUser('igsy10');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['syncFindings' => [], 'unmatched' => []],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->postJson('/api/platforms/instagram/synced/apply', ['platform' => 'not-a-real-platform'])
        ->assertStatus(422);
});

it('preserves an unrelated existing finding when applying a swap for a different platform', function () {
    $user = igSyncedUser('igsy11');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['syncFindings' => [
            [
                'platform' => 'facebook', 'resourceId' => 'facebook', 'category' => 'social',
                'label' => 'Facebook', 'foundUrl' => 'https://www.facebook.com/docpizzabar',
                'outcome' => 'conflict',
                'apply' => ['remove' => ['facebook'], 'write' => [
                    'platform' => 'facebook', 'resourceId' => 'facebook',
                    'payload' => ['username' => 'docpizzabar', 'url' => 'https://www.facebook.com/docpizzabar', 'source' => 'instagram'],
                ]],
            ],
            [
                'platform' => 'tiktok', 'resourceId' => 'tiktok', 'category' => 'social',
                'label' => 'TikTok', 'foundUrl' => 'https://www.tiktok.com/@docpizza',
                'outcome' => 'seeded', 'apply' => null,
            ],
        ], 'unmatched' => []],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'facebook', 'resource_id' => 'facebook',
        'payload' => ['username' => 'mine', 'url' => 'https://facebook.com/mine', 'source' => 'manual'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'tiktok', 'resource_id' => 'tiktok',
        'payload' => ['username' => 'docpizza', 'url' => 'https://www.tiktok.com/@docpizza', 'source' => 'instagram'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->postJson('/api/platforms/instagram/synced/apply', ['platform' => 'facebook'])->assertOk();

    $synced = collect(actingAsUser($user)->getJson('/api/platforms/instagram/synced')->json('synced'));
    expect($synced->firstWhere('platform', 'facebook')['status'])->toBe('synced');
    expect($synced->firstWhere('platform', 'tiktok')['status'])->toBe('synced'); // untouched by the facebook swap
});
