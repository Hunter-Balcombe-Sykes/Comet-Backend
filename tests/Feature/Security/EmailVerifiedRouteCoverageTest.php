<?php

use App\Http\Middleware\Auth\RequireEmailVerified;
use App\Http\Middleware\Auth\VerifySupabaseJwt;
use Illuminate\Support\Facades\Route;

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

it('every supabase.jwt route also runs RequireEmailVerified (or is explicitly exempt)', function () {
    $offenders = [];

    foreach (Route::getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        $hasJwt = in_array(VerifySupabaseJwt::class, $middleware, true)
            || in_array('supabase.jwt', $middleware, true);

        if (! $hasJwt) {
            continue;
        }

        $hasGate = in_array(RequireEmailVerified::class, $middleware, true)
            || in_array('require.email_verified', $middleware, true);

        if ($hasGate) {
            continue;
        }

        $signature = strtoupper(implode('|', $route->methods())).' '.$route->uri();
        // Normalise to the first verb for readability (Laravel adds HEAD to GET).
        $primary = strtoupper($route->methods()[0]).' '.$route->uri();

        if (in_array($primary, EMAIL_VERIFY_EXEMPT, true)) {
            continue;
        }

        $offenders[] = $primary;
    }

    expect($offenders)->toBe(
        [],
        "Routes using supabase.jwt without require.email_verified:\n  - "
            .implode("\n  - ", $offenders)
            ."\nAdd 'require.email_verified' to the route group, or add the route to EMAIL_VERIFY_EXEMPT with a justification."
    );
});
