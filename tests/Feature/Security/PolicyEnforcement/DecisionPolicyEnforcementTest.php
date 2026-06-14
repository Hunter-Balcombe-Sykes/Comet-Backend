<?php

/**
 * B23 — DecisionPolicy enforcement (Decision model).
 *
 * DecisionPolicy is registered: Gate::policy(Decision::class, DecisionPolicy::class).
 * Both methods are PartnaStaff-typed and return true — the tests here prove the
 * policy IS registered and resolves correctly from the resource's class. An
 * unregistered policy would cause the Gate to deny every call, so a `true` result
 * proves the policy wiring is intact (not a tautology — it catches missing
 * Gate::policy() registrations in AppServiceProvider).
 *
 * No User actor tests: both methods are typed `PartnaStaff $staff`; passing a User
 * would cause a PHP TypeError at call time.
 *
 * All assertions go through Gate::forUser() — never via direct policy instantiation.
 */

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\Decision;
use Illuminate\Support\Facades\Gate;

function decisionPolicy_staff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->role = PartnaStaff::ROLE_ADMIN;

    return $staff;
}

function decisionPolicy_decision(): Decision
{
    $decision = new Decision;
    $decision->id = 'decision-1';

    return $decision;
}

it('allows staff to view a decision', function () {
    expect(
        Gate::forUser(decisionPolicy_staff())->allows('view', decisionPolicy_decision())
    )->toBeTrue();
});

it('allows staff to reverse a decision', function () {
    expect(
        Gate::forUser(decisionPolicy_staff())->allows('reverse', decisionPolicy_decision())
    )->toBeTrue();
});
