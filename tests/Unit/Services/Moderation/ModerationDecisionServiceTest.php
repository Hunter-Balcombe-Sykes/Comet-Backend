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
    // Fake the queue globally so dispatcher jobs are captured, not executed inline.
    // Inline execution would fail because site.sites does not exist in the SQLite
    // in-memory test database. Individual tests that need to assert on dispatched
    // jobs call Queue::fake() themselves (redundant but harmless).
    Queue::fake();
    setupPartnaStaffTable();
    setupAllModerationTables();
});

it('writes a decision + dispatches actions + transitions case to resolved', function () {
    Queue::fake();
    $staff = PartnaStaff::factory()->create();
    $case = ModerationCase::factory()->underReview()->create();
    $dto = new DecisionDto('hide_site', 'repeated spam', null);

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
    $case = ModerationCase::factory()->create(['status' => 'auto_actioned']);
    $dto = new DecisionDto('override_csam_auto_action', 'false positive', null);

    expect(fn () => app(ModerationDecisionService::class)->decide($case, $staff, $dto))
        ->toThrow(InvalidArgumentException::class, 'requires second_staff_approval_id');
});

it('rejects a CSAM override where second_staff equals deciding staff', function () {
    $staff = PartnaStaff::factory()->create();
    $case = ModerationCase::factory()->create(['status' => 'auto_actioned']);
    $dto = new DecisionDto('override_csam_auto_action', 'fp', $staff->id);

    expect(fn () => app(ModerationDecisionService::class)->decide($case, $staff, $dto))
        ->toThrow(InvalidArgumentException::class, 'second staff must differ');
});

it('records CSAM override with second_staff_approved_at timestamp', function () {
    Queue::fake();
    $staff1 = PartnaStaff::factory()->create();
    $staff2 = PartnaStaff::factory()->create();
    $case = ModerationCase::factory()->create(['status' => 'auto_actioned']);
    $dto = new DecisionDto('override_csam_auto_action', 'fp', $staff2->id);

    $decision = app(ModerationDecisionService::class)->decide($case, $staff1, $dto);

    expect($decision->second_staff_approval_id)->toBe($staff2->id);
    expect($decision->second_staff_approved_at)->not->toBeNull();
});

it('decideAsSystem writes a decision with decided_by_system=true and auto_actioned=true', function () {
    $case = ModerationCase::factory()->csamMatch()->create();
    $dto = new DecisionDto('suspend_user', 'auto_csam_match', null);

    $decision = app(ModerationDecisionService::class)->decideAsSystem($case, $dto);

    expect($decision->decided_by_system)->toBeTrue();
    expect($decision->decided_by_staff_id)->toBeNull();
    expect($decision->auto_actioned)->toBeTrue();
});

it('decideAsSystem transitions auto_actioned cases', function () {
    $case = ModerationCase::factory()->create(['status' => 'open']);
    $dto = new DecisionDto('suspend_user', 'auto_csam_match', null);

    app(ModerationDecisionService::class)->decideAsSystem($case, $dto);

    expect($case->fresh()->status)->toBe('auto_actioned');
});

it('decideAsSystem dispatches the appropriate action set', function () {
    Queue::fake();
    $case = ModerationCase::factory()->csamMatch()->create();
    $dto = new DecisionDto('suspend_user', 'auto_csam_match', null);

    $decision = app(ModerationDecisionService::class)->decideAsSystem($case, $dto);

    expect(ActionLogEntry::query()->where('decision_id', $decision->id)->count())
        ->toBeGreaterThan(0);
});
