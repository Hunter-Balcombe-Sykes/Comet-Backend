<?php

use App\Jobs\Moderation\NotifyStaffOfCaseUpdateJob;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\CaseCreatedStaffNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    setupPartnaStaffTable();
    setupModerationCasesTable();
});

it('notifies admin staff at threshold signal_count 1', function () {
    Notification::fake();
    $staff = PartnaStaff::factory()->create(['role' => 'admin']);
    $case = ModerationCase::factory()->create(['signal_count' => 1]);

    (new NotifyStaffOfCaseUpdateJob($case->id))->handle();

    Notification::assertSentTo($staff, CaseCreatedStaffNotification::class);
});

it('does NOT notify at signal_count 2 (between thresholds)', function () {
    Notification::fake();
    PartnaStaff::factory()->create(['role' => 'admin']);
    $case = ModerationCase::factory()->create(['signal_count' => 2]);

    (new NotifyStaffOfCaseUpdateJob($case->id))->handle();

    Notification::assertNothingSent();
});

it('notifies at threshold 3', function () {
    Notification::fake();
    $staff = PartnaStaff::factory()->create(['role' => 'admin']);
    $case = ModerationCase::factory()->create(['signal_count' => 3]);

    (new NotifyStaffOfCaseUpdateJob($case->id))->handle();

    Notification::assertSentTo($staff, CaseCreatedStaffNotification::class);
});

it('does not notify support-role staff (only admin)', function () {
    Notification::fake();
    PartnaStaff::factory()->create(['role' => 'support']);
    $case = ModerationCase::factory()->create(['signal_count' => 1]);

    (new NotifyStaffOfCaseUpdateJob($case->id))->handle();

    Notification::assertNothingSent();
});
