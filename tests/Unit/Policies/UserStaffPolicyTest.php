<?php

/**
 * #P2-03 — UserSelfPolicy staff-actor abilities
 *
 * Verifies that the three staff-facing policy methods (staffManage, staffForceDelete,
 * staffBulkManage) correctly gate on PartnaStaff::isAdmin(). These tests exercise
 * the policy in isolation — no HTTP layer — mirroring the approach used in
 * UserSelfPolicyTest.php (same directory).
 */

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Policies\UserSelfPolicy;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    $this->policy = new UserSelfPolicy;
});

// Helper: build an unsaved PartnaStaff with the given role.
function makeStaffWithRole(string $role): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = $role;

    return $staff;
}

// Helper: build a minimal User target (unsaved — just needs to exist as a model).
function makeUserTarget(): User
{
    $user = new User;
    $user->id = (string) Str::uuid();
    $user->status = 'active';

    return $user;
}

// -------------------------------------------------------------------------
// staffManage
// -------------------------------------------------------------------------

it('staffManage: allows an admin staff actor', function () {
    $staff = makeStaffWithRole(PartnaStaff::ROLE_ADMIN);
    $target = makeUserTarget();

    expect($this->policy->staffManage($staff, $target))->toBeTrue();
});

it('staffManage: denies a support staff actor', function () {
    // Defence-in-depth: if a support staffer ever reaches this policy
    // (e.g. the route guard is misconfigured), the policy denies them.
    $staff = makeStaffWithRole(PartnaStaff::ROLE_SUPPORT);
    $target = makeUserTarget();

    expect($this->policy->staffManage($staff, $target))->toBeFalse();
});

// -------------------------------------------------------------------------
// staffForceDelete (the most destructive ability — irreversible)
// -------------------------------------------------------------------------

it('staffForceDelete: allows an admin staff actor', function () {
    $staff = makeStaffWithRole(PartnaStaff::ROLE_ADMIN);
    $target = makeUserTarget();

    expect($this->policy->staffForceDelete($staff, $target))->toBeTrue();
});

it('staffForceDelete: DENIES a support staff actor (irreversible action is admin-only)', function () {
    // This is the load-bearing DENIAL test. Even if a support staffer passes the
    // route-level staff.admin middleware (e.g. after a future role relaxation),
    // the policy prevents them from permanently destroying a user account.
    $staff = makeStaffWithRole(PartnaStaff::ROLE_SUPPORT);
    $target = makeUserTarget();

    expect($this->policy->staffForceDelete($staff, $target))->toBeFalse();
});

// -------------------------------------------------------------------------
// staffBulkManage (collection-level, no model target)
// -------------------------------------------------------------------------

it('staffBulkManage: allows an admin staff actor', function () {
    $staff = makeStaffWithRole(PartnaStaff::ROLE_ADMIN);

    expect($this->policy->staffBulkManage($staff))->toBeTrue();
});

it('staffBulkManage: denies a support staff actor', function () {
    // Bulk status changes affect many users at once — admin-only for the same
    // reason as forceDelete: the blast radius is too high for support access.
    $staff = makeStaffWithRole(PartnaStaff::ROLE_SUPPORT);

    expect($this->policy->staffBulkManage($staff))->toBeFalse();
});
