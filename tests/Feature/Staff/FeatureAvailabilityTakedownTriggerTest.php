<?php

use App\Jobs\Platforms\ReconcilePlatformTakedownJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupSegmentsTables();
    setupFeatureAvailabilityTable();
    setupPartnaStaffTable();
    // staff.audit middleware writes on terminate() — create the audit table so
    // RecordStaffAuditEntry does not throw (mirrors StaffBulkStatusTest).
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY, staff_id TEXT, staff_email_snapshot TEXT,
        impersonator_staff_id TEXT, impersonator_email_snapshot TEXT, user_id TEXT,
        professional_handle_snapshot TEXT, route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\', status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\', ip_hash TEXT, user_agent TEXT, created_at TEXT
    )');
    DB::connection('pgsql')->statement('DELETE FROM core.feature_availability');
    FeatureAvailability::flush();
});

// Unpersisted admin actor — mirrors bulkStatus_makeAdminStaff() in
// tests/Feature/Staff/StaffBulkStatusTest.php. actingAsStaff() stubs the JWT +
// staff resolution, so the row need not be persisted.
function takedownTriggerStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin-tk@partna.au';

    return $staff;
}

it('dispatches a global takedown when an integration is disabled', function () {
    Bus::fake();

    actingAsStaff(takedownTriggerStaff())->putJson('/api/staff/feature-availability', [
        'feature_key' => 'integration.skool', 'mode' => 'disabled',
    ])->assertSuccessful();

    Bus::assertDispatched(ReconcilePlatformTakedownJob::class, fn ($job) => $job->platform === 'skool' && $job->segmentId === null);
});

it('does NOT dispatch when enabling, or for a non-integration key', function () {
    Bus::fake();
    $staff = takedownTriggerStaff();

    actingAsStaff($staff)->putJson('/api/staff/feature-availability', [
        'feature_key' => 'integration.skool', 'mode' => 'enabled',
    ])->assertSuccessful();

    actingAsStaff($staff)->putJson('/api/staff/feature-availability', [
        'feature_key' => 'feature.shop', 'mode' => 'disabled',
    ])->assertSuccessful();

    Bus::assertNotDispatched(ReconcilePlatformTakedownJob::class);
});

it('re-enabling does not reactivate a taken-down connection', function () {
    $user = User::create([
        'handle' => 'tkreenable', 'handle_lc' => 'tkreenable', 'display_name' => 'Re',
        'account_type' => 'partna', 'auth_user_id' => (string) Str::uuid(), 'primary_email' => 'tkreenable@example.com',
    ]);
    // Already taken down.
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'skool', 'resource_id' => 'c-reenable',
        'payload' => ['url' => 'https://www.skool.com/demo'], 'is_active' => false,
    ]);

    actingAsStaff(takedownTriggerStaff())->putJson('/api/staff/feature-availability', [
        'feature_key' => 'integration.skool', 'mode' => 'enabled',
    ])->assertSuccessful();

    expect($conn->refresh()->is_active)->toBeFalse(); // stays off — no auto-reactivation
});
