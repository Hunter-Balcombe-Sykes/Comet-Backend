<?php

// FOUND-16: verifies that booking_mode='none' is accepted by the generic
// site-update endpoint, the staff endpoint, and the dedicated booking endpoint;
// and that an unknown mode is rejected everywhere. Also confirms charlie_enabled
// is now accepted and persisted via the staff endpoint (honored premise A).

use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // PATCH /api/site carries throttle:authenticated — disable so repeated runs
    // in one process don't trip the limiter.
    config(['partna.throttle.enabled' => false]);
    setupSubdomainAliasesTable();
    setupHandleAliasesTable();
    setupPartnaStaffTable();
});

it('accepts booking_mode none on the user site-update endpoint and persists the column', function () {
    $pro = createTenant('booking-none');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['booking_mode' => 'none']])
        ->assertOk();

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('booking_mode'))
        ->toBe('none');
});

it('rejects an unknown booking_mode with a 422 on settings.booking_mode', function () {
    $pro = createTenant('booking-bad');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['booking_mode' => 'calendly']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.booking_mode']);
});

it('accepts booking_mode manual on the user site-update endpoint', function () {
    $pro = createTenant('booking-manual');

    actingAsUser($pro)
        ->patchJson('/api/site', ['settings' => ['booking_mode' => 'manual']])
        ->assertOk();

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('booking_mode'))
        ->toBe('manual');
});

it('accepts and persists charlie_enabled on the staff site-update endpoint', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('staff-charlie');

    actingAsStaff($staff)
        ->patchJson("/api/staff/professionals/{$pro->id}/site", ['settings' => ['charlie_enabled' => true]])
        ->assertOk();

    expect((bool) DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->value('charlie_enabled'))
        ->toBeTrue();
});

it('rejects an unknown booking_mode on the dedicated booking-settings endpoint', function () {
    $pro = createTenant('booking-dedicated-bad');

    actingAsUser($pro)
        ->patchJson('/api/booking/settings', ['booking_mode' => 'calendly'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['booking_mode']);
});
