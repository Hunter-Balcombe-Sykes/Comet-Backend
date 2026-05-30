<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ModerationCase;

beforeEach(function () {
    setupUsersTable();
    setupPartnaStaffTable();
    setupAllModerationTables();
});

it('takes a triaged case → under_review', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->triaged()->create();

    $res = actingAsStaff($staff)->postJson("/api/staff/cases/{$case->id}/take");
    $res->assertOk();
    expect($case->fresh()->status)->toBe('under_review');
});

it('releases an under_review case back to triaged', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->underReview()->create();

    $res = actingAsStaff($staff)->postJson("/api/staff/cases/{$case->id}/release");
    $res->assertOk();
    expect($case->fresh()->status)->toBe('triaged');
});

it('rejects take on already-taken case with 409 conflict', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->underReview()->create();

    // Already under_review by another staffer is a concurrency conflict (409),
    // not an illegal transition (422).
    $res = actingAsStaff($staff)->postJson("/api/staff/cases/{$case->id}/take");
    $res->assertStatus(409);
});
