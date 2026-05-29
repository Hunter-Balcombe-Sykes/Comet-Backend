<?php

use App\Jobs\Moderation\NotifyReportedUserJob;
use App\Models\Core\User\User;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\ContentHiddenNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    setupUsersTable();
    setupPartnaStaffTable();
    setupAllModerationTables();
});

it('does not notify users whose receive_moderation_notifications capability is false', function () {
    Notification::fake();

    // Disabled/banned users have receive_moderation_notifications = false (fail-closed).
    $user     = User::factory()->create(['status' => 'disabled']);
    $case     = ModerationCase::factory()->create(['reportable_owner_user_id' => $user->id]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site']);
    $entry    = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_reported_user']);

    (new NotifyReportedUserJob($entry->id, $case->id))->handle();

    Notification::assertNothingSent();
    // Entry must be completed (not failed) — skipping is intentional, not an error.
    expect($entry->fresh()->status)->toBe('completed');
});

it('still notifies users whose state is active', function () {
    Notification::fake();

    $user     = User::factory()->create(['status' => 'active']);
    $case     = ModerationCase::factory()->create(['reportable_owner_user_id' => $user->id]);
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'hide_site']);
    $entry    = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_reported_user']);

    (new NotifyReportedUserJob($entry->id, $case->id))->handle();

    Notification::assertSentTo($user, ContentHiddenNotification::class);
});
