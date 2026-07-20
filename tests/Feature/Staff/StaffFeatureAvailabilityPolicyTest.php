<?php

/**
 * TEST-102 — FeatureAvailabilityPolicy enforcement (StaffFeatureAvailabilityController).
 *
 * FeatureAvailabilityPolicy is registered:
 *   Gate::policy(FeatureAvailabilityRule::class, FeatureAvailabilityPolicy::class).
 *
 * Role gradient under test:
 *   - support role → staffView allowed,  staffManage denied.
 *   - admin role   → staffView allowed,  staffManage allowed.
 *   - a staff row with a role outside support/admin → staffView denied (403).
 *     This is the ONE case gated purely by the policy ability rather than
 *     duplicated by middleware — the read-side route group only requires
 *     `staff` (any role), no role check at that layer.
 *   - no staff row at all → 403 staff_required (EnsurePartnaStaff), before the
 *     policy is ever consulted.
 *
 * Read routes (GET /staff/feature-availability) sit behind the `staff` group
 * (role-agnostic middleware) — staffView is the only thing gating role there.
 * Write routes (PUT/DELETE) sit behind `staff.admin` (EnsurePartnaAdmin),
 * which enforces the identical `$actor->isAdmin()` rule as
 * FeatureAvailabilityPolicy::staffManage — real defence-in-depth, but it means
 * an HTTP-level "support denied" assertion on a write route is satisfied by
 * either layer. The bare Gate::forUser() assertions below isolate the policy
 * method itself so a neutered ability is provable independent of the
 * middleware duplicate.
 *
 * All HTTP calls go through real routes via actingAsStaff() (never bare
 * controller calls) so the route-to-ability wiring is proven, not assumed.
 */

use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

beforeEach(function () {
    setupPartnaStaffTable();
    setupSegmentsTables();
    setupFeatureAvailabilityTable();

    // staff.audit middleware (RecordStaffAuditEntry) writes on terminate() for
    // write methods — create the table so it doesn't throw (mirrors
    // FeatureAvailabilityTakedownTriggerTest.php).
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

function featAvailPolicy_staff(string $role): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = $role;
    $staff->primary_email = "featavail-{$role}@partna.au";

    return $staff;
}

// --- staffView: role gradient, real HTTP route (GET is role-agnostic at the middleware layer) ---

it('allows support-role staff to list feature-availability rules', function () {
    actingAsStaff(featAvailPolicy_staff(PartnaStaff::ROLE_SUPPORT))
        ->getJson('/api/staff/feature-availability')
        ->assertOk()
        ->assertJsonStructure(['rules']);
});

it('allows admin-role staff to list feature-availability rules', function () {
    actingAsStaff(featAvailPolicy_staff(PartnaStaff::ROLE_ADMIN))
        ->getJson('/api/staff/feature-availability')
        ->assertOk()
        ->assertJsonStructure(['rules']);
});

it('denies a staff role outside support/admin from listing feature-availability rules (403, policy-gated)', function () {
    // Nothing else guards this route by role — the `staff` middleware group
    // accepts any authenticated staff row. This is the ability doing the work.
    actingAsStaff(featAvailPolicy_staff('unknown_role'))
        ->getJson('/api/staff/feature-availability')
        ->assertStatus(403);
});

it('rejects a non-staff actor entirely, before the policy is consulted (403 staff_required)', function () {
    // actingAsUser() stubs the JWT only — EnsurePartnaStaff runs for real and
    // finds no core.partna_staff row for this auth_user_id.
    $notStaff = new User;
    $notStaff->id = (string) Str::uuid();
    $notStaff->auth_user_id = (string) Str::uuid();
    $notStaff->primary_email = 'not-staff@example.test';

    actingAsUser($notStaff)
        ->getJson('/api/staff/feature-availability')
        ->assertStatus(403)
        ->assertJson(['error' => 'staff_required']);
});

// --- staffManage: role gradient, real HTTP write routes ---

it('rejects support-role staff from upserting a feature-availability rule (403)', function () {
    actingAsStaff(featAvailPolicy_staff(PartnaStaff::ROLE_SUPPORT))
        ->putJson('/api/staff/feature-availability', [
            'feature_key' => 'feature.beta',
            'mode' => 'enabled',
        ])
        ->assertStatus(403);
});

it('allows admin-role staff to upsert a feature-availability rule', function () {
    actingAsStaff(featAvailPolicy_staff(PartnaStaff::ROLE_ADMIN))
        ->putJson('/api/staff/feature-availability', [
            'feature_key' => 'feature.beta',
            'mode' => 'enabled',
        ])
        ->assertSuccessful();

    expect(FeatureAvailabilityRule::query()->where('feature_key', 'feature.beta')->exists())->toBeTrue();
});

it('rejects support-role staff from deleting a feature-availability rule (403)', function () {
    $rule = FeatureAvailabilityRule::query()->create(['feature_key' => 'feature.gamma', 'mode' => 'disabled']);

    actingAsStaff(featAvailPolicy_staff(PartnaStaff::ROLE_SUPPORT))
        ->deleteJson("/api/staff/feature-availability/{$rule->id}")
        ->assertStatus(403);

    expect(FeatureAvailabilityRule::query()->whereKey($rule->id)->exists())->toBeTrue();
});

it('allows admin-role staff to delete a feature-availability rule', function () {
    $rule = FeatureAvailabilityRule::query()->create(['feature_key' => 'feature.delta', 'mode' => 'disabled']);

    actingAsStaff(featAvailPolicy_staff(PartnaStaff::ROLE_ADMIN))
        ->deleteJson("/api/staff/feature-availability/{$rule->id}")
        ->assertStatus(204);

    expect(FeatureAvailabilityRule::query()->whereKey($rule->id)->exists())->toBeFalse();
});

// --- staffManage: bare Gate assertions pinning the ability itself ---
//
// EnsurePartnaAdmin (the staff.admin middleware) enforces the exact same
// $actor->isAdmin() rule as staffManage, so an HTTP "support denied" test
// above is satisfied even if staffManage were neutered to always-allow —
// the middleware alone would still 403 it. These bare-Gate assertions
// isolate the ability so it can't hide behind that duplicate.

it('FeatureAvailabilityPolicy::staffManage denies support and allows admin (ability-level, not just middleware)', function () {
    expect(Gate::forUser(featAvailPolicy_staff(PartnaStaff::ROLE_SUPPORT))->allows('staffManage', FeatureAvailabilityRule::class))
        ->toBeFalse();

    expect(Gate::forUser(featAvailPolicy_staff(PartnaStaff::ROLE_ADMIN))->allows('staffManage', FeatureAvailabilityRule::class))
        ->toBeTrue();
});

it('FeatureAvailabilityPolicy denies every ability for a User actor (staff-only resource)', function () {
    $user = new User;
    $user->id = (string) Str::uuid();
    $user->status = 'active';

    expect(Gate::forUser($user)->allows('viewAny', FeatureAvailabilityRule::class))->toBeFalse();
    expect(Gate::forUser($user)->allows('manage', FeatureAvailabilityRule::class))->toBeFalse();
});
