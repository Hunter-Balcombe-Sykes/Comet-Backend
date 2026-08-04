<?php

/**
 * #PRIV-2 route-level — StaffEmailSubscriberController::export() is
 * admin-only via authorizeForUser($staff, 'staffManage', $professional)
 * INSIDE the action; the route itself carries no staff.admin middleware —
 * both email-subscriber routes live in the any-staff group
 * (routes/api/staff.php:137-140). Existing coverage
 * (StaffEmailSubscriberControllerTest, EmailSubscriberExportCapTest) calls
 * the controller directly, so it never proves a real HTTP request carrying a
 * support-tier actor is actually rejected by this route, nor that
 * route-model binding 404s a UUID with no matching professional. This file
 * closes both gaps over real HTTP, and pins the index()/export() tier split.
 */

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupEmailSubscriptionsTable();

    // staff.audit middleware writes here on every request through this route group.
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

function exportAuthTest_staff(string $role): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = $role;

    return $staff;
}

/** Insert a professional row; returns the loaded User model. */
function exportAuthTest_seedProfessional(): User
{
    $id = (string) Str::uuid();
    $handle = 'exp-auth-'.Str::random(8);

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => 'Export Auth Pro',
        'first_name' => 'Export',
        'primary_email' => 'exp-auth-'.Str::random(6).'@example.test',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return User::query()->findOrFail($id);
}

function exportAuthTest_seedSubscription(string $proId, string $email): void
{
    DB::connection('pgsql')->table('notifications.email_subscriptions')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $proId,
        'list_key' => 'marketing',
        'email' => $email,
        'email_lc' => strtolower($email),
        'full_name' => 'Export Auth Fan',
        'status' => 'subscribed',
        'subscribed_at' => now()->toDateTimeString(),
        'unsubscribe_token' => Str::random(16),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

it('denies the CSV export to support-tier staff over real HTTP (#PRIV-2)', function () {
    $pro = exportAuthTest_seedProfessional();
    exportAuthTest_seedSubscription($pro->id, 'fan@example.test');

    $response = actingAsStaff(exportAuthTest_staff(PartnaStaff::ROLE_SUPPORT))
        ->getJson("/api/staff/professionals/{$pro->id}/email-subscribers/export");

    $response->assertForbidden();
});

it('streams the CSV export for admin-tier staff over real HTTP, with the canonical header row', function () {
    $pro = exportAuthTest_seedProfessional();
    $secretEmail = 'export-secret-'.Str::random(20).'@example.test';
    exportAuthTest_seedSubscription($pro->id, $secretEmail);

    $response = actingAsStaff(exportAuthTest_staff(PartnaStaff::ROLE_ADMIN))
        ->getJson("/api/staff/professionals/{$pro->id}/email-subscribers/export");

    $response->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->toContain('email,full_name,status,subscribed_at,unsubscribed_at')
        ->and($csv)->toContain($secretEmail);
});

it('404s the export route for a syntactically-valid UUID with no matching professional (route-model binding)', function () {
    $response = actingAsStaff(exportAuthTest_staff(PartnaStaff::ROLE_ADMIN))
        ->getJson('/api/staff/professionals/'.((string) Str::uuid()).'/email-subscribers/export');

    $response->assertNotFound();
});

// Sibling index() has NO admin gate — pinning the split at
// StaffEmailSubscriberController.php:26-27 (any-staff) vs :86-91 (admin-only export).
it('leaves the paginated subscriber index open to support-tier staff (any-staff, unlike export)', function () {
    $pro = exportAuthTest_seedProfessional();
    exportAuthTest_seedSubscription($pro->id, 'fan@example.test');

    $response = actingAsStaff(exportAuthTest_staff(PartnaStaff::ROLE_SUPPORT))
        ->getJson("/api/staff/professionals/{$pro->id}/email-subscribers");

    $response->assertOk();
});
