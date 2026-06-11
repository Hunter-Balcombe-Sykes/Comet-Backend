<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\Decision;
use App\Models\Moderation\ModerationCase;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // Queue::fake() prevents outcome jobs from attempting real dispatches in tests
    Queue::fake();
    setupUsersTable();
    setupPartnaStaffTable();
    setupAllModerationTables();
});

it('records a decision and transitions case to resolved', function () {
    $staff = PartnaStaff::factory()->create();
    $case = ModerationCase::factory()->underReview()->create();

    $res = actingAsStaff($staff)->postJson("/api/staff/cases/{$case->id}/decide", [
        'decision_type' => 'hide_site',
        'reason' => 'repeated spam after warning',
    ]);
    $res->assertCreated();

    expect($case->fresh()->status)->toBe('resolved');
    expect(Decision::query()->where('case_id', $case->id)->count())->toBe(1);
});

it('rejects CSAM override without second_staff_approval_id with 422', function () {
    $staff = PartnaStaff::factory()->create();
    $case = ModerationCase::factory()->create(['status' => 'auto_actioned', 'case_type' => 'csam_match']);

    $res = actingAsStaff($staff)->postJson("/api/staff/cases/{$case->id}/decide", [
        'decision_type' => 'override_csam_auto_action',
        'reason' => 'false positive confirmed by review',
    ]);
    $res->assertStatus(422);
});

it('accepts CSAM override with a different second_staff_approval_id', function () {
    $staff1 = PartnaStaff::factory()->create();
    $staff2 = PartnaStaff::factory()->create();
    $case = ModerationCase::factory()->create(['status' => 'auto_actioned', 'case_type' => 'csam_match']);

    $res = actingAsStaff($staff1)->postJson("/api/staff/cases/{$case->id}/decide", [
        'decision_type' => 'override_csam_auto_action',
        'reason' => 'confirmed false positive after hash review',
        'second_staff_approval_id' => $staff2->id,
    ]);
    $res->assertCreated();
});

it('rejects when second_staff_approval_id equals deciding staff', function () {
    $staff = PartnaStaff::factory()->create();
    $case = ModerationCase::factory()->create(['status' => 'auto_actioned', 'case_type' => 'csam_match']);

    $res = actingAsStaff($staff)->postJson("/api/staff/cases/{$case->id}/decide", [
        'decision_type' => 'override_csam_auto_action',
        'reason' => 'confirmed false positive — self signed',
        'second_staff_approval_id' => $staff->id,
    ]);
    $res->assertStatus(422);
});

it('rejects a non-override decision on a csam_match case with 422 (F8)', function () {
    $staff = PartnaStaff::factory()->create();
    $case = ModerationCase::factory()->create(['status' => 'auto_actioned', 'case_type' => 'csam_match']);

    $res = actingAsStaff($staff)->postJson("/api/staff/cases/{$case->id}/decide", [
        'decision_type' => 'dismiss',
        'reason' => 'attempting to dismiss a csam_match case',
    ]);
    $res->assertStatus(422);
});

it('returns 409 when deciding on an already-resolved case (F16)', function () {
    $staff = PartnaStaff::factory()->create();
    $case = ModerationCase::factory()->create(['status' => 'resolved']);

    $res = actingAsStaff($staff)->postJson("/api/staff/cases/{$case->id}/decide", [
        'decision_type' => 'hide_site',
        'reason' => 'deciding on an already resolved case',
    ]);
    $res->assertStatus(409);
});

it('rejects reason shorter than 10 chars', function () {
    $staff = PartnaStaff::factory()->create();
    $case = ModerationCase::factory()->underReview()->create();

    $res = actingAsStaff($staff)->postJson("/api/staff/cases/{$case->id}/decide", [
        'decision_type' => 'dismiss',
        'reason' => 'short',
    ]);
    $res->assertStatus(422);
});
