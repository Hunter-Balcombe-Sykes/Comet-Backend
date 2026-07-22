<?php

use App\Models\Core\User\PreAccountBuild;
use App\Models\Core\User\User;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPreAccountBuildsTable();
});

it('defaults auto_invite to true and invited_at to null', function () {
    $user = User::factory()->create(['status' => 'unclaimed']);
    $build = PreAccountBuild::factory()->make();
    $build->user()->associate($user);
    $build->save();

    $fresh = $build->fresh();
    expect($fresh->auto_invite)->toBeTrue()
        ->and($fresh->invited_at)->toBeNull();
});

it('casts auto_invite to boolean and is mass-assignable', function () {
    $user = User::factory()->create(['status' => 'unclaimed']);
    $build = PreAccountBuild::factory()->make(['auto_invite' => false]);
    $build->user()->associate($user);
    $build->save();

    expect($build->fresh()->auto_invite)->toBeFalse();
});
