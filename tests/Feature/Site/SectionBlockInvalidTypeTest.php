<?php

use App\Models\Core\Site\Block;
use Illuminate\Support\Facades\Config;

/**
 * P3-21: Invalid blockType must return 422 (invalid input), not 403 (authz failure).
 * Ownership-missing still returns 404 via route model binding / 404 abort.
 */
beforeEach(function () {
    tenantHelpersEnsureTables();
    setupBlocksTable();
    shimPgAdvisoryLockForSqlite();
    Config::set('partna.section_block_types', ['newsletter', 'contact']);
});

it('returns 422 when upsert is called with an unrecognised blockType', function () {
    $pro = createTenant('section-block-422-a');

    // The FormRequest rejects unknown block_type before the controller runs,
    // so the response uses Laravel's standard validation error envelope
    // (top-level `message` + `errors` keys).
    actingAsUser($pro)
        ->putJson('/api/sections/totally-unknown-type', ['publication_state' => 'draft'])
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors']);
});

it('returns 422 when remove is called with an unrecognised blockType', function () {
    $pro = createTenant('section-block-422-b');

    actingAsUser($pro)
        ->deleteJson('/api/sections/totally-unknown-type')
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($msg) => str_contains((string) $msg, 'recognised'));
});

it('returns 422 body shape consistent with other error responses (message key present)', function () {
    $pro = createTenant('section-block-422-c');

    // The remove() path goes through the controller (no FormRequest), so the
    // ApiController::error() shape applies: {"message": "..."}
    $response = actingAsUser($pro)
        ->deleteJson('/api/sections/not-a-real-block')
        ->assertStatus(422);

    // Shape: {"message": "..."} — matches ApiController::error() envelope.
    $response->assertJsonStructure(['message']);
});

it('allows upsert for a valid blockType (sanity: 200/201 not 422)', function () {
    $pro = createTenant('section-block-valid-a');

    // newsletter is in the allowed list — must not be rejected as invalid.
    $response = actingAsUser($pro)
        ->putJson('/api/sections/newsletter', ['publication_state' => 'draft']);

    expect($response->status())->toBeIn([200, 201]);
});

it('remove() for a valid-but-absent blockType returns success, not 422 or 403', function () {
    // remove() is idempotent: if the block row doesn't exist yet, it returns
    // success with the block created by syncAllowedSections and then marked
    // draft. The important assertion is: no 422 or 403.
    $pro = createTenant('section-block-owner-a');

    actingAsUser($pro)
        ->deleteJson('/api/sections/newsletter') // valid type
        ->assertSuccessful(); // 200, not 422/403
});

it('rejects bio now that it has been removed from the section allowlist', function () {
    $pro = createTenant('section-block-bio-dead');

    actingAsUser($pro)
        ->putJson('/api/sections/bio', ['publication_state' => 'draft'])
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'errors']);
});
