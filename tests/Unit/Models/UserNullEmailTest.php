<?php

use App\Models\Core\User\User;
use Tests\TestCase;

// User::factory()->create() needs a booted Laravel app (DB resolver).
uses(TestCase::class)->in(__FILE__);

beforeEach(fn () => setupUsersTable());

it('routes mail as null for an email-less provisional user instead of throwing', function () {
    $user = User::factory()->create(['primary_email' => null, 'status' => 'unclaimed', 'auth_user_id' => null]);

    expect($user->routeNotificationForMail())->toBeNull();
});
