<?php

use App\Jobs\Moderation\NotifyReportedUserJob;
use App\Models\Core\User\User;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\AccountSuspendedNotification;
use App\Notifications\Moderation\ContentHiddenNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    setupUsersTable();
    setupPartnaStaffTable();
    setupAllModerationTables();
});

it('sends ContentHiddenNotification for hide_site decisions', function () {
    Notification::fake();
    $user = User::factory()->create(['status' => 'active']);
    $case = ModerationCase::factory()->create([
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create(['decision_type' => 'hide_site']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_reported_user']);

    (new NotifyReportedUserJob($entry->id, $case->id))->handle();

    Notification::assertSentTo($user, ContentHiddenNotification::class);
    expect($entry->fresh()->status)->toBe('completed');
});

it('sends AccountSuspendedNotification for suspend_user decisions', function () {
    Notification::fake();
    $user = User::factory()->create(['status' => 'active']);
    $case = ModerationCase::factory()->create([
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create(['decision_type' => 'suspend_user']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_reported_user']);

    (new NotifyReportedUserJob($entry->id, $case->id))->handle();

    Notification::assertSentTo($user, AccountSuspendedNotification::class);
});

it('skips notification when user capability receive_moderation_notifications is false', function () {
    Notification::fake();
    $user = User::factory()->create(['status' => 'suspended']); // capability returns false
    $case = ModerationCase::factory()->create([
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create(['decision_type' => 'hide_site']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_reported_user']);

    (new NotifyReportedUserJob($entry->id, $case->id))->handle();

    Notification::assertNothingSent();
    expect($entry->fresh()->status)->toBe('completed');
});

it('marks entry completed when no owner user exists', function () {
    Notification::fake();
    $case = ModerationCase::factory()->create(['reportable_owner_user_id' => null]);
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create();
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_reported_user']);

    (new NotifyReportedUserJob($entry->id, $case->id))->handle();

    Notification::assertNothingSent();
    expect($entry->fresh()->status)->toBe('completed');
});

it('does not re-notify the reported user when a retry follows a crash after the send', function () {
    Notification::fake();
    $user = User::factory()->create(['status' => 'active']);
    $case = ModerationCase::factory()->create([
        'reportable_owner_user_id' => $user->id,
    ]);
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create(['decision_type' => 'hide_site']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_reported_user']);

    (new NotifyReportedUserJob($entry->id, $case->id))->handle();

    // Simulate a crash after the send completed but before the completion mark committed.
    ActionLogEntry::query()->where('id', $entry->id)->update(['status' => 'dispatched', 'completed_at' => null]);

    (new NotifyReportedUserJob($entry->id, $case->id))->handle();

    Notification::assertSentToTimes($user, ContentHiddenNotification::class, 1);
    expect($entry->fresh()->status)->toBe('completed');
});
