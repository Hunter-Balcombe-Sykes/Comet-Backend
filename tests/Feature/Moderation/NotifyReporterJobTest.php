<?php

use App\Jobs\Moderation\NotifyReporterJob;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\ReportOutcomeNotification;
use Illuminate\Notifications\AnonymousNotifiable;
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

it('does not re-email reporters when a retry follows a crash between the send and the completion mark', function () {
    Notification::fake();
    $case = ModerationCase::factory()->create();
    CaseSignal::factory()->forCase($case)->create(['reporter_email' => 'r1@example.com']);
    CaseSignal::factory()->forCase($case)->create(['reporter_email' => 'r2@example.com']);
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create(['decision_type' => 'hide_site']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_reporter']);

    (new NotifyReporterJob($entry->id, $case->id))->handle();

    // Simulate a crash after the sends completed but before the completion mark committed.
    ActionLogEntry::query()->where('id', $entry->id)->update(['status' => 'dispatched', 'completed_at' => null]);

    (new NotifyReporterJob($entry->id, $case->id))->handle();

    Notification::assertCount(2);
    expect($entry->fresh()->status)->toBe('completed');
});

it('re-sends only the reporter whose send threw, not the ones already emailed', function () {
    Notification::fake();
    $case = ModerationCase::factory()->create();
    CaseSignal::factory()->forCase($case)->create(['reporter_email' => 'r1@example.com']);
    CaseSignal::factory()->forCase($case)->create(['reporter_email' => 'r2@example.com']);
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create(['decision_type' => 'hide_site']);
    $entry = ActionLogEntry::factory()->forDecision($decision)->create(['action_type' => 'notify_reporter']);

    // Fail the SECOND recipient processed, whichever email that turns out to be —
    // reporters is an unordered ->unique() collection, so pinning a literal email
    // here would make the test's pass/fail depend on iteration order rather than
    // on the behaviour under test.
    $job = new class($entry->id, $case->id) extends NotifyReporterJob
    {
        public static array $calls = [];

        public static bool $hasThrown = false;

        protected function sendTo(string $email, $decision): void
        {
            self::$calls[] = $email;
            if (count(self::$calls) === 2 && ! self::$hasThrown) {
                self::$hasThrown = true;
                throw new RuntimeException('simulated mailer failure');
            }
            parent::sendTo($email, $decision);
        }
    };

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);

    [$sentEmail, $failedEmail] = $job::$calls;

    $sentCount = fn (string $email) => Notification::sent(
        new AnonymousNotifiable,
        ReportOutcomeNotification::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $email
    )->count();

    // The first recipient's claim was consumed by a real send; the second's
    // claim was rolled back by releaseRecipient() on the throw.
    expect($sentCount($sentEmail))->toBe(1);
    expect($sentCount($failedEmail))->toBe(0);

    // Retry with the throw disabled — the "fixed mailer" attempt.
    $entry->refresh();
    (new NotifyReporterJob($entry->id, $case->id))->handle();

    expect($sentCount($sentEmail))->toBe(1); // not re-sent
    expect($sentCount($failedEmail))->toBe(1); // now delivered
    expect($entry->fresh()->status)->toBe('completed');
});

it('emails the same reporter again for a different action log entry', function () {
    Notification::fake();
    $case = ModerationCase::factory()->create();
    CaseSignal::factory()->forCase($case)->create(['reporter_email' => 'repeat@example.com']);

    $decision1 = Decision::factory()->forCase($case)->systemAutoActioned()->create(['decision_type' => 'hide_site']);
    $entry1 = ActionLogEntry::factory()->forDecision($decision1)->create(['action_type' => 'notify_reporter']);
    (new NotifyReporterJob($entry1->id, $case->id))->handle();

    $decision2 = Decision::factory()->forCase($case)->systemAutoActioned()->create(['decision_type' => 'hide_site']);
    $entry2 = ActionLogEntry::factory()->forDecision($decision2)->create(['action_type' => 'notify_reporter']);
    (new NotifyReporterJob($entry2->id, $case->id))->handle();

    Notification::assertCount(2);
});
