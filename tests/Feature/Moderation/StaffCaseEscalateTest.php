<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    setupUsersTable();
    setupPartnaStaffTable();
    setupAllModerationTables();
});

it('escalates to law enforcement and records a decision', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->underReview()->create();

    $res = actingAsStaff($staff)->postJson("/api/staff/cases/{$case->id}/escalate", [
        'escalation_target' => 'law_enforcement',
        'notes'             => 'CSAM evidence preserved; tip filed; this is a follow-up from the auto-action.',
    ]);
    $res->assertCreated();

    expect(Decision::query()
        ->where('case_id', $case->id)
        ->where('decision_type', 'escalate_law_enforcement')
        ->exists())->toBeTrue();
});

it('rejects unknown escalation_target', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->underReview()->create();

    $res = actingAsStaff($staff)->postJson("/api/staff/cases/{$case->id}/escalate", [
        'escalation_target' => 'mars',
        'notes'             => 'taking this to the martians',
    ]);
    $res->assertStatus(422);
});

it('rejects notes shorter than 20 chars', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->underReview()->create();

    $res = actingAsStaff($staff)->postJson("/api/staff/cases/{$case->id}/escalate", [
        'escalation_target' => 'esafety',
        'notes'             => 'too short',
    ]);
    $res->assertStatus(422);
});
