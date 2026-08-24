<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    setupUsersTable();

    Route::middleware(['supabase.jwt', 'require.strong_auth'])
        ->get('/__test/strong-auth-gate', fn () => response()->json(['ok' => true]));
});

// SEC-1 regression: with enforcement on, a blank/absent amr must DENY, not
// fail open. Before the fix, `$methods === []` short-circuited the enforced
// branch and let every empty-amr session through regardless of the flag.
it('denies an empty amr when strong_auth_enforce is on', function () {
    config(['partna.auth.strong_auth_enforce' => true]);

    $pro = createTenant('strong-auth-empty-amr');

    actingAsUser($pro) // default amr => []
        ->getJson('/__test/strong-auth-gate')
        ->assertStatus(401)
        ->assertJson([
            'code' => 'strong_auth_required',
            'error' => 'strong_auth_required',
        ]);
});

it('still allows an empty amr in shadow mode', function () {
    config(['partna.auth.strong_auth_enforce' => false]);

    Log::spy();

    $pro = createTenant('strong-auth-shadow');

    actingAsUser($pro) // default amr => []
        ->getJson('/__test/strong-auth-gate')
        ->assertOk();

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) {
            return $message === 'auth.strong_auth.would_deny'
                && ($context['amr_empty'] ?? null) === true
                && ($context['enforced'] ?? null) === false;
        });
});

it('allows a strong amr when enforcement is on', function () {
    config(['partna.auth.strong_auth_enforce' => true]);

    $pro = createTenant('strong-auth-password');

    actingAsUser($pro, ['amr' => [['method' => 'password', 'timestamp' => time()]]])
        ->getJson('/__test/strong-auth-gate')
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('denies a weak non-empty amr when enforcement is on', function () {
    config(['partna.auth.strong_auth_enforce' => true]);

    $pro = createTenant('strong-auth-otp');

    actingAsUser($pro, ['amr' => [['method' => 'otp', 'timestamp' => time()]]])
        ->getJson('/__test/strong-auth-gate')
        ->assertStatus(401);
});
