<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ModerationCase;

beforeEach(function () {
    setupUsersTable();
    setupPartnaStaffTable();
    setupAllModerationTables();
});

it('triages an open case', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->create(['status' => 'open']);

    $res = actingAsStaff($staff)
        ->postJson("/api/staff/cases/{$case->id}/triage", ['priority' => 2, 'notes' => 'looks bad']);

    $res->assertOk();
    expect($case->fresh()->status)->toBe('triaged');
    expect($case->fresh()->priority)->toBe(2);
});

it('rejects triage on resolved case with 422', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->resolved()->create();

    $res = actingAsStaff($staff)->postJson("/api/staff/cases/{$case->id}/triage", []);
    $res->assertStatus(422);
});

it('rejects priority out of range', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->create(['status' => 'open']);

    $res = actingAsStaff($staff)->postJson("/api/staff/cases/{$case->id}/triage", ['priority' => 99]);
    $res->assertStatus(422);
});
