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
        'first_name' => ucfirst($handle),
        'account_type' => 'partna',
    ]);
}

it("returns a handle's platform connections grouped by platform", function () {
    $user = makePublicUser('jane');
    // 'custom' (not 'shop') here: this is testing the generic multi-row/ordering
    // behaviour of the grouping query, which needs a platform whose payload is
    // read verbatim. Shop's payload is FOUND-25 relational now (built from
    // shopBrands, not this row's payload), so it can't stand in for that anymore.
    IntegrationConnection::create(['user_id' => $user->id, 'platform' => 'custom', 'resource_id' => 'b1', 'payload' => ['name' => 'Store A']]);
    IntegrationConnection::create(['user_id' => $user->id, 'platform' => 'custom', 'resource_id' => 'b2', 'payload' => ['name' => 'Store B'], 'sort_order' => 1]);
    IntegrationConnection::create(['user_id' => $user->id, 'platform' => 'eventbrite', 'resource_id' => 'org1', 'payload' => ['organiser' => 'Acme']]);

    $res = $this->getJson('/api/public/profiles/jane/platforms');

    $res->assertOk();
    expect($res->json('data.platforms.custom'))->toHaveCount(2);
    expect($res->json('data.platforms.custom.0.payload.name'))->toBe('Store A'); // sort_order 0 first
    // The grouping is what this asserts, not the payload: eventbrite's public
    // allowlist emptied when slice 2 Task 9 retired the legacy events lane, so
    // the row still groups under its platform key but carries nothing.
    expect($res->json('data.platforms.eventbrite'))->toHaveCount(1);
    expect($res->json('data.platforms.eventbrite.0.payload'))->toBe([]);
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
