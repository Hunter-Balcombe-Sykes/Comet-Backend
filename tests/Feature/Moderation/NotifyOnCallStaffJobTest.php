<?php

use App\Jobs\Moderation\NotifyOnCallStaffJob;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\CaseEscalatedStaffNotification;
use App\Notifications\Moderation\CsamAutoActionStaffNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    setupPartnaStaffTable();
    setupAllModerationTables();
});

it('notifies on-call staff with CsamAutoAction for csam_match cases', function () {
    Notification::fake();
    $staff = PartnaStaff::factory()->create(['role' => 'admin']);
    $case  = ModerationCase::factory()->csamMatch()->create();
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create();
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_oncall_staff']);

    (new NotifyOnCallStaffJob($entry->id, $case->id))->handle();

    Notification::assertSentTo($staff, CsamAutoActionStaffNotification::class);
    expect($entry->fresh()->status)->toBe('completed');
});

it('notifies on-call staff with Escalated for escalate_law_enforcement', function () {
    Notification::fake();
    $staff = PartnaStaff::factory()->create(['role' => 'admin']);
    $case  = ModerationCase::factory()->create();
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'escalate_law_enforcement']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_oncall_staff']);

    (new NotifyOnCallStaffJob($entry->id, $case->id))->handle();

    Notification::assertSentTo($staff, CaseEscalatedStaffNotification::class);
    expect($entry->fresh()->status)->toBe('completed');
});

it('completes gracefully when no admin staff exist', function () {
    Notification::fake();
    $case = ModerationCase::factory()->create();
    $decision = Decision::factory()->forCase($case)->create();
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_oncall_staff']);

    (new NotifyOnCallStaffJob($entry->id, $case->id))->handle();

    Notification::assertNothingSent();
    expect($entry->fresh()->status)->toBe('completed');
});
