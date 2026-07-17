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
