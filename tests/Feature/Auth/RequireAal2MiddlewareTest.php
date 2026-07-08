<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    setupUsersTable();

    Route::middleware(['supabase.jwt', 'require.aal2'])
        ->get('/__test/aal2-gate', fn () => response()->json(['ok' => true]));
});

it('returns 401 with mfa_required code when the session is aal1', function () {
    $pro = createAffiliateTenant('aal1-user');

    actingAsUser($pro) // default aal1
        ->getJson('/__test/aal2-gate')
        ->assertStatus(401)
        ->assertJson([
            'code' => 'mfa_required',
            'error' => 'mfa_required',
        ]);
});

it('passes through when the session is aal2', function () {
    $pro = createAffiliateTenant('aal2-user');

    actingAsUser($pro, aal2ClaimsWithFreshTotp())
        ->getJson('/__test/aal2-gate')
        ->assertOk()
        ->assertJson(['ok' => true]);
});
