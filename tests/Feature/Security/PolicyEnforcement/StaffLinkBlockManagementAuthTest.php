<?php

/**
 * #SEC-4 — StaffLinkBlockManagementController::index()/store() lacked
 * authorization while update()/destroy() had it. Premise correction while
 * implementing: reorder() also had zero authorization (the finding claimed
 * it already matched update()/destroy() — it did not), so it's fixed here
 * too, for internal consistency within the same file.
 *
 * index() lives in the non-admin `staff` route group and is a plain read of
 * data that's already public on the professional's sitepage once published
 * — so it gets staffView (any staff role), matching the read-surface
 * convention (#SEC-5), not staffManageBlock (admin-only). store()/reorder()
 * live in the staff.admin route group and get staffManageBlock, matching
 * update()/destroy() in the same file.
 */

use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupPartnaStaffTable();
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

function secFourTest_staff(string $role): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->role = $role;

    return $staff;
}

it('support staff can list link blocks (staffView, any role)', function () {
    $pro = createTenant('sec4-links-index');
    createLinkBlockFor($pro);

    actingAsStaff(secFourTest_staff(PartnaStaff::ROLE_SUPPORT))
        ->getJson("/api/staff/professionals/{$pro->id}/links")
        ->assertStatus(200);
});

it('support staff is denied 403 on the link-block reorder endpoint (staff.admin route group)', function () {
    $pro = createTenant('sec4-links-reorder');
    $block = createLinkBlockFor($pro);

    actingAsStaff(secFourTest_staff(PartnaStaff::ROLE_SUPPORT))
        ->postJson("/api/staff/professionals/{$pro->id}/links/reorder", ['ids' => [$block->id]])
        ->assertStatus(403);
});

it('admin staff can reorder link blocks', function () {
    $pro = createTenant('sec4-links-reorder-admin');
    $block = createLinkBlockFor($pro);

    actingAsStaff(secFourTest_staff(PartnaStaff::ROLE_ADMIN))
        ->postJson("/api/staff/professionals/{$pro->id}/links/reorder", ['ids' => [$block->id]])
        ->assertStatus(200);
});

it('support staff is denied 403 creating a link block (staff.admin route group)', function () {
    $pro = createTenant('sec4-links-store');

    actingAsStaff(secFourTest_staff(PartnaStaff::ROLE_SUPPORT))
        ->postJson("/api/staff/professionals/{$pro->id}/links", ['title' => 'New link', 'url' => 'https://example.com', 'category' => 'other'])
        ->assertStatus(403);
});

it('admin staff can create a link block', function () {
    $pro = createTenant('sec4-links-store-admin');

    actingAsStaff(secFourTest_staff(PartnaStaff::ROLE_ADMIN))
        ->postJson("/api/staff/professionals/{$pro->id}/links", ['title' => 'New link', 'url' => 'https://example.com', 'category' => 'other'])
        ->assertStatus(201);
});
