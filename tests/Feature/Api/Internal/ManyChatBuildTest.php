<?php

use App\Models\Core\User\PreAccountBuild;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    shimPgAdvisoryLockForSqlite();
    Queue::fake();   // else GeneratePreAccountSiteJob runs inline and really scrapes
    config(['services.manychat.webhook_secret' => 'a-test-secret-value']);
});

function manychatPost(array $body = []): TestResponse
{
    return test()
        ->withHeader('X-Partna-Webhook-Secret', 'a-test-secret-value')
        ->postJson('/api/internal/webhooks/manychat/builds', $body + [
            'account_type' => 'partna',
            'source_type' => 'instagram',
            'source_ref' => 'amiconirestaurant',
            'idempotency_key' => 'manychat-sub-4471',
        ]);
}

it('creates an outreach build and returns a claim url carrying a token', function () {
    $response = manychatPost()->assertStatus(202);

    $build = PreAccountBuild::findOrFail($response->json('build_id'));

    expect($build->built_via)->toBe(PreAccountBuild::VIA_STAFF)
        ->and($build->isOutreach())->toBeTrue()
        ->and($build->claim_token_hash)->not->toBeNull();

    $claimUrl = $response->json('claim_url');
    expect($claimUrl)->toContain('/claim/')->toContain('?t=');

    parse_str((string) parse_url($claimUrl, PHP_URL_QUERY), $query);
    expect(hash('sha256', $query['t']))->toBe($build->claim_token_hash)
        ->and($query['t'])->not->toBe($build->claim_token_hash);
});

it('does NOT return a claim url when deduped by a DIFFERENT caller', function () {
    manychatPost()->assertStatus(202);

    $second = manychatPost(['idempotency_key' => 'someone-elses-key'])->assertStatus(200);

    expect($second->json('reused'))->toBeTrue()
        ->and($second->json('claim_url'))->toBeNull();
});

it('does not re-mint for a different caller on the deduped path', function () {
    $first = manychatPost()->assertStatus(202);
    $build = PreAccountBuild::findOrFail($first->json('build_id'));
    $hashAfterFirst = $build->claim_token_hash;

    manychatPost(['idempotency_key' => 'someone-elses-key'])->assertStatus(200);

    expect($build->fresh()->claim_token_hash)->toBe($hashAfterFirst);
});

it('re-mints for a retry carrying the SAME idempotency key', function () {
    $first = manychatPost()->assertStatus(202);
    $build = PreAccountBuild::findOrFail($first->json('build_id'));
    $hashAfterFirst = $build->claim_token_hash;

    $retry = manychatPost()->assertStatus(200);

    expect($retry->json('claim_url'))->not->toBeNull()
        ->and($build->fresh()->claim_token_hash)->not->toBe($hashAfterFirst);
});

it('requires account_type, source_type, source_ref and idempotency_key', function () {
    test()->withHeader('X-Partna-Webhook-Secret', 'a-test-secret-value')
        ->postJson('/api/internal/webhooks/manychat/builds', [])
        ->assertStatus(422);
});

it('requires source_name when the source is google_business', function () {
    manychatPost(['source_type' => 'google_business', 'source_ref' => 'ChIJabc'])
        ->assertStatus(422);
});

it('returns 422 with a code — not a 500 — when requestBuild rejects the input', function () {
    // Guards the EXCEPTION CONTRACT, and must stay non-vacuous. An input that
    // fails VALIDATION (e.g. a blank source_ref) never reaches the catch, so it
    // would prove nothing. 'business' + 'instagram' are each individually valid
    // enum members, so the form request passes and the PAIRING map inside
    // requestBuild is what rejects them. Asserting the `code` key is what makes
    // it non-vacuous: a validation 422 carries no top-level `code`, so this
    // fails if the catch is ever bypassed.
    manychatPost([
        'account_type' => 'business',
        'source_type' => 'instagram',
        'source_ref' => 'pairingprobehandle',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'SOURCE_PAIRING_INVALID');
});
