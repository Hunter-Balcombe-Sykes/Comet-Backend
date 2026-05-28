<?php

use App\DTOs\Moderation\DecisionDto;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\AuditEvent;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use App\Services\Moderation\ModerationDecisionService;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    setupPartnaStaffTable();
    setupAllModerationTables();
});

it('writes a decision + dispatches actions + transitions case to resolved', function () {
    Queue::fake();
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->underReview()->create();
    $dto   = new DecisionDto('hide_site', 'repeated spam', null);

    $decision = app(ModerationDecisionService::class)->decide($case, $staff, $dto);

    expect($decision)->toBeInstanceOf(Decision::class);
    expect($decision->decision_type)->toBe('hide_site');
    expect($decision->decided_by_staff_id)->toBe($staff->id);

    expect(ActionLogEntry::query()->where('decision_id', $decision->id)->count())->toBeGreaterThan(0);

    $case->refresh();
    expect($case->status)->toBe('resolved');

    expect(AuditEvent::query()->where('action', 'case.decided')->exists())->toBeTrue();
});

it('rejects a CSAM override without second_staff_approval_id', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->create(['status' => 'auto_actioned']);
    $dto   = new DecisionDto('override_csam_auto_action', 'false positive', null);

    expect(fn () => app(ModerationDecisionService::class)->decide($case, $staff, $dto))
        ->toThrow(\InvalidArgumentException::class, 'requires second_staff_approval_id');
});

it('rejects a CSAM override where second_staff equals deciding staff', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->create(['status' => 'auto_actioned']);
    $dto   = new DecisionDto('override_csam_auto_action', 'fp', $staff->id);

    expect(fn () => app(ModerationDecisionService::class)->decide($case, $staff, $dto))
        ->toThrow(\InvalidArgumentException::class, 'second staff must differ');
});

it('records CSAM override with second_staff_approved_at timestamp', function () {
    Queue::fake();
    $staff1 = PartnaStaff::factory()->create();
    $staff2 = PartnaStaff::factory()->create();
    $case   = ModerationCase::factory()->create(['status' => 'auto_actioned']);
    $dto    = new DecisionDto('override_csam_auto_action', 'fp', $staff2->id);

    $decision = app(ModerationDecisionService::class)->decide($case, $staff1, $dto);

    expect($decision->second_staff_approval_id)->toBe($staff2->id);
    expect($decision->second_staff_approved_at)->not->toBeNull();
});
