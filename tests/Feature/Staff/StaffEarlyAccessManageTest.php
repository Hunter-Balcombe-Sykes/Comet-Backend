<?php

/**
 * OV-A — staff manage early-access rows: the resource exposes the build source
 * (source_type/source_ref) plus a has_build flag, and staff store/update accept
 * + validate those two fields with the public form's pairing rule (a source_type
 * must be a known generator key, and the type/ref pair is all-or-nothing).
 */

use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupEarlyAccessTable();
    setupPartnaStaffTable();

    // staff.audit middleware writes here after each staff response.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY, staff_id TEXT, staff_email_snapshot TEXT,
        impersonator_staff_id TEXT, impersonator_email_snapshot TEXT, user_id TEXT,
        professional_handle_snapshot TEXT, route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\', status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\', ip_hash TEXT, user_agent TEXT, created_at TEXT
    )');

    DB::connection('pgsql')->statement('DELETE FROM core.early_access_signups');
    Mail::fake();
    Queue::fake(); // User::factory + any save-side observers stay inert
});

// staffManage is admin-only (EarlyAccessSignupPolicy::staffManage).
function earlyAccessManageAdmin(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->auth_user_id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin-manage@partna.au';

    return $staff;
}

it('store persists and returns source_type/source_ref with has_build false', function () {
    actingAsStaff(earlyAccessManageAdmin())
        ->postJson('/api/staff/early-access', [
            'email' => 'lead@example.test',
            'type' => 'partna',
            'source_type' => 'instagram',
            'source_ref' => 'lead_handle',
        ])
        ->assertStatus(201)
        ->assertJsonPath('signup.source_type', 'instagram')
        ->assertJsonPath('signup.source_ref', 'lead_handle')
        ->assertJsonPath('signup.has_build', false);

    $row = EarlyAccessSignup::query()->where('email_lc', 'lead@example.test')->first();
    expect($row->source_type)->toBe('instagram')
        ->and($row->source_ref)->toBe('lead_handle');
});

it('store still works with no source at all (manual add stays looser than public)', function () {
    actingAsStaff(earlyAccessManageAdmin())
        ->postJson('/api/staff/early-access', [
            'email' => 'nosource@example.test',
            'type' => 'partna',
        ])
        ->assertStatus(201)
        ->assertJsonPath('signup.source_type', null)
        ->assertJsonPath('signup.has_build', false);
});

it('store rejects a half-filled source pair', function () {
    actingAsStaff(earlyAccessManageAdmin())
        ->postJson('/api/staff/early-access', [
            'email' => 'half@example.test',
            'type' => 'partna',
            'source_ref' => 'orphan_handle', // no source_type
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['source_type']);
});

it('store rejects an unknown source_type', function () {
    actingAsStaff(earlyAccessManageAdmin())
        ->postJson('/api/staff/early-access', [
            'email' => 'bogus@example.test',
            'type' => 'partna',
            'source_type' => 'tiktok',
            'source_ref' => 'x',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['source_type']);
});

it('update corrects a malformed source_ref', function () {
    $signup = EarlyAccessSignup::create([
        'email' => 'edit@example.test', 'email_lc' => 'edit@example.test',
        'type' => 'partna', 'source' => 'manual',
        'source_type' => 'instagram', 'source_ref' => 'bad handle',
    ]);

    actingAsStaff(earlyAccessManageAdmin())
        ->patchJson("/api/staff/early-access/{$signup->id}", [
            'source_type' => 'instagram',
            'source_ref' => 'good_handle',
        ])
        ->assertStatus(200)
        ->assertJsonPath('signup.source_ref', 'good_handle');

    expect($signup->fresh()->source_ref)->toBe('good_handle');
});

it('update leaves the source untouched when only other fields change', function () {
    $signup = EarlyAccessSignup::create([
        'email' => 'keep@example.test', 'email_lc' => 'keep@example.test',
        'type' => 'partna', 'source' => 'manual',
        'source_type' => 'google_business', 'source_ref' => 'place_123',
    ]);

    actingAsStaff(earlyAccessManageAdmin())
        ->patchJson("/api/staff/early-access/{$signup->id}", [
            'workplace_or_industry' => 'Barber',
        ])
        ->assertStatus(200);

    $row = $signup->fresh();
    expect($row->source_type)->toBe('google_business')
        ->and($row->source_ref)->toBe('place_123');
});

it('update rejects a half-filled source pair', function () {
    $signup = EarlyAccessSignup::create([
        'email' => 'halfedit@example.test', 'email_lc' => 'halfedit@example.test',
        'type' => 'partna', 'source' => 'manual',
    ]);

    actingAsStaff(earlyAccessManageAdmin())
        ->patchJson("/api/staff/early-access/{$signup->id}", [
            'source_ref' => 'orphan_handle', // no source_type
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['source_type']);
});

it('index exposes has_build true for a build-linked row', function () {
    $user = User::factory()->create(['status' => 'unclaimed']);
    $signup = EarlyAccessSignup::create([
        'email' => 'linked@example.test', 'email_lc' => 'linked@example.test',
        'type' => 'partna', 'source' => 'marketing',
        'source_type' => 'instagram', 'source_ref' => 'linked_handle',
    ]);
    $signup->forceFill(['user_id' => $user->id])->save();

    actingAsStaff(earlyAccessManageAdmin())
        ->getJson('/api/staff/early-access')
        ->assertStatus(200)
        ->assertJsonPath('signups.0.has_build', true)
        ->assertJsonPath('signups.0.source_type', 'instagram')
        ->assertJsonPath('signups.0.source_ref', 'linked_handle');
});
