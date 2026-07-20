<?php

/**
 * TEST-102 — UserSegmentPolicy enforcement (StaffSegmentController).
 *
 * UserSegmentPolicy is registered:
 *   Gate::policy(UserSegment::class, UserSegmentPolicy::class);
 *   Gate::policy(UserSegmentMember::class, UserSegmentPolicy::class);
 *
 * Role gradient under test:
 *   - support role → staffView allowed,  staffManage denied.
 *   - admin role   → staffView allowed,  staffManage allowed.
 *   - a staff row with a role outside support/admin → staffView denied (403).
 *     The read-side route group (`staff` middleware) is role-agnostic, so
 *     this case is gated purely by the policy ability.
 *   - no staff row at all → 403 staff_required (EnsurePartnaStaff), before the
 *     policy is ever consulted.
 *
 * Write routes (POST/PATCH/DELETE) sit behind `staff.admin` (EnsurePartnaAdmin),
 * which enforces the identical `$actor->isAdmin()` rule as
 * UserSegmentPolicy::staffManage — real defence-in-depth, but it means an
 * HTTP-level "support denied" assertion on a write route is satisfied by
 * either layer. The bare Gate::forUser() assertions below isolate the policy
 * method itself so a neutered ability is provable independent of the
 * middleware duplicate.
 *
 * All HTTP calls go through real routes via actingAsStaff() (never bare
 * controller calls) so the route-to-ability wiring is proven, not assumed.
 */

use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\FeatureAvailability\FeatureAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupPartnaStaffTable();
    setupSegmentsTables();
    setupFeatureAvailabilityTable();

    // staff.audit middleware (RecordStaffAuditEntry) writes on terminate() for
    // write methods — create the table so it doesn't throw (mirrors
    // SegmentTakedownTriggerTest.php).
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY, staff_id TEXT, staff_email_snapshot TEXT,
        impersonator_staff_id TEXT, impersonator_email_snapshot TEXT, user_id TEXT,
        professional_handle_snapshot TEXT, route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\', status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\', ip_hash TEXT, user_agent TEXT, created_at TEXT
    )');

    DB::connection('pgsql')->statement('DELETE FROM core.user_segments');
    DB::connection('pgsql')->statement('DELETE FROM core.users');
    FeatureAvailability::flush();
});

function segmentPolicy_staff(string $role): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = $role;
    $staff->primary_email = "segpolicy-{$role}@partna.au";

    return $staff;
}

// --- staffView: role gradient, real HTTP route (GET is role-agnostic at the middleware layer) ---

it('allows support-role staff to list segments', function () {
    actingAsStaff(segmentPolicy_staff(PartnaStaff::ROLE_SUPPORT))
        ->getJson('/api/staff/segments')
        ->assertOk()
        ->assertJsonStructure(['segments']);
});

it('allows admin-role staff to list segments', function () {
    actingAsStaff(segmentPolicy_staff(PartnaStaff::ROLE_ADMIN))
        ->getJson('/api/staff/segments')
        ->assertOk()
        ->assertJsonStructure(['segments']);
});

it('denies a staff role outside support/admin from listing segments (403, policy-gated)', function () {
    // Nothing else guards this route by role — the `staff` middleware group
    // accepts any authenticated staff row. This is the ability doing the work.
    actingAsStaff(segmentPolicy_staff('unknown_role'))
        ->getJson('/api/staff/segments')
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
        ->getJson('/api/staff/segments')
        ->assertStatus(403)
        ->assertJson(['error' => 'staff_required']);
});

it('denies a staff role outside support/admin from viewing one segment (403, policy-gated)', function () {
    $segment = UserSegment::query()->create(['name' => 'seg-view-'.Str::random(4), 'filters' => []]);

    actingAsStaff(segmentPolicy_staff('unknown_role'))
        ->getJson("/api/staff/segments/{$segment->id}")
        ->assertStatus(403);
});

// --- staffManage: role gradient, real HTTP write routes ---

it('rejects support-role staff from creating a segment (403)', function () {
    actingAsStaff(segmentPolicy_staff(PartnaStaff::ROLE_SUPPORT))
        ->postJson('/api/staff/segments', ['name' => 'seg-'.Str::random(4)])
        ->assertStatus(403);
});

it('allows admin-role staff to create a segment', function () {
    $name = 'seg-'.Str::random(6);

    actingAsStaff(segmentPolicy_staff(PartnaStaff::ROLE_ADMIN))
        ->postJson('/api/staff/segments', ['name' => $name])
        ->assertStatus(201);

    expect(UserSegment::query()->where('name', $name)->exists())->toBeTrue();
});

it('rejects support-role staff from updating a segment (403)', function () {
    $segment = UserSegment::query()->create(['name' => 'seg-upd-'.Str::random(4), 'filters' => []]);

    actingAsStaff(segmentPolicy_staff(PartnaStaff::ROLE_SUPPORT))
        ->patchJson("/api/staff/segments/{$segment->id}", ['name' => 'renamed'])
        ->assertStatus(403);

    expect($segment->fresh()->name)->not->toBe('renamed');
});

it('allows admin-role staff to update a segment', function () {
    $segment = UserSegment::query()->create(['name' => 'seg-upd2-'.Str::random(4), 'filters' => []]);

    actingAsStaff(segmentPolicy_staff(PartnaStaff::ROLE_ADMIN))
        ->patchJson("/api/staff/segments/{$segment->id}", ['name' => 'renamed'])
        ->assertOk();

    expect($segment->fresh()->name)->toBe('renamed');
});

it('rejects support-role staff from deleting a segment (403)', function () {
    $segment = UserSegment::query()->create(['name' => 'seg-del-'.Str::random(4), 'filters' => []]);

    actingAsStaff(segmentPolicy_staff(PartnaStaff::ROLE_SUPPORT))
        ->deleteJson("/api/staff/segments/{$segment->id}")
        ->assertStatus(403);

    expect(UserSegment::query()->whereKey($segment->id)->exists())->toBeTrue();
});

it('allows admin-role staff to delete a segment', function () {
    $segment = UserSegment::query()->create(['name' => 'seg-del2-'.Str::random(4), 'filters' => []]);

    actingAsStaff(segmentPolicy_staff(PartnaStaff::ROLE_ADMIN))
        ->deleteJson("/api/staff/segments/{$segment->id}")
        ->assertStatus(204);

    expect(UserSegment::query()->whereKey($segment->id)->exists())->toBeFalse();
});

// --- staffManage: bare Gate assertions pinning the ability itself ---
//
// EnsurePartnaAdmin (the staff.admin middleware) enforces the exact same
// $actor->isAdmin() rule as staffManage, so an HTTP "support denied" test
// above is satisfied even if staffManage were neutered to always-allow —
// the middleware alone would still 403 it. These bare-Gate assertions
// isolate the ability so it can't hide behind that duplicate.

it('UserSegmentPolicy::staffManage denies support and allows admin (ability-level, not just middleware)', function () {
    expect(Gate::forUser(segmentPolicy_staff(PartnaStaff::ROLE_SUPPORT))->allows('staffManage', UserSegment::class))
        ->toBeFalse();

    expect(Gate::forUser(segmentPolicy_staff(PartnaStaff::ROLE_ADMIN))->allows('staffManage', UserSegment::class))
        ->toBeTrue();
});

it('UserSegmentPolicy denies every ability for a User actor (staff-only resource)', function () {
    $user = new User;
    $user->id = (string) Str::uuid();
    $user->status = 'active';

    expect(Gate::forUser($user)->allows('viewAny', UserSegment::class))->toBeFalse();
    expect(Gate::forUser($user)->allows('manage', UserSegment::class))->toBeFalse();
});
