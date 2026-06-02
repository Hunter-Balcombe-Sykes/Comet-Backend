<?php

use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\AuditEvent;
use App\Models\Moderation\Decision;
use App\Models\Moderation\Evidence;
use App\Models\Moderation\ModerationCase;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    setupModerationCasesTable();
    setupModerationCaseSignalsTable();
    setupModerationEvidenceTable();
    setupModerationDecisionsTable();
    setupModerationActionLogTable();
    setupAuditModerationEventsTable();
    setupPartnaStaffTable();
});

it('creates Evidence with case relation + JSONB payload', function () {
    $case = ModerationCase::factory()->create();
    $ev = Evidence::factory()->forCase($case)->create(['payload' => ['name' => 'snapshot']]);

    expect($ev->case->id)->toBe($case->id);
    expect($ev->payload)->toBe(['name' => 'snapshot']);
    expect($ev->evidence_type)->toBe('content_snapshot');
});

it('creates Decision with auto_actioned system flag', function () {
    $case = ModerationCase::factory()->create();
    $decision = Decision::factory()->forCase($case)->systemAutoActioned()->create();

    expect($decision->decided_by_system)->toBeTrue();
    expect($decision->decided_by_staff_id)->toBeNull();
    expect($decision->auto_actioned)->toBeTrue();
});

it('creates ActionLogEntry tied to a decision', function () {
    $decision = Decision::factory()->systemAutoActioned()->create();
    $entry = ActionLogEntry::factory()->forDecision($decision)->create();

    expect($entry->decision_id)->toBe($decision->id);
    expect($entry->status)->toBe('pending');
});

it('creates AuditEvent in the audit schema', function () {
    $event = AuditEvent::factory()->systemAction('case.created', ['case_id' => 'abc'])->create();

    expect((new AuditEvent)->getTable())->toBe('audit.moderation_events');
    expect($event->actor_kind)->toBe('system');
    expect($event->action)->toBe('case.created');
});
