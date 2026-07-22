<?php

/**
 * Task 7 — staff "allow claiming": both the single and bulk approve endpoints
 * just need to authorize (admin-only, staffManage) and fan out
 * ApproveEarlyAccessBuildJob; the job itself is covered by
 * ApproveEarlyAccessBuildJobTest.
 */

use App\Jobs\PreAccount\ApproveEarlyAccessBuildJob;
use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupEarlyAccessTable();
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

// staffManage is admin-only (EarlyAccessSignupPolicy::staffManage) — matches
// StaffBuildEndpointTest's staffBuildActor() pattern, named locally to avoid a
// global-function redeclaration clash across test files.
function earlyAccessApproveAdminStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->auth_user_id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;

    return $staff;
}

function earlyAccessApproveSupportStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->auth_user_id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_SUPPORT;

    return $staff;
}

function earlyAccessApproveSignup(): EarlyAccessSignup
{
    $user = User::factory()->create(['status' => 'unclaimed']);
    $email = Str::uuid().'@e.com'; // email_lc is UNIQUE — each call needs its own row
    // user_id is deliberately not $fillable (B11 doctrine) — link it after create().
    $signup = EarlyAccessSignup::create([
        'email' => $email, 'email_lc' => $email, 'type' => 'partna',
        'status' => 'waitlist', 'source' => 'marketing',
    ]);
    $signup->forceFill(['user_id' => $user->id])->save();

    return $signup;
}

it('dispatches an approval job for a single signup', function () {
    Queue::fake();
    $signup = earlyAccessApproveSignup();

    actingAsStaff(earlyAccessApproveAdminStaff()); // ROLE_ADMIN — staffManage is admin-only
    $this->postJson("/api/staff/early-access/{$signup->id}/approve")->assertStatus(202);

    Queue::assertPushed(ApproveEarlyAccessBuildJob::class,
        fn ($j) => $j->signupId === $signup->id);
});

it('rejects a support-role staff actor (staffManage is admin-only)', function () {
    Queue::fake();
    $signup = earlyAccessApproveSignup();

    actingAsStaff(earlyAccessApproveSupportStaff());
    $this->postJson("/api/staff/early-access/{$signup->id}/approve")->assertStatus(403);

    Queue::assertNotPushed(ApproveEarlyAccessBuildJob::class);
});

it('bulk-dispatches by explicit ids', function () {
    Queue::fake();
    $signupA = earlyAccessApproveSignup();
    $signupB = earlyAccessApproveSignup();

    actingAsStaff(earlyAccessApproveAdminStaff());
    $this->postJson('/api/staff/early-access/approve-bulk', ['ids' => [$signupA->id, $signupB->id]])
        ->assertStatus(202)
        ->assertJsonPath('dispatched', 2);

    Queue::assertPushed(ApproveEarlyAccessBuildJob::class, 2);
});

it('bulk-dispatches all waitlisted signups when all_waitlisted is true', function () {
    Queue::fake();
    $waitlisted = earlyAccessApproveSignup();
    $alreadyInvited = earlyAccessApproveSignup();
    $alreadyInvited->forceFill(['status' => EarlyAccessSignup::STATUS_INVITED])->save();

    actingAsStaff(earlyAccessApproveAdminStaff());
    $this->postJson('/api/staff/early-access/approve-bulk', ['all_waitlisted' => true])
        ->assertStatus(202)
        ->assertJsonPath('dispatched', 1);

    Queue::assertPushed(ApproveEarlyAccessBuildJob::class,
        fn ($j) => $j->signupId === $waitlisted->id);
});

it('rejects bulk approval with neither ids nor all_waitlisted', function () {
    Queue::fake();

    actingAsStaff(earlyAccessApproveAdminStaff());
    $this->postJson('/api/staff/early-access/approve-bulk', [])
        ->assertStatus(422);

    Queue::assertNotPushed(ApproveEarlyAccessBuildJob::class);
});

// A signup with no linked build (user_id null) — e.g. a manually-added lead or
// a build that collided/failed. approve-bulk has nothing to dispatch for it.
function earlyAccessApproveBuildlessSignup(): EarlyAccessSignup
{
    $email = Str::uuid().'@e.com';

    return EarlyAccessSignup::create([
        'email' => $email, 'email_lc' => $email, 'type' => 'partna',
        'status' => 'waitlist', 'source' => 'manual',
    ]); // user_id deliberately left null — no build to approve
}

it('bulk approve by ids reports dispatched_ids and skips build-less rows honestly', function () {
    Queue::fake();
    $withBuild = earlyAccessApproveSignup();        // user_id set
    $noBuild = earlyAccessApproveBuildlessSignup(); // user_id null

    actingAsStaff(earlyAccessApproveAdminStaff());
    $this->postJson('/api/staff/early-access/approve-bulk', ['ids' => [$withBuild->id, $noBuild->id]])
        ->assertStatus(202)
        ->assertJsonPath('dispatched', 1)                 // count unchanged (backward compatible)
        ->assertJsonPath('dispatched_ids', [$withBuild->id])
        ->assertJsonPath('skipped_ids', [$noBuild->id]);  // was silently dropped before

    Queue::assertPushed(ApproveEarlyAccessBuildJob::class, 1);
    Queue::assertPushed(ApproveEarlyAccessBuildJob::class, fn ($j) => $j->signupId === $withBuild->id);
});

it('bulk approve dedupes duplicate ids so a double-click dispatches once', function () {
    Queue::fake();
    $signup = earlyAccessApproveSignup();

    actingAsStaff(earlyAccessApproveAdminStaff());
    $this->postJson('/api/staff/early-access/approve-bulk', ['ids' => [$signup->id, $signup->id]])
        ->assertStatus(202)
        ->assertJsonPath('dispatched', 1)
        ->assertJsonPath('dispatched_ids', [$signup->id]);

    Queue::assertPushed(ApproveEarlyAccessBuildJob::class, 1);
});

it('bulk approve by all_waitlisted reports dispatched_ids with no skips', function () {
    Queue::fake();
    $withBuild = earlyAccessApproveSignup();
    earlyAccessApproveBuildlessSignup(); // waitlisted but build-less — simply out of scope, not "skipped"

    actingAsStaff(earlyAccessApproveAdminStaff());
    $this->postJson('/api/staff/early-access/approve-bulk', ['all_waitlisted' => true])
        ->assertStatus(202)
        ->assertJsonPath('dispatched', 1)
        ->assertJsonPath('dispatched_ids', [$withBuild->id])
        ->assertJsonPath('skipped_ids', []);
});
