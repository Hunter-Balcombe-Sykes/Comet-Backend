<?php

/**
 * Staff feedback triage writes (2026-07-17 design):
 *   PATCH  /staff/feedback/{feedback}  — status change, support or admin
 *   DELETE /staff/feedback/{feedback}  — junk removal, admin only (soft
 *                                        delete; purged after 30 days)
 * Archive semantics live in terminal statuses (shipped/wontfix/duplicate),
 * NOT in deletion — see docs/superpowers/specs/2026-07-17-feedback-triage-design.md.
 */

use App\Models\Core\Feedback;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\Feedback\FeedbackService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['partna.throttle.enabled' => false]);

    setupUsersTable();
    setupFeedbackTable();
    setupPartnaStaffTable();

    // staff.audit middleware writes here after each staff response.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY,
        staff_id TEXT,
        staff_email_snapshot TEXT,
        impersonator_staff_id TEXT,
        impersonator_email_snapshot TEXT,
        user_id TEXT,
        professional_handle_snapshot TEXT,
        route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\',
        status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\',
        ip_hash TEXT,
        user_agent TEXT,
        created_at TEXT
    )');
});

function triageSupportStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_SUPPORT;
    $staff->primary_email = 'support-triage@partna.au';

    return $staff;
}

function triageAdminStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin-triage@partna.au';

    return $staff;
}

function triageSeedUser(string $handle): User
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => $handle,
        'handle_lc' => mb_strtolower($handle),
        'display_name' => ucfirst($handle),
        'primary_email' => "{$handle}@example.test",
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return User::findOrFail($id);
}

function triageSeedFeedback(string $userId, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.feedback')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'kind' => 'idea',
        'type' => 'idea',
        'area' => 'analytics',
        'message' => 'seed feedback',
        'status' => 'new',
        'internal_notes' => '[]',
        'tags' => '[]',
        'source' => 'dashboard',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ], $overrides));

    return $id;
}

// ── Policy abilities (Gate-resolved — house rule: never `new Policy()`) ──

it('allows support and admin to staffTriage', function () {
    $feedback = new Feedback(['status' => 'new']);

    expect(Gate::forUser(triageSupportStaff())->allows('staffTriage', $feedback))->toBeTrue();
    expect(Gate::forUser(triageAdminStaff())->allows('staffTriage', $feedback))->toBeTrue();
});

it('allows only admin to staffDelete', function () {
    $feedback = new Feedback(['status' => 'new']);

    expect(Gate::forUser(triageSupportStaff())->allows('staffDelete', $feedback))->toBeFalse();
    expect(Gate::forUser(triageAdminStaff())->allows('staffDelete', $feedback))->toBeTrue();
});

// ── Service layer ──

it('updateStatus persists the new status', function () {
    $alice = triageSeedUser('alice');
    $id = triageSeedFeedback($alice->id);

    $updated = app(FeedbackService::class)->updateStatus(Feedback::findOrFail($id), 'triaged');

    expect($updated->status)->toBe('triaged');
    expect(Feedback::findOrFail($id)->status)->toBe('triaged');
});

it('deleteByStaff soft deletes — row leaves the default scope but survives withTrashed', function () {
    $alice = triageSeedUser('alice');
    $id = triageSeedFeedback($alice->id);

    app(FeedbackService::class)->deleteByStaff(Feedback::findOrFail($id));

    expect(Feedback::find($id))->toBeNull();
    expect(Feedback::withTrashed()->findOrFail($id)->deleted_at)->not->toBeNull();
});

// ── PATCH /staff/feedback/{feedback} ──

it('lets support set a triage status', function () {
    $alice = triageSeedUser('alice');
    $id = triageSeedFeedback($alice->id);

    $response = actingAsStaff(triageSupportStaff())
        ->patchJson("/api/staff/feedback/{$id}", ['status' => 'in_progress']);

    $response->assertStatus(200);
    expect($response->json('feedback.status'))->toBe('in_progress');
    expect(Feedback::findOrFail($id)->status)->toBe('in_progress');
});

it('lets admin set a triage status', function () {
    $alice = triageSeedUser('alice');
    $id = triageSeedFeedback($alice->id);

    actingAsStaff(triageAdminStaff())
        ->patchJson("/api/staff/feedback/{$id}", ['status' => 'shipped'])
        ->assertStatus(200);

    expect(Feedback::findOrFail($id)->status)->toBe('shipped');
});

it('422s an out-of-vocabulary status (archived is NOT a status)', function () {
    $alice = triageSeedUser('alice');
    $id = triageSeedFeedback($alice->id);

    actingAsStaff(triageSupportStaff())
        ->patchJson("/api/staff/feedback/{$id}", ['status' => 'archived'])
        ->assertStatus(422);

    expect(Feedback::findOrFail($id)->status)->toBe('new');
});

it('422s a missing status', function () {
    $alice = triageSeedUser('alice');
    $id = triageSeedFeedback($alice->id);

    actingAsStaff(triageSupportStaff())
        ->patchJson("/api/staff/feedback/{$id}", [])
        ->assertStatus(422);
});

it('404s an unknown feedback id on PATCH', function () {
    actingAsStaff(triageSupportStaff())
        ->patchJson('/api/staff/feedback/'.Str::uuid(), ['status' => 'triaged'])
        ->assertStatus(404);
});

it('404s when PATCHing a soft-deleted row', function () {
    $alice = triageSeedUser('alice');
    $id = triageSeedFeedback($alice->id, ['deleted_at' => now()->toDateTimeString()]);

    actingAsStaff(triageSupportStaff())
        ->patchJson("/api/staff/feedback/{$id}", ['status' => 'triaged'])
        ->assertStatus(404);
});

it('rejects a non-staff authenticated user PATCH with 403 (real EnsurePartnaStaff)', function () {
    $intruder = triageSeedUser('intruder');
    $id = triageSeedFeedback($intruder->id);

    actingAsUser($intruder)
        ->patchJson("/api/staff/feedback/{$id}", ['status' => 'triaged'])
        ->assertStatus(403);
});

it('rejects an unauthenticated PATCH with 401', function () {
    $this->patchJson('/api/staff/feedback/'.Str::uuid(), ['status' => 'triaged'])
        ->assertStatus(401);
});

it('records a staff audit row for the status write', function () {
    $alice = triageSeedUser('alice');
    $id = triageSeedFeedback($alice->id);

    actingAsStaff(triageSupportStaff())
        ->patchJson("/api/staff/feedback/{$id}", ['status' => 'triaged'])
        ->assertStatus(200);

    $writes = DB::connection('pgsql')->table('audit.staff_audit_log')
        ->where('http_method', 'PATCH')
        ->count();
    expect($writes)->toBe(1);
});
