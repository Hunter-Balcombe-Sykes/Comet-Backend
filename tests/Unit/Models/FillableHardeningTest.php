<?php

use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Models\Core\Gdpr\DataExportAudit;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\Customer;
use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use App\Models\Core\User\User;
use App\Models\Core\User\UserDeletionAuditEntry;

// DataExportAudit — server-controlled timestamps must not be mass-assignable
it('does not allow created_at to be mass-assigned on DataExportAudit', function () {
    expect((new DataExportAudit)->getFillable())->not->toContain('created_at');
});

it('does not allow completed_at to be mass-assigned on DataExportAudit', function () {
    expect((new DataExportAudit)->getFillable())->not->toContain('completed_at');
});

// UserDeletionAuditEntry — server-controlled timestamp must not be mass-assignable
it('does not allow created_at to be mass-assigned on UserDeletionAuditEntry', function () {
    expect((new UserDeletionAuditEntry)->getFillable())->not->toContain('created_at');
});

// UserDeletionAuditEntry — user_id/actor_id/ip_address/professional_email_snapshot/
// actor_handle_snapshot are server-managed on this append-only audit table (SEC-3).
it('does not allow user_id, actor_id, ip_address, or PII snapshots to be mass-assigned on UserDeletionAuditEntry', function () {
    $fillable = (new UserDeletionAuditEntry)->getFillable();

    expect($fillable)->not->toContain('user_id')
        ->and($fillable)->not->toContain('actor_id')
        ->and($fillable)->not->toContain('ip_address')
        ->and($fillable)->not->toContain('professional_email_snapshot')
        ->and($fillable)->not->toContain('actor_handle_snapshot');
});

// PartnaStaff — role must not be mass-assignable (privilege-escalation guard, SEC-1).
// Role transitions go through promoteToAdmin() / demoteToSupport().
it('does not allow role to be mass-assigned on PartnaStaff', function () {
    expect((new PartnaStaff)->getFillable())->not->toContain('role');
});

it('rejects role via fill() on PartnaStaff', function () {
    $staff = (new PartnaStaff)->forceFill(['role' => PartnaStaff::ROLE_SUPPORT]);
    $staff->fill(['role' => PartnaStaff::ROLE_ADMIN, 'name' => 'New Name']);

    expect($staff->role)->toBe(PartnaStaff::ROLE_SUPPORT);
    expect($staff->name)->toBe('New Name');
});

// PartnaStaff — PII fields hidden from serialization (SEC-2).
it('hides primary_email, name, phone, auth_user_id from PartnaStaff::toArray()', function () {
    $staff = (new PartnaStaff)->forceFill([
        'id' => 'staff-1',
        'role' => PartnaStaff::ROLE_ADMIN,
        'auth_user_id' => 'auth-uuid',
        'primary_email' => 'admin@partna.test',
        'name' => 'Test Admin',
        'phone' => '+61400000000',
    ]);

    $array = $staff->toArray();

    expect($array)->not->toHaveKey('primary_email');
    expect($array)->not->toHaveKey('name');
    expect($array)->not->toHaveKey('phone');
    expect($array)->not->toHaveKey('auth_user_id');
    expect($array)->toHaveKey('id');
    expect($array)->toHaveKey('role');
});

// PartnaStaff — promoteToAdmin / demoteToSupport are the sanctioned role-transition methods (SEC-1).
it('exposes promoteToAdmin and demoteToSupport as the sanctioned role transition path', function () {
    expect(method_exists(PartnaStaff::class, 'promoteToAdmin'))->toBeTrue();
    expect(method_exists(PartnaStaff::class, 'demoteToSupport'))->toBeTrue();
    expect(PartnaStaff::ROLE_ADMIN)->toBe('admin');
    expect(PartnaStaff::ROLE_SUPPORT)->toBe('support');
});

// SEC-1 (B11): user_id must not be mass-assignable on tenant-owned models —
// it's set via the owning relation's create() or direct property assignment.
it('does not allow user_id to be mass-assigned on Customer, Service, ServiceCategory, or EarlyAccessSignup', function () {
    expect((new Customer)->getFillable())->not->toContain('user_id')
        ->and((new Service)->getFillable())->not->toContain('user_id')
        ->and((new ServiceCategory)->getFillable())->not->toContain('user_id')
        ->and((new EarlyAccessSignup)->getFillable())->not->toContain('user_id');
});

// SEC-2 (B11): deletion lifecycle + status + admin_notes are server-managed on
// User — writers use forceFill()/direct assignment. account_type and
// primary_email stay fillable (legitimately validated by UpdateUserRequest).
// handle/handle_lc are KEPT fillable (Josh, 2026-07-21) — already excluded from
// the /me endpoint + changed only via the dedicated rename flow; removal cost a
// ~90-test-file blast radius for minimal defence-in-depth.
it('does not allow status, deletion_*, or admin_notes to be mass-assigned on User', function () {
    $fillable = (new User)->getFillable();

    expect($fillable)->not->toContain('status')
        ->and($fillable)->not->toContain('deletion_token_hash')
        ->and($fillable)->not->toContain('deletion_requested_at')
        ->and($fillable)->not->toContain('deletion_confirmed_at')
        ->and($fillable)->not->toContain('deletion_previous_status')
        ->and($fillable)->not->toContain('deletion_mail_sent_at')
        ->and($fillable)->not->toContain('admin_notes')
        ->and($fillable)->toContain('account_type')
        ->and($fillable)->toContain('primary_email');
});

// SEC-4 (B11): build_state/claimed_at/failure_code drive the pre-account build
// state machine — writers use forceFill()/direct assignment so a dropped write
// can't silently strand a build in the wrong state.
it('does not allow build_state, claimed_at, or failure_code to be mass-assigned on PreAccountBuild', function () {
    $fillable = (new PreAccountBuild)->getFillable();

    expect($fillable)->not->toContain('build_state')
        ->and($fillable)->not->toContain('claimed_at')
        ->and($fillable)->not->toContain('failure_code');
});

// SEC-17: site_id (Workplace's PK + FK to site.sites, non-incrementing) is not
// mass-assignable — every write path sets it via explicit assignment from the
// auth-resolved current site. site_id is the PK, so a bare create() throws on
// the NOT NULL constraint before ever reaching this — fill() is the direct,
// non-vacuous way to assert the allowlist itself dropped the key.
it('does not allow site_id to be mass-assigned on Workplace', function () {
    $workplace = (new Workplace)->fill(['site_id' => 'attacker-site-id', 'name' => 'x']);

    expect($workplace->site_id)->toBeNull()
        ->and($workplace->name)->toBe('x');
});

// SEC-18: a silently-dropped mass-assignment write to moderation_state or
// unpublished_at would strand a site in the wrong moderation state, or
// offline/online, with no error. Writers assign these explicitly instead
// (AccountDeletionService::confirm/cancel, ClaimSiteService) or bulk-update
// via the query builder, which never consults $fillable (SuspendSiteJob).
it('does not allow moderation_state or unpublished_at to be mass-assigned on Site', function () {
    // Asserts fill() BEHAVIOUR, not just the $fillable literal: the allowlist is
    // the mechanism, but what SEC-18 actually needs is that a hostile payload
    // reaching fill() cannot move either column.
    // getAttributes() is the RAW bag — asserting through the accessor would run
    // unpublished_at's datetime cast, which needs a DB connection and turns a
    // mutation into a confusing Error instead of a clean assertion failure.
    $attributes = (new Site)->fill([
        'moderation_state' => 'hidden',
        'unpublished_at' => '2026-08-25T00:00:00+00:00',
        'subdomain' => 'still-fillable',
    ])->getAttributes();

    expect($attributes)->not->toHaveKey('moderation_state')
        ->and($attributes)->not->toHaveKey('unpublished_at')
        ->and($attributes['subdomain'])->toBe('still-fillable');
});
