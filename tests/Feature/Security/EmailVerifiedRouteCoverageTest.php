<?php

use App\Http\Middleware\Auth\RequireEmailVerified;
use App\Http\Middleware\Auth\VerifySupabaseJwt;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Support\Architecture\SweepGuard;

/*
|--------------------------------------------------------------------------
| Email-Verified Route Coverage Sweep
|--------------------------------------------------------------------------
| Every route that runs VerifySupabaseJwt MUST also run RequireEmailVerified,
| unless it is explicitly listed in EMAIL_VERIFY_EXEMPT with a justification.
|
| Default-deny pattern: a new authenticated route file added in the future
| automatically inherits the gate when it uses the `supabase.jwt` middleware
| group. Genuine exemptions (bootstrap, account-state discovery) must be
| acknowledged here.
*/

const EMAIL_VERIFY_EXEMPT = [
    // The frontend calls /bootstrap to discover account state — including
    // whether the user still needs to verify their email — so it must work
    // BEFORE verification. The controller itself is read-only and only
    // returns the caller's own data.
    'POST api/bootstrap',
    // Same OV-A pattern as bootstrap: /claim binds a fresh Supabase auth user
    // (email OTP, possibly still unverified at the JWT layer) to an unclaimed
    // pre-account site. ClaimController fails closed itself — it checks for
    // the PRESENCE of a verified email claim and 422s EMAIL_VERIFICATION_REQUIRED
    // when absent, rather than reading RequireEmailVerified's `email_verified`
    // boolean — an OTP-minted JWT can't ordinarily carry an email claim that
    // isn't verified, so a route-level require.email_verified gate would be
    // redundant and would block the legitimate unverified-token case this
    // endpoint exists to handle.
    'POST api/claim',
];

/**
 * @param  iterable<Illuminate\Routing\Route>  $routes
 * @return array{matched: list<string>, offenders: list<string>}
 */
function emailVerifySweep(iterable $routes): array
{
    $matched = [];
    $offenders = [];

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();

        $hasJwt = in_array(VerifySupabaseJwt::class, $middleware, true)
            || in_array('supabase.jwt', $middleware, true);

        if (! $hasJwt) {
            continue;
        }

        // Normalise to the first verb for readability (Laravel adds HEAD to GET).
        $primary = strtoupper($route->methods()[0]).' '.$route->uri();
        $matched[] = $primary;

        $hasGate = in_array(RequireEmailVerified::class, $middleware, true)
            || in_array('require.email_verified', $middleware, true);

        if ($hasGate || in_array($primary, EMAIL_VERIFY_EXEMPT, true)) {
            continue;
        }

        $offenders[] = $primary;
    }

    return ['matched' => $matched, 'offenders' => $offenders];
}

it('every supabase.jwt route also runs RequireEmailVerified (or is explicitly exempt)', function () {
    $sweep = emailVerifySweep(Route::getRoutes());

    // COV-GUARD-5. 106 routes match today. If the `supabase.jwt` alias is
    // renamed or the three staff route groups are folded into a group alias,
    // this collapses to ~2 and `$offenders === []` becomes vacuously true.
    // NOTE: gatherMiddleware() does NOT expand groups, so the ~372 routes on
    // the `user.api` group are outside this sweep entirely — a separate gap,
    // not something this floor covers.
    SweepGuard::assertDenominator($sweep['matched'], 50, 'supabase.jwt routes');

    expect($sweep['offenders'])->toBe(
        [],
        "Routes using supabase.jwt without require.email_verified:\n  - "
            .implode("\n  - ", $sweep['offenders'])
            ."\nAdd 'require.email_verified' to the route group, or add the route to EMAIL_VERIFY_EXEMPT with a justification."
    );
});

// Positive control (COV-GUARD-5). The route is constructed, NOT registered, so
// it cannot leak into Route::getRoutes() and redden the real sweep above.
it('proves the guard can fail: a jwt route without require.email_verified IS flagged', function () {
    $probe = (new Illuminate\Routing\Route(['GET'], 'api/__guard-probe', fn () => null))
        ->middleware('supabase.jwt');

    $sweep = emailVerifySweep([$probe]);

    expect($sweep['matched'])->toBe(['GET api/__guard-probe'])
        ->and($sweep['offenders'])->toBe(['GET api/__guard-probe']);
});

// Positive control (COV-GUARD-5): the denominator half — zero matched routes
// must redden the floor, proving the guard itself can fail.
it('proves the denominator guard can fail: zero matched routes is rejected', function () {
    expect(fn () => SweepGuard::assertDenominator([], 50, 'probe'))
        ->toThrow(ExpectationFailedException::class);
});
