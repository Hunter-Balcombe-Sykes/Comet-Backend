<?php

use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `revocation.strict` on REAL production routes.
 *
 * StrictRevocationTest proves the middleware BEHAVES correctly, but does it on
 * ad-hoc routes registered in the test. StrictRevocationTest's route-table guard
 * proves the middleware is ATTACHED. Neither one actually drives a real strict
 * route end to end, and the gap between "behaves" and "attached" is where the
 * first draft of this change shipped 58 unprotected staff routes.
 *
 * These tests close it: a real strict route under a simulated outage, and a real
 * non-strict route under the same conditions.
 *
 * `['revocation_verified' => false]` on actingAsUser/actingAsStaff simulates
 * "VerifySupabaseJwt could not reach the blocklist" — see tests/Pest.php.
 */
beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupPartnaStaffTable();
    // SubstituteBindings is in bootstrap/app.php's priority list and
    // RequireVerifiedRevocation is not, so route-model binding resolves BEFORE the
    // strict gate. On the third staff group's {notification} binding that means a
    // real query — without this table the request 500s on the binding and never
    // reaches the gate under test. (Binding is a read, so this ordering is not a
    // security issue; it is a test-setup consequence worth stating.)
    setupNotificationsTable();

    // staff.audit writes on terminate(); without this table RecordStaffAuditEntry
    // throws and masks the status code under test.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY, staff_id TEXT, staff_email_snapshot TEXT,
        impersonator_staff_id TEXT, impersonator_email_snapshot TEXT, user_id TEXT,
        professional_handle_snapshot TEXT, route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\', status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\', ip_hash TEXT, user_agent TEXT, created_at TEXT
    )');
});

/** Unpersisted admin actor — actingAsStaff() stubs JWT + staff resolution. */
function strictRevocationAdmin(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin-strictrev@partna.au';

    return $staff;
}

// ── User surfaces ────────────────────────────────────────────────────────────

it('503s POST /api/me/deletion/confirm when the blocklist could not be reached', function () {
    // Account deletion is the one irreversible thing a user can do. This is the
    // whole reason the change exists.
    $pro = createTenant('strictrev-del');

    actingAsUser($pro, ['revocation_verified' => false])
        ->postJson('/api/me/deletion/confirm', ['token' => 'irrelevant'])
        ->assertStatus(503)
        ->assertHeader('Retry-After', '5');
});

it('does NOT 503 the same route when the blocklist answered', function () {
    // Control. Proves the 503 above comes from the gate and not from the route
    // being broken in the test environment. The controller's own status (4xx for a
    // bogus token) is not the point — only that the gate let it through.
    $pro = createTenant('strictrev-del-ok');

    $response = actingAsUser($pro)
        ->postJson('/api/me/deletion/confirm', ['token' => 'irrelevant']);

    expect($response->getStatusCode())->not->toBe(503);
});

it('still serves the non-strict GET /api/site under the identical failure', function () {
    // The selective property on a REAL route: the dashboard read a customer needs
    // during an outage keeps working while deletion is blocked.
    $pro = createTenant('strictrev-lenient');

    actingAsUser($pro, ['revocation_verified' => false])
        ->getJson('/api/site')
        ->assertOk();
});

// ── Staff surfaces ───────────────────────────────────────────────────────────

it('503s DELETE /api/staff/professionals/{id}/force when the blocklist could not be reached', function () {
    // The exact route from the review's failure scenario: a fired staffer whose
    // session was revoked, hitting hard-delete during a Redis outage. This route
    // lives in the SECOND prefix('staff') group — the one the first draft missed —
    // so this test is also the regression guard for that specific omission.
    $victim = createTenant('strictrev-victim');

    actingAsStaff(strictRevocationAdmin(), ['revocation_verified' => false])
        ->deleteJson("/api/staff/professionals/{$victim->id}/force")
        ->assertStatus(503)
        ->assertHeader('Retry-After', '5');
});

it('does NOT 503 a staff route when the blocklist answered', function () {
    $victim = createTenant('strictrev-victim-ok');

    $response = actingAsStaff(strictRevocationAdmin())
        ->deleteJson("/api/staff/professionals/{$victim->id}/force");

    expect($response->getStatusCode())->not->toBe(503);
});

it('503s a staff route in the THIRD prefix group too', function () {
    // routes/api/staff.php has three top-level groups; the unscoped notifications
    // group is the third. Covered separately so a regression in any one group is
    // named by a failing test rather than hidden behind the other two.
    //
    // The notification row must really exist: SubstituteBindings resolves it
    // BEFORE the strict gate (see beforeEach), so a random UUID 404s on the
    // binding and never reaches the gate — which would make this test pass for
    // the wrong reason if it asserted anything looser than 503.
    $victim = createTenant('strictrev-victim-3');
    $notificationId = (string) Str::uuid();
    DB::connection('pgsql')->table('notifications.notifications')->insert([
        'id' => $notificationId,
        'user_id' => $victim->id,
        'type' => 'system',
        'title' => 'Drill',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    actingAsStaff(strictRevocationAdmin(), ['revocation_verified' => false])
        ->postJson("/api/staff/professionals/{$victim->id}/notifications/{$notificationId}/read")
        ->assertStatus(503);
});
