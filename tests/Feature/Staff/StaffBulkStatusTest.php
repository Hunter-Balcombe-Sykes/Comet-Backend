<?php

/**
 * Behavioral tests for StaffUserController::bulkUpdateStatus().
 *
 * These tests drive the real HTTP route so the StaffBulkUpdateStatusRequest
 * FormRequest validates through the normal lifecycle (validateResolved()).
 *
 * Validation-only cases that are already fully covered by
 * StaffBulkUpdateStatusValidationTest are not duplicated here; only unique
 * cases and behavioral (DB-state / response-shape) assertions live below.
 */

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    attachTestSchemas();

    // RecordStaffAuditEntry middleware runs after the response and writes to
    // audit.staff_audit_log — create the table so terminate() does not throw.
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

function bulkStatus_makeAdminStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin-bulk-beh@partna.au';

    return $staff;
}

function bulkStatus_seedUsers(int $n, string $status = 'active'): array
{
    $ids = [];
    $now = now()->toDateTimeString();

    for ($i = 0; $i < $n; $i++) {
        $id = (string) Str::uuid();
        $handle = 'bulk-beh-'.$i.'-'.Str::random(4);
        DB::connection('pgsql')->table('core.users')->insert([
            'id' => $id,
            'handle' => $handle,
            'handle_lc' => strtolower($handle),
            'display_name' => "Bulk Pro {$i}",
            'first_name' => "Bulk Pro {$i}",
            'primary_email' => 'bulk-beh-'.$i.'@example.test',
            'account_type' => 'partna',
            'status' => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $ids[] = $id;
    }

    return $ids;
}

it('bulk-suspends a wave of professionals', function () {
    $ids = bulkStatus_seedUsers(5);

    Log::spy();

    actingAsStaff(bulkStatus_makeAdminStaff())
        ->postJson('/api/staff/professionals/bulk-status', [
            'ids' => $ids,
            'status' => 'suspended',
        ])
        ->assertStatus(200)
        ->assertJsonPath('updated_count', 5)
        ->assertJsonPath('status', 'suspended')
        ->assertJsonPath('missing_ids', []);

    // Every seeded row should now be suspended.
    expect(User::query()->whereIn('id', $ids)->where('status', 'suspended')->count())->toBe(5);

    // One audit log entry per professional.
    Log::shouldHaveReceived('info')->times(5);
});

it('accepts exactly 100 IDs', function () {
    $ids = bulkStatus_seedUsers(100);

    actingAsStaff(bulkStatus_makeAdminStaff())
        ->postJson('/api/staff/professionals/bulk-status', [
            'ids' => $ids,
            'status' => 'suspended',
        ])
        ->assertStatus(200)
        ->assertJsonPath('updated_count', 100);
});

it('returns missing_ids for unknown UUIDs without rolling back valid ones', function () {
    $valid = bulkStatus_seedUsers(2);
    $missing = [(string) Str::uuid(), (string) Str::uuid()];

    actingAsStaff(bulkStatus_makeAdminStaff())
        ->postJson('/api/staff/professionals/bulk-status', [
            'ids' => array_merge($valid, $missing),
            'status' => 'suspended',
        ])
        ->assertStatus(200)
        ->assertJsonPath('updated_count', 2)
        ->assertJsonCount(2, 'missing_ids');

    // The two valid professionals should still be updated.
    expect(User::query()->whereIn('id', $valid)->where('status', 'suspended')->count())->toBe(2);
});

it('rejects empty ids array with 422', function () {
    actingAsStaff(bulkStatus_makeAdminStaff())
        ->postJson('/api/staff/professionals/bulk-status', [
            'ids' => [],
            'status' => 'suspended',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ids']);
});
