<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    // Google Maps / Places — used client-side by the Hydrogen storefront
    // for address autocomplete. Key is HTTP-referrer-restricted in
    // Google Cloud, so it's safe to expose via /public/config/integrations.
    'google_maps' => [
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    // Supabase Send Email Hook — secret used to verify the HMAC signature
    // on incoming Standard-Webhooks-format requests to
    // POST /internal/email-hooks/supabase. Set this from the Supabase
    // Dashboard → Authentication → Hooks → Send Email Hook page. Without it
    // the hook endpoint returns 503 (fail-closed).
    'supabase' => [
        'email_hook_secret' => env('SUPABASE_EMAIL_HOOK_SECRET'),
        // Public anon key — required as the `apikey` query param on links to
        // {site_url}/auth/v1/verify. Already used elsewhere in the app
        // (frontend bundle) so it's safe to embed in outbound email URLs.
        'anon_key' => env('SUPABASE_ANON_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Cloudflare DNS + KV — DNS provisions subdomains; KV holds the subdomain
    // routing table read by the Edge Worker to route brands vs affiliate redirects.
    'cloudflare' => [
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'kv_namespace_id' => env('CLOUDFLARE_KV_NAMESPACE_ID'),
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        // Scoped API token with only the "Zone.Cache Purge" permission on the
        // partna.au zone. Distinct from `api_token` so credential rotation /
        // blast-radius can be reasoned about per surface. Used by
        // CloudflarePurgeService (§28.7) — never elsewhere.
        'cache_purge_token' => env('CLOUDFLARE_CACHE_PURGE_TOKEN'),
    ],

    'twitch' => [
        'client_id' => env('TWITCH_CLIENT_ID'),
        'client_secret' => env('TWITCH_CLIENT_SECRET'),
    ],
    'kick' => [
        'client_id' => env('KICK_CLIENT_ID'),
        'client_secret' => env('KICK_CLIENT_SECRET'),
    ],

    // Apify — used by the test-mode Instagram platform integration to run the
    // instagram-profile-scraper actor. One token, server-side only.
    'apify' => [
        'token' => env('APIFY_TOKEN'),
    ],

];
