<?php

use App\Jobs\Moderation\NotifyOnCallStaffJob;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\CaseEscalatedStaffNotification;
use App\Notifications\Moderation\CsamAutoActionStaffNotification;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    setupPartnaStaffTable();
    setupAllModerationTables();
});

it('notifies on-call staff with CsamAutoAction for csam_match cases', function () {
    Notification::fake();
    $staff = PartnaStaff::factory()->create(['role' => 'admin']);
    $case = ModerationCase::factory()->csamMatch()->create();
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create();
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_oncall_staff']);

    (new NotifyOnCallStaffJob($entry->id, $case->id))->handle();

    Notification::assertSentTo($staff, CsamAutoActionStaffNotification::class);
    expect($entry->fresh()->status)->toBe('completed');
});

it('notifies on-call staff with Escalated for escalate_law_enforcement', function () {
    Notification::fake();
    $staff = PartnaStaff::factory()->create(['role' => 'admin']);
    $case = ModerationCase::factory()->create();
    $decision = Decision::factory()->forCase($case)->create(['decision_type' => 'escalate_law_enforcement']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_oncall_staff']);

    (new NotifyOnCallStaffJob($entry->id, $case->id))->handle();

    Notification::assertSentTo($staff, CaseEscalatedStaffNotification::class);
    expect($entry->fresh()->status)->toBe('completed');
});

// #W2-OBS-1: this case previously asserted the BUG — an empty on-call roster
// marked the entry 'completed' though nobody was paged. NotifyOnCallStaffJob is
// dispatched INDEPENDENTLY (nothing downstream in the Bus::chain), so it now
// throws and lets failed() write the audit trail. Do NOT convert this to the
// non-throwing markFailed() path used by the chained enforcement jobs.
it('throws instead of completing when no admin staff exist', function () {
    Exceptions::fake();
    Notification::fake();
    $case = ModerationCase::factory()->create();
    $decision = Decision::factory()->forCase($case)->create();
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_oncall_staff']);

    $job = new NotifyOnCallStaffJob($entry->id, $case->id);

    expect(fn () => $job->handle())
        ->toThrow(RuntimeException::class, 'No on-call staff available to page');

    Notification::assertNothingSent();
    expect($entry->fresh()->status)->not->toBe('completed');
    expect($entry->fresh()->status)->toBe('dispatched');
});

it('records the empty-roster failure on the action log once the job permanently fails', function () {
    Exceptions::fake();
    Notification::fake();
    $case = ModerationCase::factory()->create();
    $decision = Decision::factory()->forCase($case)->create();
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_oncall_staff']);

    $job = new NotifyOnCallStaffJob($entry->id, $case->id);

    // Mirrors the queue worker exhausting $tries and invoking failed().
    // NB: a sentinel, not $this->fail() — PHPUnit's AssertionFailedError extends
    // RuntimeException, so the catch below would swallow it.
    $thrown = null;
    try {
        $job->handle();
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    $job->failed($thrown);

    $fresh = $entry->fresh();
    expect($fresh->status)->toBe('failed');
    expect((string) $fresh->failure_reason)->toContain('No on-call staff available to page');
    Exceptions::assertReported(RuntimeException::class);
});

it('does not re-page on-call staff when a retry follows a crash after the send', function () {
    Notification::fake();
    $staffA = PartnaStaff::factory()->create(['role' => 'admin']);
    $staffB = PartnaStaff::factory()->create(['role' => 'admin']);
    $case = ModerationCase::factory()->csamMatch()->create();
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create();
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_oncall_staff']);

    (new NotifyOnCallStaffJob($entry->id, $case->id))->handle();

    // Simulate a crash after the sends completed but before the completion mark committed.
    ActionLogEntry::query()->where('id', $entry->id)->update(['status' => 'dispatched', 'completed_at' => null]);

    (new NotifyOnCallStaffJob($entry->id, $case->id))->handle();

    Notification::assertSentToTimes($staffA, CsamAutoActionStaffNotification::class, 1);
    Notification::assertSentToTimes($staffB, CsamAutoActionStaffNotification::class, 1);
    expect($entry->fresh()->status)->toBe('completed');
});
