<?php

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;
use Tests\TestCase;

// Relationship assertions require a booted Laravel app (DB resolver).
uses(TestCase::class)->in(__FILE__);

beforeEach(function () {
    setupUsersTable();
    setupPreAccountBuildsTable();
});

it('links 1:1 to its provisional user and scopes live builds', function () {
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);

    $build = new PreAccountBuild([
        'source_type' => 'instagram',
        'source_ref' => 'JaneDoe',
        'source_ref_lc' => 'janedoe',
        'built_via' => PreAccountBuild::VIA_SIGNUP,
        'expires_at' => now()->addDays(30),
    ]);
    $build->user()->associate($user);
    $build->save();

    expect($user->fresh()->preAccountBuild->id)->toBe($build->id)
        ->and($user->fresh()->isUnclaimed())->toBeTrue()
        ->and(PreAccountBuild::live()->count())->toBe(1);

    $build->forceFill(['claimed_at' => now()])->save(); // B11 SEC-4: claimed_at no longer fillable
    expect(PreAccountBuild::live()->count())->toBe(0);
});

it('does not mass-assign tenancy FKs', function () {
    expect((new PreAccountBuild)->isFillable('user_id'))->toBeFalse()
        ->and((new PreAccountBuild)->isFillable('built_by_staff_id'))->toBeFalse();
});

it('treats a VIA_STAFF build as outreach even with no built_by_staff_id', function () {
    // built_by_staff_id is ON DELETE SET NULL — deleting the staff row that
    // created a build must not silently un-gate it. built_via survives that
    // deletion, so isOutreach() must check it too, not just the FK.
    $build = new PreAccountBuild(['built_via' => PreAccountBuild::VIA_STAFF]);

    expect($build->isOutreach())->toBeTrue();
});

it('does not treat a VIA_SIGNUP build as outreach', function () {
    $build = new PreAccountBuild(['built_via' => PreAccountBuild::VIA_SIGNUP]);

    expect($build->isOutreach())->toBeFalse();
});
