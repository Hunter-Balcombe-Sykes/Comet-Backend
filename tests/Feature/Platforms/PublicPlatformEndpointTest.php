<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function makePublicUser(string $handle): User
{
    return User::create([
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'account_type' => 'individual',
    ]);
}

it("returns a handle's platform connections grouped by platform", function () {
    $user = makePublicUser('jane');
    IntegrationConnection::create(['user_id' => $user->id, 'platform' => 'shopify', 'resource_id' => 'b1', 'payload' => ['name' => 'Store A']]);
    IntegrationConnection::create(['user_id' => $user->id, 'platform' => 'shopify', 'resource_id' => 'b2', 'payload' => ['name' => 'Store B'], 'sort_order' => 1]);
    IntegrationConnection::create(['user_id' => $user->id, 'platform' => 'eventbrite', 'resource_id' => 'org1', 'payload' => ['organiser' => 'Acme']]);

    $res = $this->getJson('/api/public/profiles/jane/platforms');

    $res->assertOk();
    expect($res->json('data.platforms.shopify'))->toHaveCount(2);
    expect($res->json('data.platforms.shopify.0.payload.name'))->toBe('Store A'); // sort_order 0 first
    expect($res->json('data.platforms.eventbrite.0.payload.organiser'))->toBe('Acme');
});

it('404s an unknown handle (no existence leak)', function () {
    $this->getJson('/api/public/profiles/nobody/platforms')->assertNotFound();
});

it('excludes inactive and soft-deleted connections', function () {
    $user = makePublicUser('sam');
    IntegrationConnection::create(['user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'a', 'payload' => [], 'is_active' => true]);
    IntegrationConnection::create(['user_id' => $user->id, 'platform' => 'youtube', 'resource_id' => 'b', 'payload' => [], 'is_active' => false]);
    $deleted = IntegrationConnection::create(['user_id' => $user->id, 'platform' => 'tiktok', 'resource_id' => 'c', 'payload' => []]);
    $deleted->delete();

    $res = $this->getJson('/api/public/profiles/sam/platforms');

    $res->assertOk();
    expect($res->json('data.platforms.youtube'))->toHaveCount(1);
    expect($res->json('data.platforms'))->not->toHaveKey('tiktok');
});
