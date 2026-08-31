<?php

use App\Http\Requests\Api\Staff\UserSite\StaffUpdateUserRequest;
use App\Http\Resources\UserDashboardResource;
use App\Http\Resources\UserStaffResource;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $conn = DB::connection('pgsql');
    // #FU-9: attachTestSchemas() also attaches `audit` — the real HTTP path
    // below runs the staff.audit middleware (RecordStaffAuditEntry), which
    // needs it, on top of the `core`/`site` this lane already attached by hand.
    attachTestSchemas();

    // UserDashboardResource reads core.user_handle_aliases on every
    // serialization (reclaimable_handles) — this lane builds its own tables
    // rather than using setupUsersTable, so the ride-along comes explicitly.
    setupHandleAliasesTable();

    $conn->statement('CREATE TABLE IF NOT EXISTS core.users (
        id TEXT PRIMARY KEY,
        handle TEXT,
        display_name TEXT,
        professional_type TEXT,
        account_type TEXT NULL CHECK (account_type IN (\'partna\',\'business\')),
        status TEXT,
        admin_notes TEXT,
        deleted_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    // UserDashboardResource (the self-service /me resource) reads the `site`
    // relationship, so the table must exist for its query to run (returns null here).
    $conn->statement('CREATE TABLE IF NOT EXISTS site.sites (
        id TEXT PRIMARY KEY,
        user_id TEXT NULL,
        subdomain TEXT NULL,
        custom_domain TEXT NULL,
        custom_domain_status TEXT NULL,
        custom_domain_primary INTEGER NULL,
        deleted_at TEXT NULL
    )');

    // The staff.audit middleware (RecordStaffAuditEntry) runs after the
    // response and writes to audit.staff_audit_log — set up the table so
    // terminate() records for real instead of silently swallowing the insert.
    // Mirrors StaffBulkUpdateStatusValidationTest.php's beforeEach exactly.
    $conn->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
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

it('accepts admin_notes through the staff update form request', function () {
    $request = StaffUpdateUserRequest::create('/', 'PATCH', [
        'admin_notes' => 'VIP brand — do not suspend',
    ]);
    $request->setContainer(app())->setRedirector(app('redirect'));

    $validated = $request->validateResolved() ?? $request->validated();

    expect($request->validated())->toHaveKey('admin_notes')
        ->and($request->validated()['admin_notes'])->toBe('VIP brand — do not suspend');
});

it('persists admin_notes when staff PATCHes the professional', function () {
    DB::table('core.users')->insert([
        'id' => $id = (string) Str::uuid(),
        'handle' => 'test',
        'display_name' => 'Test Brand',
        'status' => 'active',
    ]);

    // The write methods enforce UserSelfPolicy::staffManage (admin-only,
    // #P2-03) via the `staff.admin` route middleware — an admin-role staff
    // actor. actingAsStaff() defaults to fresh-totp AAL2 claims, satisfying
    // both `require.aal2` and update()'s own fresh-AAL2 gate (#W1-SEC-12).
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;

    // #FU-9: real HTTP request through the full staff middleware stack
    // (supabase.jwt, staff, require.aal2, staff.admin, revocation.strict,
    // staff.audit) rather than calling the controller method directly — a
    // direct-controller call 401s internally on a missing gate and still
    // "passes" because nothing asserts a status, which is exactly the blind
    // spot this test used to have.
    actingAsStaff($staff)
        ->patchJson("/api/staff/professionals/{$id}", [
            'admin_notes' => 'DMCA pending — flag any takedown requests',
        ])
        ->assertStatus(200)
        ->assertJsonPath('professional.admin_notes', 'DMCA pending — flag any takedown requests');

    $fresh = User::query()->findOrFail($id);
    expect($fresh->admin_notes)->toBe('DMCA pending — flag any takedown requests');
});

it('exposes admin_notes in staff resource but not in self-service resource', function () {
    $professional = new User;
    $professional->id = (string) Str::uuid();
    $professional->admin_notes = 'Internal: do not contact this brand directly';
    $professional->display_name = 'Test';

    // admin_notes is PII-gated (#SEC-101) — only visible when $showPii is true.
    $staffShape = (new UserStaffResource($professional, true))->toArray(request());
    $selfShape = (new UserDashboardResource($professional))->toArray(request());

    expect($staffShape)->toHaveKey('admin_notes')
        ->and($staffShape['admin_notes'])->toBe('Internal: do not contact this brand directly')
        ->and($selfShape)->not->toHaveKey('admin_notes');
});

it('rejects admin_notes longer than 5000 chars', function () {
    $request = StaffUpdateUserRequest::create('/', 'PATCH', [
        'admin_notes' => str_repeat('a', 5001),
    ]);
    $request->setContainer(app())->setRedirector(app('redirect'));

    expect(fn () => $request->validateResolved())
        ->toThrow(ValidationException::class);
});
