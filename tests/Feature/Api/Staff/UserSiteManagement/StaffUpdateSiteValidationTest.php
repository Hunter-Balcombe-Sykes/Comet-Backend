<?php

// TEST-4 (staff side): functional validation coverage for StaffUpdateSiteRequest.
//
// StaffUpdateSiteRequest carries the same design-kit / architecture / subdomain
// rules as the user-facing UpdateSiteRequest (the two are kept in sync — see
// DesignKitRequestSyncTest). The structural drift test never invokes the
// validator, so these tests drive the real
// PATCH /api/staff/professionals/{professional}/site pipeline and assert each
// rule rejects bad input with a 422 on the right key.

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupPartnaStaffTable();
    // createTenant() (called per test) sets up core.users + site.sites; the
    // subdomain closure also queries these alias tables.
    setupSubdomainAliasesTable();
    setupHandleAliasesTable();
});

function patchStaffSite(PartnaStaff $staff, User $pro, array $payload)
{
    return actingAsStaff($staff)->patchJson("/api/staff/professionals/{$pro->id}/site", $payload);
}

it('rejects a non-hex design-kit colour', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-hex');

    patchStaffSite($staff, $pro, ['design_kit' => ['color_accent' => 'red']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['design_kit.color_accent']);
});

it('ignores architecture_id entirely and never lets it reach the column', function () {
    // architecture_id left the wire 2026-08-20. It is now an unknown key: the
    // request succeeds and the value is dropped. The property that matters is
    // the second one — an ignored field must not be able to write a value the
    // DB CHECK would reject.
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-skel');

    patchStaffSite($staff, $pro, ['architecture_id' => 'skeleton-9'])->assertOk();

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('architecture_id'))
        ->toBe('scroll');
});

it('rejects a reserved subdomain', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-reserved');

    patchStaffSite($staff, $pro, ['subdomain' => 'admin'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['subdomain']);
});

it('rejects a malformed subdomain', function () {
    // Underscore survives prepareForValidation()'s lowercasing and fails the
    // DNS-safe regex (an uppercase value would be normalised and pass).
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-format');

    patchStaffSite($staff, $pro, ['subdomain' => 'bad_subdomain'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['subdomain']);
});

it('rejects any settings.design.* path (skeleton-cleanup guard)', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-design');

    patchStaffSite($staff, $pro, ['settings' => ['design' => ['accent' => '#ffffff']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.design']);
});

it('accepts settings (negative tests are not over-rejecting)', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-valid');

    patchStaffSite($staff, $pro, [
        'settings' => ['booking_mode' => 'manual'],
    ])->assertOk();

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('architecture_id'))
        ->toBe('scroll');
});

it('ignores retired legacy ids and the dropped skeleton_id field', function () {
    // The skeleton_id alias + LEGACY_ARCHITECTURE_IDS collapse were removed
    // 2026-08-05; architecture_id itself left the wire 2026-08-20. Both are now
    // unknown keys — accepted and dropped, never persisted.
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-legacy-field');

    patchStaffSite($staff, $pro, ['architecture_id' => 'dock'])->assertOk();
    patchStaffSite($staff, $pro, ['skeleton_id' => 'dock'])->assertOk();

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('architecture_id'))
        ->toBe('scroll');
});

// ── Staff endpoint enforces the same ordering-payload rules as the user
// endpoint (shared via SiteOrderingValidationRules) — a staff edit must not be
// able to write an ordering payload PATCH /api/site would reject ───────────────

it('rejects a non-grammar action id in settings.actions.slots (staff parity with the user endpoint)', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-bad-slot');

    patchStaffSite($staff, $pro, ['settings' => ['actions' => ['mode' => 'smart', 'slots' => [
        ['position' => 0, 'id' => 'custom:javascript:alert(1)'],
    ]]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.actions.slots.0.id']);
});

it('rejects an unknown page id in manual_page_order (staff parity)', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-bad-order');

    patchStaffSite($staff, $pro, ['settings' => ['manual_page_order' => ['book', 'checkout']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.manual_page_order.1']);
});

it('rejects a pool_order mode for events (staff parity)', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-pool-events');

    patchStaffSite($staff, $pro, ['settings' => ['pool_order' => ['events' => 'smart']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.pool_order']);
});

it('accepts a valid ordering payload on the staff endpoint (not over-rejecting)', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-good-order');

    patchStaffSite($staff, $pro, ['settings' => [
        'actions' => ['mode' => 'manual', 'slots' => [['position' => 0, 'id' => 'page:menu'], ['position' => 1, 'id' => 'platform:instagram']]],
        'pool_order' => ['menus' => 'manual'],
    ]])->assertOk();

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('settings'))
        ->toContain('platform:instagram');
});

// ── B6/SEC-5: this is a mutating admin action (staff rename with force-publish
// + subdomain override). It must be gated by UserSelfPolicy::staffManage
// (admin-only) at the Policy layer, not the staffView no-op — so the check
// holds even if the staff.admin route middleware is ever removed. Without the
// gate, a support-role staffer's request passes the Policy check as readily as
// an admin's; these two tests fail if the gate reverts to staffView. ─────────

it('denies a support-role staffer the site edit (staffManage is admin-only)', function () {
    // The factory default role is ROLE_SUPPORT (see PartnaStaffFactory) — no
    // ->support() state exists; the bare factory IS the support role.
    $staff = PartnaStaff::factory()->create();
    $pro = createTenant('staff-site-support');

    // Send an UNPINNED column (is_published, seeded true by createTenant) alongside
    // architecture_id so a landed write would leave an observable trace.
    patchStaffSite($staff, $pro, ['architecture_id' => 'dock', 'is_published' => false])
        ->assertStatus(403);

    // The write must not have landed. sites_architecture_id_check pins architecture_id
    // to 'scroll' (the write path collapses legacy ids to the platform default,
    // indistinguishable whether or not a write landed. is_published is NOT pinned — the
    // same PATCH would have flipped it to false — so its staying at the seeded published
    // value is what actually proves the 403 blocked the write.
    $row = DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->first();
    expect((int) $row->is_published)->toBe(1);
    expect($row->architecture_id)->toBe('scroll');
});

it('allows an admin-role staffer the site edit', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-site-admin');

    patchStaffSite($staff, $pro, ['architecture_id' => 'staple'])->assertOk();
});
