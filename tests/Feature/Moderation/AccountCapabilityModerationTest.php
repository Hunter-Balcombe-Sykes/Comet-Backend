<?php

use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;

beforeEach(function () {
    setupUsersTable();
});

it('active user can receive moderation notifications', function () {
    $user = User::factory()->create(['status' => 'active']);
    expect(AccountCapabilities::for($user)->receive_moderation_notifications)->toBeTrue();
});

it('suspended user cannot receive moderation notifications', function () {
    $user = User::factory()->create(['status' => 'suspended']);
    expect(AccountCapabilities::for($user)->receive_moderation_notifications)->toBeFalse();
});

it('banned (disabled) user cannot receive moderation notifications', function () {
    $user = User::factory()->create(['status' => 'disabled']);
    expect(AccountCapabilities::for($user)->receive_moderation_notifications)->toBeFalse();
});

it('active user can_be_reported', function () {
    $user = User::factory()->create(['status' => 'active']);
    expect(AccountCapabilities::for($user)->can_be_reported)->toBeTrue();
});

it('suspended user cannot be reported', function () {
    $user = User::factory()->create(['status' => 'suspended']);
    expect(AccountCapabilities::for($user)->can_be_reported)->toBeFalse();
});
