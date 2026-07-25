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

it('rejects an unknown architecture id', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-skel');

    patchStaffSite($staff, $pro, ['architecture_id' => 'skeleton-9'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['architecture_id']);
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

it('accepts a valid architecture and settings (negative tests are not over-rejecting)', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-valid');

    patchStaffSite($staff, $pro, [
        'architecture_id' => 'dock', // legacy id — must collapse to 'staple'
        'settings' => ['booking_mode' => 'manual'],
    ])->assertOk();

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('architecture_id'))
        ->toBe('staple');
});

it('accepts the legacy skeleton_id field name and collapses it (transition alias)', function () {
    // Old staff-dashboard builds still send skeleton_id — prepareForValidation
    // merges it into architecture_id, so the write succeeds and stores 'staple'.
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-legacy-field');

    patchStaffSite($staff, $pro, ['skeleton_id' => 'dock'])->assertOk();

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('architecture_id'))
        ->toBe('staple');
});

// ── OV-I: staff endpoint enforces the same ordering-payload rules as the user
// endpoint (shared via SiteOrderingValidationRules) — a staff edit must not be
// able to write an ordering payload PATCH /api/site would reject ───────────────

it('rejects a custom action with a javascript: url (staff parity with the user endpoint)', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-js-url');

    patchStaffSite($staff, $pro, ['settings' => ['manual_actions' => [
        ['kind' => 'custom', 'label' => 'Gift cards', 'url' => 'javascript:alert(1)'],
    ]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.manual_actions.0.url']);
});

it('rejects an unknown page id in manual_page_order (staff parity)', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-bad-order');

    patchStaffSite($staff, $pro, ['settings' => ['manual_page_order' => ['book', 'checkout']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.manual_page_order.1']);
});

it('rejects label/url on a non-custom action entry (staff parity)', function () {
    // Current-shape entry ({kind: action, ...}) — a LEGACY {kind: button, ...}
    // entry with a stray label would instead be migrated by
    // SiteOrderingValidationRules::normalizeManualActionEntry() before this
    // rule ever runs (button+ref -> {kind: action, ref}, dropping the
    // unrecognised label rather than rejecting it — that's the deliberate
    // lenient-on-legacy-shapes posture; strict validation still applies in
    // full to anything already in the current shape, which is what this test
    // proves).
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-strict-order');

    patchStaffSite($staff, $pro, ['settings' => ['manual_actions' => [
        ['kind' => 'action', 'ref' => 'instagram', 'label' => 'Relabelled'],
    ]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.manual_actions.0']);
});

it('migrates a legacy page-kind action ref on the staff endpoint (staff parity)', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-legacy-order');

    patchStaffSite($staff, $pro, ['settings' => ['manual_actions' => [
        ['kind' => 'page', 'ref' => 'book'],
    ]]])->assertOk();

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('settings'))
        ->toContain('booking-services');
});

it('accepts a valid ordering payload on the staff endpoint (not over-rejecting)', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-good-order');

    patchStaffSite($staff, $pro, ['settings' => [
        'smart_actions' => false,
        'manual_actions' => [
            ['kind' => 'action', 'ref' => 'menu'],
            ['kind' => 'custom', 'label' => 'Gift cards', 'url' => 'https://gifts.example/cards'],
        ],
    ]])->assertOk();
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
    // to 'staple' (the write path collapses legacy ids to it), so that column is
    // indistinguishable whether or not a write landed. is_published is NOT pinned — the
    // same PATCH would have flipped it to false — so its staying at the seeded published
    // value is what actually proves the 403 blocked the write.
    $row = DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->first();
    expect((int) $row->is_published)->toBe(1);
    expect($row->architecture_id)->toBe('staple');
});

it('allows an admin-role staffer the site edit', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-site-admin');

    patchStaffSite($staff, $pro, ['architecture_id' => 'dock'])->assertOk();
});
