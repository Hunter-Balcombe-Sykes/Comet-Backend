<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\Evidence;
use App\Models\Moderation\ModerationCase;

beforeEach(function () {
    setupPartnaStaffTable();
    setupAllModerationTables();
    setupUsersTable();
    setupSitesTable();
});

it('returns case detail with eager-loaded signals + evidence + decisions', function () {
    $staff = PartnaStaff::factory()->create();
    $case = ModerationCase::factory()->create();
    CaseSignal::factory()->forCase($case)->create();
    Evidence::factory()->forCase($case)->create();

    $res = actingAsStaff($staff)->getJson("/api/staff/cases/{$case->id}");
    $res->assertOk();
    $res->assertJsonStructure([
        'data' => ['case', 'signals', 'evidence', 'decisions'],
    ]);
});

it('never exposes reporter_email in the response', function () {
    $staff = PartnaStaff::factory()->create();
    $case = ModerationCase::factory()->create();
    CaseSignal::factory()->forCase($case)->create(['reporter_email' => 'secret@example.com']);

    $res = actingAsStaff($staff)->getJson("/api/staff/cases/{$case->id}");
    $res->assertOk();
    expect($res->content())->not->toContain('secret@example.com');
});

it('returns 404 for non-existent case', function () {
    $staff = PartnaStaff::factory()->create();
    $res = actingAsStaff($staff)->getJson('/api/staff/cases/00000000-0000-0000-0000-000000000000');
    $res->assertStatus(404);
});
