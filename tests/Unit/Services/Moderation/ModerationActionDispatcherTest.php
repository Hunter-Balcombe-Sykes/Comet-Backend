<?php

use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\Decision;
use App\Services\Moderation\ModerationActionDispatcher;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    setupPartnaStaffTable();
    setupAllModerationTables();
});

it('writes action_log rows for hide_site (suspend_site + sync_subdomain_kv + notify_reported_user)', function () {
    $decision = Decision::factory()->create(['decision_type' => 'hide_site']);
    app(ModerationActionDispatcher::class)->dispatchFor($decision);

    $types = ActionLogEntry::query()->where('decision_id', $decision->id)->pluck('action_type')->all();
    expect($types)->toContain('suspend_site', 'sync_subdomain_kv', 'notify_reported_user');
});

it('writes action_log rows for suspend_user (suspend_user + suspend_site + sync_subdomain_kv + notify_reported_user)', function () {
    $decision = Decision::factory()->create(['decision_type' => 'suspend_user']);
    app(ModerationActionDispatcher::class)->dispatchFor($decision);

    $types = ActionLogEntry::query()->where('decision_id', $decision->id)->pluck('action_type')->all();
    expect($types)->toContain('suspend_user', 'suspend_site', 'sync_subdomain_kv', 'notify_reported_user');
});

it('does not include notify_reporter when the case has no reporter_email', function () {
    $decision = Decision::factory()->create(['decision_type' => 'warn']);
    app(ModerationActionDispatcher::class)->dispatchFor($decision);

    $types = ActionLogEntry::query()->where('decision_id', $decision->id)->pluck('action_type')->all();
    expect($types)->not->toContain('notify_reporter');
});

it('emits no actions for dismiss decision', function () {
    $decision = Decision::factory()->create(['decision_type' => 'dismiss']);
    app(ModerationActionDispatcher::class)->dispatchFor($decision);

    expect(ActionLogEntry::query()->where('decision_id', $decision->id)->count())->toBe(0);
});
