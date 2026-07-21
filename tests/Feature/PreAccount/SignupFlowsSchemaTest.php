<?php

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;

beforeEach(function () {
    setupUsersTable();
    setupPreAccountBuildsTable();
    setupEarlyAccessTable();
});

it('persists an early-access build with contact_email and null expiry', function () {
    $user = User::factory()->create(['status' => 'unclaimed', 'auth_user_id' => null, 'primary_email' => null]);

    $build = new PreAccountBuild([
        'source_type' => 'instagram',
        'source_ref' => 'prospect',
        'source_ref_lc' => 'prospect',
        'built_via' => PreAccountBuild::VIA_EARLY_ACCESS,
        'contact_email' => 'lead@example.com',
        'expires_at' => null,
    ]);
    $build->build_state = PreAccountBuild::STATE_PENDING;
    $build->user()->associate($user);
    $build->save();

    $fresh = $build->fresh();
    expect($fresh->built_via)->toBe('early_access')
        ->and($fresh->contact_email)->toBe('lead@example.com')
        ->and($fresh->expires_at)->toBeNull();
});
