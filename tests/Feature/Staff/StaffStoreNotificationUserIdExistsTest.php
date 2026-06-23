<?php

/**
 * P3-09 — StaffStoreNotificationRequest must reject non-existent user_id UUIDs with 422.
 *
 * Without the Rule::exists check, a staff member could create a notification targeting
 * a phantom user_id, creating a dangling row that can never be delivered.
 */

use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    attachTestSchemas();
    setupUsersTable();

    $conn = DB::connection('pgsql');

    // The staff.audit middleware writes to audit.staff_audit_log after each response.
    $conn->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
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
        ip TEXT,
        user_agent TEXT,
        created_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS notifications.notifications (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        type TEXT NOT NULL,
        category TEXT NULL,
        title TEXT NOT NULL,
        body TEXT NOT NULL,
        cta_url TEXT NULL,
        primary_action_label TEXT NULL,
        secondary_action_label TEXT NULL,
        secondary_action_url TEXT NULL,
        severity TEXT NOT NULL DEFAULT "info",
        starts_at TEXT NULL,
        ends_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        deleted_at TEXT NULL
    )');

    $conn->statement('DELETE FROM notifications.notifications');
});

function p309_makeAdminStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin-p309@partna.au';

    return $staff;
}

function p309_basePayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'info',
        'title' => 'Test Notification',
        'body' => 'Test body',
        'severity' => 'info',
    ], $overrides);
}

it('returns 422 when user_id is a non-existent UUID (dangling reference)', function () {
    $phantomId = (string) Str::uuid();

    actingAsStaff(p309_makeAdminStaff())
        ->postJson('/api/staff/notifications', p309_basePayload(['user_id' => $phantomId]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['user_id']);
});

it('returns 422 when user_id is not a valid UUID format', function () {
    actingAsStaff(p309_makeAdminStaff())
        ->postJson('/api/staff/notifications', p309_basePayload(['user_id' => 'not-a-uuid']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['user_id']);
});

it('accepts a null user_id (global notification)', function () {
    actingAsStaff(p309_makeAdminStaff())
        ->postJson('/api/staff/notifications', p309_basePayload(['user_id' => null]))
        ->assertStatus(201);
});

it('accepts an existing user_id (targeted notification)', function () {
    $userId = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => 'notif-target-'.Str::random(4),
        'display_name' => 'Target User',
        'primary_email' => 'target-'.Str::random(4).'@example.test',
        'account_type' => 'individual',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    actingAsStaff(p309_makeAdminStaff())
        ->postJson('/api/staff/notifications', p309_basePayload(['user_id' => $userId]))
        ->assertStatus(201);
});
