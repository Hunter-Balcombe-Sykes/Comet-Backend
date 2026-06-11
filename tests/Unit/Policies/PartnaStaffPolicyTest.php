<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Policies\PartnaStaffPolicy;
use Illuminate\Auth\Access\Response;

beforeEach(function () {
    $this->policy = new PartnaStaffPolicy;
});

// --- view ---

it('allows admin to view any staff record', function () {
    $admin = (new PartnaStaff)->forceFill(['id' => 'staff-1', 'role' => PartnaStaff::ROLE_ADMIN]);
    $other = (new PartnaStaff)->forceFill(['id' => 'staff-2', 'role' => PartnaStaff::ROLE_SUPPORT]);

    expect($this->policy->view($admin, $other))->toBeTrue();
});

it('allows support to view their own record', function () {
    $self = (new PartnaStaff)->forceFill(['id' => 'staff-1', 'role' => PartnaStaff::ROLE_SUPPORT]);

    expect($this->policy->view($self, $self))->toBeTrue();
});

it('denies support viewing another staff record with 404', function () {
    $actor = (new PartnaStaff)->forceFill(['id' => 'staff-1', 'role' => PartnaStaff::ROLE_SUPPORT]);
    $target = (new PartnaStaff)->forceFill(['id' => 'staff-2', 'role' => PartnaStaff::ROLE_SUPPORT]);

    $result = $this->policy->view($actor, $target);

    expect($result)->toBeInstanceOf(Response::class);
    expect($result->status())->toBe(404);
});

// --- update ---

it('allows admin to update another staff record', function () {
    $admin = (new PartnaStaff)->forceFill(['id' => 'staff-1', 'role' => PartnaStaff::ROLE_ADMIN]);
    $target = (new PartnaStaff)->forceFill(['id' => 'staff-2', 'role' => PartnaStaff::ROLE_SUPPORT]);

    expect($this->policy->update($admin, $target))->toBeTrue();
});

it('denies admin from updating their own record (no self-edit)', function () {
    $admin = (new PartnaStaff)->forceFill(['id' => 'staff-1', 'role' => PartnaStaff::ROLE_ADMIN]);

    expect($this->policy->update($admin, $admin))->toBeFalse();
});

it('denies support from updating anyone', function () {
    $actor = (new PartnaStaff)->forceFill(['id' => 'staff-1', 'role' => PartnaStaff::ROLE_SUPPORT]);
    $target = (new PartnaStaff)->forceFill(['id' => 'staff-2', 'role' => PartnaStaff::ROLE_SUPPORT]);

    expect($this->policy->update($actor, $target))->toBeFalse();
});

it('denies support from updating their own record', function () {
    $actor = (new PartnaStaff)->forceFill(['id' => 'staff-1', 'role' => PartnaStaff::ROLE_SUPPORT]);

    expect($this->policy->update($actor, $actor))->toBeFalse();
});

// --- delete ---

it('allows admin to delete another staff record', function () {
    $admin = (new PartnaStaff)->forceFill(['id' => 'staff-1', 'role' => PartnaStaff::ROLE_ADMIN]);
    $target = (new PartnaStaff)->forceFill(['id' => 'staff-2', 'role' => PartnaStaff::ROLE_SUPPORT]);

    expect($this->policy->delete($admin, $target))->toBeTrue();
});

it('denies admin from deleting their own record (prevents org lockout)', function () {
    $admin = (new PartnaStaff)->forceFill(['id' => 'staff-1', 'role' => PartnaStaff::ROLE_ADMIN]);

    expect($this->policy->delete($admin, $admin))->toBeFalse();
});

it('denies support from deleting anyone', function () {
    $actor = (new PartnaStaff)->forceFill(['id' => 'staff-1', 'role' => PartnaStaff::ROLE_SUPPORT]);
    $target = (new PartnaStaff)->forceFill(['id' => 'staff-2', 'role' => PartnaStaff::ROLE_SUPPORT]);

    expect($this->policy->delete($actor, $target))->toBeFalse();
});
