<?php

/**
 * Task 17 — POST /api/staff/builds (the ManyChat/marketing surface). Staff
 * builds publish by default (the site IS the pitch, unlike the public
 * signup-path build which never publishes pre-claim) and skip the IP cap
 * entirely (PreAccountBuildService::requestBuild only checks the cap when
 * $staff is null).
 */

use App\Jobs\PreAccount\GeneratePreAccountSiteJob;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
    setupPartnaStaffTable();

    // staff.audit middleware (RecordStaffAuditEntry) writes here after every
    // staff response — mirrors UnclaimedGatingTest / StaffEarlyAccessInviteTest.
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

// Copies the arrange helper used by PreAccountBuildServiceTest::makePartnaStaff()
// / UnclaimedGatingTest — no persistence needed, associate() only reads the key.
function staffBuildActor(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->auth_user_id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;

    return $staff;
}

it('lets staff trigger a published marketing build', function () {
    actingAsStaff(staffBuildActor());
    Queue::fake();

    $this->postJson('/api/staff/builds', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'prospect'])
        ->assertStatus(202);

    $build = PreAccountBuild::firstOrFail();
    expect($build->built_via)->toBe(PreAccountBuild::VIA_STAFF)
        ->and($build->built_by_staff_id)->not->toBeNull();
    Queue::assertPushed(GeneratePreAccountSiteJob::class, fn ($j) => $j->publish === true);
});

it('honours publish=false and expires_days', function () {
    actingAsStaff(staffBuildActor());
    Queue::fake();

    $this->postJson('/api/staff/builds', [
        'account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'unpublished_prospect',
        'publish' => false, 'expires_days' => 60,
    ])->assertStatus(202);

    $build = PreAccountBuild::firstOrFail();
    expect($build->expires_at->isAfter(now()->addDays(59)))->toBeTrue()
        ->and($build->expires_at->isBefore(now()->addDays(61)))->toBeTrue();
    Queue::assertPushed(GeneratePreAccountSiteJob::class, fn ($j) => $j->publish === false);
});

it('rejects non-staff callers', function () {
    $plainUser = User::factory()->create();

    actingAsUser($plainUser)
        ->postJson('/api/staff/builds', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'nope'])
        ->assertStatus(403)
        ->assertJsonPath('error', 'staff_required');

    expect(PreAccountBuild::query()->count())->toBe(0);
});

it('ignores the IP cap for staff builds', function () {
    config(['partna.pre_account.max_unclaimed_per_ip' => 0]);
    actingAsStaff(staffBuildActor());
    Queue::fake();

    $this->postJson('/api/staff/builds', ['account_type' => 'partna', 'source_type' => 'instagram', 'source_ref' => 'cap_immune'])
        ->assertStatus(202);

    expect(PreAccountBuild::query()->count())->toBe(1);
});

it('stores a contact_email on a staff build', function () {
    actingAsStaff(staffBuildActor());
    Queue::fake();

    $this->postJson('/api/staff/builds', [
        'account_type' => 'partna', 'source_type' => 'instagram',
        'source_ref' => 'prospect', 'contact_email' => 'prospect@example.com',
    ])->assertStatus(202);

    expect(PreAccountBuild::firstOrFail()->contact_email)->toBe('prospect@example.com');
});

it('persists auto_invite=false on a staff build', function () {
    actingAsStaff(staffBuildActor());
    Queue::fake();

    $this->postJson('/api/staff/builds', [
        'account_type' => 'partna', 'source_type' => 'instagram',
        'source_ref' => 'review_me', 'contact_email' => 'p@example.com', 'auto_invite' => false,
    ])->assertStatus(202);

    expect(PreAccountBuild::firstOrFail()->auto_invite)->toBeFalse();
});
