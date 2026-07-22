<?php

/**
 * Task 5 — DELETE /api/staff/professionals/{professional}/force now runs the
 * FULL immediate purge (AccountDeletionService::adminPurgeNow): pseudonymise
 * + delete the Supabase auth user (frees the email) + hard-delete + retire KV.
 * Skips the 30-day grace period entirely — unlike the self-service flow, no
 * scheduled-deletion mail is ever queued.
 *
 * Self-contained (does NOT require_once StaffUserControllerFreshAal2Test.php):
 * that file's beforeEach schema (setupUsersTable, no auth_user_id) is a
 * minimal-columns stub built for the fresh-AAL2 gate test, not the full purge
 * write path (deletion_* columns, admin_notes, site_media, user_deletion_audit).
 * AccountDeletionTestCase (Task 3's AdminPurgeNowTest) already proves the right
 * schema for driving adminPurgeNow — reused here at the HTTP layer.
 */

use App\Mail\Notifications\AccountDeletionScheduledMail;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\User\AccountDeletion\AccountDeletionTestCase;

beforeEach(function () {
    AccountDeletionTestCase::boot();

    // staff.audit middleware (RecordStaffAuditEntry) writes to
    // audit.staff_audit_log after the response — set it up so terminate()
    // doesn't throw on SQLite. Copied verbatim from
    // StaffUserControllerFreshAal2Test.php:38-53.
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

    config([
        'supabase.url' => 'https://test.supabase.co',
        'supabase.service_role_key' => 'test-service-role-key',
        'app.frontend_url' => 'https://app.sidest.test',
    ]);
    Mail::fake();

    // Deliberately NOT faked here (unlike a single shared default) — Http::fake()
    // resolves stubs first-match-wins, not last-registered-wins (Factory::fake()
    // appends to $stubCallbacks and buildStubHandler() takes ->filter()->first()).
    // A blanket 200 stub registered in beforeEach would silently outrank a
    // test-specific 500 override registered later in the same test, since both
    // match the same URL pattern and the earlier one wins. Each test fakes
    // Http for itself instead.
});

function makeImmediateForceDeleteAdmin(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->auth_user_id = (string) Str::uuid();
    // EnsurePartnaAdmin::handle checks isAdmin() on the partna_staff request
    // attribute — the /force route is under the staff.admin group.
    $staff->role = PartnaStaff::ROLE_ADMIN;

    return $staff;
}

function makeImmediateForceDeleteNonAdmin(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->auth_user_id = (string) Str::uuid();
    // Non-admin role — isAdmin() returns false, so EnsurePartnaAdmin rejects
    // the request before it ever reaches the controller/adminPurgeNow.
    $staff->role = PartnaStaff::ROLE_SUPPORT;

    return $staff;
}

/**
 * Full valid professional row, including auth_user_id — the happy-path test
 * asserts the Supabase DELETE was sent for it, so it must be present.
 */
function seedImmediateForceDeletePro(array $overrides = []): User
{
    $id = (string) Str::uuid();
    $data = array_merge([
        'id' => $id,
        'auth_user_id' => (string) Str::uuid(),
        'handle' => 'pro-'.substr($id, 0, 6),
        'handle_lc' => 'pro-'.substr($id, 0, 6),
        'display_name' => 'Pro User',
        'primary_email' => 'pro-'.substr($id, 0, 6).'@example.com',
        'status' => 'active',
        'stripe_manual_balance_cents' => 0,
    ], $overrides);

    DB::connection('pgsql')->table('core.users')->insert($data);

    return User::query()->where('id', $id)->first();
}

it('admin force-delete returns 200 with email_freed and removes the row', function () {
    $staff = makeImmediateForceDeleteAdmin();
    $pro = seedImmediateForceDeletePro();

    Http::fake(['*/auth/v1/admin/users/*' => Http::response('', 200)]);

    actingAsStaff($staff)
        ->deleteJson("/api/staff/professionals/{$pro->id}/force", ['reason' => 'Confirmed spam — ticket #4242'])
        ->assertStatus(200)
        ->assertJsonFragment(['permanently_deleted' => true, 'email_freed' => true]);

    expect(DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->exists())->toBeFalse();

    Http::assertSent(fn ($r) => $r->method() === 'DELETE'
        && str_contains($r->url(), "/auth/v1/admin/users/{$pro->auth_user_id}"));

    // Immediate purge skips the 30-day grace period — never queues the
    // scheduled-deletion email (adminPurgeNow calls executeConfirmation with
    // suppressMail: true).
    Mail::assertNotQueued(AccountDeletionScheduledMail::class);
});

it('rejects a missing reason with 422 before touching the account', function () {
    $staff = makeImmediateForceDeleteAdmin();
    $pro = seedImmediateForceDeletePro();

    Http::fake(['*/auth/v1/admin/users/*' => Http::response('', 200)]);

    actingAsStaff($staff)
        ->deleteJson("/api/staff/professionals/{$pro->id}/force", [])
        ->assertStatus(422);

    expect(DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->exists())->toBeTrue();
    Http::assertNothingSent();
});

it('maps an auth-delete failure to 502 and leaves the account present', function () {
    $staff = makeImmediateForceDeleteAdmin();
    $pro = seedImmediateForceDeletePro();

    Http::fake(['*/auth/v1/admin/users/*' => Http::response('', 500)]);

    actingAsStaff($staff)
        ->deleteJson("/api/staff/professionals/{$pro->id}/force", ['reason' => 'Confirmed spam — ticket #4242'])
        ->assertStatus(502);

    expect(DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->exists())->toBeTrue();
});

it('rejects a non-admin staff member with 403 before touching the account', function () {
    $staff = makeImmediateForceDeleteNonAdmin();
    $pro = seedImmediateForceDeletePro();

    actingAsStaff($staff)
        ->deleteJson("/api/staff/professionals/{$pro->id}/force", ['reason' => 'Non-admin attempt — ticket #1'])
        ->assertStatus(403);

    expect(DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->exists())->toBeTrue();
    Http::assertNothingSent();
});
