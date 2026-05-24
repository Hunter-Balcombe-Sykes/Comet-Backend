<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    setupProfessionalsTable();

    // Register a test route that requires the full professional.api group and returns
    // the resolved professional's ID so we can confirm all three middleware ran.
    Route::middleware(['professional.api'])
        ->get('/__test/auth-api-group', function (\Illuminate\Http\Request $request) {
            return response()->json(['professional_id' => $request->get('professional')->id]);
        });
});

it('rejects unauthenticated requests with 401', function () {
    $this->getJson('/__test/auth-api-group')
        ->assertStatus(401);
});

it('resolves the professional when a valid JWT is supplied', function () {
    $pro = createAffiliateTenant('auth-api-group-user');

    actingAsProfessional($pro)
        ->getJson('/__test/auth-api-group')
        ->assertOk()
        ->assertJsonFragment(['professional_id' => $pro->id]);
});

it('rejects a valid JWT when email is not verified', function () {
    $pro = createAffiliateTenant('auth-api-group-unverified');

    // Pass email_verified=false in claims — RequireEmailVerified reads this
    // from the supabase_claims request attribute set by the JWT stub.
    actingAsProfessional($pro, ['email_verified' => false])
        ->getJson('/__test/auth-api-group')
        ->assertStatus(403)
        ->assertJsonFragment(['error' => 'email_verification_required']);
});
