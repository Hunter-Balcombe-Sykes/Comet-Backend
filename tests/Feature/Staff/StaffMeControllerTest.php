<?php

/**
 * #SEC-6 (P3) — StaffMeController::show() previously had no Policy call at
 * all. Purely a consistency fix (the resource IS the actor, so there's no
 * privilege-escalation path either way) — this just proves the gate exists
 * and doesn't accidentally deny anyone.
 */

use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Support\Str;

beforeEach(function () {
    setupPartnaStaffTable();
});

it('a staff member can view their own /me record regardless of role', function (string $role) {
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = $role;

    actingAsStaff($staff)
        ->getJson('/api/staff/me')
        ->assertStatus(200)
        ->assertJsonPath('staff.id', $staff->id);
})->with([PartnaStaff::ROLE_SUPPORT, PartnaStaff::ROLE_ADMIN]);

// ── Wave 8 (2026-09-02): the settings page's own-record edit ─────────────────

it('a staff member of either role can rename their own record through PATCH /staff/me', function (string $role) {
    $staff = new PartnaStaff;
    $staff->forceFill(['id' => (string) Str::uuid(), 'auth_user_id' => (string) Str::uuid(), 'role' => $role, 'name' => 'Before', 'primary_email' => 'me@partna.au']);
    $staff->save();

    actingAsStaff($staff)
        ->patchJson('/api/staff/me', ['name' => 'After'])
        ->assertStatus(200)
        ->assertJsonPath('staff.name', 'After')
        ->assertJsonPath('staff.role', $role);

    expect($staff->fresh()->name)->toBe('After');
})->with([PartnaStaff::ROLE_SUPPORT, PartnaStaff::ROLE_ADMIN]);

it('PATCH /staff/me cannot touch role or email — the request only knows `name`', function () {
    $staff = new PartnaStaff;
    $staff->forceFill(['id' => (string) Str::uuid(), 'auth_user_id' => (string) Str::uuid(), 'role' => PartnaStaff::ROLE_SUPPORT, 'name' => 'Before', 'primary_email' => 'me@partna.au']);
    $staff->save();

    actingAsStaff($staff)
        ->patchJson('/api/staff/me', ['name' => 'After', 'role' => PartnaStaff::ROLE_ADMIN, 'primary_email' => 'other@partna.au'])
        ->assertStatus(200);

    $fresh = $staff->fresh();
    expect($fresh->name)->toBe('After')
        ->and($fresh->role)->toBe(PartnaStaff::ROLE_SUPPORT)
        ->and($fresh->primary_email)->toBe('me@partna.au');
});

it('PATCH /staff/me refuses an empty name', function () {
    $staff = new PartnaStaff;
    $staff->forceFill(['id' => (string) Str::uuid(), 'auth_user_id' => (string) Str::uuid(), 'role' => PartnaStaff::ROLE_ADMIN, 'name' => 'Before']);
    $staff->save();

    actingAsStaff($staff)->patchJson('/api/staff/me', ['name' => ''])->assertStatus(422);
    expect($staff->fresh()->name)->toBe('Before');
});
