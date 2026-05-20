<?php

// F2 AUTH-1 — boot guard for SUPABASE_JWT_ISSUER / SUPABASE_JWT_AUD.
//
// Tested via source-structure assertion (architecture-test style) to match
// the sibling SupabaseJwksFailClosedBootGuardTest: invoking the full
// AppServiceProvider::boot() in isolation needs more container scaffolding
// than the test is worth, and the other env-presence guards in that file
// are verified the same way.

it('AppServiceProvider::boot() contains the JWT issuer/audience config guard', function () {
    $path = base_path('app/Providers/AppServiceProvider.php');
    expect(file_exists($path))->toBeTrue();

    $source = (string) file_get_contents($path);

    // Required components of the guard (all must be present together):
    //  1. Skips local + testing (so dev/CI aren't affected)
    //  2. Reads via config() not env() (config:cache respected)
    //  3. Throws RuntimeException naming both env vars
    expect($source)
        ->toContain("environment('local', 'testing')")
        ->toContain("config('supabase.jwt_issuer')")
        ->toContain("config('supabase.jwt_audience')")
        ->toContain('SUPABASE_JWT_ISSUER and SUPABASE_JWT_AUD must be configured');
});
