<?php

/**
 * B23 — FeatureFlagPolicy enforcement (FeatureFlag + FeatureFlagOverride models).
 *
 * FeatureFlagPolicy is registered for both models:
 *   Gate::policy(FeatureFlag::class, FeatureFlagPolicy::class)
 *   Gate::policy(FeatureFlagOverride::class, FeatureFlagPolicy::class)
 *
 * The three User-typed methods (viewAny/view/manage) are a deny-all shield —
 * the real auth gate is the EnsurePartnaStaff middleware on the staff route
 * group. They exist as defence-in-depth so a misconfigured non-staff route
 * cannot grant a Professional actor access via Gate::forUser($pro).
 *
 * PartnaStaff actors (#FFLAG-2) are dispatched to staffView/staffManage by
 * first-parameter type — they do NOT bypass the policy; they are routed to a
 * separate set of methods. staffView = support|admin, staffManage = admin only.
 *
 * All assertions go through Gate::forUser() — never via direct policy instantiation.
 */

use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

function featureFlagPolicy_user(): User
{
    $user = new User;
    $user->id = 'ff-user-1';

    return $user;
}

function featureFlagPolicy_staff(string $role): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = $role;

    return $staff;
}

// --- FeatureFlag ---

it('denies a user viewAny feature flags', function () {
    expect(
        Gate::forUser(featureFlagPolicy_user())->allows('viewAny', FeatureFlag::class)
    )->toBeFalse();
});

it('denies a user viewing a FeatureFlag resource', function () {
    $flag = new FeatureFlag;
    $flag->key = 'some-flag';

    expect(
        Gate::forUser(featureFlagPolicy_user())->allows('view', $flag)
    )->toBeFalse();
});

it('denies a user managing a FeatureFlag resource', function () {
    $flag = new FeatureFlag;
    $flag->key = 'some-flag';

    expect(
        Gate::forUser(featureFlagPolicy_user())->allows('manage', $flag)
    )->toBeFalse();
});

// --- FeatureFlagOverride ---

it('denies a user viewing a FeatureFlagOverride resource', function () {
    $override = new FeatureFlagOverride;
    $override->id = 'ffo-1';

    expect(
        Gate::forUser(featureFlagPolicy_user())->allows('view', $override)
    )->toBeFalse();
});

it('denies a user managing a FeatureFlagOverride resource', function () {
    $override = new FeatureFlagOverride;
    $override->id = 'ffo-1';

    expect(
        Gate::forUser(featureFlagPolicy_user())->allows('manage', $override)
    )->toBeFalse();
});

// --- staffView / staffManage (#FFLAG-2) ---

it('staffView: admin actor is allowed against the FeatureFlag class string', function () {
    expect(
        Gate::forUser(featureFlagPolicy_staff(PartnaStaff::ROLE_ADMIN))->allows('staffView', FeatureFlag::class)
    )->toBeTrue();
});

it('staffView: support actor is allowed against the FeatureFlag class string', function () {
    expect(
        Gate::forUser(featureFlagPolicy_staff(PartnaStaff::ROLE_SUPPORT))->allows('staffView', FeatureFlag::class)
    )->toBeTrue();
});

it('staffView: null actor is denied against the FeatureFlag class string', function () {
    expect(
        Gate::forUser(null)->allows('staffView', FeatureFlag::class)
    )->toBeFalse();
});

it('staffView: admin and support are allowed against the FeatureFlagOverride class string', function () {
    expect(
        Gate::forUser(featureFlagPolicy_staff(PartnaStaff::ROLE_ADMIN))->allows('staffView', FeatureFlagOverride::class)
    )->toBeTrue();
    expect(
        Gate::forUser(featureFlagPolicy_staff(PartnaStaff::ROLE_SUPPORT))->allows('staffView', FeatureFlagOverride::class)
    )->toBeTrue();
});

it('staffManage: admin actor is allowed against a FeatureFlag instance', function () {
    $flag = new FeatureFlag;
    $flag->key = 'some-flag';

    expect(
        Gate::forUser(featureFlagPolicy_staff(PartnaStaff::ROLE_ADMIN))->allows('staffManage', $flag)
    )->toBeTrue();
});

it('staffManage: support actor is denied against a FeatureFlag instance', function () {
    $flag = new FeatureFlag;
    $flag->key = 'some-flag';

    expect(
        Gate::forUser(featureFlagPolicy_staff(PartnaStaff::ROLE_SUPPORT))->allows('staffManage', $flag)
    )->toBeFalse();
});

it('staffManage: null actor is denied against the FeatureFlag class string', function () {
    expect(
        Gate::forUser(null)->allows('staffManage', FeatureFlag::class)
    )->toBeFalse();
});

it('staffManage: admin actor is allowed against a FeatureFlagOverride instance, support is denied', function () {
    $override = new FeatureFlagOverride;
    $override->id = 'ffo-1';

    expect(
        Gate::forUser(featureFlagPolicy_staff(PartnaStaff::ROLE_ADMIN))->allows('staffManage', $override)
    )->toBeTrue();
    expect(
        Gate::forUser(featureFlagPolicy_staff(PartnaStaff::ROLE_SUPPORT))->allows('staffManage', $override)
    )->toBeFalse();
});

it('regression: the User-typed manage shield still denies a User actor after staff seams were added', function () {
    $flag = new FeatureFlag;
    $flag->key = 'some-flag';

    expect(
        Gate::forUser(featureFlagPolicy_user())->allows('manage', $flag)
    )->toBeFalse();
});
