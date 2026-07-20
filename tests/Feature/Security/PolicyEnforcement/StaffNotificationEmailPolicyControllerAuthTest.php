<?php

/**
 * #SEC-1 — StaffNotificationEmailPolicyController had zero Policy-layer
 * authorization on all four methods. All four routes sit in the
 * staff.admin-gated route group, so there was no live privilege-escalation
 * path — but every sibling write controller in the same route group
 * redundantly re-checks at the Policy layer as insurance against the route
 * middleware group being misconfigured later. This proves that seam now
 * exists via the Gate (not a direct policy instantiation, which would hide
 * a mis-registered ability), plus an HTTP smoke test for the happy path.
 */

use App\Models\Core\Notifications\Notification;
use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    setupPartnaStaffTable();
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS notifications.notification_email_policies (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        category_key TEXT NOT NULL,
        mode TEXT NOT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

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

function secOneTest_staff(string $role): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->role = $role;

    return $staff;
}

it('staffView allows any staff role against Notification::class', function () {
    expect(Gate::forUser(secOneTest_staff(PartnaStaff::ROLE_SUPPORT))->allows('staffView', Notification::class))
        ->toBeTrue();
    expect(Gate::forUser(secOneTest_staff(PartnaStaff::ROLE_ADMIN))->allows('staffView', Notification::class))
        ->toBeTrue();
});

it('staffManage denies support staff and allows admin staff against Notification::class', function () {
    expect(Gate::forUser(secOneTest_staff(PartnaStaff::ROLE_SUPPORT))->allows('staffManage', Notification::class))
        ->toBeFalse();
    expect(Gate::forUser(secOneTest_staff(PartnaStaff::ROLE_ADMIN))->allows('staffManage', Notification::class))
        ->toBeTrue();
});

// updateGlobal()'s raw INSERT uses Postgres-only NOW()/ON CONFLICT...WHERE
// syntax (pre-existing, not touched by this fix) that SQLite can't execute —
// so only the read path is exercised over HTTP here; the write ability
// itself is proven by the Gate test above.
it('admin staff can read global notification email policies over HTTP', function () {
    actingAsStaff(secOneTest_staff(PartnaStaff::ROLE_ADMIN))
        ->getJson('/api/staff/notification-email-policies')
        ->assertStatus(200);
});

it('support staff is denied 403 by the staff.admin route group before reaching the controller', function () {
    actingAsStaff(secOneTest_staff(PartnaStaff::ROLE_SUPPORT))
        ->getJson('/api/staff/notification-email-policies')
        ->assertStatus(403);
});
