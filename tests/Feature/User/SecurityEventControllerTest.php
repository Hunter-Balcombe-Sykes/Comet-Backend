<?php

use App\Mail\Security\PasswordChangedMail;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    setupUsersTable();
    Cache::flush();
});

it('queues the password-changed notice for the acting user', function () {
    Mail::fake();
    $pro = User::factory()->create(['status' => 'active']);

    actingAsUser($pro)->postJson('/api/me/security-events', ['event' => 'password_changed'])
        ->assertOk();

    Mail::assertQueued(PasswordChangedMail::class, fn ($m) => $m->recipientEmail === $pro->primary_email);
});

it('dedupes repeat pings inside the cooldown window', function () {
    Mail::fake();
    $pro = User::factory()->create(['status' => 'active']);

    actingAsUser($pro)->postJson('/api/me/security-events', ['event' => 'password_changed'])->assertOk();
    actingAsUser($pro)->postJson('/api/me/security-events', ['event' => 'password_changed'])->assertOk();

    Mail::assertQueuedCount(1);
});

it('rejects unknown events', function () {
    Mail::fake();
    $pro = User::factory()->create(['status' => 'active']);

    actingAsUser($pro)->postJson('/api/me/security-events', ['event' => 'account_hacked'])
        ->assertStatus(422);

    Mail::assertNothingQueued();
});
