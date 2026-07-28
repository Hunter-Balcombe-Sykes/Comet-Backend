<?php

// B4 (p8 deletion readiness, blocker 4): the findings / synced-modal contract.
//
// The new router records a scan-discovered conflict as a HOLD intent, but the
// dashboard's Instagram modal reads GET /platforms/instagram/synced. These
// tests pin the fold: Hold intents from the bio-scan origins appear in that
// response shaped as legacy conflict findings, and the modal's existing
// "Change to" swap (POST /synced/apply) resolves them through the same
// transaction the suggestions inbox uses. Without this, LinkInBioScanJob and
// InstagramAutoSync would migrate "successfully" and silently stop telling
// users about conflicts.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

function seedHoldConflict(User $user, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::table('routing.source_intents')->insert(array_merge([
        'id' => $id,
        'user_id' => $user->id,
        'surface_key' => 'opentable.reserve',
        'routing_class' => 'reservations',
        'identifier' => '12345',
        'canonical_url' => 'https://www.opentable.com/r/some-venue',
        'state' => 'blocked',
        'block_reason' => 'conflict',
        'origin' => 'bio_harvest',
        'first_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $id;
}

it('folds a bio-scan Hold conflict into the synced-modal response', function () {
    $pro = createTenant('fold-basic');
    seedHoldConflict($pro);

    $response = actingAsUser($pro)->getJson('/api/platforms/instagram/synced');

    $response->assertOk();
    $synced = collect($response->json('synced'));
    expect($synced)->toHaveCount(1)
        ->and($synced[0]['platform'])->toBe('opentable')
        ->and($synced[0]['category'])->toBe('reservations')
        ->and($synced[0]['status'])->toBe('conflict')
        ->and($synced[0]['foundUrl'])->toBe('https://www.opentable.com/r/some-venue')
        ->and($synced[0]['removePath'])->toBeNull();
});

it('keeps legacy payload findings first and dedupes the fold by platform', function () {
    $pro = createTenant('fold-dedupe');
    $ig = new IntegrationConnection([
        'surface_key' => 'instagram.profile',
        'routing_class' => 'social',
        'resource_id' => 'me',
        'payload' => [
            'username' => 'me',
            'syncFindings' => [[
                'platform' => 'opentable', 'category' => 'reservations', 'label' => 'OpenTable',
                'outcome' => 'conflict', 'foundUrl' => 'https://www.opentable.com/r/legacy-venue',
            ]],
        ],
        'is_active' => true,
    ]);
    $ig->user_id = $pro->id;
    $ig->save();

    seedHoldConflict($pro);
    seedHoldConflict($pro, [
        'surface_key' => 'fresha.book', 'routing_class' => 'booking',
        'identifier' => 'salon', 'canonical_url' => 'https://www.fresha.com/a/salon',
    ]);

    $synced = collect(actingAsUser($pro)->getJson('/api/platforms/instagram/synced')->json('synced'));

    // The recorded payload finding for opentable stands; only fresha folds in.
    expect($synced)->toHaveCount(2)
        ->and($synced->firstWhere('platform', 'opentable')['foundUrl'])->toBe('https://www.opentable.com/r/legacy-venue')
        ->and($synced->firstWhere('platform', 'fresha')['status'])->toBe('conflict');
});

it('does not fold intents from origins other scan surfaces own', function () {
    // website_import conflicts belong to the suggestions inbox; paste never
    // writes a Hold conflict the modal should adopt either.
    $pro = createTenant('fold-origin-scope');
    seedHoldConflict($pro, ['origin' => 'website_import']);

    expect(actingAsUser($pro)->getJson('/api/platforms/instagram/synced')->json('synced'))->toBeEmpty();
});

it('does not fold proposed (below-threshold) intents — the inbox owns those', function () {
    $pro = createTenant('fold-proposed');
    seedHoldConflict($pro, ['state' => 'proposed', 'block_reason' => null]);
    seedHoldConflict($pro, [
        'identifier' => 'gated', 'block_reason' => 'gate',
    ]);

    expect(actingAsUser($pro)->getJson('/api/platforms/instagram/synced')->json('synced'))->toBeEmpty();
});

it('applies a folded conflict through synced/apply — demote, connect, settle', function () {
    $pro = createTenant('fold-apply');

    $incumbent = new IntegrationConnection([
        'surface_key' => 'resdiary.reserve',
        'routing_class' => 'reservations',
        'resource_id' => 'old-venue',
        'payload' => [],
        'is_active' => true,
        'is_primary' => true,
    ]);
    $incumbent->user_id = $pro->id;
    $incumbent->save();

    $intentId = seedHoldConflict($pro, ['conflicting_connection_id' => $incumbent->id]);

    $response = actingAsUser($pro)->postJson('/api/platforms/instagram/synced/apply', ['platform' => 'opentable']);

    $response->assertOk();

    $created = IntegrationConnection::query()
        ->where('user_id', $pro->id)
        ->where('surface_key', 'opentable.reserve')
        ->first();
    expect($created)->not->toBeNull()
        ->and($created->is_primary)->toBeTrue()
        // Demoted, not deleted — the user asked for a different primary.
        ->and($incumbent->fresh()->is_primary)->toBeFalse()
        ->and($incumbent->fresh()->deleted_at)->toBeNull();

    $intent = DB::table('routing.source_intents')->where('id', $intentId)->first();
    expect($intent->state)->toBe('applied')
        ->and($intent->connection_id)->toBe($created->id);

    // The settled conflict leaves the modal's list.
    expect(collect($response->json('synced'))->where('status', 'conflict'))->toBeEmpty();
});

it('still 404s synced/apply when neither ledger has a conflict for the platform', function () {
    $pro = createTenant('fold-apply-missing');

    actingAsUser($pro)->postJson('/api/platforms/instagram/synced/apply', ['platform' => 'opentable'])
        ->assertStatus(404);
});

it('never folds another user\'s conflicts', function () {
    $mine = createTenant('fold-mine');
    $theirs = createTenant('fold-theirs');
    seedHoldConflict($theirs);

    expect(actingAsUser($mine)->getJson('/api/platforms/instagram/synced')->json('synced'))->toBeEmpty();

    actingAsUser($mine)->postJson('/api/platforms/instagram/synced/apply', ['platform' => 'opentable'])
        ->assertStatus(404);
    expect(DB::table('routing.source_intents')->where('user_id', $theirs->id)->value('state'))->toBe('blocked');
});
