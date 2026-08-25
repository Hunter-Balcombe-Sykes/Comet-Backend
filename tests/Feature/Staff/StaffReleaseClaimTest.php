<?php

/**
 * POST /api/staff/professionals/{professional}/release-claim
 *
 * Non-destructive counterpart to /force: unbinds the claimer and returns the
 * site to 'unclaimed' so the rightful owner can claim it normally, instead of
 * destroying the scraped site and making them rebuild.
 *
 * Owner ruling 2026-08-25: the site goes back to OPEN first-come (no email
 * lock), and the release ALWAYS succeeds — attached content produces a warning
 * in the response, not a refusal. The warning is the control: a non-empty one
 * means the claimer actually used the account, and /force is the correct tool.
 */

use App\Models\Core\Site\Site;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\PreAccount\ClaimSiteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupPartnaStaffTable();
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupEmailSubscriptionsTable();
    setupNotificationsTable();
    setupSubdomainAliasesTable();
    setupCustomersTable();
    setupEnquiriesTable();
    setupIntegrationConnectionsTable();
    setupMediaTables();
    attachTestSchemas();

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

    Queue::fake();
});

function makeReleaseClaimStaff(string $role = PartnaStaff::ROLE_ADMIN): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->auth_user_id = (string) Str::uuid();
    $staff->role = $role;

    return $staff;
}

it('releases a claimed pre-account site and reports no warnings when nothing was added', function () {
    [$user] = makeReadyBuild();
    app(ClaimSiteService::class)->claim('squatter-uid', 'squatter@example.com', 'janedoe');

    $response = actingAsStaff(makeReleaseClaimStaff())
        ->postJson("/api/staff/professionals/{$user->id}/release-claim")
        ->assertStatus(200);

    expect($response->json('released'))->toBeTrue();
    expect($response->json('warnings'))->toBe([]);

    $fresh = $user->fresh();
    expect($fresh->auth_user_id)->toBeNull();
    expect($fresh->status)->toBe('unclaimed');
});

// The warning is the whole safety story for the owner's "release anyway"
// ruling — if it under-reports, a release silently hands a stranger's data to
// the incoming owner.
it('warns with per-category counts when the claimer had added content', function () {
    [$user] = makeReadyBuild();
    app(ClaimSiteService::class)->claim('squatter-uid', 'squatter@example.com', 'janedoe');

    DB::connection('pgsql')->table('site.customers')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'email' => 'lead@example.com',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $response = actingAsStaff(makeReleaseClaimStaff())
        ->postJson("/api/staff/professionals/{$user->id}/release-claim")
        ->assertStatus(200);

    expect($response->json('warnings.customers'))->toBe(1);
});

it('refuses a release on a row that is not claimed', function () {
    [$user] = makeReadyBuild();

    actingAsStaff(makeReleaseClaimStaff())
        ->postJson("/api/staff/professionals/{$user->id}/release-claim")
        ->assertStatus(409)
        ->assertJsonPath('code', 'NOT_CLAIMED');
});

it('refuses a release on a user with no pre-account build', function () {
    $user = User::factory()->create(['status' => 'active', 'auth_user_id' => 'uid-x']);
    Site::factory()->create(['user_id' => $user->id, 'subdomain' => 'normalpro']);

    actingAsStaff(makeReleaseClaimStaff())
        ->postJson("/api/staff/professionals/{$user->id}/release-claim")
        ->assertStatus(409)
        ->assertJsonPath('code', 'NOT_PRE_ACCOUNT');
});

it('is admin-only — a support-tier staff member is refused', function () {
    [$user] = makeReadyBuild();
    app(ClaimSiteService::class)->claim('squatter-uid', 'squatter@example.com', 'janedoe');

    actingAsStaff(makeReleaseClaimStaff(PartnaStaff::ROLE_SUPPORT))
        ->postJson("/api/staff/professionals/{$user->id}/release-claim")
        ->assertStatus(403);

    expect($user->fresh()->auth_user_id)->toBe('squatter-uid');
});
