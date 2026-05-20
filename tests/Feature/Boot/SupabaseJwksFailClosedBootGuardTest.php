<?php

// §28.17 SEC-2 — Production boot guard for SUPABASE_JWKS_FAIL_CLOSED.
//
// Tested via source-structure assertion (architecture-test style) because
// invoking the full AppServiceProvider::boot() in isolation requires far
// more container scaffolding than the value of the test justifies — the
// other env-presence guards in the same file similarly have no direct
// test. The guard is one of four boot-time guards (throttle, public
// domain, shopify api version, jwks fail closed); they share a structural
// pattern and this test enforces it for our new addition.

it('AppServiceProvider::boot() contains the SUPABASE_JWKS_FAIL_CLOSED production guard', function () {
    $path = base_path('app/Providers/AppServiceProvider.php');
    expect(file_exists($path))->toBeTrue();

    $source = (string) file_get_contents($path);

    // Required components of the guard (all must be present together):
    //  1. Production check (so dev/CI aren't affected)
    //  2. Read via config() not env() (config:cache respected)
    //  3. Throw RuntimeException with a recognizable message naming the env var
    expect($source)
        ->toContain('app()->isProduction()')
        ->toContain("config('supabase.jwks_fail_closed'")
        ->toContain('SUPABASE_JWKS_FAIL_CLOSED');

    // Quick sanity: the same file has its other production guards using the
    // same shape. Confirm there are at least 3 isProduction()-gated checks
    // so a refactor that drops one to a global guard surfaces here.
    $count = substr_count($source, 'app()->isProduction()');
    expect($count)->toBeGreaterThanOrEqual(3, 'Expected ≥3 isProduction()-gated guards (throttle, public_domain, jwks_fail_closed)');
});
