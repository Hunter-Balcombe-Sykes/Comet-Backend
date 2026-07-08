<?php

return [
    'url' => env('SUPABASE_URL'),                       // e.g. https://abc123.supabase.co
    'anon_key' => env('SUPABASE_ANON_KEY'),

    // Issuer usually looks like: https://<project-ref>.supabase.co/auth/v1
    'jwt_issuer' => env('SUPABASE_JWT_ISSUER'),

    // Most user access tokens use aud = "authenticated"
    'jwt_audience' => env('SUPABASE_JWT_AUD', 'authenticated'),

    'jwks_url' => env('SUPABASE_JWKS_URL'),             // full URL
    'jwks_cache_seconds' => (int) env('SUPABASE_JWKS_CACHE_SECONDS', 300),

    // Clock-skew tolerance for JWT exp/nbf validation and the Http client timeout
    // used for both the JWKS fetch and the Auth-Server fallback. Defaults match
    // the previously hardcoded values — no env change required at deploy.
    'jwt_leeway_seconds' => (int) env('SUPABASE_JWT_LEEWAY_SECONDS', 60),
    'http_timeout_seconds' => (int) env('SUPABASE_HTTP_TIMEOUT_SECONDS', 5),

    // When true (the default), a JWKS outage returns 503 instead of falling
    // back to the Auth-Server. Set to false only for legacy shared-secret
    // projects that genuinely need the fallback; production refuses to boot
    // with this false (see AppServiceProvider::boot()).
    'jwks_fail_closed' => (bool) env('SUPABASE_JWKS_FAIL_CLOSED', true),

    // Service role key for server-side admin operations (user creation, etc.)
    'service_role_key' => env('SUPABASE_SERVICE_ROLE_KEY'),

    /*
    | Admin API base URL — typically <SUPABASE_URL>/auth/v1/admin. Split
    | as its own config so we can point staging at a different host if
    | needed (e.g. for hermetic tests).
    */
    'admin' => [
        'base_url' => env('SUPABASE_ADMIN_BASE_URL', rtrim((string) env('SUPABASE_URL'), '/').'/auth/v1/admin'),
    ],
];
