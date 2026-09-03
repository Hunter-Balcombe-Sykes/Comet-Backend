<?php

/**
 * Task 18 — staff visibility of the marketing pipeline.
 *
 * Two things pinned here:
 *   1. GET /api/staff/professionals/{professional} carries a `pre_account_build`
 *      block on the `professional` payload when the user has a linked
 *      PreAccountBuild row, and omits the key entirely otherwise.
 *   2. GET /api/staff/professionals?status=unclaimed already filters correctly
 *      (StaffUserController::index's `$status` filter is an arbitrary
 *      `where('status', $status)` — no code change needed here, just a pin).
 */

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupPartnaStaffTable();

    // staff.audit middleware (RecordStaffAuditEntry) writes here after every
    // staff response — mirrors StaffBuildEndpointTest / UnclaimedGatingTest.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY,
        staff_id TEXT,
        staff_email_snapshot TEXT,
        impersonator_staff_id TEXT,
        impersonator_email_snapshot TEXT,
        user_id TEXT,
        professional_handle_snapshot TEXT,
        route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\',
        status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\',
        ip_hash TEXT,
        user_agent TEXT,
        created_at TEXT
    )');
});

// Copies the arrange helper used by StaffBuildEndpointTest / UnclaimedGatingTest
// / PreAccountBuildServiceTest::makePartnaStaff() — no persistence needed,
// actingAsStaff() only reads the fields it stubs into the request attribute.
function unclaimedVisibilityStaffActor(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->auth_user_id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;

    return $staff;
}

it('pins the ?status=unclaimed filter on the staff index', function () {
    actingAsStaff(unclaimedVisibilityStaffActor());

    [$unclaimedUser] = makeReadyBuild('pipeline-pin'); // suite-global helper — tests/Pest.php
    $activeUser = User::factory()->create(['status' => 'active']);

    $response = $this->getJson('/api/staff/professionals?status=unclaimed')->assertOk();

    $ids = array_column($response->json('professionals'), 'id');

    expect($ids)->toContain((string) $unclaimedUser->id)
        ->not->toContain((string) $activeUser->id);
});

it('includes the pre_account_build block for an unclaimed user with a build', function () {
    actingAsStaff(unclaimedVisibilityStaffActor());

    [$user, , $build] = makeReadyBuild('pipeline-present');

    $this->getJson("/api/staff/professionals/{$user->id}")
        ->assertOk()
        ->assertJsonPath('professional.pre_account_build.source_type', $build->source_type)
        ->assertJsonPath('professional.pre_account_build.source_ref', $build->source_ref)
        ->assertJsonPath('professional.pre_account_build.built_via', $build->built_via)
        ->assertJsonPath('professional.pre_account_build.build_state', $build->build_state)
        ->assertJsonPath('professional.pre_account_build.failure_code', $build->failure_code)
        ->assertJsonPath('professional.pre_account_build.expires_at', $build->expires_at?->toIso8601String())
        ->assertJsonPath('professional.pre_account_build.claimed_at', null);
});

// A stalled build gets no email by ruling, so the row IS the surface -- staff
// have no other way to learn it happened.
it('exposes settled_at and setup_stalled_at on the staff pre_account_build block', function () {
    actingAsStaff(unclaimedVisibilityStaffActor());

    [$user, , $build] = makeReadyBuild('pipeline-stalled');
    $build->forceFill(['setup_stalled_at' => now()])->save();

    $this->getJson("/api/staff/professionals/{$user->id}")
        ->assertOk()
        ->assertJsonPath('professional.pre_account_build.settled_at', null)
        ->assertJsonPath('professional.pre_account_build.setup_stalled_at', fn ($v) => $v !== null);
});

it('omits the pre_account_build block for a normal user with no build', function () {
    actingAsStaff(unclaimedVisibilityStaffActor());

    $user = User::factory()->create(['status' => 'active']);

    $this->getJson("/api/staff/professionals/{$user->id}")
        ->assertOk()
        ->assertJsonMissingPath('professional.pre_account_build');
});
