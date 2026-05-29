<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\ModerationCase;

beforeEach(function () {
    setupUsersTable();
    setupPartnaStaffTable();
    setupAllModerationTables();
});

it('returns 401 for unauthenticated requests to every staff case endpoint', function () {
    $case = ModerationCase::factory()->create();

    $routes = [
        ['GET',  '/api/staff/cases'],
        ['GET',  "/api/staff/cases/{$case->id}"],
        ['POST', "/api/staff/cases/{$case->id}/triage"],
        ['POST', "/api/staff/cases/{$case->id}/take"],
        ['POST', "/api/staff/cases/{$case->id}/release"],
        ['POST', "/api/staff/cases/{$case->id}/decide"],
        ['POST', "/api/staff/cases/{$case->id}/escalate"],
    ];

    foreach ($routes as [$method, $url]) {
        $this->json($method, $url)->assertStatus(401);
    }
});

it('returns 401 when staff is authenticated but AAL1 (no MFA)', function () {
    $staff = PartnaStaff::factory()->create();
    $case  = ModerationCase::factory()->create();

    // Pass aal=aal1 via the actingAsStaff helper — VerifySupabaseJwt stub sets
    // supabase_aal='aal1' on the request, which require.aal2 reads and rejects.
    // Return is 401 (not 403) so the frontend interprets this as a step-up trigger.
    $res = actingAsStaff($staff, ['aal' => 'aal1', 'amr' => []])
        ->getJson('/api/staff/cases');

    $res->assertStatus(401);
});
