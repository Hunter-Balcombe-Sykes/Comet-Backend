<?php

/**
 * B23 — CasePolicy enforcement (ModerationCase model).
 *
 * CasePolicy is registered: Gate::policy(ModerationCase::class, CasePolicy::class).
 * All abilities are staff-only; Users are denied on the two User|PartnaStaff methods.
 * The triage/take/release/decide/escalate methods are PartnaStaff-typed — passing
 * a User actor to those would cause a TypeError, so we only test them with staff.
 *
 * All assertions go through Gate::forUser(), which resolves the policy from the
 * resource class (ModerationCase → CasePolicy). A direct `new CasePolicy()->method()`
 * call would bypass that resolution.
 */

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\Gate;

function casePolicy_staff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->role = PartnaStaff::ROLE_ADMIN;

    return $staff;
}

function casePolicy_user(): User
{
    $user = new User;
    $user->id = 'user-case-1';

    return $user;
}

function casePolicy_case(): ModerationCase
{
    $case = new ModerationCase;
    $case->id = 'case-1';

    return $case;
}

// --- viewAny ---

it('allows staff to viewAny moderation cases', function () {
    expect(
        Gate::forUser(casePolicy_staff())->allows('viewAny', ModerationCase::class)
    )->toBeTrue();
});

it('denies a user viewAny moderation cases', function () {
    expect(
        Gate::forUser(casePolicy_user())->allows('viewAny', ModerationCase::class)
    )->toBeFalse();
});

// --- view ---

it('allows staff to view a moderation case', function () {
    expect(
        Gate::forUser(casePolicy_staff())->allows('view', casePolicy_case())
    )->toBeTrue();
});

it('denies a user viewing a moderation case', function () {
    expect(
        Gate::forUser(casePolicy_user())->allows('view', casePolicy_case())
    )->toBeFalse();
});

// --- PartnaStaff-typed abilities: triage/take/release/decide/escalate ---
// These methods are typed `PartnaStaff $staff` — only testing with staff actor.

it('allows staff to triage a moderation case', function () {
    expect(
        Gate::forUser(casePolicy_staff())->allows('triage', casePolicy_case())
    )->toBeTrue();
});

it('allows staff to take a moderation case', function () {
    expect(
        Gate::forUser(casePolicy_staff())->allows('take', casePolicy_case())
    )->toBeTrue();
});

it('allows staff to release a moderation case', function () {
    expect(
        Gate::forUser(casePolicy_staff())->allows('release', casePolicy_case())
    )->toBeTrue();
});

it('allows staff to decide on a moderation case', function () {
    expect(
        Gate::forUser(casePolicy_staff())->allows('decide', casePolicy_case())
    )->toBeTrue();
});

it('allows staff to escalate a moderation case', function () {
    expect(
        Gate::forUser(casePolicy_staff())->allows('escalate', casePolicy_case())
    )->toBeTrue();
});
