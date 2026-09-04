<?php

// #DRIFT-1: PlacementPolicy::capabilityDenial() (record time) and
// SuggestionApplier::apply() (apply time) used to carry their own copy of the
// same booking/reservations/ordering match-arms, with a comment ("change
// BOTH or they drift") as the only thing stopping divergence. Both now
// delegate to RoutingCapabilityGate; this file exercises the two ORIGINAL
// public entry points — PlacementPolicy::decide() and SuggestionApplier::
// apply() — so a future edit that inlines a new arm directly into one class
// (bypassing the shared gate) still fails here, not just a unit test on the
// gate class itself.
//
// The fixture trap this guards against: createTenant() defaults to
// account_type 'partna', and for a partna account can_use_booking /
// can_use_reservations are unconditionally true — the denial branches for
// those two classes are unreachable with the default fixture. Every case
// below uses an explicit account_type + sector chosen so the branch under
// test actually fires.

use App\Catalog\CompiledCatalog;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Routing\LinkValidity;
use App\Routing\PlacementPolicy;
use App\Routing\Projection;
use App\Routing\RoutingContext;
use App\Routing\SuggestionApplier;
use App\Routing\Verdict;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    AccountCapabilities::flushCache();
});

/** A real catalog surface per routing_class under test, so decide() and apply() both see production shapes. */
function cgpSurfaceKeyFor(string $routingClass): string
{
    return match ($routingClass) {
        'booking' => 'fresha.book',
        'reservations' => 'opentable.reserve',
        'ordering' => 'deliveroo.order',
        // Not one of the gated classes — the default `=> null` arm. A real
        // surface (instagram.profile) whose actual routing_class is 'social'.
        default => 'instagram.profile',
    };
}

/**
 * A REAL, specific detector id belonging to that surface.
 *
 * The synthetic 'cgp-test-detector' this used to pass stopped working on
 * 2026-09-03, and correctly: PlacementPolicy's Gate 3 asks whether the matched
 * rule identified an account, LinkValidity treats an id the catalog does not
 * know as WEAK on purpose ("a detector that no longer exists cannot vouch for
 * anything"), and a weak match on a shaped surface is now a Note. The fix is
 * not to exempt the test — it is to hand decide() the kind of projection it
 * actually receives, which is what the rest of this fixture already aims for.
 */
function cgpDetectorIdFor(string $surfaceKey): string
{
    $detectors = CompiledCatalog::detectors();
    foreach (CompiledCatalog::surface($surfaceKey)['detectors'] as $id) {
        if (isset($detectors[$id]) && LinkValidity::detectorIsSpecific($detectors[$id])) {
            return (string) $id;
        }
    }

    throw new RuntimeException("No specific detector for {$surfaceKey} — catalog fixture drifted.");
}

/**
 * Insert a routing.source_intents row shaped like SuggestionsController's
 * seedIntent() (SuggestionsInboxTest.php), under a different name so both
 * files can load in the same process without a redeclare fatal.
 */
function cgpSeedIntent(User $user, string $routingClass, string $surfaceKey): string
{
    $id = (string) Str::uuid();
    DB::table('routing.source_intents')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'surface_key' => $surfaceKey,
        'routing_class' => $routingClass,
        'identifier' => 'cgp-test-id',
        'canonical_url' => 'https://example.test/cgp-test-id',
        'state' => 'proposed',
        'origin' => 'website_import',
        'first_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/** @return array<string, callable(): User> a User-builder per account shape the audit named. */
function cgpAccountShapes(): array
{
    return [
        'partna' => fn () => createTenant('cgp-partna-'.Str::lower(Str::random(6))),
        'business-food' => fn () => createTenant('cgp-food-'.Str::lower(Str::random(6)), [
            'account_type' => 'business', 'sector' => 'restaurant',
        ]),
        'business-non-food' => fn () => createTenant('cgp-nonfood-'.Str::lower(Str::random(6)), [
            'account_type' => 'business', 'sector' => 'plumber',
        ]),
    ];
}

/**
 * The oracle: re-derive the expected denial straight from AccountCapabilities
 * booleans, independent of RoutingCapabilityGate/PlacementPolicy/
 * SuggestionApplier — so a bug shared by all three call sites still fails
 * this test, not just a disagreement between two buggy copies.
 */
function cgpExpectedDenial(User $user, string $routingClass): ?string
{
    $capabilities = AccountCapabilities::for($user);

    return match ($routingClass) {
        'booking' => $capabilities->can_use_booking ? null : 'booking is not available for this account',
        'reservations' => $capabilities->can_use_reservations ? null : 'reservations are not available for this account',
        'ordering' => $capabilities->can_use_online_ordering ? null : 'online ordering is not available for this account',
        default => null,
    };
}

it('gives PlacementPolicy::decide() and SuggestionApplier::apply() the same verdict for every routing_class and account shape', function (string $shapeLabel, string $routingClass) {
    $user = cgpAccountShapes()[$shapeLabel]();
    $surfaceKey = cgpSurfaceKeyFor($routingClass);
    $surface = CompiledCatalog::surface($surfaceKey);
    expect($surface)->not->toBeNull(); // sanity: catalog fixture didn't drift

    $expectedDenial = cgpExpectedDenial($user, $routingClass);

    // ── PlacementPolicy::decide() — the record-time gate ────────────────────
    // Confidence/margin are set to the ceiling: the gate check runs before
    // the confidence branch, so this isolates the capability arm regardless
    // of any real detector's score. Real, catalog-backed surfaceKey — no
    // synthetic routing_class, so the projection is one decide() could
    // actually receive off a real detector match.
    $projection = new Projection(
        surfaceKey: $surfaceKey,
        detectorId: cgpDetectorIdFor($surfaceKey),
        captures: [],
        identifier: 'cgp-test-id',
        reason: null,
    );
    $placement = (new PlacementPolicy)->decide($projection, RoutingContext::forUser($user, 'paste'));

    // ── SuggestionApplier::apply() — the apply-time re-check ────────────────
    $intentId = cgpSeedIntent($user, $routingClass, $surfaceKey);
    $intent = DB::table('routing.source_intents')->where('id', $intentId)->first();

    if ($expectedDenial !== null) {
        expect($placement->verdict)->toBe(Verdict::Note)
            ->and($placement->blockReason)->toBe('gate')
            ->and($placement->explanation)->toBe($expectedDenial);

        expect(fn () => app(SuggestionApplier::class)->apply($user, $intent, $surface))
            ->toThrow(AuthorizationException::class, $expectedDenial);

        $settled = DB::table('routing.source_intents')->where('id', $intentId)->first();
        expect($settled->state)->toBe('blocked')
            ->and($settled->block_reason)->toBe('gate');
    } else {
        // Not denied: PlacementPolicy's gate falls through to the confidence
        // branch, and confidence=100/margin=100 clears every threshold in
        // RoutingPolicy — so "allowed" is observably Place, not just
        // "not gate'd". context->user is non-null throughout, so Gate 2
        // (no-account classes) never engages here either.
        expect($placement->verdict)->toBe(Verdict::Place)
            ->and($placement->blockReason)->toBeNull();

        $connection = app(SuggestionApplier::class)->apply($user, $intent, $surface);
        expect($connection)->toBeInstanceOf(IntegrationConnection::class)
            ->and($connection->surface_key)->toBe($surfaceKey);

        $settled = DB::table('routing.source_intents')->where('id', $intentId)->first();
        expect($settled->state)->toBe('applied');
    }
})->with([
    'partna × booking' => ['partna', 'booking'],
    'partna × reservations' => ['partna', 'reservations'],
    'partna × ordering' => ['partna', 'ordering'],
    'partna × unknown class' => ['partna', 'social'],
    'business-food × booking' => ['business-food', 'booking'],
    'business-food × reservations' => ['business-food', 'reservations'],
    'business-food × ordering' => ['business-food', 'ordering'],
    'business-food × unknown class' => ['business-food', 'social'],
    'business-non-food × booking' => ['business-non-food', 'booking'],
    'business-non-food × reservations' => ['business-non-food', 'reservations'],
    'business-non-food × ordering' => ['business-non-food', 'ordering'],
    'business-non-food × unknown class' => ['business-non-food', 'social'],
]);

it('proves the account shapes actually disagree, so the matrix above is not vacuous', function () {
    // The trap the audit called out by name: createTenant()'s default
    // (partna) makes can_use_booking/can_use_reservations unconditionally
    // true, so a test suite built only on the default fixture could assert
    // "never denied" and pass while the denial branch rotted. This pins the
    // truth table the matrix above depends on.
    $partna = createTenant('cgp-truth-partna-'.Str::lower(Str::random(6)));
    $food = createTenant('cgp-truth-food-'.Str::lower(Str::random(6)), ['account_type' => 'business', 'sector' => 'restaurant']);
    $nonFood = createTenant('cgp-truth-nonfood-'.Str::lower(Str::random(6)), ['account_type' => 'business', 'sector' => 'plumber']);

    expect(AccountCapabilities::for($partna)->can_use_booking)->toBeTrue()
        ->and(AccountCapabilities::for($partna)->can_use_reservations)->toBeTrue()
        ->and(AccountCapabilities::for($partna)->can_use_online_ordering)->toBeFalse()
        ->and(AccountCapabilities::for($food)->can_use_booking)->toBeFalse()
        ->and(AccountCapabilities::for($food)->can_use_reservations)->toBeTrue()
        ->and(AccountCapabilities::for($food)->can_use_online_ordering)->toBeTrue()
        ->and(AccountCapabilities::for($nonFood)->can_use_booking)->toBeTrue()
        ->and(AccountCapabilities::for($nonFood)->can_use_reservations)->toBeFalse()
        ->and(AccountCapabilities::for($nonFood)->can_use_online_ordering)->toBeFalse();
});
