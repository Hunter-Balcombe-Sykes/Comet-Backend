<?php

/**
 * U2 — bound the services advisory lock. Proves the CONTROLLER-side of the
 * fix: StaffServiceManagementController::reorder() and UserServiceController::
 * reorder() both catch AdvisoryLockTimeoutException and return the same 423
 * every other interactive platform-connection write returns on contention
 * (ManagesIntegrationConnection::withConnectionLock's established wording).
 *
 * Real Postgres lock contention can't be reproduced under SQLite (see
 * AdvisoryLock's docblock — the shim in tests/Pest.php no-ops the lock
 * entirely), so these tests container-mock ReorderService to throw the exact
 * exception a real SET LOCAL lock_timeout abort would produce, proving the
 * controller's catch clause genuinely converts it to 423 rather than letting
 * it fall through to a 500. They do NOT prove Postgres enforces the bound.
 *
 * The store()/InsertWithSortOrder path isn't covered here: InsertWithSortOrder
 * is called via a static method (not container-resolved), so it can't be
 * container-mocked the way ReorderService can without changing its design —
 * out of scope for this unit. Its catch (AdvisoryLockTimeoutException) block
 * is structurally identical to reorder()'s (same exception type, same
 * message, same 423) in both controllers — see the U2 report for this
 * explicitly-acknowledged gap.
 */

use App\Models\Core\Staff\PartnaStaff;
use App\Services\Site\AdvisoryLockTimeoutException;
use App\Services\Site\ReorderService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    setupPartnaStaffTable();
    shimPgAdvisoryLockForSqlite();

    // staff.audit middleware writes here on every write request.
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

function servicesLockTimeoutTest_adminStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin@partna.au';

    return $staff;
}

it('staff reorder returns 423 when the services advisory lock times out', function () {
    $pro = createTenant('svclock-staff');
    $service = createServiceFor($pro);

    $this->mock(ReorderService::class, fn ($m) => $m->shouldReceive('reorder')->once()
        ->andThrow(new AdvisoryLockTimeoutException("services:{$pro->id}")));

    actingAsStaff(servicesLockTimeoutTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services/reorder", ['ids' => [$service->id]])
        ->assertStatus(423)
        ->assertJsonPath('message', 'Another change is still saving — please retry in a moment.');
});

it('user reorder returns 423 when the services advisory lock times out', function () {
    $pro = createTenant('svclock-user');
    $service = createServiceFor($pro);

    $this->mock(ReorderService::class, fn ($m) => $m->shouldReceive('reorder')->once()
        ->andThrow(new AdvisoryLockTimeoutException("services:{$pro->id}")));

    actingAsUser($pro)
        ->postJson('/api/services/reorder', ['ids' => [$service->id]])
        ->assertStatus(423)
        ->assertJsonPath('message', 'Another change is still saving — please retry in a moment.');
});
