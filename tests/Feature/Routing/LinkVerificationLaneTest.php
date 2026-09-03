<?php

use App\Jobs\Routing\VerifyLinkJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Routing\SuggestionApplier;
use App\Routing\Verification\LinkVerifier;
use App\Routing\Verification\VerificationVerdict;
use App\Services\Setup\SetupBatchApplier;
use App\Services\Setup\SetupPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * B.2 — the lane an accept takes when the brand is one we can actually CHECK.
 *
 * The trigger is "is there an adapter for this surface", not "is L1 weak". L1
 * says the URL is SHAPED like an account page, which a fabricated id also is:
 * quandoo.com/place/not-a-place-99999999 passes L1 and 404s. And since
 * PlacementPolicy's Gate 3 turns a weak match into a Note before any intent is
 * written, an L1-gated detour would never fire at all.
 *
 * The invariant under test throughout: only a DEFINITIVE not_found refuses the
 * save. Being blocked, having no adapter, or the job dying all still connect —
 * with the flag. That asymmetry is what makes this lane unable to cost anyone
 * a link.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    // SetupPayload reads across the whole setup surface, so the wire assertion
    // at the bottom of this file needs the same DDL chain SetupControllerTest
    // provisions — not just the routing tables the lane itself touches.
    setupContentTables();
    setupIngestTables();
    setupPreAccountBuildsTable();
    setupPreAccountBuildEventsTable();
});

function seedLaneIntent(string $userId, array $overrides = []): string
{
    $id = (string) Str::uuid();

    DB::table('routing.source_intents')->insert(array_merge([
        // doordash.order by DEFAULT, and deliberately: it has no adapter, so
        // the job-level tests below exercise the verifier without any test
        // reaching the network. The two trigger tests override this to a brand
        // that DOES have one (and fake the queue, so they do not fetch either).
        'id' => $id,
        'user_id' => $userId,
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
    $intentId = seedLaneIntent($pro->id, verifiableLaneIntent());

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
    // doordash.order has no adapter — Class C, save-and-flag. Most brands are,
    // and this is the path that makes adding this lane unable to break an
    // existing link: a brand we have not taught it about behaves exactly as it
    // did before, plus a flag.
    $pro = createTenant('verify-classc', ['account_type' => 'business', 'sector' => 'restaurant']);
    $intentId = seedLaneIntent($pro->id, ['state' => 'verifying']);

    (new VerifyLinkJob((string) $pro->id, $intentId))->handle(app(LinkVerifier::class), app(SuggestionApplier::class));

    $connection = IntegrationConnection::query()->where('user_id', $pro->id)->first();

    expect($connection)->not->toBeNull()
        ->and($connection->verification_state)->toBe(IntegrationConnection::VERIFICATION_UNVERIFIED)
        ->and(DB::table('routing.source_intents')->where('id', $intentId)->value('state'))->toBe('applied');
});

it('connects with the verified flag when the page is found', function () {
    $pro = createTenant('verify-found', ['account_type' => 'business', 'sector' => 'restaurant']);
    $intentId = seedLaneIntent($pro->id, ['state' => 'verifying']);

    $verifier = Mockery::mock(LinkVerifier::class);
    $verifier->shouldReceive('verify')->once()->andReturn(VerificationVerdict::Found);

    (new VerifyLinkJob((string) $pro->id, $intentId))->handle($verifier, app(SuggestionApplier::class));

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->first()->verification_state)
        ->toBe(IntegrationConnection::VERIFICATION_VERIFIED);
});

it('refuses the save — and only this verdict does — when the page is definitively not there', function () {
    $pro = createTenant('verify-notfound', ['account_type' => 'business', 'sector' => 'restaurant']);
    $intentId = seedLaneIntent($pro->id, ['state' => 'verifying']);

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
    $intentId = seedLaneIntent($pro->id, ['state' => 'verifying']);

    (new VerifyLinkJob((string) $pro->id, $intentId))->failed(new RuntimeException('queue died'));

    $connection = IntegrationConnection::query()->where('user_id', $pro->id)->first();

    expect($connection)->not->toBeNull()
        ->and($connection->verification_state)->toBe(IntegrationConnection::VERIFICATION_UNVERIFIED);
});

it('claims the intent exactly once, so a redelivered job cannot connect twice', function () {
    $pro = createTenant('verify-once', ['account_type' => 'business', 'sector' => 'restaurant']);
    $intentId = seedLaneIntent($pro->id, ['state' => 'verifying']);

    $job = new VerifyLinkJob((string) $pro->id, $intentId);
    $job->handle(app(LinkVerifier::class), app(SuggestionApplier::class));
    $job->handle(app(LinkVerifier::class), app(SuggestionApplier::class));

    expect(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(1);
});

it('accepts straight through when there is nothing that could check the brand', function () {
    Queue::fake();
    $pro = createTenant('verify-strong');
    // instagram.profile is Class C — it answers 200 for fabricated handles, so
    // there is no adapter and no question worth parking the accept for.
    $intentId = seedLaneIntent($pro->id, [
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

/** An intent on a brand the verifier actually has an adapter for. */
function verifiableLaneIntent(): array
{
    expect(app(LinkVerifier::class)->canVerify('quandoo.reserve'))->toBeTrue();

    return [
        'surface_key' => 'quandoo.reserve',
        'routing_class' => 'reservations',
        'identifier' => '92706',
        'canonical_url' => 'https://www.quandoo.com.au/place/ricks-place-92706',
    ];
}

it('takes the same detour from the setup dialog — one rule, both accept lanes', function () {
    Queue::fake();
    $pro = createTenant('verify-setup', ['account_type' => 'business', 'sector' => 'restaurant']);
    $intentId = seedLaneIntent($pro->id, verifiableLaneIntent());

    // The dialog's Continue, not the inbox's Accept. It reports success — the
    // person's tick WAS accepted — and the pending half is our own check.
    $result = app(SetupBatchApplier::class)->apply($pro, ['accept' => [$intentId]]);

    expect($result['errors'])->toBe([])
        ->and(DB::table('routing.source_intents')->where('id', $intentId)->value('state'))->toBe('verifying')
        ->and(IntegrationConnection::query()->where('user_id', $pro->id)->count())->toBe(0);

    Queue::assertPushed(VerifyLinkJob::class, fn ($job) => $job->intentId === $intentId);
});

it('tells the setup dialog a row is being checked, ticked and locked', function () {
    Queue::fake();
    $pro = createTenant('verify-wire', ['account_type' => 'business', 'sector' => 'restaurant']);
    $intentId = seedLaneIntent($pro->id, array_merge(verifiableLaneIntent(), ['state' => 'verifying']));

    $row = collect(app(SetupPayload::class)->for($pro)['passes'] ?? [])
        ->flatMap(fn (array $pass) => $pass['suggestions'] ?? [])
        ->firstWhere('id', $intentId);

    // Without this the row comes back from Continue looking exactly as it did
    // before the tick, which reads as "nothing happened" — the person answered
    // and the wait is OURS.
    expect($row)->not->toBeNull()
        ->and($row['verifying'])->toBeTrue()
        ->and($row['preselected'])->toBeTrue()
        // Neither verb is offered while the queue holds it: accept would
        // re-park it and dismiss would race the job that is answering.
        ->and($row['actions'])->toBe([]);
});
