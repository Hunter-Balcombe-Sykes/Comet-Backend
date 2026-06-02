<?php

use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Notifications\Moderation\AccountSuspendedNotification;
use App\Notifications\Moderation\ContentHiddenNotification;
use App\Notifications\Moderation\ReportOutcomeNotification;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

beforeEach(fn () => setupAllModerationTables());

it('builds mail message for ContentHiddenNotification', function () {
    $case = ModerationCase::factory()->create();
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create(['decision_type' => 'hide_site', 'reason' => 'spam']);

    $n = new ContentHiddenNotification($decision);
    $mail = $n->toMail(new \stdClass);
    expect($mail->subject)->toContain('hidden');
});

it('AccountSuspendedNotification mail message includes reason', function () {
    $case = ModerationCase::factory()->create();
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create(['decision_type' => 'suspend_user', 'reason' => 'repeated violations']);

    $n = new AccountSuspendedNotification($decision);
    $mail = $n->toMail(new \stdClass);
    expect($mail->introLines)->not->toBeEmpty();
});

it('ReportOutcomeNotification mail message contains outcome key', function () {
    $case = ModerationCase::factory()->create();
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create(['decision_type' => 'hide_site', 'reason' => 'spam']);

    $n = new ReportOutcomeNotification($decision);
    $array = $n->toArray(new \stdClass);
    expect($array)->toHaveKey('outcome');
});
