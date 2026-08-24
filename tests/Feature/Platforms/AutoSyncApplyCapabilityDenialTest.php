<?php

// #TEST-1 / #SILENT-1: BuildsAutoSyncFindings::applyFinding()'s apply-time
// capability re-check (2026-08-04) THROWS AuthorizationException on a
// booking/reservations denial instead of the bare `return true` #SILENT-1
// documented — the old behaviour looked like success (200, finding flipped
// to 'seeded') while nothing was actually written.
//
// 2026-08-19: the two per-platform "Change to" endpoints this guarded are
// retired; the swap is one row in the suggestions inbox, addressed
// `sync:{holder}:{platform}`. Same trait, same denial, one caller.
//
// The fixture trap: every applySync fixture in InstagramSyncedTest.php /
// GoogleBusinessApifyTest.php uses either a plain User::create() (account_type
// 'partna') or a non-food business — for both, can_use_booking is true, so
// the denial branch this file guards is unreachable. createTenant() also
// defaults to 'partna'. Needs the food-sector business account
// (SectorTaxonomy::FOOD_SECTORS) that makes can_use_booking false.

use App\Models\Core\Site\IntegrationConnection;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // IntegrationConnectionObserver::seedContentFromGoogle() fires on every
    // saved google-business connection — needs the table even though this
    // file never asserts on it.
    // The inbox reads routing.source_intents alongside the folded payload
    // findings, so the table must exist even on the denial path.
    setupRoutingTables();
});

/** A conflict finding occupying the booking slot — the shape GoogleBusinessAutoSync::seedBooking / InstagramAutoSync's booking branch actually produce. */
function autoSyncBookingConflictFinding(): array
{
    return [
        'platform' => 'fresha', 'resourceId' => 'fresha', 'category' => 'booking',
        'label' => 'Fresha', 'foundUrl' => 'https://www.fresha.com/a/doc-cuts',
        'outcome' => 'conflict',
        'apply' => ['remove' => ['fresha'], 'write' => [
            'platform' => 'fresha', 'resourceId' => 'fresha',
            'payload' => ['url' => 'https://www.fresha.com/a/doc-cuts', 'source' => 'google-business'],
        ]],
    ];
}

it('403s accepting a Google-Business booking finding a food-sector business account cannot have, and leaves the finding conflict', function () {
    $user = createTenant('gbdeny-food', ['account_type' => 'business', 'sector' => 'restaurant']);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'google-business', 'resource_id' => 'google-business',
        'payload' => ['placeId' => 'ChIJtest', 'syncFindings' => [autoSyncBookingConflictFinding()]],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $response = actingAsUser($user)->postJson('/api/routing/suggestions/sync:google-business:fresha/accept');

    $response->assertStatus(403)
        ->assertJsonPath('message', 'booking is not available for this account');

    $gb = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'google-business')->firstOrFail();
    expect($gb->payload['syncFindings'][0]['outcome'])->toBe('conflict');

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
});

it('403s accepting an Instagram booking finding a food-sector business account cannot have, and leaves the finding conflict', function () {
    $user = createTenant('igdeny-food', ['account_type' => 'business', 'sector' => 'restaurant']);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'instagram', 'resource_id' => 'instagram',
        'payload' => ['username' => 'docpizza', 'syncFindings' => [autoSyncBookingConflictFinding()], 'unmatched' => []],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $response = actingAsUser($user)->postJson('/api/routing/suggestions/sync:instagram:fresha/accept');

    $response->assertStatus(403)
        ->assertJsonPath('message', 'booking is not available for this account');

    $ig = IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'instagram')->firstOrFail();
    expect($ig->payload['syncFindings'][0]['outcome'])->toBe('conflict');

    expect(IntegrationConnection::query()->where('user_id', $user->id)->where('platform', 'fresha')->exists())->toBeFalse();
});
