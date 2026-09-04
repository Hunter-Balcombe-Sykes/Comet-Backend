<?php

// A partna's workplace website is often a CHAIN's site, and its locations page
// carries one booking link per branch. Each branch is a genuinely distinct
// account — Fresha gives every one its own venue id, its own owner id and its
// own slug, and leaves `additionalLocations` empty, so nothing in the payload
// says "these six are one business". ConnectionIdentity therefore refuses to
// merge them, correctly, and the booking XOR holds every branch after the
// first as a `conflict` the inbox renders as "You already have a booking
// link. Use this Fresha one instead?" — five times for a six-branch chain.
//
// Measured on dev 2026-09-03: teegandyson and liamsaunders carried five such
// rows each, every one pointing at the same incumbent.
//
// The 2026-09-03 workplace-identity gate deliberately let the ACTION classes
// through ("an account says who you are, a booking link says how to reach
// you") — right for ONE workplace, but a chain multiplies it: five of the six
// are other shops, and are not how you reach this person at all.
//
// What settles it was already computed and then never consulted.
// FreshaAutoSelector fetches the winning venue's roster, runs
// StaffNameMatcher, and on a hit records `selection.mode = 'employee'`. That
// is a positive identification of the account holder on that branch's team —
// and it required BOTH a name match and a successful per-employee services
// fetch, so it is stronger evidence than a bare name match. When the incumbent
// carries it, the question "which branch is theirs?" is already answered and
// the siblings are not questions at all.

use App\Models\Core\Site\IntegrationConnection;
use App\Routing\LinkRoutingService;
use App\Routing\RoutingContext;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
    AccountCapabilities::flushCache();
});

/**
 * The branch that won the booking slot.
 *
 * `selection.mode` is the whole discriminator: 'employee' means the roster
 * named this person, 'storewide' means it did not.
 */
function bsbIncumbent(object $user, string $slug, string $mode, ?string $ownerScope = null): IntegrationConnection
{
    $connection = new IntegrationConnection([
        'surface_key' => 'fresha.book',
        'routing_class' => 'booking',
        'resource_id' => $slug,
        'payload' => [
            'url' => "https://www.fresha.com/a/{$slug}",
            'selection' => ['url' => "https://www.fresha.com/a/{$slug}", 'mode' => $mode],
        ],
        'is_active' => true,
    ]);
    // user_id and owner_scope are both non-fillable (tenancy FK / system-written).
    $connection->user_id = $user->id;
    $connection->owner_scope = $ownerScope;
    $connection->save();

    return $connection;
}

/** A different branch of the same chain, arriving from the workplace website scan. */
function bsbRouteSibling(object $user, string $slug, string $origin = 'website_import'): ?object
{
    app(LinkRoutingService::class)->route(
        "https://www.fresha.com/a/{$slug}/booking?menu=true",
        RoutingContext::forUser($user, $origin),
    );

    return DB::table('routing.source_intents')
        ->where('user_id', $user->id)
        ->where('identifier', $slug)
        ->first();
}

it('does not ask about another branch when the roster already named this person at the incumbent', function () {
    $pro = createTenant('bsb-confirmed');
    bsbIncumbent($pro, 'the-barber-club-yarraville-xzedrij1', 'employee');

    $intent = bsbRouteSibling($pro, 'the-barber-club-richmond-richmond-102-bridge-road-ibqux14f');

    // Still recorded — the observation is explained, not invisible — but it is
    // not a question, so the inbox never renders it.
    expect($intent)->not->toBeNull()
        ->and($intent->state)->toBe('blocked')
        ->and($intent->block_reason)->toBe('sibling_branch');
});

it('still asks when the incumbent roster did NOT name them — which branch is theirs is genuinely unknown', function () {
    // 'storewide' means StaffNameMatcher found nobody, so first-wins picked a
    // branch on nothing but processing order. That question is real, and it
    // keeps today's Swap card until the deferred branch-disambiguation lane
    // (fetch each roster, name-match across them) is built.
    $pro = createTenant('bsb-storewide');
    bsbIncumbent($pro, 'the-barber-club-yarraville-xzedrij1', 'storewide', 'workplace');

    $intent = bsbRouteSibling($pro, 'the-barber-club-richmond-richmond-102-bridge-road-ibqux14f');

    expect($intent->block_reason)->toBe('conflict');
});

it('does not offer the workplace\'s branch as a swap against the account holder\'s OWN booking link', function () {
    // The incumbent came from their own bio or their own hand. A link scraped
    // off their employer's site does not get to propose replacing it — the
    // booking-lane counterpart of the 2026-09-03 identity gate. Deliberately
    // 'storewide' so ONLY the owner_scope clause can settle this.
    $pro = createTenant('bsb-self');
    bsbIncumbent($pro, 'the-barber-club-port-melbourne-melbourne-103a-bay-street-tjggebwn', 'storewide', 'self');

    $intent = bsbRouteSibling($pro, 'the-barber-club-richmond-richmond-102-bridge-road-ibqux14f');

    expect($intent->block_reason)->toBe('sibling_branch');
});

it('gates the harvest, not the platform: a branch found in the user\'s OWN bio still asks', function () {
    // link_in_bio is the person's own publication, so ownerScopeFor() answers
    // 'self' and the suppression never arms — they put both links there and
    // the question of which to use is theirs to answer.
    $pro = createTenant('bsb-bio');
    bsbIncumbent($pro, 'the-barber-club-yarraville-xzedrij1', 'employee', 'workplace');

    $intent = bsbRouteSibling($pro, 'the-barber-club-richmond-richmond-102-bridge-road-ibqux14f', 'link_in_bio');

    expect($intent->block_reason)->toBe('conflict');
});

it('leaves a business account alone — its own website is its own brand', function () {
    // workplace_brand_is_site_identity makes ownerScopeFor() answer 'self' for
    // website_import, so a multi-venue business still gets the Swap card it
    // has always had. Its second venue is a real choice, not someone else's shop.
    $pro = createTenant('bsb-business', ['account_type' => 'business']);
    bsbIncumbent($pro, 'the-barber-club-yarraville-xzedrij1', 'employee', 'workplace');

    $intent = bsbRouteSibling($pro, 'the-barber-club-richmond-richmond-102-bridge-road-ibqux14f');

    expect($intent->block_reason)->toBe('conflict');
});

it('never renders a settled sibling branch in the suggestions inbox', function () {
    // The point of the whole change. A blocked row with an unrecognised
    // block_reason would otherwise fall through questionFor()'s default arm
    // and read "Add this Fresha link?" — the same noise wearing a friendlier
    // hat.
    $pro = createTenant('bsb-inbox');
    bsbIncumbent($pro, 'the-barber-club-yarraville-xzedrij1', 'employee', 'workplace');
    bsbRouteSibling($pro, 'the-barber-club-richmond-richmond-102-bridge-road-ibqux14f');

    $response = actingAsUser($pro)->getJson('/api/routing/suggestions');

    $response->assertOk();
    expect(collect($response->json('suggestions'))->pluck('identifier'))
        ->not->toContain('the-barber-club-richmond-richmond-102-bridge-road-ibqux14f');
});
