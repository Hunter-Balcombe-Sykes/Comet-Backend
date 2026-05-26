<?php

use App\Models\Core\Gdpr\DataExportAudit;
use App\Models\Core\User\UserDeletionAuditEntry;
use App\Models\Core\Staff\PartnaStaff;

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
