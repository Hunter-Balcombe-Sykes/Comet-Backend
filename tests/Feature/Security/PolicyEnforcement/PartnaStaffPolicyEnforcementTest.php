<?php

/**
 * TEST-11 — PartnaStaffPolicy enforcement via the Gate.
 *
 * Actor AND target are both PartnaStaff in-memory models (no DB round-trip
 * needed — the policy reads only $actor->role, $actor->id, and $target->id).
 * All assertions go through Gate::forUser(), never new PartnaStaffPolicy().
 *
 * Abilities covered:
 *   view   — admin sees any target; support sees own (same id), 404 on other.
 *   update — admin + different target → true; admin + self → false (403);
 *            support (any target) → false (403).
 *   delete — same shape as update.
 *
 * The self-edit/self-delete guard (B24 finding) is explicitly called out below.
 */

use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

// ---------------------------------------------------------------------------
// Helpers — file-unique names to avoid global-function collision.
// ---------------------------------------------------------------------------

function staffPolicyActor(string $role, ?string $id = null): PartnaStaff
{
    $actor = new PartnaStaff;
    $actor->id = $id ?? (string) Str::uuid();
    $actor->role = $role;

    return $actor;
}

function staffPolicyTarget(?string $id = null): PartnaStaff
{
    $target = new PartnaStaff;
    $target->id = $id ?? (string) Str::uuid();
    $target->role = PartnaStaff::ROLE_SUPPORT; // role on target is irrelevant to these checks

    return $target;
}

// ---------------------------------------------------------------------------
// view
// ---------------------------------------------------------------------------

it('allows admin staff to view any staff target', function () {
    $actor = staffPolicyActor(PartnaStaff::ROLE_ADMIN);
    $target = staffPolicyTarget(); // different id

    expect(
        Gate::forUser($actor)->allows('view', $target)
    )->toBeTrue();
});

it('allows support staff to view their own record (same id)', function () {
    $sharedId = (string) Str::uuid();
    $actor = staffPolicyActor(PartnaStaff::ROLE_SUPPORT, $sharedId);
    $target = staffPolicyTarget($sharedId); // same id → own record

    expect(
        Gate::forUser($actor)->allows('view', $target)
    )->toBeTrue();
});

it('denies support staff viewing a different staff record with 404', function () {
    $actor = staffPolicyActor(PartnaStaff::ROLE_SUPPORT);
    $target = staffPolicyTarget(); // distinct id

    $response = Gate::forUser($actor)->inspect('view', $target);

    expect($response->denied())->toBeTrue();
    expect($response->status())->toBe(404);
});

// ---------------------------------------------------------------------------
// update — self-edit guard is the key B24 finding
// ---------------------------------------------------------------------------

it('allows admin staff to update a DIFFERENT staff record', function () {
    $actor = staffPolicyActor(PartnaStaff::ROLE_ADMIN);
    $target = staffPolicyTarget(); // distinct id

    expect(
        Gate::forUser($actor)->allows('update', $target)
    )->toBeTrue();
});

it('denies admin staff from updating their OWN record (self-edit guard, 403)', function () {
    $sharedId = (string) Str::uuid();
    $actor = staffPolicyActor(PartnaStaff::ROLE_ADMIN, $sharedId);
    $target = staffPolicyTarget($sharedId); // same id → self

    // Returns false → Gate renders 403 (not 404).
    expect(
        Gate::forUser($actor)->allows('update', $target)
    )->toBeFalse();
});

it('denies support staff from updating any staff record (403)', function () {
    $actor = staffPolicyActor(PartnaStaff::ROLE_SUPPORT);
    $target = staffPolicyTarget();

    expect(
        Gate::forUser($actor)->allows('update', $target)
    )->toBeFalse();
});

// ---------------------------------------------------------------------------
// delete — mirrors update shape; self-delete guard is the other B24 finding
// ---------------------------------------------------------------------------

it('allows admin staff to delete a DIFFERENT staff record', function () {
    $actor = staffPolicyActor(PartnaStaff::ROLE_ADMIN);
    $target = staffPolicyTarget(); // distinct id

    expect(
        Gate::forUser($actor)->allows('delete', $target)
    )->toBeTrue();
});

it('denies admin staff from deleting their OWN record (self-delete guard, 403)', function () {
    $sharedId = (string) Str::uuid();
    $actor = staffPolicyActor(PartnaStaff::ROLE_ADMIN, $sharedId);
    $target = staffPolicyTarget($sharedId); // same id → self

    // Returns false → Gate renders 403.
    expect(
        Gate::forUser($actor)->allows('delete', $target)
    )->toBeFalse();
});

it('denies support staff from deleting any staff record (403)', function () {
    $actor = staffPolicyActor(PartnaStaff::ROLE_SUPPORT);
    $target = staffPolicyTarget();

    expect(
        Gate::forUser($actor)->allows('delete', $target)
    )->toBeFalse();
});
