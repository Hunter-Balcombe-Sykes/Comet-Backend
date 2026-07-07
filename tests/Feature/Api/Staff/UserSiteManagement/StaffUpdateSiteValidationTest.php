<?php

// TEST-4 (staff side): functional validation coverage for StaffUpdateSiteRequest.
//
// StaffUpdateSiteRequest carries the same design-kit / skeleton / subdomain
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

it('rejects an unknown skeleton id', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-skel');

    patchStaffSite($staff, $pro, ['skeleton_id' => 'skeleton-9'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['skeleton_id']);
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

it('accepts a valid skeleton and settings (negative tests are not over-rejecting)', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-valid');

    patchStaffSite($staff, $pro, [
        'skeleton_id' => 'dock',
        'settings' => ['booking_mode' => 'manual'],
    ])->assertOk();

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('skeleton_id'))
        ->toBe('dock');
});
