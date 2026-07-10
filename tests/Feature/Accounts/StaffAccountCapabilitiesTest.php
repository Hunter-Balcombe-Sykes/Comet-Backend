<?php

/**
 * OV-A — staff account type capabilities.
 *
 * account_type='staff' flips every user-facing capability off (no site, no
 * integrations, no design, not reportable) and derives granular staff powers
 * from the linked core.partna_staff role: support = view-level, admin =
 * view + manage. A staff users row with NO partna_staff row gets no powers.
 */

use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupPartnaStaffTable();
    AccountCapabilities::flushCache();
});

function ovaMakeStaffUser(?string $role, string $suffix = ''): User
{
    $authUserId = (string) Str::uuid();
    $userId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'auth_user_id' => $authUserId,
        'handle' => 'staff-'.Str::random(6).$suffix,
        'display_name' => 'Staff Member',
        'account_type' => 'staff',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    if ($role !== null) {
        DB::connection('pgsql')->table('core.partna_staff')->insert([
            'id' => (string) Str::uuid(),
            'auth_user_id' => $authUserId,
            'role' => $role,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    return User::query()->findOrFail($userId);
}

it('flips user-facing capabilities off for staff accounts', function () {
    $caps = AccountCapabilities::for(ovaMakeStaffUser('admin'));

    expect($caps->is_staff)->toBeTrue()
        ->and($caps->can_have_site)->toBeFalse()
        ->and($caps->can_connect_integrations)->toBeFalse()
        ->and($caps->can_edit_design)->toBeFalse()
        ->and($caps->can_submit_feedback)->toBeFalse()
        ->and($caps->can_be_reported)->toBeFalse()
        ->and($caps->receive_moderation_notifications)->toBeFalse()
        ->and($caps->can_book_storewide)->toBeFalse()
        ->and($caps->can_use_multipage_site)->toBeFalse();
});

it('grants admin staff both view and manage powers', function () {
    $caps = AccountCapabilities::for(ovaMakeStaffUser('admin'));

    expect($caps->staff_manage_users)->toBeTrue()
        ->and($caps->staff_manage_segments)->toBeTrue()
        ->and($caps->staff_manage_availability)->toBeTrue()
        ->and($caps->staff_send_notifications)->toBeTrue()
        ->and($caps->staff_manage_early_access)->toBeTrue()
        ->and($caps->staff_view_aggregate_analytics)->toBeTrue()
        ->and($caps->staff_view_feedback)->toBeTrue();
});

it('grants support staff view powers but no manage powers', function () {
    $caps = AccountCapabilities::for(ovaMakeStaffUser('support'));

    expect($caps->staff_view_aggregate_analytics)->toBeTrue()
        ->and($caps->staff_view_feedback)->toBeTrue()
        ->and($caps->staff_manage_users)->toBeFalse()
        ->and($caps->staff_manage_segments)->toBeFalse()
        ->and($caps->staff_manage_availability)->toBeFalse()
        ->and($caps->staff_send_notifications)->toBeFalse()
        ->and($caps->staff_manage_early_access)->toBeFalse();
});

it('grants no powers to a staff users row without a partna_staff record', function () {
    $caps = AccountCapabilities::for(ovaMakeStaffUser(null));

    expect($caps->is_staff)->toBeTrue()
        ->and($caps->staff_view_aggregate_analytics)->toBeFalse()
        ->and($caps->staff_manage_users)->toBeFalse();
});

it('keeps non-staff accounts exactly as before', function () {
    $userId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert([
        'id' => $userId,
        'handle' => 'pro-'.Str::random(6),
        'account_type' => 'business',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $caps = AccountCapabilities::for(User::query()->findOrFail($userId));

    expect($caps->is_staff)->toBeFalse()
        ->and($caps->can_have_site)->toBeTrue()
        ->and($caps->can_connect_integrations)->toBeTrue()
        ->and($caps->can_edit_design)->toBeTrue()
        ->and($caps->can_book_storewide)->toBeTrue()
        ->and($caps->staff_manage_users)->toBeFalse();
});
