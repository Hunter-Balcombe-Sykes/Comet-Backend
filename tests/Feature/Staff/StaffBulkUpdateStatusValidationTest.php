<?php

/**
 * SEC-12 — bulkUpdateStatus() validation via StaffBulkUpdateStatusRequest.
 *
 * Verifies:
 *   - Missing/invalid input returns 422 (request-level, before the controller body runs)
 *   - A valid payload is accepted and returns 200
 */

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    // The staff.audit middleware (RecordStaffAuditEntry) runs after the response
    // and writes to audit.staff_audit_log — set up the table so terminate() does not throw.
    attachTestSchemas();
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
        ip TEXT,
        user_agent TEXT,
        created_at TEXT
    )');
});

function bulkStatusTest_makeAdminStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin-bulk@partna.au';

    return $staff;
}

function bulkStatusTest_seedProfessional(): User
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => 'pro-'.Str::random(6),
        'display_name' => 'Bulk Pro',
        'primary_email' => 'bulk-'.Str::random(6).'@example.test',
        'account_type' => 'individual',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return User::query()->findOrFail($id);
}

it('returns 422 when ids is missing', function () {
    actingAsStaff(bulkStatusTest_makeAdminStaff())
        ->postJson('/api/staff/professionals/bulk-status', [
            'status' => 'suspended',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ids']);
});

it('returns 422 when status is missing', function () {
    actingAsStaff(bulkStatusTest_makeAdminStaff())
        ->postJson('/api/staff/professionals/bulk-status', [
            'ids' => [(string) Str::uuid()],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('returns 422 when status is not active or suspended', function () {
    actingAsStaff(bulkStatusTest_makeAdminStaff())
        ->postJson('/api/staff/professionals/bulk-status', [
            'ids' => [(string) Str::uuid()],
            'status' => 'deleted',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('returns 422 when ids contains a non-uuid string', function () {
    actingAsStaff(bulkStatusTest_makeAdminStaff())
        ->postJson('/api/staff/professionals/bulk-status', [
            'ids' => ['not-a-uuid'],
            'status' => 'active',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ids.0']);
});

it('returns 422 when ids exceeds 100 entries', function () {
    $ids = array_map(fn () => (string) Str::uuid(), range(1, 101));

    actingAsStaff(bulkStatusTest_makeAdminStaff())
        ->postJson('/api/staff/professionals/bulk-status', [
            'ids' => $ids,
            'status' => 'active',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ids']);
});

it('accepts a valid payload and returns 200', function () {
    $pro = bulkStatusTest_seedProfessional();

    actingAsStaff(bulkStatusTest_makeAdminStaff())
        ->postJson('/api/staff/professionals/bulk-status', [
            'ids' => [$pro->id],
            'status' => 'suspended',
        ])
        ->assertStatus(200)
        ->assertJsonPath('updated_count', 1);
});
