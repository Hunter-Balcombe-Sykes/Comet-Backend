<?php

// The two ledgers, in ONE inbox.
//
// B4 folded new-pipeline Hold intents INTO the per-platform synced modal, so a
// migrated scan path would not silently stop telling users about conflicts.
// The owner retired that modal on 2026-08-19, so the fold inverted: the
// suggestions inbox is the sink, an intent conflict is a native row, and a
// legacy `payload.syncFindings` conflict is folded in beside it.
//
// The scenarios are unchanged in substance — what appears, what does not, who
// can resolve it — but they are asserted where the question is actually asked
// now. The dedupe case matters MORE in this direction: a scan that recorded an
// intent AND left a payload finding would otherwise ask the same question
// twice, one row above the other.

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

it('shows a bio-scan Hold conflict as a Swap row', function () {
    $pro = createTenant('fold-basic');
    seedHoldConflict($pro);

    $rows = collect(actingAsUser($pro)->getJson('/api/routing/suggestions')->assertOk()->json('suggestions'));
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['surfaceKey'])->toBe('opentable.reserve')
        ->and($rows[0]['routingClass'])->toBe('reservations')
        ->and($rows[0]['blockReason'])->toBe('conflict')
        ->and($rows[0]['url'])->toBe('https://www.opentable.com/r/some-venue')
        ->and($rows[0]['actions'])->toBe(['replace', 'dismiss']);
});

it('asks once when both ledgers hold the same conflict — the intent wins', function () {
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

    $synced = collect(actingAsUser($pro)->getJson('/api/routing/suggestions')->json('suggestions'));

    // The recorded payload finding for opentable stands; only fresha folds in.
    // Two rows, not three: opentable is claimed by ONE of the ledgers, and
    // fresha (intent-only) stands on its own.
    expect($synced)->toHaveCount(2)
        ->and($synced->firstWhere('surfaceKey', 'opentable.reserve'))->not->toBeNull()
        ->and($synced->firstWhere('surfaceKey', 'fresha.book')['blockReason'])->toBe('conflict');
});

it('lists an intent from any scan origin — the inbox is not per-platform', function () {
    // website_import conflicts belong to the suggestions inbox; paste never
    // writes a Hold conflict the modal should adopt either.
    $pro = createTenant('fold-origin-scope');
    seedHoldConflict($pro, ['origin' => 'website_import']);

    expect(actingAsUser($pro)->getJson('/api/routing/suggestions')->json('suggestions'))->toHaveCount(1);
});

it('lists a proposed (below-threshold) intent as an Add, not a Swap', function () {
    $pro = createTenant('fold-proposed');
    seedHoldConflict($pro, ['state' => 'proposed', 'block_reason' => null]);
    seedHoldConflict($pro, [
        'identifier' => 'gated', 'block_reason' => 'gate',
    ]);

    // Both are listed — the inbox shows a gate refusal too, explained rather
    // than actionable — but only the proposed one offers a verb.
    $rows = collect(actingAsUser($pro)->getJson('/api/routing/suggestions')->json('suggestions'));
    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('state', 'proposed')['actions'])->toBe(['accept', 'dismiss'])
        ->and($rows->firstWhere('blockReason', 'gate')['actions'])->toBe(['dismiss']);
});

it('applies a conflict on accept — demote, connect, settle', function () {
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

    $response = actingAsUser($pro)->postJson("/api/routing/suggestions/{$intentId}/accept");

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

    // The settled conflict leaves the inbox.
    expect(actingAsUser($pro)->getJson('/api/routing/suggestions')->json('suggestions'))->toBeEmpty();
});

it('still 404s an accept when neither ledger has a conflict for the platform', function () {
    $pro = createTenant('fold-apply-missing');

    actingAsUser($pro)->postJson('/api/routing/suggestions/sync:instagram:opentable/accept')
        ->assertStatus(404);
    actingAsUser($pro)->postJson('/api/routing/suggestions/'.Str::uuid().'/accept')
        ->assertStatus(404);
});

it('never shows or resolves another user\'s conflicts', function () {
    $mine = createTenant('fold-mine');
    $theirs = createTenant('fold-theirs');
    $theirIntent = seedHoldConflict($theirs);

    expect(actingAsUser($mine)->getJson('/api/routing/suggestions')->json('suggestions'))->toBeEmpty();

    actingAsUser($mine)->postJson("/api/routing/suggestions/{$theirIntent}/accept")
        ->assertStatus(404);
    expect(DB::table('routing.source_intents')->where('user_id', $theirs->id)->value('state'))->toBe('blocked');
});
