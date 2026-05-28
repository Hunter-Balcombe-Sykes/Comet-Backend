<?php

use App\DTOs\Moderation\TriageDto;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\AuditEvent;
use App\Models\Moderation\ModerationCase;
use App\Services\Moderation\CaseStateMachine;
use App\Services\Moderation\IllegalCaseTransition;
use App\Services\Moderation\ModerationCaseService;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    setupPartnaStaffTable();
    setupModerationCasesTable();
    setupAuditModerationEventsTable();
});

it('triages an open case → triaged + records audit + updates priority', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->create(['status' => 'open', 'priority' => 5]);
    $dto   = new TriageDto(priority: 2, notes: 'high signal volume');

    $updated = app(ModerationCaseService::class)->triage($case, $staff, $dto);

    expect($updated->status)->toBe('triaged');
    expect($updated->priority)->toBe(2);
    expect($updated->triaged_by_staff_id)->toBe($staff->id);
    expect($updated->triaged_at)->not->toBeNull();

    $audit = AuditEvent::query()->where('action', 'case.triaged')->latest('created_at')->first();
    expect($audit->actor_staff_id)->toBe($staff->id);
    expect($audit->target_id)->toBe($case->id);
});

it('refuses to triage a resolved case', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->resolved()->create();
    $dto   = new TriageDto(priority: null, notes: null);

    expect(fn () => app(ModerationCaseService::class)->triage($case, $staff, $dto))
        ->toThrow(IllegalCaseTransition::class);
});

it('preserves existing priority when dto.priority is null', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->create(['status' => 'open', 'priority' => 3]);
    $dto   = new TriageDto(priority: null, notes: null);

    $updated = app(ModerationCaseService::class)->triage($case, $staff, $dto);
    expect($updated->priority)->toBe(3);
});
