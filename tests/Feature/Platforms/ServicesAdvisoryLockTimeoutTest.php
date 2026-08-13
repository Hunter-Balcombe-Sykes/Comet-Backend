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
 * entirely), so these tests container-mock a dependency inside the locked
 * transaction to throw the exact exception a real SET LOCAL lock_timeout
 * abort would produce, proving the controller's catch clause genuinely
 * converts it to 423 rather than letting it fall through to a 500. They do
 * NOT prove Postgres enforces the bound.
 *
 * Slice 3a Task 5 (review round 2): UserServiceController::reorder() no
 * longer calls ReorderService at all — it renumbers site.services.sort_order
 * for the professional's WHOLE live legacy set (Fresha AND still-present
 * legacy owner-authored rows) itself, via a hand-rolled two-pass renumber
 * (renumberLegacySortOrder()), because delegating that to
 * ReorderService::reorder() was tried and reverted: its own internal
 * recompaction silently breaks cross-store ordering the moment an
 * unresolvable (newly-created) manual id sits ahead of a Fresha id in the
 * submitted order (see ServiceEndpointCutoverTest's "round-trips even when a
 * brand-new manual service... sits between two Fresha ids" regression test).
 * So the user-side test below can no longer mock ReorderService — nothing
 * calls it. It mocks ManualServiceWriter::pin() instead (the other
 * container-resolved dependency reorder() calls inside the SAME locked
 * transaction, for the manual half) — a fixture with at least one manual id
 * in the submitted order is required for pin() to be reached at all.
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
use App\Services\Content\ManualServiceWriter;
use App\Services\Site\AdvisoryLockTimeoutException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    setupPartnaStaffTable();
    // reorder() now reads/writes content.* (the manual half) regardless of
    // which store the submitted ids belong to.
    setupIngestTables();
    setupContentTables();
    setupBlocksTable();
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

    // Slice 3b Task 11: StaffServiceManagementController::reorder() is no
    // longer "untouched by the content.* cutover" — it stopped calling
    // ReorderService for exactly the reason the user side did (that class's
    // internal recompaction silently breaks the shared manual+Fresha rank; see
    // this file's header), and a `source IS NULL` fixture is no longer
    // addressable through any staff service route. So this case now mirrors
    // the user one exactly: a real manual service, and the mock on
    // ManualServiceWriter::pin() — the container-resolved dependency the staff
    // reorder calls INSIDE its own locked transaction.
    $serviceId = (string) actingAsStaff(servicesLockTimeoutTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services", ['title' => 'Manual', 'price_cents' => 1000])
        ->assertStatus(201)
        ->json('service.id');

    $this->mock(ManualServiceWriter::class, function ($m) {
        // Mocked AFTER the create above, so store()'s own real
        // projectionFor()/write()/pin() calls are untouched — only the pin()
        // inside reorder()'s locked transaction throws.
        $m->shouldReceive('pin')->once()->andThrow(new AdvisoryLockTimeoutException('services:pending'));
    });

    actingAsStaff(servicesLockTimeoutTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services/reorder", ['ids' => [$serviceId]])
        ->assertStatus(423)
        ->assertJsonPath('message', 'Another change is still saving — please retry in a moment.');
});

it('user reorder returns 423 when the services advisory lock times out', function () {
    $pro = createTenant('svclock-user');

    $manualId = actingAsUser($pro)->postJson('/api/services', ['title' => 'Manual', 'price_cents' => 1000])
        ->assertCreated()->json('service.id');

    $this->mock(ManualServiceWriter::class, function ($m) {
        // projectionFor()/write()/coordFor() are real production calls
        // store() above already exercised — only pin(), the call inside
        // reorder()'s own locked transaction, needs to throw.
        $m->shouldReceive('pin')->once()->andThrow(new AdvisoryLockTimeoutException('services:pending'));
    });

    actingAsUser($pro)
        ->postJson('/api/services/reorder', ['ids' => [$manualId]])
        ->assertStatus(423)
        ->assertJsonPath('message', 'Another change is still saving — please retry in a moment.');
});
