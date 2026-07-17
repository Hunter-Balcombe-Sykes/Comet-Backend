<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ModerationCase;

beforeEach(function () {
    setupPartnaStaffTable();
    setupAllModerationTables();
    setupUsersTable();
    setupSitesTable();
});

it('returns 401 for unauthenticated requests', function () {
    $this->getJson('/api/staff/cases')->assertStatus(401);
});

it('lists cases sorted by severity DESC then priority ASC then created_at ASC', function () {
    $staff = PartnaStaff::factory()->create();
    ModerationCase::factory()->create(['severity' => 1, 'priority' => 5, 'status' => 'open']);
    $sev5 = ModerationCase::factory()->csamMatch()->create();
    ModerationCase::factory()->create(['severity' => 3, 'priority' => 2, 'status' => 'open']);

    $res = actingAsStaff($staff)->getJson('/api/staff/cases');
    $res->assertOk();

    $first = $res->json('data.0.id');
    expect($first)->toBe($sev5->id);
});

it('filters by status', function () {
    $staff = PartnaStaff::factory()->create();
    ModerationCase::factory()->resolved()->create();
    ModerationCase::factory()->create(['status' => 'open']);

    $res = actingAsStaff($staff)->getJson('/api/staff/cases?status=resolved');
    expect($res->json('data'))->toHaveCount(1);
});

it('filters by case_type', function () {
    $staff = PartnaStaff::factory()->create();
    ModerationCase::factory()->csamMatch()->create();
    ModerationCase::factory()->create(['case_type' => 'content_report']);

    $res = actingAsStaff($staff)->getJson('/api/staff/cases?case_type=csam_match');
    expect($res->json('data'))->toHaveCount(1);
});

it('wraps the list in the shared paginatedResponse envelope (data + meta)', function () {
    $staff = PartnaStaff::factory()->create();
    ModerationCase::factory()->count(2)->create();

    $res = actingAsStaff($staff)->getJson('/api/staff/cases');

    $res->assertOk();
    $res->assertJsonStructure([
        'data' => [['id', 'case_type', 'severity', 'status']],
        'meta' => ['current_page', 'per_page', 'total', 'last_page', 'next_page_url', 'prev_page_url'],
    ]);
    expect($res->json('data.0.id'))->toBeString();
    expect($res->json('meta.total'))->toBe(2);
});
