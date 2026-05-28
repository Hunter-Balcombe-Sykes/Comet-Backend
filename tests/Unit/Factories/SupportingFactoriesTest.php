<?php

use App\Models\Core\Site\Site;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPartnaStaffTable();
});

it('Site::factory() creates a valid Site row tied to a User', function () {
    $user = User::factory()->create();
    $site = Site::factory()->for($user, 'user')->create();

    expect($site->user_id)->toBe($user->id);
    expect($site->subdomain)->toBeString();
    expect($site->skeleton_id)->toBe('skeleton-1');
});

it('PartnaStaff::factory()->create() defaults to support role', function () {
    $staff = PartnaStaff::factory()->create();
    expect($staff->role)->toBe('support');
    expect($staff->auth_user_id)->toBeString();
});

it('PartnaStaff::factory()->admin()->create() promotes to admin role', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    expect($staff->role)->toBe('admin');
});
