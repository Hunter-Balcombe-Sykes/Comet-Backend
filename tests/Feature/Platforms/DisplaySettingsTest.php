<?php

use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function displaySeedConnection(string $userId, array $payload, string $platform = 'google-business', ?array $displaySettings = null): string
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => $id,
        'user_id' => $userId,
        'platform' => $platform,
        'resource_id' => 'res-'.Str::random(6),
        'payload' => json_encode($payload),
        'display_settings' => $displaySettings !== null ? json_encode($displaySettings) : null,
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

it('returns every declared toggle defaulting ON', function () {
    $pro = createTenant('toggles-defaults');
    displaySeedConnection($pro->id, ['name' => 'Cafe']);

    $response = actingAsUser($pro)->getJson('/api/platforms/google-business/display-settings');

    $response->assertOk();
    $toggles = collect($response->json('toggles'));
    expect($toggles->pluck('key')->all())->toBe(['reviews', 'hours', 'photos', 'location', 'menu'])
        ->and($toggles->every(fn ($t) => $t['enabled'] === true))->toBeTrue();
});

it('persists a toggle flip sparsely and reports it disabled', function () {
    $pro = createTenant('toggles-flip');
    $id = displaySeedConnection($pro->id, ['name' => 'Cafe']);

    actingAsUser($pro)
        ->patchJson('/api/platforms/google-business/display-settings', [
            'toggles' => ['reviews' => false],
        ])
        ->assertOk()
        ->assertJsonPath('toggles.0.enabled', false);

    $stored = IntegrationConnection::query()->find($id)->display_settings;
    expect($stored)->toBe(['reviews' => false]);

    // Re-enabling removes the key entirely (sparse deviations only).
    actingAsUser($pro)
        ->patchJson('/api/platforms/google-business/display-settings', [
            'toggles' => ['reviews' => true],
        ])
        ->assertOk();

    expect(IntegrationConnection::query()->find($id)->display_settings)->toBeNull();
});

it('rejects unknown toggle keys and untoggleable platforms', function () {
    $pro = createTenant('toggles-unknown');
    displaySeedConnection($pro->id, ['name' => 'Cafe']);

    actingAsUser($pro)
        ->patchJson('/api/platforms/google-business/display-settings', [
            'toggles' => ['nonsense' => false],
        ])
        ->assertStatus(422);

    actingAsUser($pro)
        ->getJson('/api/platforms/spotify/display-settings')
        ->assertStatus(404);
});

it('suppresses toggled-off sections from the public integrations payload', function () {
    $pro = createTenant('toggles-public');
    displaySeedConnection($pro->id, [
        'name' => 'Cafe',
        'rating' => 4.5,
        'reviewCount' => 12,
        'reviews' => [['author' => 'A', 'text' => 'Great']],
        'hours' => ['weekdays' => ['Monday: 9–5']],
        'photos' => ['https://example.com/p.jpg'],
        'address' => '1 Test St',
    ], displaySettings: ['reviews' => false, 'photos' => false]);

    $response = actingAsUser($pro)->getJson('/api/public/profiles/'.$pro->handle.'/integrations');

    $response->assertOk();
    $body = $response->json('data.platforms.google-business.0.payload') ?? [];
    expect($body)->not->toHaveKeys(['reviews', 'reviewSummary', 'rating', 'reviewCount', 'photos'])
        ->and($body['hours'] ?? null)->not->toBeNull()
        ->and($body['address'] ?? null)->toBe('1 Test St');
});
