<?php

use App\Models\Core\Notifications\NotificationEmailPreference;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    setupUsersTable();
    setupNotificationEmailPreferencesTable();
});

it('one-click POST flips the category preference off', function () {
    $user = User::factory()->create(['status' => 'active']);

    $url = URL::signedRoute('public.notification-unsubscribe', [
        'userId' => $user->id,
        'category' => 'feature_announcement',
    ]);

    $this->post($url)->assertOk()->assertJsonPath('unsubscribed', true);

    $pref = NotificationEmailPreference::query()
        ->where('user_id', $user->id)
        ->where('category_key', 'feature_announcement')
        ->first();

    expect($pref)->not->toBeNull()
        ->and($pref->enabled)->toBeFalse();

    // Idempotent — a second click (GET this time) still 2xx.
    $this->get($url)->assertOk();
});

it('rejects a tampered signature', function () {
    $user = User::factory()->create(['status' => 'active']);

    $url = URL::signedRoute('public.notification-unsubscribe', [
        'userId' => $user->id,
        'category' => 'feature_announcement',
    ]);

    $this->post($url.'x')->assertForbidden();
});

it('refuses mandatory and unknown categories', function () {
    $user = User::factory()->create(['status' => 'active']);

    foreach (['policy_update', 'critical', 'not_a_category'] as $category) {
        $url = URL::signedRoute('public.notification-unsubscribe', [
            'userId' => $user->id,
            'category' => $category,
        ]);

        $this->post($url)->assertNotFound();
    }
});
