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
