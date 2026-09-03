<?php

use App\Catalog\CompiledCatalog;
use App\Jobs\Routing\VerifyLinkJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Routing\LinkValidity;
use App\Routing\SuggestionApplier;
use App\Routing\Verification\LinkVerifier;
use App\Routing\Verification\VerificationVerdict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * B.2 — the lane an accept takes when L1 is weak: the detector that matched
 * constrained nothing beyond the brand's domain, so the URL identifies a BRAND
 * and accepting it as-is would file the whole URL as the account's id.
 *
 * The invariant under test throughout: only a DEFINITIVE not_found refuses the
 * save. Being blocked, having no adapter, or the job dying all still connect —
 * with the flag.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

function seedWeakIntent(string $userId, array $overrides = []): string
{
    $id = (string) Str::uuid();

    DB::table('routing.source_intents')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        // doordash.order: connectable, active, url-kind, and every one of its
        // detectors matches the bare domain — the exact shape this lane exists
        // for. detector_id left null, which l1ForDetector reads as NONE, so the
        // tests that need WEAK set it explicitly.
        'surface_key' => 'doordash.order',
        'routing_class' => 'ordering',
        'identifier' => 'https://www.doordash.com/store/some-cafe',
        'canonical_url' => 'https://www.doordash.com/store/some-cafe',
        'state' => 'proposed',
        'origin' => 'bio_harvest',
        'first_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $id;
}

it('parks the intent and answers 202 rather than connecting a link it has not checked', function () {
    Queue::fake();
    $pro = createTenant('verify-park', ['account_type' => 'business', 'sector' => 'restaurant']);
    $intentId = seedWeakIntent($pro->id, ['detector_id' => weakDetectorIdFor('doordash.order')]);

    actingAsUser($pro)
        ->postJson("/api/routing/suggestions/{$intentId}/accept")
        ->assertStatus(202)
        ->assertJsonPath('status', 'verifying')
        ->assertJsonPath('connectionId', null);

    expect(DB::table('routing.source_intents')->where('id', $intentId)->value('state'))->toBe('verifying')
        ->and(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);

    Queue::assertPushed(VerifyLinkJob::class, fn ($job) => $job->intentId === $intentId);
});

it('connects with the unverified flag when the brand cannot be checked at all', function () {
    // No adapter is registered for any surface today: every brand is Class C
    // until one is written, and Class C is save-and-flag. This is the DEFAULT
    // path, which is what makes adding this lane unable to break an existing
    // link.
    $pro = createTenant('verify-classc', ['account_type' => 'business', 'sector' => 'restaurant']);
    $intentId = seedWeakIntent($pro->id, ['state' => 'verifying']);

    (new VerifyLinkJob((string) $pro->id, $intentId))->handle(app(LinkVerifier::class), app(SuggestionApplier::class));

    $connection = IntegrationConnection::query()->where('user_id', $pro->id)->first();

    expect($connection)->not->toBeNull()
        ->and($connection->verification_state)->toBe(IntegrationConnection::VERIFICATION_UNVERIFIED)
        ->and(DB::table('routing.source_intents')->where('id', $intentId)->value('state'))->toBe('applied');
});

it('connects with the verified flag when the page is found', function () {
    $pro = createTenant('verify-found', ['account_type' => 'business', 'sector' => 'restaurant']);
    $intentId = seedWeakIntent($pro->id, ['state' => 'verifying']);

    $verifier = Mockery::mock(LinkVerifier::class);
    $verifier->shouldReceive('verify')->once()->andReturn(VerificationVerdict::Found);

    (new VerifyLinkJob((string) $pro->id, $intentId))->handle($verifier, app(SuggestionApplier::class));

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->first()->verification_state)
        ->toBe(IntegrationConnection::VERIFICATION_VERIFIED);
});

it('refuses the save — and only this verdict does — when the page is definitively not there', function () {
    $pro = createTenant('verify-notfound', ['account_type' => 'business', 'sector' => 'restaurant']);
    $intentId = seedWeakIntent($pro->id, ['state' => 'verifying']);

    $verifier = Mockery::mock(LinkVerifier::class);
    $verifier->shouldReceive('verify')->once()->andReturn(VerificationVerdict::NotFound);

    (new VerifyLinkJob((string) $pro->id, $intentId))->handle($verifier, app(SuggestionApplier::class));

    $intent = DB::table('routing.source_intents')->where('id', $intentId)->first();

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0)
        ->and($intent->state)->toBe('blocked')
        ->and($intent->block_reason)->toBe('not_found')
        ->and($intent->resolved_at)->not->toBeNull();
});

it('still saves the link when the job itself dies — a dead job is not evidence against a link', function () {
    $pro = createTenant('verify-failed', ['account_type' => 'business', 'sector' => 'restaurant']);
    $intentId = seedWeakIntent($pro->id, ['state' => 'verifying']);

    (new VerifyLinkJob((string) $pro->id, $intentId))->failed(new RuntimeException('queue died'));

    $connection = IntegrationConnection::query()->where('user_id', $pro->id)->first();

    expect($connection)->not->toBeNull()
        ->and($connection->verification_state)->toBe(IntegrationConnection::VERIFICATION_UNVERIFIED);
});

it('claims the intent exactly once, so a redelivered job cannot connect twice', function () {
    $pro = createTenant('verify-once', ['account_type' => 'business', 'sector' => 'restaurant']);
    $intentId = seedWeakIntent($pro->id, ['state' => 'verifying']);

    $job = new VerifyLinkJob((string) $pro->id, $intentId);
    $job->handle(app(LinkVerifier::class), app(SuggestionApplier::class));
    $job->handle(app(LinkVerifier::class), app(SuggestionApplier::class));

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(1);
});

it('accepts a strong link straight through — no detour for a link that names an account', function () {
    Queue::fake();
    $pro = createTenant('verify-strong');
    // instagram.profile captures the handle by name, so L1 passes and the
    // accept must NOT park.
    $intentId = seedWeakIntent($pro->id, [
        'surface_key' => 'instagram.profile',
        'routing_class' => 'social',
        'identifier' => 'someone',
        'canonical_url' => 'https://www.instagram.com/someone',
    ]);

    actingAsUser($pro)
        ->postJson("/api/routing/suggestions/{$intentId}/accept")
        ->assertOk();

    Queue::assertNotPushed(VerifyLinkJob::class);
});

/** The id of one detector on this surface that constrains nothing but the host. */
function weakDetectorIdFor(string $surfaceKey): string
{
    $surface = CompiledCatalog::surface($surfaceKey);
    $detectors = CompiledCatalog::detectors();

    foreach ($surface['detectors'] as $id) {
        if (isset($detectors[$id]) && ! LinkValidity::detectorIsSpecific($detectors[$id])) {
            return $id;
        }
    }

    throw new RuntimeException("No host-only detector on {$surfaceKey} — pick another surface for this test.");
}

it('takes the same detour from the setup dialog — one rule, both accept lanes', function () {
    Queue::fake();
    $pro = createTenant('verify-setup', ['account_type' => 'business', 'sector' => 'restaurant']);
    $intentId = seedWeakIntent($pro->id, ['detector_id' => weakDetectorIdFor('doordash.order')]);

    // The dialog's Continue, not the inbox's Accept. It reports success — the
    // person's tick WAS accepted — and the pending half is our own check.
    $result = app(App\Services\Setup\SetupBatchApplier::class)->apply($pro, ['accept' => [$intentId]]);

    expect($result['errors'])->toBe([])
        ->and(DB::table('routing.source_intents')->where('id', $intentId)->value('state'))->toBe('verifying')
        ->and(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);

    Queue::assertPushed(VerifyLinkJob::class, fn ($job) => $job->intentId === $intentId);
});
