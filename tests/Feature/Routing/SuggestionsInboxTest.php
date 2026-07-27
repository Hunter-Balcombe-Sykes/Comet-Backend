<?php

use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

function seedIntent(string $userId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::table('routing.source_intents')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'surface_key' => 'instagram.profile',
        'routing_class' => 'social',
        'identifier' => 'someone',
        'canonical_url' => 'https://www.instagram.com/someone',
        'state' => 'proposed',
        'origin' => 'website_import',
        'first_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $id;
}

it('lists what the router recognised but would not act on alone', function () {
    $pro = createTenant('inbox-list');
    seedIntent($pro->id);
    seedIntent($pro->id, ['identifier' => 'other', 'state' => 'blocked', 'block_reason' => 'below_threshold']);
    // Applied and dismissed intents are settled: an inbox that re-shows them
    // is an inbox people stop reading.
    seedIntent($pro->id, ['identifier' => 'applied-one', 'state' => 'applied']);
    seedIntent($pro->id, ['identifier' => 'dismissed-one', 'state' => 'dismissed']);

    $response = actingAsUser($pro)->getJson('/api/routing/suggestions');

    $response->assertOk();
    expect($response->json('suggestions'))->toHaveCount(2);
});

it('asks the question in the user\'s words, not the reconciler\'s', function () {
    $pro = createTenant('inbox-question');
    seedIntent($pro->id, ['state' => 'blocked', 'block_reason' => 'below_threshold']);

    $response = actingAsUser($pro)->getJson('/api/routing/suggestions');

    expect($response->json('suggestions.0.question'))->toBe('Is this your Instagram?')
        ->and($response->json('suggestions.0.displayName'))->toBe('Instagram');
});

it('offers replace rather than accept when something already owns the slot', function () {
    $pro = createTenant('inbox-conflict');
    seedIntent($pro->id, [
        'surface_key' => 'opentable.reserve', 'routing_class' => 'reservations',
        'identifier' => '12345', 'state' => 'blocked', 'block_reason' => 'conflict',
        'conflicting_connection_id' => (string) Str::uuid(),
    ]);

    $response = actingAsUser($pro)->getJson('/api/routing/suggestions');

    expect($response->json('suggestions.0.actions'))->toBe(['replace', 'dismiss'])
        ->and($response->json('suggestions.0.question'))->toContain('instead?');
});

it('offers only dismissal for something the account cannot have', function () {
    $pro = createTenant('inbox-gated');
    seedIntent($pro->id, ['state' => 'blocked', 'block_reason' => 'gate']);

    expect(actingAsUser($pro)->getJson('/api/routing/suggestions')->json('suggestions.0.actions'))
        ->toBe(['dismiss']);
});

it('creates the connection when a suggestion is accepted', function () {
    $pro = createTenant('inbox-accept');
    $intentId = seedIntent($pro->id);

    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intentId}/accept")->assertOk();

    $connection = IntegrationConnection::query()->where('user_id', $pro->id)->first();
    expect($connection)->not->toBeNull()
        ->and($connection->surface_key)->toBe('instagram.profile')
        ->and($connection->resource_id)->toBe('someone');

    $intent = DB::table('routing.source_intents')->where('id', $intentId)->first();
    expect($intent->state)->toBe('applied')
        ->and($intent->connection_id)->toBe($connection->id);
});

it('demotes the incumbent rather than deleting it when replacing', function () {
    // The user asked for a different primary, not for their data to go.
    $pro = createTenant('inbox-replace');

    $incumbent = new IntegrationConnection([
        'user_id' => $pro->id, 'surface_key' => 'resdiary.reserve',
        'routing_class' => 'reservations', 'resource_id' => 'old-venue',
        'payload' => [], 'is_active' => true, 'is_primary' => true,
    ]);
    $incumbent->save();

    $intentId = seedIntent($pro->id, [
        'surface_key' => 'opentable.reserve', 'routing_class' => 'reservations',
        'identifier' => '12345', 'state' => 'blocked', 'block_reason' => 'conflict',
        'conflicting_connection_id' => $incumbent->id,
    ]);

    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intentId}/accept")->assertOk();

    expect($incumbent->fresh()->is_primary)->toBeFalse()
        ->and($incumbent->fresh()->deleted_at)->toBeNull()
        ->and(IntegrationConnection::query()->where('user_id', $pro->id)->where('is_primary', true)->value('surface_key'))
        ->toBe('opentable.reserve');
});

it('never re-asks a question the user already dismissed', function () {
    $pro = createTenant('inbox-dismiss');
    $intentId = seedIntent($pro->id);

    actingAsUser($pro)->postJson("/api/routing/suggestions/{$intentId}/dismiss")->assertOk();

    expect(DB::table('routing.source_intents')->where('id', $intentId)->value('state'))->toBe('dismissed')
        ->and(actingAsUser($pro)->getJson('/api/routing/suggestions')->json('suggestions'))->toBeEmpty()
        // The tombstone is what stops the next harvest simply proposing it again.
        ->and(DB::table('routing.item_tombstones')
            ->where('user_id', $pro->id)
            ->where('source_ref', 'instagram.profile:someone')
            ->exists())->toBeTrue();
});

it('never lets one user resolve another user\'s suggestion', function () {
    $mine = createTenant('inbox-mine');
    $theirs = createTenant('inbox-theirs');
    $intentId = seedIntent($theirs->id);

    actingAsUser($mine)->postJson("/api/routing/suggestions/{$intentId}/accept")->assertStatus(404);
    actingAsUser($mine)->postJson("/api/routing/suggestions/{$intentId}/dismiss")->assertStatus(404);

    expect(DB::table('routing.source_intents')->where('id', $intentId)->value('state'))->toBe('proposed');
});

it('requires authentication', function () {
    $this->getJson('/api/routing/suggestions')->assertStatus(401);
});
