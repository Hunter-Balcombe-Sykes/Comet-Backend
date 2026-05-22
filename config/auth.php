<?php

// ============================================================
// Authentication — INERT under Supabase JWT auth.
// ============================================================
//
// This app does NOT use Laravel's session/Eloquent auth stack.
// `Auth::user()` ALWAYS returns null. Authentication is resolved
// by the VerifySupabaseJwt middleware, which validates the
// Supabase JWT and exposes the resolved actor as a request
// attribute — read it via `$request->attributes->get('professional')`.
//
// See app/Http/Middleware/Auth/VerifySupabaseJwt.php.
//
// The stanzas below are a minimal stub kept only so Laravel's
// AuthManager has valid structure to resolve. There is no
// `App\Models\User`, no session guard, and no password-reset
// flow — do not wire any.
// ============================================================

return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            // Points at the Partna user model. Auth::user() is always null in this
            // app (Supabase JWT auth) — this provider is a stub required by Laravel.
            'model' => App\Models\Core\Professional\User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,

];
