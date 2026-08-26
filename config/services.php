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

    // Google Maps / Places — client-side key for the professional dashboard's
    // address autocomplete, returned by the AUTHENTICATED GET /api/config/integrations
    // (moved off the public/CDN route — audit public-surface/SEC-1). Defence-in-depth:
    // it MUST also be HTTP-referrer-restricted to *.partna.au/* in the Google Cloud
    // Console. Re-verify that restriction on every key rotation and fresh-environment
    // deploy — see .env.example.
    'google_maps' => [
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
        // Server-side key for google-business Place Details enrichment.
        // API-restricted to Places API (New) in the Cloud Console; lives only
        // in server env vars — NEVER returned by GET /api/config/integrations.
        'server_api_key' => env('GOOGLE_MAPS_SERVER_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
        // Svix signing secret for the Resend bounce/complaint webhook, verified
        // by VerifyResendWebhookSignature. Copy from Resend Dashboard → Webhooks →
        // (endpoint) → Signing Secret. Format: `whsec_<base64>`. Without it,
        // POST /internal/webhooks/resend returns 503 (fail-closed).
        'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
    ],

    // Supabase Send Email Hook — secret used to verify the HMAC signature
    // on incoming Standard-Webhooks-format requests to
    // POST /internal/email-hooks/supabase. Set this from the Supabase
    // Dashboard → Authentication → Hooks → Send Email Hook page. Without it
    // the hook endpoint returns 503 (fail-closed).
    'supabase' => [
        'email_hook_secret' => env('SUPABASE_EMAIL_HOOK_SECRET'),
        // Shared secret for Supabase Auth Hooks (Standard Webhooks signing).
        // Set in Supabase Dashboard → Authentication → Hooks alongside the
        // hook URL. Rotate via env var + dashboard update simultaneously.
        'auth_hook_secret' => env('SUPABASE_AUTH_HOOK_SECRET'),
        // Public anon key — required as the `apikey` query param on links to
        // {site_url}/auth/v1/verify. Already used elsewhere in the app
        // (frontend bundle) so it's safe to embed in outbound email URLs.
        'anon_key' => env('SUPABASE_ANON_KEY'),
    ],

    // ManyChat marketing automation → POST /api/internal/webhooks/manychat/builds.
    //
    // A STATIC shared secret, not an HMAC signature (spec §5.1): ManyChat's
    // External Request action can set headers but cannot sign a request body,
    // so the Standard Webhooks scheme used for Supabase/Resend is unavailable.
    // Weaker by construction — the control that bounds it is that a claim token
    // is only ever minted for a NEW build, or a retry carrying the same
    // idempotency_key (spec §5.4).
    //
    // Rotate by changing this env var and the ManyChat flow's header together.
    'manychat' => [
        'webhook_secret' => env('MANYCHAT_WEBHOOK_SECRET'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Cloudflare DNS + KV — DNS provisions subdomains; KV holds the subdomain
    // routing table read by the Edge Worker to route individual sites vs alias redirects.
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
        // Cloudflare for SaaS — custom hostnames for user-connected domains.
        // saas_api_token needs Zone:SSL and Certificates:Edit + Zone:Read on the
        // partna.au zone (falls back to api_token). saas_cname_target is the DNS
        // value users point their domain at; it must resolve to the SaaS fallback
        // origin running the subdomain-router Worker.
        'saas_api_token' => env('CLOUDFLARE_SAAS_API_TOKEN'),
        'saas_cname_target' => env('CLOUDFLARE_SAAS_CNAME_TARGET', 'cname.partna.au'),
    ],

    'twitch' => [
        'client_id' => env('TWITCH_CLIENT_ID'),
        'client_secret' => env('TWITCH_CLIENT_SECRET'),
        // Helix streams endpoint — override if Twitch migrates the URL or for local stubs.
        'streams_url' => env('TWITCH_STREAMS_URL', 'https://api.twitch.tv/helix/streams'),
        // CFG-1: OAuth Client Credentials token endpoint — was hardcoded in
        // StreamingTokenManager::PLATFORM_CONFIG; override if Twitch migrates the URL.
        'token_url' => env('TWITCH_TOKEN_URL', 'https://id.twitch.tv/oauth2/token'),
    ],
    'kick' => [
        'client_id' => env('KICK_CLIENT_ID'),
        'client_secret' => env('KICK_CLIENT_SECRET'),
        // Public channels endpoint — override if Kick changes their API path.
        'channels_url' => env('KICK_CHANNELS_URL', 'https://api.kick.com/public/v1/channels'),
        // CFG-1: OAuth token endpoint — was hardcoded in StreamingTokenManager::PLATFORM_CONFIG.
        'token_url' => env('KICK_TOKEN_URL', 'https://id.kick.com/oauth/token'),
    ],

    // Fresha's persisted-query hash + client version are pinned to a Fresha
    // frontend build and rotate when they redeploy. Override via env without a
    // code deploy; when they rotate, fetchEmployeeServices falls back to the
    // whole-location menu until these are updated.
    'fresha' => [
        'booking_init_hash' => env('FRESHA_BOOKING_INIT_HASH', '4ea9d1b31075d62f789fcec884c45d76aaeb42e56ffb1b78cc1b7f7c557ad7cb'),
        'client_version' => env('FRESHA_CLIENT_VERSION', 'd135e4b3a3be51f9dd24f5cc2af6dd6a647f85dd'),
    ],

    // Apify — used by the test-mode Instagram platform integration to run the
    // instagram-profile-scraper actor. One token, server-side only.
    'apify' => [
        'token' => env('APIFY_TOKEN'),
        // Shared by GoogleBusinessApifyScraper + GoogleMenuImagesScraper — one
        // source of truth so the two callers can't drift on the actor id.
        'actors' => [
            'google_places' => 'compass~crawler-google-places',
            'booksy' => 'crawlerbros~booksy-scraper',
            'opentable_resy' => 'crawlerbros~opentable-resy-scraper',
            'ra_events' => 'crawlerbros~resident-advisor-scraper',
        ],
    ],

    // Mistral — hosted OCR (menu photo/PDF → markdown text) for all menu
    // scans: the automatic Google-photos/website jobs and the manual upload
    // endpoint (POST /platforms/menu/scan). The ONLY place this key lives
    // since the dashboard's duplicate route was deleted (2026-08-26).
    'mistral' => [
        'key' => env('MISTRAL_API_KEY'),
    ],

    // DeepSeek — text structuring (OCR text → menu items) for the same menu
    // scan pipeline. Menu scans bill only through this backend now.
    'deepseek' => [
        'key' => env('DEEPSEEK_API_KEY'),
    ],

];
