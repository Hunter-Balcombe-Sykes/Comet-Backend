<?php

/**
 * SEC-8 / SEC-9 — the `staffManageBlock` ability that gates staff block mutations
 * (StaffSectionManagementController + StaffLinkBlockManagementController):
 *   - admin staff on an active professional  → allowed
 *   - support (non-admin) staff              → denied (403)
 *   - professional pending deletion          → 423 deny (even for admin)
 *
 * Asserted THROUGH THE GATE (Gate::forUser(...)->allows/inspect), NOT by calling a
 * policy class directly. The controllers call authorizeForUser($staff,
 * 'staffManageBlock', $professional) where $professional is a User, so the Gate
 * resolves the policy from User::class → UserSelfPolicy. A direct `new SomePolicy`
 * call would bypass that resolution and hide a mis-registered ability.
 */

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Gate;

function staffBlockPolicy_staff(string $role): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->role = $role;

    return $staff;
}

function staffBlockPolicy_pro(string $status): User
{
    $pro = new User;
    $pro->status = $status;

    return $pro;
}

it('allows admin staff to manage a block for an active professional', function () {
    expect(
        Gate::forUser(staffBlockPolicy_staff(PartnaStaff::ROLE_ADMIN))
            ->allows('staffManageBlock', staffBlockPolicy_pro('active'))
    )->toBeTrue();
});

it('denies support (non-admin) staff', function () {
    expect(
        Gate::forUser(staffBlockPolicy_staff(PartnaStaff::ROLE_SUPPORT))
            ->allows('staffManageBlock', staffBlockPolicy_pro('active'))
    )->toBeFalse();
});

it('denies with 423 when the professional is pending deletion, even for admin staff', function () {
    $response = Gate::forUser(staffBlockPolicy_staff(PartnaStaff::ROLE_ADMIN))
        ->inspect('staffManageBlock', staffBlockPolicy_pro('pending_deletion'));

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(423);
});
