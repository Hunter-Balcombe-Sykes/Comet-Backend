<?php

/**
 * B23 — FeatureFlagPolicy enforcement (FeatureFlag + FeatureFlagOverride models).
 *
 * FeatureFlagPolicy is registered for both models:
 *   Gate::policy(FeatureFlag::class, FeatureFlagPolicy::class)
 *   Gate::policy(FeatureFlagOverride::class, FeatureFlagPolicy::class)
 *
 * The policy is a deny-all for User actors — the real auth gate is the
 * EnsurePartnaStaff middleware on the staff route group. The policy exists as a
 * defence-in-depth layer so a misconfigured non-staff route cannot grant a
 * Professional actor access to flag management via Gate::forUser($pro).
 *
 * Policy methods are User-typed, so we test with a User actor only. PartnaStaff
 * actors bypass the policy entirely (middleware is the gate for staff).
 *
 * All assertions go through Gate::forUser() — never via direct policy instantiation.
 */

use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Gate;

function featureFlagPolicy_user(): User
{
    $user = new User;
    $user->id = 'ff-user-1';

    return $user;
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
