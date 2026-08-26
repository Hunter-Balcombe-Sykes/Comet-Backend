<?php

// #SEC-9: SuggestionsController::acceptGoogleListing used to gate on a
// hand-rolled `throw new AuthorizationException(...)` instead of a Policy —
// it failed closed, but sat outside PolicyCoverageTest's structural sweep.
// Now routed through IntegrationConnectionPolicy::createForRoutingClass().
// This file pins the wire contract (403 + exact message on deny, 200 +
// connection created on allow) so the swap couldn't silently change either.

use App\Models\Core\Site\IntegrationConnection;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupRoutingTables();
});

/** The google-business payload shape suggestionFromGoogleBusiness() reads: a reservation.url pointing at a real OpenTable profile link. */
function agListingGbPayload(): array
{
    return [
        'placeId' => 'ChIJtest',
        'name' => 'Doc Pizza',
        'reservation' => ['url' => 'https://www.opentable.com.au/restaurant/profile/123456'],
    ];
}

it('403s accepting the Google-listed OpenTable link for an account without reservations capability, and writes no connection', function () {
    $user = createTenant('ag-deny', ['account_type' => 'business', 'sector' => 'plumber']);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'payload' => agListingGbPayload(), 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $response = actingAsUser($user)->postJson('/api/routing/suggestions/listing:opentable/accept');

    $response->assertStatus(403)
        ->assertJsonPath('message', 'reservations are not available for this account');

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'opentable')->exists())->toBeFalse();
});

it('accepts the Google-listed OpenTable link for an account with reservations capability', function () {
    $user = createTenant('ag-allow');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'payload' => agListingGbPayload(), 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $response = actingAsUser($user)->postJson('/api/routing/suggestions/listing:opentable/accept');

    $response->assertOk()->assertJsonPath('surfaceKey', 'opentable.reserve');

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'opentable')->exists())->toBeTrue();
});
