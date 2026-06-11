<?php

use App\Jobs\Moderation\NotifyReporterJob;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    setupPartnaStaffTable();
    setupAllModerationTables();
});

it('sends ReportOutcomeNotification to all reporters with reporter_email', function () {
    Notification::fake();
    $case = ModerationCase::factory()->create();
    CaseSignal::factory()->forCase($case)->create(['reporter_email' => 'r1@example.com']);
    CaseSignal::factory()->forCase($case)->create(['reporter_email' => 'r2@example.com']);
    CaseSignal::factory()->forCase($case)->create(['reporter_email' => null]); // anonymous — skipped

    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create(['decision_type' => 'hide_site']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_reporter']);

    (new NotifyReporterJob($entry->id, $case->id))->handle();

    // Two notifications sent: one each to r1@ and r2@; none to anonymous
    Notification::assertCount(2);
    expect($entry->fresh()->status)->toBe('completed');
});

it('skips when all reporters were anonymous', function () {
    Notification::fake();
    $case = ModerationCase::factory()->create();
    CaseSignal::factory()->forCase($case)->create(['reporter_email' => null]);
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create();
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_reporter']);

    (new NotifyReporterJob($entry->id, $case->id))->handle();

    Notification::assertNothingSent();
    expect($entry->fresh()->status)->toBe('completed');
});

it('deduplicates when same email appears multiple times', function () {
    Notification::fake();
    $case = ModerationCase::factory()->create();
    CaseSignal::factory()->forCase($case)->create(['reporter_email' => 'dupe@example.com']);
    CaseSignal::factory()->forCase($case)->create(['reporter_email' => 'dupe@example.com']); // duplicate
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create();
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_reporter']);

    (new NotifyReporterJob($entry->id, $case->id))->handle();

    Notification::assertCount(1); // deduplicated
});
