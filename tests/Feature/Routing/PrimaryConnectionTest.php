<?php

// #26 primary-CTA API: per-class is_primary on connection reads plus
// POST /routing/connections/{id}/primary — the backend the SetPrimarySheet
// is gated on. One primary per (user, routing_class) is a DB invariant
// (idx_platform_connections_primary_per_class), so the write must demote the
// incumbent in the same transaction it promotes the target.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function seedConnection(User $user, array $overrides = []): IntegrationConnection
{
    $connection = new IntegrationConnection(array_merge([
        'surface_key' => 'fresha.book',
        'routing_class' => 'booking',
        'resource_id' => 'salon-'.Str::random(6),
        'payload' => ['url' => 'https://www.fresha.com/a/salon'],
        'is_active' => true,
        'is_primary' => false,
    ], $overrides));
    $connection->user_id = $user->id;
    $connection->save();

    return $connection;
}

it('exposes routingClass and isPrimary on every connection read', function () {
    $pro = createTenant('primary-read');
    $primary = seedConnection($pro, ['is_primary' => true]);
    seedConnection($pro, ['surface_key' => 'square.book', 'resource_id' => 'sq-1']);
    seedConnection($pro, ['surface_key' => 'instagram.profile', 'routing_class' => 'social', 'resource_id' => 'me']);

    $response = actingAsUser($pro)->getJson('/api/routing/connections');

    $response->assertOk();
    $connections = collect($response->json('connections'));
    expect($connections)->toHaveCount(3)
        ->and($connections->firstWhere('id', $primary->id)['isPrimary'])->toBeTrue()
        ->and($connections->firstWhere('id', $primary->id)['routingClass'])->toBe('booking')
        ->and($connections->where('isPrimary', true))->toHaveCount(1)
        // The per-class map answers "who owns the CTA" without a client-side scan.
        ->and($response->json('primary.booking'))->toBe($primary->id);
});

it('never leaks another user\'s connections into the read', function () {
    $mine = createTenant('primary-read-mine');
    $theirs = createTenant('primary-read-theirs');
    seedConnection($theirs, ['is_primary' => true]);

    expect(actingAsUser($mine)->getJson('/api/routing/connections')->json('connections'))->toBeEmpty();
});

it('sets a connection primary and demotes the incumbent in the same class', function () {
    $pro = createTenant('primary-swap');
    $incumbent = seedConnection($pro, ['is_primary' => true]);
    $challenger = seedConnection($pro, ['surface_key' => 'square.book', 'resource_id' => 'sq-2']);

    $response = actingAsUser($pro)->postJson("/api/routing/connections/{$challenger->id}/primary");

    $response->assertOk();
    expect($response->json('connection.isPrimary'))->toBeTrue()
        ->and($response->json('demotedConnectionId'))->toBe($incumbent->id)
        ->and($challenger->fresh()->is_primary)->toBeTrue()
        // Demoted, not deleted: the user asked for a different CTA, not for
        // their data to go.
        ->and($incumbent->fresh()->is_primary)->toBeFalse()
        ->and($incumbent->fresh()->deleted_at)->toBeNull();
});

it('leaves other classes\' primaries alone', function () {
    $pro = createTenant('primary-scoped');
    $socialPrimary = seedConnection($pro, [
        'surface_key' => 'instagram.profile', 'routing_class' => 'social',
        'resource_id' => 'me', 'is_primary' => true,
    ]);
    $booking = seedConnection($pro);

    actingAsUser($pro)->postJson("/api/routing/connections/{$booking->id}/primary")->assertOk();

    expect($socialPrimary->fresh()->is_primary)->toBeTrue()
        ->and($booking->fresh()->is_primary)->toBeTrue();
});

it('is idempotent when the connection is already primary', function () {
    $pro = createTenant('primary-idempotent');
    $connection = seedConnection($pro, ['is_primary' => true]);

    $response = actingAsUser($pro)->postJson("/api/routing/connections/{$connection->id}/primary");

    $response->assertOk();
    expect($response->json('demotedConnectionId'))->toBeNull()
        ->and($connection->fresh()->is_primary)->toBeTrue();
});

it('404s another user\'s connection rather than revealing it exists', function () {
    $mine = createTenant('primary-mine');
    $theirs = createTenant('primary-theirs');
    $connection = seedConnection($theirs);

    actingAsUser($mine)->postJson("/api/routing/connections/{$connection->id}/primary")->assertStatus(404);

    expect($connection->fresh()->is_primary)->toBeFalse();
});

it('404s a connection that does not exist', function () {
    $pro = createTenant('primary-missing');

    actingAsUser($pro)->postJson('/api/routing/connections/'.Str::uuid().'/primary')->assertStatus(404);
});

it('404s a soft-deleted connection', function () {
    $pro = createTenant('primary-deleted');
    $connection = seedConnection($pro);
    $connection->delete();

    actingAsUser($pro)->postJson("/api/routing/connections/{$connection->id}/primary")->assertStatus(404);
});

it('requires authentication', function () {
    $this->getJson('/api/routing/connections')->assertStatus(401);
    $this->postJson('/api/routing/connections/'.Str::uuid().'/primary')->assertStatus(401);
});
