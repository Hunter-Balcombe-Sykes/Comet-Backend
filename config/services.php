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

    'square' => [
        'application_id' => env('SQUARE_APPLICATION_ID'),
        'client_secret' => env('SQUARE_CLIENT_SECRET'),
        'environment' => env('SQUARE_ENVIRONMENT', 'production'),
        'webhook_signature_key' => env('SQUARE_WEBHOOK_SIGNATURE_KEY'),
        'webhook_notification_url' => env('SQUARE_WEBHOOK_NOTIFICATION_URL'),
    ],

    'fresha' => [
        'client_id' => env('FRESHA_CLIENT_ID'),
        'client_secret' => env('FRESHA_CLIENT_SECRET'),
        'environment' => env('FRESHA_ENVIRONMENT', 'production'),
        'webhook_signature_key' => env('FRESHA_WEBHOOK_SIGNATURE_KEY'),
        'webhook_notification_url' => env('FRESHA_WEBHOOK_NOTIFICATION_URL'),
    ],

    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        // Three scoped webhook secrets — one per Stripe Event Destination. The new
        // Event Destinations system splits delivery by payload style, so the platform
        // endpoint is served by two destinations (snapshot for v1 events, thin for v2
        // account events) with separate signing secrets.
        //   - connect_webhook_secret:       Connect-scope v1 events (account.updated,
        //                                   account.application.deauthorized, checkout.session.completed,
        //                                   payment_method.attached|detached)
        //   - platform_webhook_secret:      Platform-scope v1 snapshot events
        //                                   (payment_intent.*, charge.refunded, charge.dispute.created)
        //                                   fired by destination charges
        //   - platform_thin_webhook_secret: Platform-scope v2 thin events
        //                                   (v2.core.account.* lifecycle on direct connected accounts)
        // Controllers select the right secret by endpoint route, not by trial validation.
        'connect_webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
        'platform_webhook_secret' => env('STRIPE_PLATFORM_WEBHOOK_SECRET'),
        'platform_thin_webhook_secret' => env('STRIPE_PLATFORM_THIN_WEBHOOK_SECRET'),
        // 2026-02-25.clover ships v2 Accounts GA. Required for v2.core.accounts.*
        // operations used by the destination-charge flow.
        'api_version' => env('STRIPE_API_VERSION', '2026-02-25.clover'),
    ],

    'hydrogen' => [
        'api_key' => env('HYDROGEN_API_KEY'),
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

    // Cloudflare Turnstile — bot-protection for public lead-capture endpoints.
    // Only required when PARTNA_CAPTCHA_ENABLED=true.
    'turnstile' => [
        'secret_key' => env('CLOUDFLARE_TURNSTILE_SECRET_KEY'),
    ],

    'twitch' => [
        'client_id' => env('TWITCH_CLIENT_ID'),
        'client_secret' => env('TWITCH_CLIENT_SECRET'),
    ],
    'kick' => [
        'client_id' => env('KICK_CLIENT_ID'),
        'client_secret' => env('KICK_CLIENT_SECRET'),
    ],

    'shopify' => [
        'api_key' => env('SHOPIFY_API_KEY'),
        'api_secret' => env('SHOPIFY_API_SECRET'),
        // Max times a single JTI may be used within the 120s replay-cache window.
        // Default 25: covers Remix SSR fan-out (root + route loaders each making
        // 1–3 backend calls with the same JWT) while blocking brute-force replay.
        // Override to 1 in tests to keep strict one-time-use assertions.
        'jti_max_uses' => (int) env('SHOPIFY_JTI_MAX_USES', 25),
        // 2026-04 (April 26) is the current stable Admin API release as of
        // May 2026. Bumped from 2025-01 alongside Partna-Shopify-App's
        // ApiVersion.April26 — the two MUST move together to keep the
        // EmbeddedSetupController validator (validateShopifyAccessToken) and
        // the Remix-side Admin API client on the same version.
        // 2026-07 is still RC until July 1 — do not pin RC versions.
        'api_version' => env('SHOPIFY_API_VERSION', '2026-04'),
        'app_scopes' => env('SHOPIFY_APP_SCOPES', ''),
        'webhook_secret' => env('SHOPIFY_WEBHOOK_SECRET', env('SHOPIFY_API_SECRET')),
        'fallback_secret' => env('SHOPIFY_FALLBACK_SECRET'),
        'webhook_orders_topic' => env('SHOPIFY_WEBHOOK_ORDERS_TOPIC', 'orders/paid'),
        // Must match `handle` in Sidest-Embedded/shopify.app.toml. Shopify's
        // admin routes apps under /store/<shop>/apps/<app_handle>, so a
        // mismatch 404s the post-install redirect. Override via env when
        // multiple app builds share this codebase.
        'app_handle' => env('SHOPIFY_APP_HANDLE', 'side-st-hydrogen'),

        // Admin API throttle client config. Shopify standard-plan GraphQL
        // bucket is 1000 points, restoring at 100 pts/sec. Plus is 2000/200.
        // We learn the actual values from throttleStatus on every response.
        'throttle' => [
            'default_max_capacity' => (int) env('SHOPIFY_THROTTLE_MAX', 1000),
            'default_restore_rate' => (int) env('SHOPIFY_THROTTLE_RESTORE_RATE', 100),
            'default_estimated_cost' => (int) env('SHOPIFY_THROTTLE_DEFAULT_COST', 10),
            'max_inprocess_retries' => (int) env('SHOPIFY_THROTTLE_MAX_RETRIES', 3),
            'max_wait_ms' => (int) env('SHOPIFY_THROTTLE_MAX_WAIT_MS', 5000),
            'default_timeout' => (int) env('SHOPIFY_HTTP_TIMEOUT', 20),
            'bucket_ttl_seconds' => 60,
            'bulk_lock_ttl_seconds' => 3600,
        ],
    ],

];
