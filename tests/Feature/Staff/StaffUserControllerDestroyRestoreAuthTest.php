<?php

/**
 * #P2-03 — HTTP-layer denial proof for destroy + restore (support staff).
 *
 * destroy (DELETE) and restore (POST .../restore) live in the staff-only
 * middleware group — NOT staff.admin — so a support-role PartnaStaff DOES
 * reach these controller methods at runtime. The staffManage policy gate is
 * the ONLY thing that denies them. These tests prove the wiring end-to-end
 * through the real HTTP stack (middleware + controller + policy).
 */

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
});

/** Build an unsaved support-role PartnaStaff (non-admin). */
function destroyRestoreTest_makeSupportStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_SUPPORT;
    $staff->primary_email = 'support@partna.au';

    return $staff;
}

/** Build an unsaved admin-role PartnaStaff. */
function destroyRestoreTest_makeAdminStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin@partna.au';

    return $staff;
}

/** Insert a professional row and return the User model. */
function destroyRestoreTest_seedProfessional(): User
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $id,
        'handle' => 'pro-'.Str::random(6),
        'display_name' => 'Test Pro',
        'primary_email' => 'pro-'.Str::random(6).'@example.test',
        'account_type' => 'partna',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return User::query()->findOrFail($id);
}

/** Soft-delete an existing professional so the restore route has something to act on. */
function destroyRestoreTest_softDelete(User $professional): void
{
    DB::connection('pgsql')
        ->table('core.users')
        ->where('id', $professional->id)
        ->update(['deleted_at' => now()->toDateTimeString()]);
}

// ---------------------------------------------------------------------------
// Support staff — denied at the policy gate (not at upstream middleware)
// ---------------------------------------------------------------------------

it('support staff is denied 403 on DELETE /api/staff/professionals/{id}', function () {
    $pro = destroyRestoreTest_seedProfessional();

    actingAsStaff(destroyRestoreTest_makeSupportStaff())
        ->deleteJson("/api/staff/professionals/{$pro->id}")
        ->assertStatus(403);
});

it('support staff is denied 403 on POST /api/staff/professionals/{id}/restore', function () {
    $pro = destroyRestoreTest_seedProfessional();
    destroyRestoreTest_softDelete($pro);

    actingAsStaff(destroyRestoreTest_makeSupportStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/restore")
        ->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Admin staff — allowed through the policy gate
// ---------------------------------------------------------------------------

it('admin staff receives 200 on DELETE /api/staff/professionals/{id}', function () {
    $pro = destroyRestoreTest_seedProfessional();

    actingAsStaff(destroyRestoreTest_makeAdminStaff())
        ->deleteJson("/api/staff/professionals/{$pro->id}")
        ->assertStatus(200);
});

it('admin staff receives 200 on POST /api/staff/professionals/{id}/restore', function () {
    $pro = destroyRestoreTest_seedProfessional();
    destroyRestoreTest_softDelete($pro);

    actingAsStaff(destroyRestoreTest_makeAdminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/restore")
        ->assertStatus(200);
});
