<?php

use App\Mail\Notifications\CriticalNotificationMail;
use App\Mail\Notifications\FeatureAnnouncementMail;
use App\Mail\Notifications\IncidentMail;
use App\Mail\Notifications\PolicyUpdateMail;
use App\Mail\Notifications\ProfileTaskMail;
use App\Services\Platforms\DoorDashMenuDriver;
use App\Services\Platforms\SquareMenuDriver;
use App\Services\Platforms\UberEatsMenuDriver;
use App\Services\PreAccount\Generators\GoogleBusinessSourceGenerator;
use App\Services\PreAccount\Generators\InstagramSourceGenerator;

// Canonical block-type registry — block_group => allowed block_types. Single
// source of truth for the section/link type split. The 'sections' list is
// cross-referenced to the DB CHECK `blocks_group_type_check`
// (supabase/migrations/20260701160000_blocks_group_type_pair_check.sql) and to
// the Block::GROUP_* / TYPE_* constants — keep all three in sync. The flat
// `section_block_types` key below is derived from this so the two never drift.
$blockTypes = [
    'links' => ['link'],
    'sections' => [
        'gallery', 'services', 'booking', 'contacts_collection',
        'barbershop_info', 'documents', 'newsletter', 'contact',
        'public_contact', 'workplace',
    ],
];

return [
    // Shared-secret token for GET /api/internal/env-check. Required to enable
    // the endpoint. When unset, the endpoint returns 503 — fail-closed by default
    // so a fresh deploy never accidentally exposes the env-var report.
    'internal_env_check_token' => env('INTERNAL_ENV_CHECK_TOKEN'),

    // Browser origins explicitly allowed to call the API via CORS. Comma-separated
    // list of `scheme://host[:port]` entries (no trailing slash, no path). Consumed by
    // config/cors.php for the HandleCors middleware AND by SecureHeaders::apply() for
    // the exception-render fallback path — keeping a single source of truth so the
    // two cannot drift. Wildcard subdomains (visitor mini-sites under *.partna.au,
    // Shopify admin hosts) are matched via `allowed_origins_patterns` in cors.php
    // rather than enumerated here.
    'frontend_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PARTNA_FRONTEND_ORIGINS', ''))
    ))),

    'handle' => [
        // Days during which only the original owner can reclaim a released handle for free.
        'reclaim_days' => (int) env('SIDEST_HANDLE_RECLAIM_DAYS', 14),

        // Total days an alias serves a 301 redirect. After this it is hard-deleted and the handle returns to the pool.
        'redirect_days' => (int) env('SIDEST_HANDLE_REDIRECT_DAYS', 90),

        // Minimum days between subdomain changes for self-serve users. Mirrored in
        // UserSelfController when computing subdomain_change_available_at for the /me payload.
        'subdomain_cooldown_days' => (int) env('SIDEST_HANDLE_SUBDOMAIN_COOLDOWN_DAYS', 30),

        // Years to retain handle_change_log rows. 7y matches typical fraud-investigation retention.
        'audit_retention_years' => (int) env('SIDEST_HANDLE_AUDIT_RETENTION_YEARS', 7),
    ],

    'audit' => [
        // B8 / models-data PRIV-2 + PRIV-3: years before the PII email/handle snapshots
        // on audit.user_deletion_audit and audit.data_export_audit are redacted in place
        // (the event row is kept). 7y aligns with the handle_change_log fraud window;
        // kept as its own key so the two policies can diverge later. Floor of 1y is
        // enforced by the audit:prune-pii-snapshots command.
        'pii_retention_years' => (int) env('PARTNA_AUDIT_PII_RETENTION_YEARS', 7),
    ],

    'public_domain' => env(
        'PARTNA_PUBLIC_DOMAIN',
        env(
            'SIDEST_PUBLIC_DOMAIN',
            parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'
        )
    ),
    // Handles/subdomains a user can never claim. Exact-match, case-insensitive.
    // Substring matching is deliberately avoided (Scunthorpe problem) — every
    // entry here must satisfy the DNS-safe regex used by the subdomain validator:
    // ^[a-z0-9]([a-z0-9-]*[a-z0-9])?$ (no dots, no underscores, no leading/trailing dash).
    // Grouped for readability; flattened into a single list at consumption.
    'reserved_subdomains' => [
        // --- Platform infrastructure / DNS ---
        'www', 'api', 'admin', 'app', 'apps', 'staff', 'dashboard',
        'support', 'help', 'helpdesk', 'billing', 'static', 'cdn', 'assets',
        'auth', 'docs', 'status', 'comet', 'sidest', 'partna',
        'mail', 'email', 'smtp', 'imap', 'pop', 'pop3', 'webmail',
        'ns', 'ns1', 'ns2', 'ns3', 'mx', 'dns', 'ftp', 'sftp', 'ssh', 'vpn',
        'proxy', 'gateway', 'server', 'host', 'cloud', 'edge', 'worker', 'workers',
        'kv', 'db', 'database', 'redis', 'cache', 'queue', 'jobs', 'cron',
        'webhook', 'webhooks', 'callback', 'callbacks', 'localhost', 'internal',
        'public', 'private', 'secure', 'security', 'ssl', 'tls',

        // --- Environments / build stages ---
        'dev', 'development', 'prod', 'production', 'staging', 'stage',
        'test', 'tests', 'testing', 'qa', 'uat', 'sandbox', 'preview',
        'beta', 'alpha', 'demo', 'local',

        // --- Auth / account routes ---
        'login', 'logout', 'signin', 'signup', 'signout', 'register',
        'account', 'accounts', 'settings', 'profile', 'profiles',
        'user', 'users', 'member', 'members', 'me', 'my', 'mine',
        'password', 'reset', 'forgot', 'verify', 'verification',
        'confirm', 'activate', 'activation', 'oauth', 'sso', 'saml', 'jwt',
        'token', 'tokens', 'key', 'keys', 'secret', 'secrets',
        'onboarding', 'install', 'setup', 'start',

        // --- Marketing / company pages ---
        'home', 'about', 'team', 'company', 'contact', 'careers',
        'hiring', 'press', 'media', 'news', 'blog', 'newsroom',
        'investors', 'enterprise', 'pricing', 'plans', 'features',
        'partner', 'partners', 'affiliate', 'affiliates',
        'referral', 'referrals', 'brand', 'brands', 'community',

        // --- Commerce / store ---
        'shop', 'store', 'stores', 'marketplace', 'cart', 'checkout',
        'order', 'orders', 'invoice', 'invoices', 'payment', 'payments',
        'refund', 'refunds', 'subscription', 'subscriptions',

        // --- Discovery / catalog ---
        'search', 'explore', 'discover', 'trending', 'popular', 'top',
        'new', 'latest', 'featured', 'browse', 'category', 'categories',
        'tag', 'tags', 'topic', 'topics', 'sitemap', 'robots', 'feed', 'rss',

        // --- Legal / trust ---
        'terms', 'tos', 'privacy', 'legal', 'dmca', 'copyright',
        'trademark', 'abuse', 'report', 'compliance', 'gdpr',

        // --- Developer / system ---
        'developer', 'developers', 'doc', 'documentation',
        'api-docs', 'graphql', 'rest', 'rpc', 'sdk', 'cli',
        'system', 'service', 'services', 'root', 'null', 'undefined',
        'true', 'false', 'nil', 'none', 'error', 'errors', 'config',

        // --- AU government / regulators / common impersonation targets ---
        'ato', 'asic', 'accc', 'acma', 'austrac', 'apra', 'rba',
        'medicare', 'mygov', 'centrelink', 'ndis', 'ahpra', 'fairwork',
        'servicesaustralia', 'gov', 'government', 'police', 'afp',
        'aec', 'abs', 'tga', 'dva', 'auspost',

        // --- Brand impersonation (high-risk lookalikes) ---
        'google', 'apple', 'microsoft', 'amazon', 'meta', 'facebook',
        'instagram', 'tiktok', 'twitter', 'youtube', 'linkedin',
        'paypal', 'stripe', 'square', 'shopify', 'cloudflare',
        'anthropic', 'claude', 'openai', 'chatgpt',

        // --- Profanity / slurs (exact-match only; substring would over-block) ---
        'fuck', 'fucker', 'fucking', 'motherfucker', 'shit', 'bullshit',
        'cunt', 'bitch', 'bastard', 'asshole', 'arsehole', 'dick',
        'cock', 'pussy', 'slut', 'whore', 'twat', 'wanker',
        'faggot', 'fag', 'nigger', 'nigga', 'retard', 'tranny',
        'kike', 'spic', 'chink', 'gook', 'wetback', 'raghead',
        'towelhead', 'dyke', 'shemale', 'porn', 'porno', 'xxx', 'nsfw',
    ],
    'link_block_icon_keys' => [
        // Functional / custom-link icons
        'scissors',
        'calendar',
        'map',
        'phone',
        'website',
        'link',
        'email',
        'whatsapp',
        // Social platform icons (mirrored in social_platforms registry below)
        'instagram',
        'facebook',
        'linkedin',
        'youtube',
        'tiktok',
        'x',
        'spotify',
        'soundcloud',
        'snapchat',
        'threads',
        'discord',
        'reddit',
        'telegram',
        // Booking platform icons
        'fresha',
        'booksy',
        'timely',
        'calendly',
        'square',
        // Education platform icons
        'stan',
        'skool',
        'kajabi',
        'circle',
        // Event platform icons
        'eventbrite',
        'humanitix',
        'luma',
        'partiful',
        'ticketmaster',
        // Content platform icons
        'apple_podcasts',
        'substack',
        'bandcamp',
        'patreon',
        'gumroad',
        'medium',
        'vimeo',
        // Streaming platform icons
        'twitch',
        'kick',
    ],
    'link_block_settings_keys' => [
        'open_in_new_tab',
        'rel_nofollow',
        'rel_sponsored',
        'rel_ugc',
        'highlight',
        'note',
        // platform/handle social-link tagging. platform + category + live_check_enabled
        // are now promoted columns on site.blocks (see 20260701000000_promote_block_settings_columns);
        // only handle remains in settings JSONB.
        'handle',
    ],

    /*
    |--------------------------------------------------------------------------
    | Link categories
    |--------------------------------------------------------------------------
    |
    | Fixed enum applied to every link block in site.blocks.settings.category.
    | One source of truth — imported by the Form Requests, the public registry
    | endpoint response, and the backfill command. Do not add values without
    | updating the frontend category picker and confirming the public mini-site
    | renderer handles the new value.
    */
    'link_categories' => ['social', 'booking', 'education', 'content', 'events', 'streaming', 'other'],

    /*
    |--------------------------------------------------------------------------
    | Platform-link cap
    |--------------------------------------------------------------------------
    |
    | Hard limit on how many link blocks a single professional can save in
    | the categories surfaced under the dashboard's "Platform Links"
    | container (Social / Content / Events / Streaming). Booking,
    | Education, and Other links live in their own containers and are
    | NOT counted against this cap. Frontend mirrors this constant so
    | the Add button greys out at the limit; backend enforces it on
    | StoreLinkBlockRequest as defence-in-depth.
    */
    'platform_links_max' => 7,
    'platform_links_categories' => ['social', 'content', 'events', 'streaming'],

    // Platforms that support automatic live status detection via the polling job.
    // Must match keys in social_platforms above.
    'streaming_platforms' => ['twitch', 'kick'],

    // Live-status polling tuning knobs. Keeps API call volume bounded.
    'streaming' => [
        // Hard cap on blocks with live_check_enabled=true per site — prevents a single
        // user from monopolizing the polling budget. Enforced in UpdateLinkBlockRequest.
        'max_live_check_per_site' => (int) env('PARTNA_STREAMING_MAX_LIVE_CHECK_PER_SITE', env('SIDEST_STREAMING_MAX_LIVE_CHECK_PER_SITE', 5)),

        // Cold-handle demotion TTLs (seconds). Handles offline for N consecutive reads
        // get a longer TTL, skipping most API budget on rarely-live handles.
        // LiveStatusPoller reads these at runtime so they can be tuned without redeploy.
        'live_ttl_seconds' => (int) env('PARTNA_STREAMING_LIVE_TTL', 180),
        'warm_offline_ttl' => (int) env('PARTNA_STREAMING_WARM_OFFLINE_TTL', 180),
        'cool_offline_ttl' => (int) env('PARTNA_STREAMING_COOL_OFFLINE_TTL', 600),
        'cold_offline_ttl' => (int) env('PARTNA_STREAMING_COLD_OFFLINE_TTL', 1800),
        'ttl_skip_threshold' => (int) env('PARTNA_STREAMING_TTL_SKIP_THRESHOLD', 60),
        // How long (seconds) the Kick rate-limit circuit breaker stays set after a 429.
        // Subsequent polling cycles skip Kick until this key expires.
        'kick_rate_limited_ttl' => (int) env('PARTNA_STREAMING_KICK_RATE_LIMITED_TTL', 300),
    ],

    'limits' => [
        // Per-platform pilot cost controls for paid/external scrapers — tunable
        // via env without a code deploy.
        'platforms' => [
            'instagram' => [
                // Per-user re-scrape cooldown (seconds) and the global daily run cap
                // for the paid Apify scraper. See InstagramController::guardApifyBudget.
                'apify_cooldown_seconds' => (int) env('PARTNA_INSTAGRAM_APIFY_COOLDOWN_SECONDS', 600),
                'apify_daily_cap' => (int) env('PARTNA_INSTAGRAM_APIFY_DAILY_CAP', 200),
            ],
        ],

        // Shared Apify cost ceiling (SCALE-2). Every paid actor (instagram, menu,
        // google-business) claims a slot from ApifyBudget before spending: a
        // per-actor daily cap PLUS a global daily cap so one integration's runaway
        // can't exhaust the account and starve the others.
        'apify' => [
            'global_daily_cap' => (int) env('PARTNA_APIFY_GLOBAL_DAILY_CAP', 1000),
            'actors' => [
                // Instagram reuses its existing tuned env var (behaviour preserved).
                'instagram' => (int) env('PARTNA_INSTAGRAM_APIFY_DAILY_CAP', 200),
                'menu' => (int) env('PARTNA_MENU_APIFY_DAILY_CAP', 300),
                'google-business' => (int) env('PARTNA_GB_APIFY_DAILY_CAP', 300),
            ],
        ],

        // AI menu-structuring spend (Mistral OCR + DeepSeek structuring, via
        // MenuAiExtractor) — same two-cap pattern as 'apify' above (per-vendor-call
        // daily cap + a global daily cap), but a separate budget/namespace since
        // these are a different vendor family entirely. Added 2026-07-23: this
        // spend previously had NO budget ceiling at all across its three callers
        // (WebsiteMenuPdfScanJob, GoogleMenuPhotoScanJob, WebsiteMenuHtmlScanJob).
        'ai_spend' => [
            'global_daily_cap' => (int) env('PARTNA_AI_SPEND_GLOBAL_DAILY_CAP', 500),
            'actors' => [
                'mistral_ocr' => (int) env('PARTNA_MISTRAL_OCR_DAILY_CAP', 300),
                'deepseek_structure' => (int) env('PARTNA_DEEPSEEK_STRUCTURE_DAILY_CAP', 300),
            ],
        ],

        // Google Places (New) spend ceiling (RV-6). The ONLY paid API in the system
        // with no ceiling until now — and Google's own budgets are alerts, not caps.
        // Three dimensions, all enforced atomically per BILLED REQUEST (not per
        // logical fetch — one fetchPlaceDetails() issues up to 16):
        //   - per-SKU daily cap   (details is the Enterprise+Atmosphere tier; photos is cheaper)
        //   - global daily cap    (binds first on a mixed storm)
        //   - per-USER daily cap  (improves on apify/ai_spend: one account cannot drain the platform)
        'places' => [
            'global_daily_cap' => (int) env('PARTNA_PLACES_GLOBAL_DAILY_CAP', 500),
            'per_user_daily_cap' => (int) env('PARTNA_PLACES_USER_DAILY_CAP', 60),
            'skus' => [
                'details' => (int) env('PARTNA_PLACES_DETAILS_DAILY_CAP', 200),
                'photos' => (int) env('PARTNA_PLACES_PHOTOS_DAILY_CAP', 400),
            ],
        ],

        // CFG-3 (user-api audit): dashboard list endpoint pagination / query-limit
        // defaults, previously hardcoded literals scattered across four
        // controllers (UserEnquiryController, NotificationController,
        // UserServiceCategoryController, UserServiceController). Values are
        // today's literals unchanged — filling in the same "tunable without a
        // redeploy" gap this block already covers for the Apify caps above.
        'pagination' => [
            'enquiries_per_page' => (int) env('PARTNA_LIMITS_ENQUIRIES_PER_PAGE', 20),
            'notifications_limit_default' => (int) env('PARTNA_LIMITS_NOTIFICATIONS_LIMIT_DEFAULT', 50),
            'notifications_limit_max' => (int) env('PARTNA_LIMITS_NOTIFICATIONS_LIMIT_MAX', 200),
            // B18/API-4: raw ->limit() caps, not true pagination — see the
            // controllers' inline comments.
            'service_categories_max' => (int) env('PARTNA_LIMITS_SERVICE_CATEGORIES_MAX', 200),
            'services_max' => (int) env('PARTNA_LIMITS_SERVICES_MAX', 500),
        ],
    ],

    // Apify actor id for the Instagram profile scrape (InstagramScraper::fetchProfile).
    // Tilde-separated owner~name form for the API path — configurable so the actor
    // can be swapped without a code deploy.
    'instagram' => [
        'actor' => env('PARTNA_INSTAGRAM_ACTOR', 'figue~instagram-profile-scraper'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Social platform registry
    |--------------------------------------------------------------------------
    |
    | Single source of truth for the social platforms surfaced in the link block
    | UI. Each entry tells the system how to validate, normalize, and render
    | links for one platform. The frontend reads a sanitised version of this
    | registry via GET /api/public/config/social-platforms and uses it to drive
    | the platform picker, input affordance, and display labels.
    |
    | Adding a new platform = one entry here + one icon_key above. No frontend
    | deploy needed — clients pick it up on next bootstrap.
    |
    | Security notes:
    | - All `handle_pattern` regexes are ASCII-only ([a-zA-Z0-9...]) to prevent
    |   Cyrillic/Greek homoglyph impersonation attacks.
    | - All `url_template` values are https:// — even http:// inputs get upgraded
    |   to https when the canonical URL is rebuilt.
    | - All quantifiers are bounded ({1,30} etc.) and non-nested → ReDoS-safe.
    | - `host_allowlist` is plain ASCII; punycoded IDN hosts won't match, which
    |   blocks a class of phishing attacks where a lookalike domain is registered.
    | - `handle_pattern`, `host_allowlist`, and `url_path_extractor` are stripped
    |   from the public registry response — they are server-side only.
    |
    | See docs/social-links.md for the full conceptual model.
    */
    'social_platforms' => [
        'instagram' => [
            'display_name' => 'Instagram',
            'icon_key' => 'instagram',
            'placeholder' => '@yourname',
            'handle_pattern' => '/^[a-zA-Z0-9._]{1,30}$/',
            'url_template' => 'https://instagram.com/{handle}',
            'host_allowlist' => ['instagram.com', 'www.instagram.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9._]{1,30})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        'facebook' => [
            'display_name' => 'Facebook',
            'icon_key' => 'facebook',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9.]{5,50}$/',
            'url_template' => 'https://facebook.com/{handle}',
            'host_allowlist' => ['facebook.com', 'www.facebook.com', 'fb.com', 'm.facebook.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9.]{5,50})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        'linkedin' => [
            'display_name' => 'LinkedIn',
            'icon_key' => 'linkedin',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,100}$/',
            'url_template' => 'https://linkedin.com/in/{handle}',
            'host_allowlist' => ['linkedin.com', 'www.linkedin.com'],
            // Matches both /in/{handle} (personal) and /company/{handle} (company pages)
            'url_path_extractor' => '#^/(?:in|company)/([a-zA-Z0-9-]{3,100})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        'youtube' => [
            'display_name' => 'YouTube',
            'icon_key' => 'youtube',
            'placeholder' => '@yourname',
            'handle_pattern' => '/^[a-zA-Z0-9._-]{3,30}$/',
            'url_template' => 'https://youtube.com/@{handle}',
            'host_allowlist' => ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'],
            'url_path_extractor' => '#^/@([a-zA-Z0-9._-]{3,30})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        'tiktok' => [
            'display_name' => 'TikTok',
            'icon_key' => 'tiktok',
            'placeholder' => '@yourname',
            'handle_pattern' => '/^[a-zA-Z0-9._]{2,24}$/',
            'url_template' => 'https://tiktok.com/@{handle}',
            'host_allowlist' => ['tiktok.com', 'www.tiktok.com', 'vm.tiktok.com'],
            'url_path_extractor' => '#^/@([a-zA-Z0-9._]{2,24})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        'x' => [
            'display_name' => 'X',
            'icon_key' => 'x',
            'placeholder' => '@yourname',
            // X handles are limited to 15 chars (Twitter legacy constraint)
            'handle_pattern' => '/^[a-zA-Z0-9_]{1,15}$/',
            'url_template' => 'https://x.com/{handle}',
            'host_allowlist' => ['x.com', 'www.x.com', 'twitter.com', 'www.twitter.com', 'mobile.twitter.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9_]{1,15})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        'spotify' => [
            'display_name' => 'Spotify',
            'icon_key' => 'spotify',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9._-]{3,40}$/',
            'url_template' => 'https://open.spotify.com/user/{handle}',
            'host_allowlist' => ['open.spotify.com', 'spotify.com'],
            // Matches /user/{handle} (profiles) and /artist/{id} (artist pages)
            'url_path_extractor' => '#^/(?:user|artist)/([a-zA-Z0-9._-]{3,40})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        'soundcloud' => [
            'display_name' => 'SoundCloud',
            'icon_key' => 'soundcloud',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9_-]{3,40}$/',
            'url_template' => 'https://soundcloud.com/{handle}',
            'host_allowlist' => ['soundcloud.com', 'www.soundcloud.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9_-]{3,40})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        'snapchat' => [
            'display_name' => 'Snapchat',
            'icon_key' => 'snapchat',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9._-]{3,15}$/',
            'url_template' => 'https://snapchat.com/add/{handle}',
            'host_allowlist' => ['snapchat.com', 'www.snapchat.com'],
            'url_path_extractor' => '#^/add/([a-zA-Z0-9._-]{3,15})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        'threads' => [
            'display_name' => 'Threads',
            'icon_key' => 'threads',
            'placeholder' => '@yourname',
            'handle_pattern' => '/^[a-zA-Z0-9._]{1,30}$/',
            'url_template' => 'https://threads.net/@{handle}',
            'host_allowlist' => ['threads.net', 'www.threads.net'],
            'url_path_extractor' => '#^/@([a-zA-Z0-9._]{1,30})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        'discord' => [
            'display_name' => 'Discord',
            'icon_key' => 'discord',
            'placeholder' => 'invite code',
            'handle_pattern' => '/^[a-zA-Z0-9-]{2,32}$/',
            'url_template' => 'https://discord.gg/{handle}',
            'host_allowlist' => ['discord.gg', 'discord.com', 'www.discord.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9-]{2,32})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        'reddit' => [
            'display_name' => 'Reddit',
            'icon_key' => 'reddit',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9_-]{3,20}$/',
            'url_template' => 'https://reddit.com/u/{handle}',
            'host_allowlist' => ['reddit.com', 'www.reddit.com'],
            'url_path_extractor' => '#^/u/([a-zA-Z0-9_-]{3,20})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        'telegram' => [
            'display_name' => 'Telegram',
            'icon_key' => 'telegram',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9_]{5,32}$/',
            'url_template' => 'https://t.me/{handle}',
            'host_allowlist' => ['t.me', 'telegram.me', 'telegram.org'],
            'url_path_extractor' => '#^/([a-zA-Z0-9_]{5,32})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        'whatsapp' => [
            'display_name' => 'WhatsApp',
            'icon_key' => 'whatsapp',
            'placeholder' => '+1234567890',
            'handle_pattern' => '/^\+?[0-9]{7,15}$/',
            'url_template' => 'https://wa.me/{handle}',
            'host_allowlist' => ['wa.me', 'api.whatsapp.com', 'whatsapp.com'],
            'url_path_extractor' => '#^/(\+?[0-9]{7,15})/?$#',
            'default_category' => 'social',
            'handle_location' => 'path',
        ],
        // --- Booking platforms (default_category: booking) ---
        'fresha' => [
            'display_name' => 'Fresha',
            'icon_key' => 'fresha',
            'placeholder' => 'your-business-slug',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,80}$/',
            'url_template' => 'https://fresha.com/a/{handle}',
            'host_allowlist' => ['fresha.com', 'www.fresha.com'],
            'url_path_extractor' => '#^/a/([a-zA-Z0-9-]{3,80})/?$#',
            'default_category' => 'booking',
            'handle_location' => 'path',
        ],
        'booksy' => [
            'display_name' => 'Booksy',
            'icon_key' => 'booksy',
            'placeholder' => 'your-business-slug',
            'handle_pattern' => '/^[a-zA-Z0-9_-]{3,80}$/',
            'url_template' => 'https://booksy.com/en-us/{handle}',
            'host_allowlist' => ['booksy.com', 'www.booksy.com'],
            // Booksy URLs include a locale prefix (e.g. /en-us/12345_salon-name)
            'url_path_extractor' => '#^/[a-z]{2}-[a-z]{2}/([a-zA-Z0-9_-]{3,80})/?$#',
            'default_category' => 'booking',
            'handle_location' => 'path',
        ],
        'timely' => [
            'display_name' => 'Timely',
            'icon_key' => 'timely',
            'placeholder' => 'your-business-slug',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,80}$/',
            'url_template' => 'https://book.gettimely.com/book/{handle}',
            'host_allowlist' => ['gettimely.com', 'book.gettimely.com', 'www.gettimely.com'],
            'url_path_extractor' => '#^/book/([a-zA-Z0-9-]{3,80})/?$#',
            'default_category' => 'booking',
            'handle_location' => 'path',
        ],
        'calendly' => [
            'display_name' => 'Calendly',
            'icon_key' => 'calendly',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9-]{2,40}$/',
            'url_template' => 'https://calendly.com/{handle}',
            'host_allowlist' => ['calendly.com', 'www.calendly.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9-]{2,40})/?$#',
            'default_category' => 'booking',
            'handle_location' => 'path',
        ],
        'square' => [
            'display_name' => 'Square',
            'icon_key' => 'square',
            'placeholder' => 'your-business-slug',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,80}$/',
            'url_template' => 'https://book.squareup.com/appointments/{handle}',
            'host_allowlist' => ['book.squareup.com', 'squareup.com', 'www.squareup.com'],
            'url_path_extractor' => '#^/appointments/([a-zA-Z0-9-]{3,80})/?$#',
            'default_category' => 'booking',
            'handle_location' => 'path',
        ],

        // --- Education platforms — path mode (default_category: education) ---
        'stan' => [
            'display_name' => 'Stan',
            'icon_key' => 'stan',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9_-]{2,40}$/',
            'url_template' => 'https://stan.store/{handle}',
            'host_allowlist' => ['stan.store', 'www.stan.store'],
            'url_path_extractor' => '#^/([a-zA-Z0-9_-]{2,40})/?$#',
            'default_category' => 'education',
            'handle_location' => 'path',
        ],
        'skool' => [
            'display_name' => 'Skool',
            'icon_key' => 'skool',
            'placeholder' => 'community-slug',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,60}$/',
            'url_template' => 'https://skool.com/{handle}',
            'host_allowlist' => ['skool.com', 'www.skool.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9-]{3,60})/?$#',
            'default_category' => 'education',
            'handle_location' => 'path',
        ],

        // --- Education platforms — subdomain mode (default_category: education) ---
        // Handle lives in the subdomain: {handle}.mykajabi.com / {handle}.circle.so
        // host_allowlist[0] = base domain; labelled-suffix match in normalizer.
        // Note: url_path_extractor is present for schema consistency but unused in subdomain mode.
        'kajabi' => [
            'display_name' => 'Kajabi',
            'icon_key' => 'kajabi',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,63}$/',
            'url_template' => 'https://{handle}.mykajabi.com/',
            'host_allowlist' => ['mykajabi.com'],
            'url_path_extractor' => '#^/?$#',
            'default_category' => 'education',
            'handle_location' => 'subdomain',
        ],
        'circle' => [
            'display_name' => 'Circle',
            'icon_key' => 'circle',
            'placeholder' => 'community-name',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,63}$/',
            'url_template' => 'https://{handle}.circle.so/',
            'host_allowlist' => ['circle.so'],
            'url_path_extractor' => '#^/?$#',
            'default_category' => 'education',
            'handle_location' => 'subdomain',
        ],

        // --- Event platforms (default_category: events) ---
        // Most event URLs are event-specific (/e/abc-123), not profile URLs.
        // The url_path_extractor targets the "organizer profile" shape; deep
        // links fall through to the lenient URL fallback — see docs/social-links.md §5.2.
        'eventbrite' => [
            'display_name' => 'Eventbrite',
            'icon_key' => 'eventbrite',
            'placeholder' => 'organizer-slug',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,80}$/',
            'url_template' => 'https://eventbrite.com/o/{handle}',
            'host_allowlist' => ['eventbrite.com', 'www.eventbrite.com'],
            'url_path_extractor' => '#^/o/([a-zA-Z0-9-]{3,80})/?$#',
            'default_category' => 'events',
            'handle_location' => 'path',
        ],
        'humanitix' => [
            'display_name' => 'Humanitix',
            'icon_key' => 'humanitix',
            'placeholder' => 'organizer-slug',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,80}$/',
            'url_template' => 'https://humanitix.com/host/{handle}',
            'host_allowlist' => ['humanitix.com', 'www.humanitix.com', 'events.humanitix.com'],
            'url_path_extractor' => '#^/host/([a-zA-Z0-9-]{3,80})/?$#',
            'default_category' => 'events',
            'handle_location' => 'path',
        ],
        'luma' => [
            'display_name' => 'Luma',
            'icon_key' => 'luma',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9-]{2,40}$/',
            'url_template' => 'https://lu.ma/{handle}',
            'host_allowlist' => ['lu.ma', 'www.lu.ma'],
            'url_path_extractor' => '#^/([a-zA-Z0-9-]{2,40})/?$#',
            'default_category' => 'events',
            'handle_location' => 'path',
        ],
        'partiful' => [
            'display_name' => 'Partiful',
            'icon_key' => 'partiful',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,40}$/',
            'url_template' => 'https://partiful.com/u/{handle}',
            'host_allowlist' => ['partiful.com', 'www.partiful.com'],
            'url_path_extractor' => '#^/u/([a-zA-Z0-9-]{3,40})/?$#',
            'default_category' => 'events',
            'handle_location' => 'path',
        ],
        'ticketmaster' => [
            'display_name' => 'Ticketmaster',
            'icon_key' => 'ticketmaster',
            'placeholder' => 'your-page-slug',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,80}$/',
            'url_template' => 'https://ticketmaster.com/{handle}',
            'host_allowlist' => ['ticketmaster.com', 'www.ticketmaster.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9-]{3,80})/?$#',
            'default_category' => 'events',
            'handle_location' => 'path',
        ],
        // --- Content platforms — path mode (default_category: content) ---
        // Apple Podcasts URLs always have the numeric ID as the stable identifier:
        //   https://podcasts.apple.com/us/podcast/{slug}/id{numeric-id}
        // The extractor captures the numeric id; most users will paste the full
        // URL, so the lenient fallback does most of the real work here.
        'apple_podcasts' => [
            'display_name' => 'Apple Podcasts',
            'icon_key' => 'apple_podcasts',
            'placeholder' => 'Paste the show URL',
            'handle_pattern' => '/^\d{5,15}$/',
            'url_template' => 'https://podcasts.apple.com/us/podcast/id{handle}',
            'host_allowlist' => ['podcasts.apple.com'],
            'url_path_extractor' => '#^/[a-z]{2}/podcast/[a-zA-Z0-9-]{1,120}/id(\d{5,15})/?$#',
            'default_category' => 'content',
            'handle_location' => 'path',
        ],

        // --- Content platforms — subdomain mode (default_category: content) ---
        // Note: url_path_extractor is present for schema consistency but unused in subdomain mode.
        'substack' => [
            'display_name' => 'Substack',
            'icon_key' => 'substack',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,63}$/',
            'url_template' => 'https://{handle}.substack.com/',
            'host_allowlist' => ['substack.com'],
            'url_path_extractor' => '#^/?$#',
            'default_category' => 'content',
            'handle_location' => 'subdomain',
        ],
        'bandcamp' => [
            'display_name' => 'Bandcamp',
            'icon_key' => 'bandcamp',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9-]{3,63}$/',
            'url_template' => 'https://{handle}.bandcamp.com/',
            'host_allowlist' => ['bandcamp.com'],
            'url_path_extractor' => '#^/?$#',
            'default_category' => 'content',
            'handle_location' => 'subdomain',
        ],
        'patreon' => [
            'display_name' => 'Patreon',
            'icon_key' => 'patreon',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9_-]{3,40}$/',
            'url_template' => 'https://patreon.com/{handle}',
            'host_allowlist' => ['patreon.com', 'www.patreon.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9_-]{3,40})/?$#',
            'default_category' => 'content',
            'handle_location' => 'path',
        ],
        'gumroad' => [
            'display_name' => 'Gumroad',
            'icon_key' => 'gumroad',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9_-]{3,40}$/',
            'url_template' => 'https://gumroad.com/{handle}',
            'host_allowlist' => ['gumroad.com', 'www.gumroad.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9_-]{3,40})/?$#',
            'default_category' => 'content',
            'handle_location' => 'path',
        ],
        'medium' => [
            'display_name' => 'Medium',
            'icon_key' => 'medium',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9_-]{3,40}$/',
            'url_template' => 'https://medium.com/@{handle}',
            'host_allowlist' => ['medium.com', 'www.medium.com'],
            'url_path_extractor' => '#^/@([a-zA-Z0-9_-]{3,40})/?$#',
            'default_category' => 'content',
            'handle_location' => 'path',
        ],
        'vimeo' => [
            'display_name' => 'Vimeo',
            'icon_key' => 'vimeo',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9_-]{3,40}$/',
            'url_template' => 'https://vimeo.com/{handle}',
            'host_allowlist' => ['vimeo.com', 'www.vimeo.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9_-]{3,40})/?$#',
            'default_category' => 'content',
            'handle_location' => 'path',
        ],

        // --- Streaming platforms ---
        'twitch' => [
            'display_name' => 'Twitch',
            'icon_key' => 'twitch',
            'placeholder' => 'your channel name',
            'handle_pattern' => '/^[a-zA-Z0-9_]{4,25}$/',
            'url_template' => 'https://twitch.tv/{handle}',
            'host_allowlist' => ['twitch.tv', 'www.twitch.tv'],
            'url_path_extractor' => '#^/([a-zA-Z0-9_]{4,25})/?$#',
            'handle_location' => 'path',
            'default_category' => 'streaming',
        ],
        'kick' => [
            'display_name' => 'Kick',
            'icon_key' => 'kick',
            'placeholder' => 'your channel name',
            'handle_pattern' => '/^[a-zA-Z0-9_-]{3,25}$/',
            'url_template' => 'https://kick.com/{handle}',
            'host_allowlist' => ['kick.com', 'www.kick.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9_-]{3,25})/?$#',
            'handle_location' => 'path',
            'default_category' => 'streaming',
        ],
    ],

    // Menu-scraping platform registry (FOUND-23). ONE entry per online-ordering
    // platform whose menu we scrape. Key ORDER is content/merge priority (Uber Eats
    // wins display-field ties and is the preferred spine). Adding a platform = one
    // entry here + one MenuPlatformDriver class — MenuSource, MenuMerger,
    // MenuApifyScraper and MenuFetchJob all read this list, none hardcode a slug.
    // config('partna.menu.platforms') is now the SINGLE source of truth for valid
    // menu platforms — the DB CHECK constraints that used to hardcode this list were
    // dropped (20260704170000); app-layer validation via this registry replaces them.
    'menu' => [
        'platforms' => [
            // Square first — top priority for pricing/images over Uber Eats/DoorDash.
            'square' => [
                'actor' => 'menus-r-us~restaurant-menu-scraper',
                // Matched against a bare host (MenuSource::platformOf passes
                // parse_url PHP_URL_HOST), so anchor on $ -- a trailing slash
                // both closed the ~ delimiter early, making the rest of the
                // pattern parse as modifiers, and could never match a host.
                'host_pattern' => '~(^|\.)square\.site$|(^|\.)square\.com$|^order\.~',
                'driver' => SquareMenuDriver::class,
            ],
            'uber-eats' => [
                'actor' => 'memo23~uber-eats-scraper',
                'host_pattern' => '~(^|\.)ubereats\.com$~',
                'driver' => UberEatsMenuDriver::class,
            ],
            'doordash' => [
                'actor' => 'dz_omar~doordash-scraper',
                'host_pattern' => '~(^|\.)doordash\.com$~',
                'driver' => DoorDashMenuDriver::class,
            ],
        ],

        // R4-RES-1: how long a failed menu scrape target is suppressed before it may
        // be re-billed. Kept BELOW the menu:retry-unavailable cadence (15 min) so the
        // scheduled self-heal always sees a clear key on its next tick.
        'blocked_ttl_seconds' => (int) env('PARTNA_MENU_BLOCKED_TTL_SECONDS', 600),
    ],

    // Unified actions system (2026-07-23 rebuild — fixed 26-action vocabulary,
    // demand-rate scoring). See App\Services\PublicSite\Actions\ActionVocabulary
    // + App\Services\Analytics\RankedActionsComputer.
    'actions' => [
        // Bayesian smoothing constant: rate = (taps + k·prior) / (exposures + k).
        // k=25 ≈ "the first ~25 real sessions outvote the editorial prior."
        'prior_k' => (int) env('PARTNA_ACTIONS_PRIOR_K', 25),
        'default_prior' => 0.05,
        // Plausible click-through-rate per action — the "average viewer intent"
        // seed. Product knob; tune from real data post-launch. Family entries
        // ('ordering', 'custom') apply to every dynamic member of that family.
        'priors' => [
            'reservations' => 0.30,
            'booking-services' => 0.28,
            'menu' => 0.28,
            'ordering' => 0.25,
            'shop' => 0.15,
            'events' => 0.14,
            'contact' => 0.12,
            'spotify' => 0.08,
            'soundcloud' => 0.08,
            'apple-music' => 0.08,
            'apple-podcasts' => 0.08,
            'twitch' => 0.08,
            'shop-tracks' => 0.08,
            'instagram' => 0.05,
            'facebook' => 0.05,
            'linkedin' => 0.05,
            'youtube' => 0.05,
            'tiktok' => 0.05,
            'x' => 0.05,
            'snapchat' => 0.05,
            'threads' => 0.05,
            'discord' => 0.05,
            'reddit' => 0.05,
            'telegram' => 0.05,
            'custom' => 0.05,
        ],
    ],

    // `contact` = visitor-submitted contact form (notification_email lives here).
    // `public_contact` = the professional's own opt-in contact details surfaced
    //                    publicly on the sitepage — distinct domain, distinct toggle.
    // `workplace`      = the professional's business / workplace card backed by
    //                    `sites.settings.google_business_profile` (Google Places-fed).
    // Canonical group → types map. Cross-referenced to the DB CHECK blocks_group_type_check
    // and to Block::GROUP_* / TYPE_* constants — keep all three in sync.
    'block_types' => $blockTypes,

    // Flat alias of block_types['sections'] — many consumers + tests read this and
    // override it via Config::set(). Derived so it can never drift from block_types.
    'section_block_types' => $blockTypes['sections'],

    // Platform-default subject dropdown options for the contact section block.
    // Merged with the professional's settings.subject_options at render and
    // submission-validation time. Professionals can extend but not remove in v1.
    'contact_subject_defaults' => [
        'General enquiry',
        'Booking',
        'Press',
        'Collaboration',
        'Other',
    ],

    'waitlist' => [
        // Signup kill-switch, NOT a waitlist capture path (retired 2026-07-19).
        // Read by PreAccountBuildController (403 WAITLIST_ONLY) and
        // PublicSignupAvailabilityController (waitlist_only flag).
        'enabled' => (bool) env('PARTNA_WAITLIST_ENABLED', env('SIDEST_WAITLIST_ENABLED', false)),
    ],

    'early_access' => [
        // PRIV-8: hard-delete non-converting applicant rows older than this window.
        // signed_up rows are excluded — those are governed by account deletion.
        'retention_days' => (int) env('PARTNA_EARLY_ACCESS_RETENTION_DAYS', 730),

        // CFG-1-style batch size for early-access:prune-old-signups — bounds each
        // DELETE's row count so the purge never holds one long-running transaction.
        'prune_batch_size' => (int) env('PARTNA_EARLY_ACCESS_PRUNE_BATCH_SIZE', 1000),
    ],

    // Ingest runtime (plan §4).
    'ingest' => [
        // Freshness window for billed-effect digests (EffectLedger/C6). Within
        // one window, retries and sibling streams of the same request dedupe
        // and replay the stored result; the next window mints a new digest so
        // a recurring billed fetch re-bills deliberately. 7 days sits at the
        // weekly cadence of the menu actors — the fastest recurring billed
        // sources; GBP's monthly cadence just re-bills once per window it
        // actually runs in.
        'effect_freshness_seconds' => (int) env('PARTNA_INGEST_EFFECT_FRESHNESS_SECONDS', 604800),

        // Lander::land() chunk size (SCALE-4). Bind-count arithmetic per chunk
        // (Postgres limit 65,535/statement): record_versions insert = 7 cols
        // -> 500x7 = 3,500; record_state upsert = 8 cols -> 4,000; the
        // `key IN` + `doc_hash IN` lists on the resolving SELECT -> ~1,000.
        // 500 leaves an order of magnitude of headroom. Also bounds memory:
        // $records is already resident (RunExecutor::drain() materialises it
        // before land() is called), so the chunk only adds one json_encode
        // string + hash per record, released between chunks.
        'land_chunk' => (int) env('PARTNA_INGEST_LAND_CHUNK', 500),

        // SCALE-1/SCALE-2: chunkById page size for `ingest:project`'s source
        // walk. Bounds both the source-list result buffer and the per-chunk
        // streams pre-fetch. Overridable so tests can shrink it (3) to make
        // chunk-boundary cases cheap to seed.
        'projection_source_chunk' => (int) env('INGEST_PROJECTION_SOURCE_CHUNK', 200),

        // #PRIV-3: grace window before an ORPHANED reviewer-PII row is deleted.
        // "Orphaned" = no live content.source_items row still carries the
        // (item_id, source_id) pair, i.e. the review is gone from the platform.
        // 14 days is slack for a transient projection failure (a run that fetched
        // zero records would otherwise retire every source_item and erase real
        // reviews that come back on the next successful run).
        'review_pii_orphan_grace_days' => (int) env('PARTNA_REVIEW_PII_ORPHAN_GRACE_DAYS', 14),

        'anomalies' => [
            // LIFE-20. How long a critical anomaly may sit unresolved before
            // it pages. 120 min is deliberately > one full ingest re-run
            // cycle at the default min_interval_secs (3600) plus the 15-min
            // dispatcher granularity, so a delete_guard trip that
            // Lander::clearGuardIfRecovered() clears on the very next
            // successful run never pages at all.
            'critical_alert_after_minutes' => (int) env('PARTNA_INGEST_ANOMALY_ALERT_AFTER_MINUTES', 120),
        ],
    ],

    // Pre-Account Sites (site-first signup + staff marketing builds).
    'pre_account' => [
        'expiry_days' => (int) env('PARTNA_PRE_ACCOUNT_EXPIRY_DAYS', 30),
        'failed_prune_hours' => (int) env('PARTNA_PRE_ACCOUNT_FAILED_PRUNE_HOURS', 24),
        'max_unclaimed_per_ip' => (int) env('PARTNA_PRE_ACCOUNT_MAX_UNCLAIMED_PER_IP', 3),

        // LIFE-4: how long a build may sit in pending/building before it's treated
        // as stuck (worker crash, never reached failed()). Used by both the hourly
        // builds:reconcile-stuck watchdog and PreAccountBuildService::reserve() —
        // deliberately well past GeneratePreAccountSiteJob's 300s timeout and 600s
        // ShouldBeUnique window so a fresh dispatch is never dropped by the unique
        // lock nor races a still-legitimately-running job.
        'stuck_build_sla_minutes' => (int) env('PARTNA_PRE_ACCOUNT_STUCK_BUILD_SLA_MINUTES', 30),

        // account_type => allowed source_types. THE one pairing map (spec §4) —
        // relaxing a pairing later is a config edit, not a validation hunt.
        'sources' => [
            'partna' => ['instagram'],
            'business' => ['google_business'],
        ],

        // source_type => generator class (registry key; a third source is one
        // class + one CHECK widening).
        'generators' => [
            'instagram' => InstagramSourceGenerator::class,
            'google_business' => GoogleBusinessSourceGenerator::class,
        ],

        // Per-provider outbound BURST rate (requests/minute) for the pre-account
        // scraping lane, keyed by provider actor slug; falls back to 'default'.
        // Cache-backed → Redis in prod → global across ALL workers (mirrors
        // connect/refresh). NOTE: the 'instagram' source does NOT read this — it
        // shares the paid-Apify 'connect.rate_limits' budget (same Apify account
        // as dashboard connects). This map sizes the 'google_business' source,
        // which hits the official Google Places API (a different vendor, its own
        // 'preaccount-places' limiter). Sized as a spike ceiling well above pre-
        // beta volume, binding only under a burst (e.g. bulk early-access approval).
        'rate_limits' => [
            'default' => (int) env('PARTNA_PREACCOUNT_PLACES_RATE_DEFAULT', 30),
            // e.g. 'google-business' => 60,
        ],
    ],

    'soft_delete_retention_days' => (int) env('PARTNA_SOFT_DELETE_RETENTION_DAYS', env('SOFT_DELETE_RETENTION_DAYS', 30)),

    'analytics_raw_event_retention_days' => (int) env('PARTNA_ANALYTICS_RAW_EVENT_RETENTION_DAYS', env('ANALYTICS_RAW_EVENT_RETENTION_DAYS', 90)),

    'throttle' => [
        // Intentionally defaults true (unlike other *_ENABLED flags): rate limiting is a protective
        // security control, not an opt-in feature — fail-closed = ON.
        'enabled' => (bool) env('PARTNA_THROTTLE_ENABLED', env('SIDEST_THROTTLE_ENABLED', true)),
        // Max notification emails sent per brand inbox per hour regardless of how many enquiries arrive.
        'enquiry_notification_per_hour' => (int) env('PARTNA_ENQUIRY_NOTIFY_PER_HOUR', env('SIDEST_ENQUIRY_NOTIFY_PER_HOUR', 10)),
        // Max visitor-facing confirmation emails per recipient address per hour
        // (shared across enquiry + subscription confirmations). Public forms send
        // to attacker-controllable addresses, so this caps email-bombing.
        'visitor_confirmation_per_hour' => (int) env('PARTNA_VISITOR_CONFIRMATION_PER_HOUR', 5),
        // Dedicated per-minute limit for the signup/availability endpoint (P2-44).
        // 6× tighter than the shared public-site bucket (60/min); generous for a
        // real signup flow (email + phone + handle checks in sequence).
        'signup_availability_per_minute' => (int) env('PARTNA_SIGNUP_AVAILABILITY_PER_MINUTE', 10),
        // Secondary per-hour anti-enumeration gate for signup/availability (P3-10).
        // Caps slow credential-stuffing loops at 60 attempts/hr/IP even when
        // the per-minute window is never fully exhausted.
        'signup_availability_per_hour' => (int) env('PARTNA_SIGNUP_AVAILABILITY_PER_HOUR', 60),
        // Dedicated per-minute limit for the login resolve-identifier endpoint (P2-44).
        // Mirrors typical mistyped-handle retry behaviour while bounding enumeration.
        'login_identifier_per_minute' => (int) env('PARTNA_LOGIN_IDENTIFIER_PER_MINUTE', 20),

        // CFG-2 (authz-core audit): the remaining limiters in
        // AppServiceProvider::configureRateLimiting() that were still hardcoded
        // literals, extended to match the config-driven pattern above. Values are
        // today's literals unchanged — this is a config-hygiene extraction, not a
        // traffic-shaping change.
        'analytics_per_minute' => (int) env('PARTNA_THROTTLE_ANALYTICS_PER_MINUTE', 120),
        'analytics_click_per_minute' => (int) env('PARTNA_THROTTLE_ANALYTICS_CLICK_PER_MINUTE', 5),
        'leads_per_minute_ip' => (int) env('PARTNA_THROTTLE_LEADS_PER_MINUTE_IP', 3),
        'leads_per_minute_subdomain' => (int) env('PARTNA_THROTTLE_LEADS_PER_MINUTE_SUBDOMAIN', 100),
        'authenticated_per_minute' => (int) env('PARTNA_THROTTLE_AUTHENTICATED_PER_MINUTE', 300),
        'staff_per_minute' => (int) env('PARTNA_THROTTLE_STAFF_PER_MINUTE', 300),
        'webhooks_per_minute' => (int) env('PARTNA_THROTTLE_WEBHOOKS_PER_MINUTE', 200),
        'bootstrap_per_minute' => (int) env('PARTNA_THROTTLE_BOOTSTRAP_PER_MINUTE', 5),
        'early_access_per_minute' => (int) env('PARTNA_THROTTLE_EARLY_ACCESS_PER_MINUTE', 5),
        'early_access_per_day' => (int) env('PARTNA_THROTTLE_EARLY_ACCESS_PER_DAY', 20),
        'early_access_per_hour_email' => (int) env('PARTNA_THROTTLE_EARLY_ACCESS_PER_HOUR_EMAIL', 12),
        'public_subscribe_per_minute' => (int) env('PARTNA_THROTTLE_PUBLIC_SUBSCRIBE_PER_MINUTE', 5),
        'public_subscribe_per_hour_email' => (int) env('PARTNA_THROTTLE_PUBLIC_SUBSCRIBE_PER_HOUR_EMAIL', 12),
        'session_writes_per_minute' => (int) env('PARTNA_THROTTLE_SESSION_WRITES_PER_MINUTE', 10),
        'document_download_per_hour' => (int) env('PARTNA_THROTTLE_DOCUMENT_DOWNLOAD_PER_HOUR', 10),

        // CFG-1 (authz-core audit follow-up): remaining hardcoded-literal limiters
        // extended to match the config-driven pattern above. Values are today's
        // literals unchanged — config-hygiene extraction only.
        'health_check_per_minute' => (int) env('PARTNA_THROTTLE_HEALTH_CHECK_PER_MINUTE', 60),
        'public_site_per_minute' => (int) env('PARTNA_THROTTLE_PUBLIC_SITE_PER_MINUTE', 60),
        'pre_account_build_per_minute' => (int) env('PARTNA_THROTTLE_PRE_ACCOUNT_BUILD_PER_MINUTE', 3),
        'pre_account_build_per_hour' => (int) env('PARTNA_THROTTLE_PRE_ACCOUNT_BUILD_PER_HOUR', 10),
        'claim_per_minute' => (int) env('PARTNA_THROTTLE_CLAIM_PER_MINUTE', 5),

        // R3-SCALE-2: provider-throughput cap for SendStaffBroadcastEmailToSubscriberJob,
        // registered as the 'mail-broadcast' queue RateLimiter (AppServiceProvider::
        // configureQueueRateLimiting()). Deliberately HALF of Resend's documented 10
        // requests/second PER-TEAM cap (shared across every API key and endpoint,
        // not a free-tier ceiling) — that budget is also spent by every transactional
        // send (enquiry/subscription confirmations, claim invites, moderation notices),
        // which must never be starved by a broadcast. A fixed-window limiter can also
        // burst up to 2x at a window boundary, so sizing at half the cap keeps the
        // worst case at the cap itself.
        'mail_broadcast_per_second' => (int) env('PARTNA_MAIL_BROADCAST_PER_SECOND', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Session tracking (TokenRevocationService)
    |--------------------------------------------------------------------------
    | Redis-backed Supabase session_id blocklist + per-user "Active Sessions"
    | tracking. See TokenRevocationService's class docblock for the full model.
    */
    'sessions' => [
        // Rolling last-activity resolution. One Redis SET NX per request in the
        // common case; the meta hash is only written when the interval elapses.
        'touch_interval_seconds' => (int) env('PARTNA_SESSION_TOUCH_INTERVAL_SECONDS', 600),

        // Refresh tokens last ~30 days in Supabase; outlast access tokens but not
        // refresh tokens, so a revoked/tracked session entry self-cleans on the
        // same schedule as the token it represents.
        'max_lifetime_seconds' => (int) env('PARTNA_SESSION_MAX_LIFETIME_SECONDS', 30 * 24 * 60 * 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency-Key middleware (App\Http\Middleware\IdempotencyKey)
    |--------------------------------------------------------------------------
    | CFG-2: tunable without a redeploy.
    */
    'idempotency' => [
        // 24h response cache — how long a completed response stays replayable.
        'ttl_seconds' => (int) env('PARTNA_IDEMPOTENCY_TTL_SECONDS', 86_400),

        // Distributed lock TTL — sized for slow synchronous handlers (mail dispatch,
        // R2 upload). Raise further only if a handler legitimately exceeds 2 min.
        'lock_seconds' => (int) env('PARTNA_IDEMPOTENCY_LOCK_SECONDS', 120),

        // 256 KB cache body cap (bigger payloads bypass cache).
        'max_body_bytes' => (int) env('PARTNA_IDEMPOTENCY_MAX_BODY_BYTES', 262_144),
    ],

    // Rate-limit config for the individual public profile endpoint (§28.8 CFG-3).
    // Tunable at runtime via env without redeploy. Values are per-IP per-minute.
    'public_profile' => [
        'rate_limit_per_minute' => (int) env('SIDEST_RATE_LIMIT_PUBLIC_PROFILE_PER_MINUTE', 60),
        // Short-TTL resolve-map window (handle → IDs). Bounded staleness without
        // mutation-driven invalidation; low enough to keep rename lag imperceptible.
        'resolve_cache_ttl' => (int) env('SIDEST_PUBLIC_PROFILE_RESOLVE_CACHE_TTL', 30),
        // Monotonic floor for the resolve timestamp (see
        // CacheKeyGenerator::handleResolveFloor). Must outlive any stale resolve
        // entry that could carry an older stamp: resolve primary is 30s with ±20%
        // jitter (≤36s) and its :stale twin is 10× that (≤360s). 600 clears both
        // with margin. Lower it and the race it closes reopens for the gap.
        'resolve_floor_ttl' => (int) env('PARTNA_PUBLIC_PROFILE_RESOLVE_FLOOR_TTL', 600),
        // Slow-request threshold for the Nightwatch P95 warning. Tune up if
        // builder is legitimately slow on cold paths; tune down to tighten alerting.
        'slow_request_threshold_ms' => (int) env('SIDEST_PUBLIC_PROFILE_SLOW_REQUEST_THRESHOLD_MS', 1000),
        // 60s edge TTL for the CacheLockService::rememberLocked payload.
        'cache_ttl_seconds' => (int) env('SIDEST_PUBLIC_PROFILE_CACHE_TTL', 60),
        // CCH-5: TTL for a payload built while a presence probe was throwing.
        // Those probes fail CLOSED — a QueryException answers "this page
        // section does not exist" — so caching that answer at the normal TTL
        // (plus its x10 stale twin) hides a live section for ~10 minutes after
        // a one-second blip. Short enough to heal promptly, long enough that
        // the single-flight lock still absorbs a burst rather than letting
        // every in-flight request rebuild during an outage.
        'degraded_cache_ttl_seconds' => (int) env('SIDEST_PUBLIC_PROFILE_DEGRADED_CACHE_TTL', 10),
        // Ceiling on the TTL above, checked hourly by AggregateCacheMetricsJob.
        // Deliberately NOT env-tunable: cache_ttl_seconds is, so raising the tap
        // needs no deploy, while raising the limit on the tap needs a reviewed
        // commit. That asymmetry is the point.
        //
        // This is a MEMORY bound, not a freshness one. public.profile:{handle}:{ts}
        // is timestamp-rotated, so a mutation mints a new key and freshness holds
        // at any TTL. What the TTL governs is how long each ABANDONED key lingers:
        // resident orphan bytes are edit_rate x TTL x payload_size — bounded by
        // edit flow, not by site count, so a thousand sites does not mean a
        // thousand orphans (research §2.4). 300 = 5x the current default, which
        // keeps orphan bytes negligible against the 250MB Valkey the queue shares.
        // Raise it only after redoing that arithmetic at the then-current edit rate.
        'cache_ttl_ceiling_seconds' => 300,
        // Analytics endpoint exposed to the architecture via data.publicConfig.
        // partna-pages reads this and uses it for client-side beacons.
        // Defaults to the current APP_URL so the fallback is always environment-correct.
        'analytics_endpoint' => env(
            'PARTNA_PUBLIC_ANALYTICS_ENDPOINT',
            rtrim(config('app.url'), '/').'/api/analytics'
        ),

        // CFG-2 (public-surface audit): QrCodeController's SVG generation
        // params — the QR points at the professional's public partna_url, so
        // this lives alongside the rest of the public-profile surface. Values
        // unchanged.
        'qr_code_size' => (int) env('PARTNA_PUBLIC_PROFILE_QR_CODE_SIZE', 320),
        'qr_code_margin' => (int) env('PARTNA_PUBLIC_PROFILE_QR_CODE_MARGIN', 10),
    ],

    // Version token for the design_kits column-list cache
    // (CacheKeyGenerator::designKitColumns). Bump this in the SAME migration PR
    // that adds or drops a site.design_kits column — the old cache key orphans
    // and TTLs out, so picking up the new column set needs no `artisan
    // cache:clear`. (LIFE-2)
    'design_kit_columns_version' => env('PARTNA_DESIGN_KIT_COLUMNS_VERSION', '2026-07-17.1'),

    /*
    |----------------------------------------------------------------------
    | Design-kit column group prefix maps
    |----------------------------------------------------------------------
    | IndividualProfilePayloadBuilder::groupKitColumns() uses these to
    | project flat snake_case DB column names (e.g. color_accent,
    | typography_font_heading) into the nested camelCase wire shape
    | (e.g. colors.accent, typography.fontHeading).
    |
    | two_token_prefixes — matched FIRST (longer prefix wins). Covers
    |   responsive companion groups (e.g. space_desktop_regular →
    |   spaceDesktop.regular).
    |
    | single_token_prefixes — fallback after two-token check. Pluralisation
    |   is NOT mechanical, so the map is explicit. Adding a new design-kit
    |   column family whose prefix isn't listed here means that group is
    |   silently dropped from the API response — add the entry here at the
    |   same time you add the Supabase migration column.
    */
    'design_kit' => [
        'column_groups' => [
            'two_token_prefixes' => [
                'space_desktop' => 'spaceDesktop',
                'text_desktop' => 'textDesktop',
                'sizing_desktop' => 'sizingDesktop',
                'typography_desktop' => 'typographyDesktop',
            ],
            'single_token_prefixes' => [
                'color' => 'colors',
                'typography' => 'typography',
                'text' => 'text',      // text scale (text_body, text_caption, text_h1, ...)
                'weight' => 'weight',    // weight scale (weight_regular, weight_medium, ...)
                'border' => 'borders',
                'space' => 'space',
                'motion' => 'motion',
                'icon' => 'icons',     // singular prefix: icon_size, icon_color (reserved)
                'icons' => 'icons',     // plural prefix:   icons_xl_size, icons_stroke_width, etc.
                'effect' => 'effects',
                'theme' => 'theme',     // theme_mode
                'sizing' => 'sizing',    // legacy (columns dropped — kept for safety)
                'button' => 'buttons',   // legacy (columns dropped — kept for safety)
            ],
        ],
    ],

    'media_disk' => env('PARTNA_MEDIA_DISK', env('SIDEST_MEDIA_DISK', 'media')),

    /*
    |----------------------------------------------------------------------
    | Image pools – per-professional limits
    |----------------------------------------------------------------------
    | gallery = showcase images (portfolio, work samples)
    | content = broad-use images (icon, headshot, banner, etc. – frontend assigns purpose)
    |
    | upload_pools = pools accepted by the generic professional upload endpoint
    |   (UploadImageRequest / ReorderPoolImagesRequest). Other pools have
    |   dedicated controllers with their own authorization logic.
    */
    'upload_pools' => ['gallery', 'content'],

    'image_pools' => [
        'gallery' => ['max' => (int) env('PARTNA_GALLERY_IMAGE_MAX', env('SIDEST_GALLERY_IMAGE_MAX', 6))],
        'content' => ['max' => (int) env('PARTNA_CONTENT_IMAGE_MAX', env('SIDEST_CONTENT_IMAGE_MAX', 6))],
        'documents' => ['max' => 1],
    ],

    /*
    |----------------------------------------------------------------------
    | Image variants configuration
    |----------------------------------------------------------------------
    | - optimized: adaptive quality targeting ~500KB, capped at 2400px
    |   long edge. Serves in-page rendering and gallery thumbnails.
    | - maximized: higher quality cap at 4000px long edge. Serves hero
    |   images and 3x retina hi-DPI displays.
    |
    | width / height = pixel caps applied via 'inside' fit — never upscales,
    |                  preserves aspect ratio, caps the long edge to the
    |                  smaller of the two dimensions. Equal w/h = long-edge cap.
    | fit            = 'inside' (fit within bounds, no crop) or 'cover' (crop).
    | quality        = preferred WebP quality ceiling (1-100). 92 is visually
    |                  indistinguishable from 100 and ~30% smaller.
    | min_quality    = lowest allowed quality while targeting size.
    | target_kb      = target max file size in kilobytes (triggers binary-search
    |                  quality targeting when set).
    |
    | NOTE: the preserve_resolution flag is still honoured when explicitly set
    | on a variant definition, but is no longer the default. Originals are
    | always stored in full via storeOriginal() — variants are for delivery.
    */
    'image_variants' => [
        'optimized' => [
            'format' => 'webp',
            'width' => 2400,
            'height' => 2400,
            'fit' => 'inside',
            'quality' => (int) env('PARTNA_IMAGE_QUALITY', env('SIDEST_IMAGE_QUALITY', 92)),
            'min_quality' => (int) env('PARTNA_IMAGE_MIN_QUALITY', env('SIDEST_IMAGE_MIN_QUALITY', 60)),
            'target_kb' => (int) env('PARTNA_IMAGE_TARGET_KB', env('SIDEST_IMAGE_TARGET_KB', 500)),
        ],
        'maximized' => [
            'format' => 'webp',
            'width' => 4000,
            'height' => 4000,
            'fit' => 'inside',
            'quality' => (int) env('PARTNA_IMAGE_MAXIMIZED_QUALITY', env('SIDEST_IMAGE_MAXIMIZED_QUALITY', 92)),
        ],
    ],

    'image_max_upload_size' => (int) env('PARTNA_IMAGE_MAX_UPLOAD_KB', env('SIDEST_IMAGE_MAX_UPLOAD_KB', 10240)), // 10 MB

    /*
    |----------------------------------------------------------------------
    | Image decode ceiling — pixel count, not file size
    |----------------------------------------------------------------------
    | Refuses to decode any uploaded image whose width × height exceeds
    | this many pixels, BEFORE any bitmap memory is allocated. This is
    | the defense against image-bomb uploads (tiny file, huge resolution)
    | and against legitimate ultra-high-resolution sources that would
    | blow worker memory_limit.
    |
    | Default is 24 MP — above typical phone sensors (12-16 MP), below
    | flagship 48 MP sensors. Conservative for a 256 MB worker memory_limit;
    | can be raised to ~50 MP when workers have more headroom.
    */
    'image_max_pixels' => (int) env('PARTNA_IMAGE_MAX_PIXELS', env('SIDEST_IMAGE_MAX_PIXELS', 24_000_000)), // 24 MP

    /*
    |----------------------------------------------------------------------
    | Logo background removal + SVG vectorization (self-hosted)
    |----------------------------------------------------------------------
    | When enabled, design-pool LOGO singletons (logo_full / logo_square) are
    | routed through the self-hosted logo-processor (Cloudflare Worker +
    | Container: rembg background removal + VTracer vectorization) instead of
    | the standard GD-only WebP path. Integration covers + gallery/content are
    | unaffected.
    |
    | Ships DISABLED. Turn on only once the container is deployed and the URL +
    | token point at it. On any processor failure the job falls back to the
    | standard WebP pipeline, so a logo never fails to appear.
    */
    'logo_removal' => [
        'enabled' => (bool) env('PARTNA_LOGO_REMOVAL_ENABLED', false),
        // Plan §12's config split. `enabled` governs WORKPLACE logos a user
        // uploaded; this governs auto-grabbed STORE logos. Two switches, not
        // one, so flipping the store path can never change what happens to a
        // file a user handed us themselves.
        // Defaults to the main switch: once the processor is deployed for user
        // logos it can carry store marks too. Set the env var to split them.
        'store_enabled' => (bool) env('PARTNA_LOGO_REMOVAL_STORE_ENABLED', env('PARTNA_LOGO_REMOVAL_ENABLED', false)),
        'url' => env('PARTNA_LOGO_PROCESSOR_URL', ''),
        'token' => env('PARTNA_LOGO_PROCESSOR_TOKEN', ''),
        'timeout' => (int) env('PARTNA_LOGO_PROCESSOR_TIMEOUT', 120),
    ],

    /*
    |----------------------------------------------------------------------
    | Outbound-fetch budget (SafeUrlFetcher)
    |----------------------------------------------------------------------
    | Every custom-link and platform-scraper fetch of a user-supplied URL goes
    | through SafeUrlFetcher. These tune the SSRF-guarded fetch itself — pool
    | concurrency for the batched fetchMany() path lives alongside the other
    | per-host burst caps at refresh.host_limits.fetch_many.
    */
    'http_fetch' => [
        // Per-request HTTP client timeout (seconds). NOTE: this is a per-hop
        // ceiling only — FetchBudget::open() (connect_budget_seconds below) is
        // what bounds the whole multi-hop/multi-retry operation.
        'timeout_seconds' => (int) env('PARTNA_HTTP_FETCH_TIMEOUT_SECONDS', 8),
        // Max redirect hops followed; each hop is re-validated for SSRF before
        // being followed (SafeUrlFetcher::fetch() / fetchMany()).
        'max_redirects' => (int) env('PARTNA_HTTP_FETCH_MAX_REDIRECTS', 5),
        // Hard cap on a fetched terminal response body (bytes). A response whose
        // declared Content-Length OR actual body exceeds this is rejected —
        // fetch() throws SafeUrlException, fetchMany() drops it to null — so a
        // hostile/oversized URL can't feed a multi-hundred-MB body into the
        // link-preview and menu/shop scrapers. 10 MB is generous for the HTML /
        // JSON those parse.
        'max_bytes' => (int) env('PARTNA_HTTP_FETCH_MAX_BYTES', 10 * 1024 * 1024),
        // TCP connect-phase timeout (seconds) — separate from the read timeout
        // above. A SYN-blackholed host would otherwise ride Guzzle's default
        // connect budget on top of timeout_seconds; this caps that leg
        // explicitly. Applied globally to every SafeUrlFetcher call site
        // (menu/shop/events/link-card scrapers included) AND to
        // YoutubeThumbnailResolver's raw pool, not just connect.
        'connect_timeout_seconds' => (int) env('PARTNA_HTTP_CONNECT_TIMEOUT_SECONDS', 3),
        // Own-infrastructure host suffixes SafeUrlFetcher::assertSafe() refuses
        // outright (exact host or any subdomain). Everything here resolves to
        // PUBLIC IPs, so the private/reserved address check never catches them —
        // without this list a pasted https://dev-api.partna.au/… or a Supabase /
        // R2 endpoint URL would be fetched by our own backend (request loops,
        // internal-surface reach). Env override is comma-separated.
        'denied_host_suffixes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'PARTNA_HTTP_FETCH_DENIED_HOST_SUFFIXES',
                'partna.au,supabase.co,laravel.cloud,r2.cloudflarestorage.com,r2.dev,workers.dev'
            ))
        ))),
        // Wall-clock budget (seconds) for one FetchBudget::open() operation —
        // e.g. a platform connect's full parse+fetch(+retry) chain, which can
        // otherwise spend up to max_redirects+1 hops x timeout_seconds (doubled
        // by the 403 honest-UA retry). Deliberately NOT owned by SafeUrlFetcher:
        // the budget spans every collaborator fetching during the operation,
        // including ones that bypass SafeUrlFetcher for good reason (see
        // FetchBudget's docblock). Opt-in per call site: callers that never open
        // a budget — the overwhelming majority — are unaffected.
        'connect_budget_seconds' => (int) env('PARTNA_CONNECT_BUDGET_SECONDS', 20),

        // Wall-clock budget (seconds) for one PlatformRefresher::refresh() call —
        // the cron/manual-refresh mirror of connect_budget_seconds above. Must stay
        // meaningfully below RefreshConnectionJob's 120s $timeout, leaving headroom
        // (~30s) for the non-fetch work a refresh still does after the fetch closes:
        // ScheduledRefresh's Cache::lock($key,10)->block(5,…) acquisition, the
        // projector sync() upserts, the model write + observer purge, the health
        // notifier. If the budget ever meets/exceeds 120s the job's SIGKILL wins
        // and the budget is moot — see RefreshBudgetInvariantTest.
        'refresh_budget_seconds' => (int) env('PARTNA_REFRESH_BUDGET_SECONDS', 90),

        // CFG-2: browser-ish UA — some providers 403 obvious bots / empty UAs.
        // Was a SafeUrlFetcher class constant; kept as a plain literal default
        // (not derived from partna.public_domain) since that key is env-specific
        // (dev-api.partna.au, localhost, …) and would make this contact URL wrong
        // outside production.
        'user_agent' => env('PARTNA_HTTP_FETCH_USER_AGENT', 'Mozilla/5.0 (compatible; PartnaBot/1.0; +https://partna.au)'),

        // CFG-2: honest bot UA for the 403 retry — see SafeUrlFetcher::fetch()'s
        // docblock. Must NOT start with "Mozilla/".
        'fallback_user_agent' => env('PARTNA_HTTP_FETCH_FALLBACK_USER_AGENT', 'PartnaBot/1.0 (+https://partna.au)'),
    ],

    /*
    |----------------------------------------------------------------------
    | Video uploads – processing config
    |----------------------------------------------------------------------
    | Availability is gated by the `video_uploads` feature flag
    | (FeatureFlagService — DB registry, config fallback
    | partna.features.video_uploads), not a config key here.
    |
    | video_max_upload_size  = max video file size accepted (KB)
    | video_max_duration_seconds = max video length (seconds)
    | ffmpeg_binary / ffprobe_binary = absolute paths or commands on $PATH
    |
    | video_queue.connection = Laravel queue connection name for video jobs
    | video_queue.name       = queue name to dispatch video jobs onto
    | video_queue.timeout    = worker --timeout (seconds); must exceed
    |                          worst-case transcode time for your machine
    |
    | video_variants define the two MP4 output tiers delivered directly to
    | the player: optimized (720p, autoplay) + maximized (1080p, on-demand).
    | The frontend chooses which to load by context.
    */
    'video_max_upload_size' => (int) env('PARTNA_VIDEO_MAX_UPLOAD_KB', env('SIDEST_VIDEO_MAX_UPLOAD_KB', 204800)), // 200 MB
    'video_max_duration_seconds' => (int) env('PARTNA_VIDEO_MAX_DURATION_SECONDS', env('SIDEST_VIDEO_MAX_DURATION_SECONDS', 30)), // 30s — short autoplay clips

    'ffmpeg_binary' => env('PARTNA_FFMPEG_BINARY', env('SIDEST_FFMPEG_BINARY', 'ffmpeg')),
    'ffprobe_binary' => env('PARTNA_FFPROBE_BINARY', env('SIDEST_FFPROBE_BINARY', 'ffprobe')),

    'video_queue' => [
        'connection' => env('PARTNA_VIDEO_QUEUE_CONNECTION', env('SIDEST_VIDEO_QUEUE_CONNECTION', 'redis_video')),
        'name' => env('PARTNA_VIDEO_QUEUE_NAME', env('SIDEST_VIDEO_QUEUE_NAME', 'videos')),
        'timeout' => (int) env('PARTNA_VIDEO_QUEUE_TIMEOUT', env('SIDEST_VIDEO_QUEUE_TIMEOUT', 3600)),
    ],

    // P1-08: recovery for video R2 artifacts orphaned when DeleteMediaArtifactsJob
    // exhausts its retries during an R2 outage. Two scheduled sweeps back it up.
    'media_orphan_sweep' => [
        // gdpr:sweep-purged-video-artifacts re-deletes paths recorded in
        // EVENT_PURGED audit rows newer than this many days. Bounded so the
        // audit-table scan stays cheap; the prefix GC is the older-than-window backstop.
        'ledger_window_days' => (int) env('PARTNA_MEDIA_LEDGER_WINDOW_DAYS', 30),
        // media:gc-orphaned-video-artifacts only deletes an unreferenced R2 object
        // once it is older than this — a safety margin against deleting files for a
        // just-created site_media row not yet visible to the sweep.
        'gc_min_age_hours' => (int) env('PARTNA_MEDIA_GC_MIN_AGE_HOURS', 24),

        // RV-11: media:gc-orphaned-platform-media only reclaims an unowned
        // platforms/instagram/{token} folder once it is older than this — the
        // safety margin against racing an in-flight mirror write (InstagramConnectJob
        // + GeneratePreAccountSiteJob's combined worst-case ceiling is ~18min).
        // Own key, not shared with gc_min_age_hours, so the two sweepers stay
        // independently tunable.
        'platform_gc_min_age_hours' => (int) env('PARTNA_PLATFORM_GC_MIN_AGE_HOURS', 24),
        // A platform_connections row soft-deleted longer ago than this is treated
        // as gone (its folder is no longer "live"), so a FAILED disconnect-delete
        // stays reclaimable without waiting out the full 30-day
        // partna:purge-soft-deletes retention. Short enough not to race a restore.
        'platform_gc_deleted_grace_days' => (int) env('PARTNA_PLATFORM_GC_DELETED_GRACE_DAYS', 7),
        // Hard ceiling on reclaim dispatches per run. A cap that is HIT is itself
        // the alarm — the remainder waits for next week's run.
        'platform_gc_max_per_run' => (int) env('PARTNA_PLATFORM_GC_MAX_PER_RUN', 200),
        // Anomaly abort: if more than this fraction of listed instagram folders
        // classify as orphan (and the anomaly floor below is also met), the run
        // aborts and dispatches nothing rather than trust what looks like a
        // broken live-set build.
        'platform_gc_max_orphan_ratio' => (float) env('PARTNA_PLATFORM_GC_MAX_ORPHAN_RATIO', 0.25),
        // Minimum candidate count before the ratio guard above can fire — keeps a
        // tiny dev bucket (e.g. 1-of-2 folders) from always tripping the ratio.
        'platform_gc_anomaly_floor' => (int) env('PARTNA_PLATFORM_GC_ANOMALY_FLOOR', 20),
    ],

    // P2-56: hard ceiling on streamed CSV exports (subscriber lists). Bounds the
    // PHP-FPM worker hold time per export; the response carries X-Export-Truncated: 1
    // when the cap is hit. Generous default — no realistic list truncates.
    'export' => [
        'max_rows' => (int) env('PARTNA_EXPORT_MAX_ROWS', 50_000),
    ],

    // Analytics ingest queue. Reuses the default 'redis' connection — Horizon's
    // supervisor-analytics already consumes the 'analytics' queue (config/horizon.php).
    // No dedicated connection: jobs are tiny + PK-idempotent, so the default
    // retry_after is harmless.
    'analytics_queue' => [
        'name' => env('PARTNA_ANALYTICS_QUEUE', 'analytics'),
    ],

    // Named queue lanes for domain-specific job routing. Keeping queue names
    // config-driven lets ops reroute to a dedicated worker without a code deploy.
    'queues' => [
        // Transactional confirmation emails (enquiry + subscription). Set
        // PARTNA_QUEUE_NOTIFICATIONS in .env to route to a different lane.
        'notifications' => env('PARTNA_QUEUE_NOTIFICATIONS', 'notifications'),
        // Staff broadcast leaf job emails (SendStaffBroadcastEmailToSubscriberJob batches).
        'mail' => env('PARTNA_QUEUE_MAIL', 'mail'),
        // Cloudflare KV sync and cache-purge jobs.
        'cloudflare' => env('PARTNA_QUEUE_CLOUDFLARE', 'cloudflare'),
        // R3-CACHE-1: lowest-priority lane for takedown-fan-out purges
        // (CloudflareCachePurgeJob bulk:true). Appended LAST in supervisor-1's
        // queue list (config/horizon.php) — balance=>false + strict priority
        // means a bulk purge is only ever served once 'cloudflare' (and every
        // lane above it) is empty, so a large takedown structurally can't
        // delay unrelated users' real-time purges.
        'cloudflare_bulk' => env('PARTNA_QUEUE_CLOUDFLARE_BULK', 'cloudflare_bulk'),
        // Public site cache pre-warm (WarmPublicSiteCacheJob).
        // NOTE: value keeps the hyphen to match the Horizon supervisor-cache-warm lane.
        'cache_warm' => env('PARTNA_QUEUE_CACHE_WARM', 'cache-warm'),
        // Image variant processing (ProcessImageVariantsJob).
        'images' => env('PARTNA_QUEUE_IMAGES', 'images'),
        // Streaming live-status polling (CheckStreamingLiveStatusJob).
        'streaming' => env('PARTNA_QUEUE_STREAMING', 'streaming'),
        // Platform scraping jobs (InstagramConnectJob etc).
        'scraping' => env('PARTNA_QUEUE_SCRAPING', 'scraping'),
        // Platform refresh fan-out (RefreshConnectionJob, dispatched by integrations:refresh).
        'platform_refresh' => env('PARTNA_QUEUE_PLATFORM_REFRESH', 'platform_refresh'),
        // Deferred-connect content fetch (ConnectFetchJob — Unit 11 W5 / LIFE-13..20).
        // Isolated from 'scraping': that lane carries ~110s Apify Instagram jobs on
        // two workers, and connects are interactive (a user is watching the modal) —
        // sharing the lane would put a user-visible spinner behind an Apify backlog.
        'platform_connect' => env('PARTNA_QUEUE_PLATFORM_CONNECT', 'platform_connect'),
    ],

    // Platform connection CONNECT-time throttle (Seam 5 / strategy §5 step 4). The
    // paid Apify connect jobs (InstagramConnectJob, MenuFetchJob, GoogleBusinessEnrichJob)
    // carry the 'platform-connect' RateLimiter as middleware, keyed by Apify ACTOR.
    // ApifyBudget caps daily SPEND; this caps per-minute BURST RATE so a signup spike
    // can't stampede one actor. Separate from 'refresh.rate_limits' because connect
    // (Apify) and refresh (official APIs) hit different vendors.
    'connect' => [
        // Per-actor connect scrapes/minute; falls back to 'default'. Cache-backed →
        // Redis in prod → global across ALL workers. Sized as a spike ceiling: well
        // above normal pre-beta connect volume, binding only under a burst (and the
        // real gate once the scraping supervisor grows past its current 2 workers).
        'rate_limits' => [
            'default' => (int) env('PARTNA_CONNECT_RATE_DEFAULT', 20),
            // e.g. 'menu' => 10,
        ],

        // Deferred-connect rollout flag (Unit 11 W6 / LIFE-13..20 Phase 2,
        // docs/superpowers/plans/2026-07-20-platform-connect-async.md §2f).
        // Comma-separated platform slugs — ConnectResolver takes the async
        // (identify()+ConnectFetchJob) path for a platform ONLY when it is
        // named here AND the platform's descriptor declares
        // supportsDeferredConnect(). Default '' → array_filter(explode(...))
        // yields [] → async is off everywhere on merge; every connect()
        // response stays byte-identical. Per-platform, per-environment via
        // env, no deploy — the same lever is the kill switch.
        'deferred' => array_filter(explode(',', (string) env('PARTNA_CONNECT_DEFERRED', ''))),
    ],

    // Platform connection refresh (SCALE-1). The dispatcher (integrations:refresh)
    // selects connections due per default_ttl_seconds (or the descriptor override)
    // and fans out RefreshConnectionJob; per-provider rate_limits cap outbound
    // pressure; the backlog command alarms when too many fall overdue.
    'refresh' => [
        // Default re-fetch cadence when a platform declares no descriptor override.
        // 86400 preserves the previous daily cadence.
        'default_ttl_seconds' => (int) env('PARTNA_REFRESH_DEFAULT_TTL', 86400),

        // Highlights picker snapshot freshness (LIFE-21..24 / Phase 1b). The picker
        // (HighlightsPicker) reads a private `recent` snapshot off the payload
        // instead of live-scraping the vendor on every modal open; this is how old
        // that snapshot is allowed to be before the picker falls back to a live
        // fetch. Deliberately 2x the 12h refreshEvery() cadence shared by
        // youtube/vimeo/youtube-music/bandcamp, so a healthy connection's snapshot
        // is always fresh by the time anyone opens the picker, and only TWO
        // consecutive missed/failed refreshes let it go stale. Lives here (not a
        // third top-level 'platforms' config array — this file already has two,
        // limits.platforms above (per-platform Apify cost knobs) and
        // menu.platforms above that (the menu-scraper registry); neither is a
        // fit for a scalar TTL) because it's a refresh-cadence concern through
        // and through. 0 makes every open fail the freshness check and go
        // straight to a live fetch (the stored snapshot still backstops a
        // failed live fetch — see HighlightsPicker::items()).
        'highlights_snapshot_ttl' => (int) env('PARTNA_HIGHLIGHTS_SNAPSHOT_TTL', 24 * 3600),

        // Circuit breaker: skip connections at/above this many consecutive failures
        // (a dead account stops consuming refresh capacity). Reset to 0 on any
        // successful refresh by ScheduledRefresh::run().
        'max_consecutive_failures' => (int) env('PARTNA_REFRESH_MAX_FAILURES', 10),

        // RV-5: bounds on the hourly dispatcher's fan-out. The cap is per PLATFORM per
        // RUN, not a global or daily ceiling — the SCALE-1 "capacity scales with the
        // fleet" design is preserved because eligibility is still pure TTL due-ness and
        // the selection is oldest-first, so an over-cap backlog drains across
        // subsequent runs instead of starving. Sized against what supervisor-1 can
        // realistically drain on the second-to-last-priority platform_refresh lane
        // within one hour, NOT against fleet size.
        'dispatch' => [
            // Max RefreshConnectionJobs dispatched per platform per run.
            'max_per_platform' => (int) env('PARTNA_REFRESH_MAX_PER_PLATFORM', 250),

            // Seconds across which one run's dispatches are spread via ->delay().
            // MUST stay well below RefreshConnectionJob::$uniqueFor (7200) — a job
            // whose unique lock expires before it runs can be duplicated by the next
            // hourly run. 2700 = 45min, leaving 15min of settle before the next
            // hourly tick.
            'stagger_window_seconds' => (int) env('PARTNA_REFRESH_STAGGER_WINDOW', 2700),

            // Upper bound on the gap between two consecutive dispatches, so a small
            // run (a handful of due connections) finishes in seconds instead of being
            // smeared over the whole window.
            'max_stagger_seconds' => (int) env('PARTNA_REFRESH_MAX_STAGGER', 10),
        ],

        // Per-provider outbound rate limit (requests/minute) for the refresh queue,
        // keyed by platform key; falls back to 'default'. Enforced by the
        // 'platform-refresh' RateLimiter (cache-backed → Redis in prod → shared
        // across ALL workers, so the cap is global, not per-process).
        'rate_limits' => [
            'default' => (int) env('PARTNA_REFRESH_RATE_DEFAULT', 60),
            // e.g. 'google-business' => 30,
        ],

        // CFG-3 (authz-core audit): per-platform refresh cadences for the hourly
        // dispatcher, previously hardcoded arithmetic literals in
        // PlatformRegistryServiceProvider's refreshEvery() calls. Each call site
        // reads its own key here with today's literal as the inline fallback, so
        // an unset entry is a no-op — mirrors rate_limits' per-platform-with-
        // default shape above. Platforms not listed here declare no
        // refreshEvery() override and fall back to default_ttl_seconds instead.
        'intervals' => [
            'eventbrite' => (int) env('PARTNA_REFRESH_INTERVAL_EVENTBRITE', 6 * 3600),
            'humanitix' => (int) env('PARTNA_REFRESH_INTERVAL_HUMANITIX', 6 * 3600),
            'shop' => (int) env('PARTNA_REFRESH_INTERVAL_SHOP', 6 * 3600),
            'fresha' => (int) env('PARTNA_REFRESH_INTERVAL_FRESHA', 2 * 86400),
            'youtube' => (int) env('PARTNA_REFRESH_INTERVAL_YOUTUBE', 12 * 3600),
            'vimeo' => (int) env('PARTNA_REFRESH_INTERVAL_VIMEO', 12 * 3600),
            'twitch' => (int) env('PARTNA_REFRESH_INTERVAL_TWITCH', 12 * 3600),
            'youtube-music' => (int) env('PARTNA_REFRESH_INTERVAL_YOUTUBE_MUSIC', 12 * 3600),
            'spotify' => (int) env('PARTNA_REFRESH_INTERVAL_SPOTIFY', 12 * 3600),
            'soundcloud' => (int) env('PARTNA_REFRESH_INTERVAL_SOUNDCLOUD', 12 * 3600),
            'bandcamp' => (int) env('PARTNA_REFRESH_INTERVAL_BANDCAMP', 12 * 3600),
            'apple-music' => (int) env('PARTNA_REFRESH_INTERVAL_APPLE_MUSIC', 12 * 3600),
            'apple-podcast' => (int) env('PARTNA_REFRESH_INTERVAL_APPLE_PODCAST', 12 * 3600),
            'google-business' => (int) env('PARTNA_REFRESH_INTERVAL_GOOGLE_BUSINESS', 2 * 86400),
        ],

        // Staleness alarm thresholds (integrations:refresh-backlog).
        'backlog' => [
            // Overdue = not refreshed within (ttl × grace_multiplier).
            'grace_multiplier' => (float) env('PARTNA_REFRESH_BACKLOG_GRACE', 2),
            // Report to Nightwatch when the overdue count exceeds this.
            'alert_threshold' => (int) env('PARTNA_REFRESH_BACKLOG_THRESHOLD', 500),
        ],

        // SCALE-3: inner per-host burst control for the refresh fetch strategies.
        // The per-provider 'platform-refresh' RateLimiter (rate_limits, above) paces
        // how many refresh JOBS run per minute per platform. It does NOT see the
        // concurrent HTTP a SINGLE fetch fans out within one job — these cap those
        // bursts so a fetch can't hammer a keyless / billed third-party host. (The old
        // global --throttle-ms was removed in Plan 1; there is no global delay to max
        // against — these are the inner-burst residual.)
        'host_limits' => [
            // iTunes Search/Lookup — keyless, ~20 req/min/IP (429s after ~20 Apple
            // refreshes in one run). Cache successful responses so repeated lookups
            // across a run (and re-runs within the window) don't each re-hit Apple.
            'itunes' => [
                'cache_ttl_seconds' => (int) env('PARTNA_REFRESH_ITUNES_CACHE_TTL', 21600), // 6h
            ],
            // i.ytimg.com maxresdefault HEAD probes (YoutubeThumbnailResolver). Cheap
            // HEADs, so a generous cap; bounds the batch when many videos miss cache.
            'youtube_thumbnails' => [
                'pool_concurrency' => (int) env('PARTNA_REFRESH_YTIMG_POOL', 10),
                // 'hq' verdicts re-probe on this cadence (maxres may appear post-upload);
                // 'maxres' verdicts keep the long CACHE_DAYS TTL (never regresses). 6h default.
                'hq_recheck_ttl_seconds' => (int) env('PARTNA_REFRESH_YTIMG_HQ_RECHECK_TTL', 21600),
            ],
            // Google Places media — BILLED per call. Keep the concurrent burst tight.
            'google_places' => [
                'pool_concurrency' => (int) env('PARTNA_REFRESH_PLACES_POOL', 5),
            ],
            // Shared SafeUrlFetcher::fetchMany pool (Eventbrite/Humanitix HTML scrapes;
            // WAF-ban risk in aggregate). Caps every fetchMany caller globally.
            'fetch_many' => [
                'pool_concurrency' => (int) env('PARTNA_REFRESH_FETCH_MANY_POOL', 6),
            ],
        ],

        // Plan 5: HTTP conditional requests (ETag / If-None-Match / 304) on the
        // single-GET poll strategies. When enabled, a wired fetch strategy sends the
        // connection's stored validator and short-circuits on a 304 (no payload
        // write, no cache purge). Global kill-switch: set false to force full fetches
        // everywhere if an upstream starts mis-answering conditional requests. Off ⇒
        // ConditionalContext::for() returns null and every strategy fetches exactly
        // as before (graceful degradation is per-strategy; this is the master off).
        'conditional' => [
            'enabled' => (bool) env('PARTNA_REFRESH_CONDITIONAL_ENABLED', true),
        ],
    ],

    'platforms' => [
        'fresha' => [
            // How long a scraped team roster stays servable without re-scraping.
            // The dashboard prompt for an unfinished connection can open once per
            // session; a live scrape per open would hammer fresha.com for a list
            // that changes when someone joins or leaves a salon.
            'team_cache_seconds' => (int) env('PARTNA_FRESHA_TEAM_CACHE_SECONDS', 86400),
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Routing — link probes (plan §11)
    |----------------------------------------------------------------------
    | Keyless commerce probes for own-domain storefronts. No vendor invoice
    | to cap, but each probe is still an outbound request this backend makes
    | on a user's say-so — unbounded, that is a reliability risk to us and an
    | amplification vector aimed at someone else. Three ceilings, three
    | different failures: a runaway import (global), one abusive account
    | (per user), one 200-link page eating a whole day's allowance (per run).
    */
    // #PRIV-4: site.site_documents retention. Only the newest version per
    // (site_id, channel) is ever read (DocumentBuilder::build()); older versions
    // have no consumer at all. keep_versions is rollback headroom for a bad
    // build, min_age_days stops the prune racing a build that is mid-flight.
    'documents' => [
        'keep_versions' => (int) env('PARTNA_SITE_DOCUMENT_KEEP_VERSIONS', 3),
        'min_age_days' => (int) env('PARTNA_SITE_DOCUMENT_MIN_AGE_DAYS', 7),
        'prune_batch_size' => (int) env('PARTNA_SITE_DOCUMENT_PRUNE_BATCH_SIZE', 500),
    ],

    'routing' => [
        'probe' => [
            // Probes one worker run may spend. Deliberately small: a page with
            // 200 links should not be 200 outbound requests.
            'per_run_cap' => (int) env('PARTNA_ROUTING_PROBE_PER_RUN_CAP', 6),
            'user_daily_cap' => (int) env('PARTNA_ROUTING_PROBE_USER_DAILY_CAP', 40),
            'global_daily_cap' => (int) env('PARTNA_ROUTING_PROBE_GLOBAL_DAILY_CAP', 2000),
            // Wall-clock ceiling for the WHOLE probe cascade, not per probe.
            'budget_seconds' => (int) env('PARTNA_ROUTING_PROBE_BUDGET_SECONDS', 15),
            // How long a URL keeps its answer, hit or miss. A miss that isn't
            // cached is a URL re-probed on every scan of the same page.
            'cooldown_minutes' => (int) env('PARTNA_ROUTING_PROBE_COOLDOWN_MINUTES', 720),
        ],

        'intents' => [
            // LIFE-19. A stuck intent is a question waiting on a USER (see
            // SuggestionsController::index), so this is a BACKLOG alarm, not
            // a per-row one — it fires when the inbox is filling faster than
            // users empty it, which is an engineering fault, not user
            // procrastination.
            'stuck_age_days' => (int) env('PARTNA_ROUTING_STUCK_INTENT_AGE_DAYS', 14),
            'stuck_alert_threshold' => (int) env('PARTNA_ROUTING_STUCK_INTENT_THRESHOLD', 500),
        ],
    ],

    'video_variants' => [
        'optimized' => [
            'resolution' => env('PARTNA_VIDEO_OPTIMIZED_RESOLUTION', env('SIDEST_VIDEO_OPTIMIZED_RESOLUTION', '1280x720')),
            'video_bitrate_kbps' => (int) env('PARTNA_VIDEO_OPTIMIZED_BITRATE', env('SIDEST_VIDEO_OPTIMIZED_BITRATE', 2000)),
            'audio_bitrate_kbps' => (int) env('PARTNA_VIDEO_OPTIMIZED_AUDIO_BITRATE', env('SIDEST_VIDEO_OPTIMIZED_AUDIO_BITRATE', 128)),
        ],
        'maximized' => [
            'resolution' => env('PARTNA_VIDEO_MAXIMIZED_RESOLUTION', env('SIDEST_VIDEO_MAXIMIZED_RESOLUTION', '1920x1080')),
            'video_bitrate_kbps' => (int) env('PARTNA_VIDEO_MAXIMIZED_BITRATE', env('SIDEST_VIDEO_MAXIMIZED_BITRATE', 5000)),
            'audio_bitrate_kbps' => (int) env('PARTNA_VIDEO_MAXIMIZED_AUDIO_BITRATE', env('SIDEST_VIDEO_MAXIMIZED_AUDIO_BITRATE', 192)),
        ],
    ],

    'form_timing' => [
        'min_ms' => (int) env('PARTNA_FORM_TIMING_MIN_MS', env('FORM_TIMING_MIN_MS', 2500)),      // 2.5s minimum fill time
        'max_ms' => (int) env('PARTNA_FORM_TIMING_MAX_MS', env('FORM_TIMING_MAX_MS', 43200000)),  // 12h max (stale form)
    ],

    'notification_retention_days' => [
        'policy_update' => 365,
        'incident' => 14,
        'feature_announcement' => 30,
        'default' => 30,
        'profile_task' => 180,
        // OV-H non-critical auto-dispatchers. These get an `ends_at` so the prune
        // auto-cleans them; critical notifications ignore this (ends_at = null, persist).
        'achievement' => 60,       // celebratory — keep visible a while
        'content_scrape' => 14,    // transient; self-heals, don't linger
        'analytics_weekly' => 14,  // superseded by next week's summary
        'integration_connected' => 30, // connect confirmation — no reason to linger
    ],

    'notifications' => [
        'email_enabled' => (bool) env('PARTNA_NOTIFICATIONS_EMAIL_ENABLED', env('NOTIFICATIONS_EMAIL_ENABLED', false)),

        // DINT-1 / PRIV-7 Gap 2: how long to retain an unsubscribed email_subscriptions row
        // before HARD-DELETING it. email and email_lc are both NOT NULL and email_lc is itself
        // PII, so there is no PII-free skeleton to keep — the whole row goes once consent has
        // been withdrawn for this window. Child broadcast_email_receipts cascade via the DINT-2
        // FK; a later re-subscribe is a fresh double-opt-in, not a reactivation of this row.
        'unsubscribed_retention_days' => (int) env('PARTNA_UNSUBSCRIBED_RETENTION_DAYS', 365),

        // CFG-1-style batch size for notifications:prune-unsubscribed-subscriptions —
        // bounds each DELETE's row count so the purge never holds one long-running
        // transaction as email_subscriptions grows.
        'prune_batch_size' => (int) env('PARTNA_NOTIFICATIONS_PRUNE_BATCH_SIZE', 1000),

        // Max jobs per Bus::batch() sub-chunk for fan-out paths. Bounds the
        // size of a single Redis pipeline write so a large notification
        // broadcast list can't spike Redis memory. Shared between
        // NotificationPublisher and SendStaffBroadcastEmailsJob.
        'batch_chunk_size' => (int) env('PARTNA_NOTIFICATIONS_BATCH_CHUNK_SIZE', 200),

        // TTL (seconds) for the cached /me/notifications index payload.
        // Short enough that a publish surfaces within one bell-poll cycle;
        // markRead/dismiss bust the key explicitly so this is just a ceiling.
        'listing_cache_ttl_seconds' => (int) env('PARTNA_NOTIFICATIONS_LISTING_CACHE_TTL', 15),

        /*
         * Category registry — single source of truth.
         *
         * Keys are valid category strings (validated in FormRequests).
         * Values are the Mailable class sent for transactional email, or null
         * for in-app-only categories. Adding a new notification type is:
         *   1. Create a Mailable in app/Mail/Notifications/ (skip for in-app only)
         *   2. Add one entry here
         *   3. Call $publisher->publish(category: 'your_key', ...) at the emit site
         * No edits to the publisher, no edits to the email dispatch job.
         */
        'mailables' => [
            'feature_announcement' => FeatureAnnouncementMail::class,
            'incident' => IncidentMail::class,
            'inbox' => null,  // in-app only — enquiry inbox; no mailable (email goes via SendEnquiryNotificationJob)
            'policy_update' => PolicyUpdateMail::class,
            'profile_tasks' => ProfileTaskMail::class,

            // OV-H automatic dispatchers. Only the `critical` ones carry a mailable
            // (email fires only for critical notifications — see NotificationPublisher).
            // The generic CriticalNotificationMail renders the notification through the
            // shared OTP layout family; SendTransactionalNotificationEmailJob also falls
            // back to it for any critical notification whose category is unmapped.
            'achievement' => null,                                // in-app only (milestones / first-enquiry)
            'platform_connection' => CriticalNotificationMail::class, // critical: connection needs reconnecting → email
            'content_scrape' => null,                             // in-app only (transient scrape/menu warnings)
            'analytics_weekly' => null,                           // in-app only (weekly summary stub)
            'integration_connected' => null,                      // in-app only (user connected an integration)
        ],

        /*
         * Mandatory categories — money-movement and account-state notices that
         * cannot be silenced by user preference. Legal basis: GDPR Art. 6(1)(b)
         * (contract performance — the user needs to know payouts moved or their
         * subscription changed), and CAN-SPAM §316.3 carves transactional
         * "primary purpose" mail out of opt-out requirements. The preference
         * controller surfaces these with mandatory=true so the frontend can
         * render them as read-only "always on" toggles.
         */
        'mandatory_categories' => [
            'policy_update',
        ],

        // CFG-1 (public-surface audit): mailing-list key allowlists, migrated
        // from the standalone config/subscriptions.php so subscription config
        // lives with the rest of notifications config instead of a second file
        // a developer has to know exists. public = subscribable from public
        // (unauthenticated) endpoints (PublicEmailSubscribeRequest); global =
        // staff/internal only, currently unread by any call site.
        'subscription_list_keys' => [
            'public' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('PARTNA_SUBSCRIPTION_PUBLIC_LIST_KEYS', 'marketing'))
            ))),
            'global' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('PARTNA_SUBSCRIPTION_GLOBAL_LIST_KEYS', 'sidest_updates'))
            ))),
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Launch feature flags
    |----------------------------------------------------------------------
    | Tier-5 config fallback read by FeatureFlagService::enabled() — must stay
    | an array. Intentionally empty; add entries here only for launch gates
    | consumed via the `feature:` middleware alias.
    */
    'features' => [],

    /*
    |--------------------------------------------------------------------------
    | GDPR
    |--------------------------------------------------------------------------
    |
    | Config for the GDPR data-export flow (ExportUserDataJob). Jobs dispatch onto a
    | dedicated queue so they don't contend with the default worker (a large
    | export can take several minutes). The placeholder domain is used
    | when anonymising customer email addresses — pick a domain you own so
    | bounces don't confuse third-party mail providers.
    |
    */

    'gdpr' => [
        'queue' => env('PARTNA_GDPR_QUEUE', env('GDPR_QUEUE', 'gdpr')),
        'redact_placeholder_domain' => env('GDPR_REDACT_PLACEHOLDER_DOMAIN', 'gdpr.partna.au'),
        'export_retention_days' => (int) env('GDPR_EXPORT_RETENTION_DAYS', 30),
        // Signed URL TTL emailed to recipients of a professional data export.
        // Must be <= export_retention_days (file is gone after that anyway).
        'signed_url_ttl_days' => (int) env('GDPR_EXPORT_SIGNED_URL_TTL_DAYS', 7),
        // Dedup window: a second export request for the same professional
        // within this many minutes returns 409 instead of queuing again.
        // Prevents accidental double-clicks AND queue thrashing.
        'dedup_window_minutes' => (int) env('GDPR_EXPORT_DEDUP_WINDOW_MINUTES', 30),
    ],

    /*
    |----------------------------------------------------------------------
    | MFA — verification windows and brute-force protection
    |----------------------------------------------------------------------
    */
    'mfa' => [
        /*
        | Default "fresh MFA" window in seconds — how long after a successful
        | TOTP/WebAuthn verify a request still counts as freshly-verified.
        | Used by BasePolicy::requiresFreshAal2() unless an explicit override
        | is passed (e.g. unenroll uses a tighter 60s window).
        */
        'fresh_window_seconds' => (int) env('SIDEST_MFA_FRESH_WINDOW_SECONDS', 300),

        /*
        | Tighter window specifically for the "remove my own MFA factor"
        | flow. The user is about to disable their own protection; force a
        | re-verification within the last minute with the factor they're
        | about to remove.
        */
        'unenroll_fresh_window_seconds' => (int) env('SIDEST_MFA_UNENROLL_WINDOW_SECONDS', 60),

        /*
        | Brute-force protection: maximum failed verifies (per user+factor)
        | within the rolling window. On the (N+1)-th attempt, the MFA
        | Verification Hook returns {decision: reject} for the duration of
        | the window. This is enforced BEFORE Supabase accepts the verify,
        | so the session never reaches aal2 from a brute-force attempt.
        */
        'verify_max_failures' => (int) env('SIDEST_MFA_VERIFY_MAX_FAILURES', 5),
        'verify_failure_window_seconds' => (int) env('SIDEST_MFA_VERIFY_WINDOW_SECONDS', 300),

        // When true, ProfessionalSelfPolicy::update requires a fresh AAL2 check.
        // Flip to true after TOTP enrolment is live in the UI and tested in production.
        'require_fresh_aal2_for_profile_update' => (bool) env('SIDEST_MFA_REQUIRE_FRESH_AAL2_FOR_PROFILE_UPDATE', false),
    ],

    /*
    |----------------------------------------------------------------------
    | Staff pagination defaults
    |----------------------------------------------------------------------
    | Central defaults for staff list endpoints so per_page behaviour is
    | consistent and tunable via config without touching controller code.
    | Controllers read config('partna.staff.pagination.per_page') as the
    | default and config('partna.staff.pagination.per_page_max') as the cap
    | for NormalizesPerPage. Callers may still override via ?per_page=N.
    |
    | StaffEmailSubscriberController intentionally deviates (50 default) — its
    | subscriber lists are large and require a higher page density; see the
    | docblock in that controller for the rationale.
    */
    'staff' => [
        'pagination' => [
            'per_page' => 25,
            'per_page_max' => 100,
        ],

        // CFG-3 (staff-api audit): truncation cap for the User-Agent string
        // persisted to core.handle_change_log on a staff-initiated site rename
        // (StaffSiteManagementController::update). Value unchanged.
        'audit_user_agent_max_length' => (int) env('PARTNA_STAFF_AUDIT_UA_MAX_LENGTH', 1024),
    ],

    'feedback' => [
        /*
        | Comma-separated recipients for the FeedbackSubmittedMail notification.
        | Empty value = no email sent (job logs a warning and returns;
        | submission still persists to core.feedback).
        */
        'notify_emails' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('FEEDBACK_NOTIFY_EMAILS', ''))
        ))),

        // Per-user rate limit on submissions. Named throttle reads these.
        'rate_limit_per_hour' => (int) env('FEEDBACK_RATE_LIMIT_HOUR', 10),
        'rate_limit_per_day' => (int) env('FEEDBACK_RATE_LIMIT_DAY', 30),

        // Window for the duplicate-message check. Same user submitting an
        // identical message inside this window returns 429.
        'duplicate_window_seconds' => (int) env('FEEDBACK_DUPLICATE_WINDOW', 60),

        // Pepper for SHA256 IP hashing. Generated per env; never committed.
        // Empty value → ip_hash stored NULL (soft-degrade; PII never leaks).
        'ip_hash_pepper' => env('FEEDBACK_IP_HASH_PEPPER'),

        // Hard cap matched by DB CHECK constraint feedback_message_length_check.
        'max_message_length' => 5000,

        // PRIV-8: retention window for core.feedback (bug/idea/praise/question
        // submissions). Nothing else ages this table out — PurgeSoftDeleted only
        // force-deletes rows a staffer already soft-deleted, so a row left in
        // 'new'/'triaged' would otherwise keep its reply_email + free-text
        // message forever.
        //
        // 90 days (Josh's call, 2026-07-20). Deliberately SHORTER than the
        // 365-day unsubscribed-subscription precedent: that table holds an email
        // and a flag, whereas this one holds free-text a user may have typed
        // anything into, alongside reply_email — so the minimisation argument is
        // stronger and the operational need to keep it is weaker. It also matches
        // analytics_raw_event_retention_days (90), the other free-form user-data
        // window in this file.
        'retention_days' => (int) env('FEEDBACK_RETENTION_DAYS', 90),

        // CFG-1-style batch size for feedback:prune-old-submissions — bounds
        // each DELETE's row count so the purge never holds one long-running
        // transaction.
        'prune_batch_size' => (int) env('FEEDBACK_PRUNE_BATCH_SIZE', 1000),
    ],

    'cache' => [
        // CACHE-3: hourly cache hit-rate SLO, checked by AggregateCacheMetricsJob
        // and reported to Nightwatch on breach.
        //
        // A hit rate is bounded by 1 - 1/(lambda * TTL): with a 60s TTL
        // ('ttls.professional_model' below), reaching 90% needs ~10 reads per key
        // per minute. Under that, the once-per-TTL expiry miss dominates and the
        // alert measures traffic volume rather than cache health — dev observed
        // ~5.7 hits per recompute on `pro`, an ~87% ceiling, and fired hourly
        // regardless. Both knobs are per-environment for that reason: keep the
        // production target here and raise min_sample where traffic is thin.
        //
        // The ceiling is a property of each prefix's TTL, so one target cannot
        // serve both: `site` is dominated by 'ttls.public_payload' at 900s
        // (90% needs under one read per minute per key — reachable anywhere),
        // while `pro` is dominated by 'ttls.professional_model' at 60s. Hence
        // min_hit_rate_by_prefix below. Do NOT raise professional_model's TTL to
        // chase the number: it is a freshness contract on the authenticated
        // user's own profile.
        'slo' => [
            'prefixes' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('PARTNA_CACHE_SLO_PREFIXES', 'site,pro'))
            ))),
            // Fallback for tracked prefixes with no entry in the map below.
            // Prefixes are open-ended — RecordCacheMetrics::extractPrefix()
            // derives one from any cache key's first segment — so this must stay.
            'min_hit_rate' => (float) env('PARTNA_CACHE_SLO_MIN_HIT_RATE', 0.9),
            // Per-prefix targets, derived from each prefix's dominant TTL. `pro`
            // sits ~7 points under its worst observed ceiling (~87% on dev) so the
            // alert has room to distinguish a real regression from traffic
            // variance; it is a defensible floor, not an exact ceiling.
            'min_hit_rate_by_prefix' => [
                'site' => (float) env('PARTNA_CACHE_SLO_MIN_HIT_RATE_SITE', 0.90),
                'pro' => (float) env('PARTNA_CACHE_SLO_MIN_HIT_RATE_PRO', 0.80),
            ],
            // Reads (hits + misses) a bucket needs before it is judged at all.
            // Deliberately NOT per-prefix: this is a statistical noise floor, not
            // a TTL-derived ceiling, so one value serves every prefix.
            'min_sample' => (int) env('PARTNA_CACHE_SLO_MIN_SAMPLE', 10),
        ],

        // cache-gold-standard §2.3: run the SWR lock-winner's recompute after the
        // response (defer()) instead of in-request, so no visitor pays the rebuild.
        // Ops kill-switch only — set false to restore the synchronous behaviour
        // without a deploy. Ignored in console/worker contexts, which always
        // recompute synchronously (see Concerns\DefersRecompute).
        'swr_defer_recompute' => (bool) env('PARTNA_CACHE_SWR_DEFER_RECOMPUTE', true),

        // CFG-3: CDN/edge TTL (seconds) for AddPublicCacheHeaders' allow-listed public
        // GET responses. Drives both max-age and s-maxage on the Cache-Control header.
        'public_max_age' => (int) env('PARTNA_CACHE_PUBLIC_MAX_AGE', 900), // 15 min

        // CFG-3 (public-surface audit): Cache-Control max-age for the alias→
        // canonical 301 redirects in PublicSiteController::show()/showByHeader().
        // An un-timed 301 is cached heuristically (often "forever") by several
        // browsers, which can strand a returning visitor on a since-renamed or
        // later-reclaimed subdomain — this bounds the redirect to a re-check window.
        'alias_redirect_max_age' => (int) env('PARTNA_CACHE_ALIAS_REDIRECT_MAX_AGE', 300), // 5 min

        // Absolute offsets, in seconds FROM THE PRIMARY PURGE, at which follow-up
        // purges land. Not per-hop delays: the primary dispatches all of them
        // up-front, each with its own delay and depth. Each must clear the sum of
        // the payload staleness windows (Laravel Cloud edge s-maxage + the Worker
        // subrequest cacheTtl) for a visitor who raced the primary purge; the
        // later entries exist for a degraded-Cloudflare window where the earlier
        // ones fail. Every entry MUST exceed CloudflareCachePurgeJob's follow-up
        // $uniqueFor (30) or a follow-up would coalesce into its own predecessor.
        'purge_followup_schedule' => [120, 300, 900],

        // cache-edge-reconcile/LIFE-1 residual: purgeHandle() enumerated URL count
        // above which CloudflarePurgeService logs a warning — makes a catalog
        // (shop/menu/events) approaching the practical purge ceiling (chunking +
        // job timeout budget) visible before it starts failing outright.
        'purge_url_volume_warning_threshold' => (int) env('PARTNA_CACHE_PURGE_URL_VOLUME_WARNING_THRESHOLD', 900),

        // R3-CACHE-1: ops lever for ReconcilePlatformTakedownJob's purge fan-out.
        // 0 (default) = off — the cloudflare_bulk lane's strict-priority
        // isolation already delivers the "never competes with real-time
        // purges" guarantee on its own; this only matters if the bulk lane
        // itself needs slowing down further. Derivation for a non-zero value
        // (~15s): the bulk lane runs at ~1 effective concurrency (last of 12
        // under balance=>false), each primary purge + its follow-up costs
        // ~6-10s, so a ~15s spacing keeps the lane's ready list near-empty.
        'bulk_purge_stagger_seconds' => (int) env('PARTNA_CACHE_BULK_PURGE_STAGGER_SECONDS', 0),

        // min($index * stagger, $cap): everything past cap/stagger dispatches
        // at the same instant (a deliberate tail-flood — harmless on the
        // lowest-priority lane). Cap only bounds how late a compliance purge
        // can land.
        'bulk_purge_max_delay_seconds' => (int) env('PARTNA_CACHE_BULK_PURGE_MAX_DELAY_SECONDS', 3600),

        // Distinct-subdomain count in one takedown run above which
        // ReconcilePlatformTakedownJob logs a warning — visibility only, no
        // continuation/self-redispatch loop (see the job's docblock).
        'bulk_purge_volume_warning_threshold' => (int) env('PARTNA_CACHE_BULK_PURGE_VOLUME_WARNING_THRESHOLD', 1000),

        'ttls' => [
            'public_payload' => (int) env('PARTNA_CACHE_TTL_PUBLIC_PAYLOAD', env('CACHE_TTL_PUBLIC_PAYLOAD', 900)),                                 // 15m
            'analytics_short' => (int) env('PARTNA_CACHE_TTL_ANALYTICS_SHORT', env('CACHE_TTL_ANALYTICS_SHORT', 300)),                             // 5m
            'auth_id_lookup' => (int) env('PARTNA_CACHE_TTL_AUTH_ID_LOOKUP', env('CACHE_TTL_AUTH_ID_LOOKUP', 1800)),                               // 30m
            'professional_model' => (int) env('PARTNA_CACHE_TTL_PROFESSIONAL_MODEL', env('CACHE_TTL_PROFESSIONAL_MODEL', 60)),                     // 60s
            'professional_handle_lookup' => (int) env('PARTNA_CACHE_TTL_PROFESSIONAL_HANDLE_LOOKUP', env('CACHE_TTL_PROFESSIONAL_HANDLE_LOOKUP', 3600)), // 60m
            'webhook_idempotency' => (int) env('PARTNA_CACHE_TTL_WEBHOOK_IDEMPOTENCY', env('CACHE_TTL_WEBHOOK_IDEMPOTENCY', 86400)),               // 24h
            'email_brand' => (int) env('PARTNA_CACHE_TTL_EMAIL_BRAND', 86400),                                                                     // 24h

            // CFG-1/WHK-2: Standard Webhooks replay-tolerance window — shared by
            // StandardWebhookVerifier::TIMESTAMP_TOLERANCE (signature check) and
            // SupabaseEmailHookController's dedup TTL, which must stay >= the
            // tolerance or a signature-valid replay could re-acquire the dedup
            // anchor and re-queue an already-sent auth email. One key so the two
            // can't drift apart.
            'webhook_timestamp_tolerance' => (int) env('PARTNA_CACHE_TTL_WEBHOOK_TIMESTAMP_TOLERANCE', env('CACHE_TTL_WEBHOOK_TIMESTAMP_TOLERANCE', 300)), // 5m

            // CFG-1/CFG-2 (staff-api audit): staff ops-dashboard TTLs, previously
            // hardcoded class constants (StaffAggregateAnalyticsController,
            // StaffStatsController). Values unchanged.
            'staff_aggregate_analytics_summary' => (int) env('PARTNA_CACHE_TTL_STAFF_AGGREGATE_ANALYTICS_SUMMARY', 60),
            'staff_ops_stats' => (int) env('PARTNA_CACHE_TTL_STAFF_OPS_STATS', 60),

            // CFG-2 (public-surface audit): browser/CDN cache lifetime for
            // QrCodeController's SVG response. Value unchanged.
            'qr_code_svg' => (int) env('PARTNA_CACHE_TTL_QR_CODE_SVG', 86400), // 24h
        ],
    ],

    // CFG-3: DB-query caps for CloudflarePurgeService::purgeHandle()'s enrichment
    // lookups (product/menu-item/event detail pages). Tunable without a redeploy;
    // raising any of these also raises purgeHandle()'s worst-case URL count — see
    // the CHUNK_PACING_MICROSECONDS docblock in CloudflarePurgeService for the
    // full budget derivation. array_chunk(..., 30) in purgeUrls() is NOT here —
    // that's Cloudflare's hard API ceiling, not a tunable cap.
    'cloudflare_purge' => [
        'products_limit' => (int) env('PARTNA_CLOUDFLARE_PURGE_PRODUCTS_LIMIT', 100),
        'menu_items_limit' => (int) env('PARTNA_CLOUDFLARE_PURGE_MENU_ITEMS_LIMIT', 150),
        'event_connections_limit' => (int) env('PARTNA_CLOUDFLARE_PURGE_EVENT_CONNECTIONS_LIMIT', 30),
        'event_ids_limit' => (int) env('PARTNA_CLOUDFLARE_PURGE_EVENT_IDS_LIMIT', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bot Protection
    |--------------------------------------------------------------------------
    | Provider-agnostic CAPTCHA verification for public mutation endpoints.
    | See docs/superpowers/specs/2026-05-26-bot-protection-foundation-design.md
    */

    'bot_protection' => [
        'driver' => env('BOT_PROTECTION_DRIVER', 'null'),       // null | turnstile | hcaptcha | fake
        'mode' => env('BOT_PROTECTION_MODE', 'off'),          // off | shadow | enforce
        'fail_open' => (bool) env('BOT_PROTECTION_FAIL_OPEN', false),

        'enforce_timeout_ms' => 3000, // SCALE-1: evaluated, kept — see VerifyBotToken::handle()
        'shadow_timeout_ms' => 500,

        'circuit_breaker' => [
            'failure_threshold' => (int) env('BOT_PROTECTION_CB_FAILURE_THRESHOLD', 5),
            'window_seconds' => (int) env('BOT_PROTECTION_CB_WINDOW_SECONDS', 60),
            'cooldown_seconds' => (int) env('BOT_PROTECTION_CB_COOLDOWN_SECONDS', 300),
        ],

        'drivers' => [
            'turnstile' => [
                'site_key' => env('TURNSTILE_SITE_KEY'),
                'secret' => env('TURNSTILE_SECRET'),
                'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            ],
            'hcaptcha' => [
                'site_key' => env('HCAPTCHA_SITE_KEY'),
                'secret' => env('HCAPTCHA_SECRET'),
                'verify_url' => 'https://api.hcaptcha.com/siteverify',
            ],
            'null' => [],
            'fake' => [],
        ],

        // Cloudflare-published test keys — refused by boot guard in production.
        'known_test_site_keys' => [
            '1x00000000000000000000AA',
            '2x00000000000000000000AB',
            '3x00000000000000000000FF',
        ],
    ],

    'moderation' => [
        'enabled' => (bool) env('PARTNA_MODERATION_ENABLED', true),
        // DINT-6 / PRIV: retention window (days) for non-account reporter PII on
        // case_signals whose parent case has been resolved. Pruned weekly by
        // moderation:prune-resolved-signal-pii.
        'signal_pii_retention_days' => (int) env('PARTNA_MODERATION_SIGNAL_PII_RETENTION_DAYS', 90),
        // Emergency kill-switch for automated enforcement (e.g. the CSAM
        // auto-action pipeline). When false, CSAM matches still preserve
        // evidence + file the NCMEC CyberTip, but the automated suspend/
        // quarantine/hide decision is NOT dispatched — the case is left open
        // for manual staff handling and a critical breadcrumb is logged.
        // Default true; flip to false only during an incident.
        'auto_actions_enabled' => (bool) env('PARTNA_MODERATION_AUTO_ACTIONS_ENABLED', true),
        // RV-10: per-recipient send-dedup marker TTL for the Notify* jobs
        // (DedupesRecipientSends). Must outlive every automatic retry of the
        // same job — tries=3, backoff=[10,30,60], timeout up to 60s, plus the
        // queue's stalled-reservation requeue window — worst case ~19 minutes.
        // 7 days sits an order of magnitude above that with room for a human
        // to re-run a stranded entry.
        'notify_send_marker_ttl_seconds' => (int) env('PARTNA_MODERATION_NOTIFY_MARKER_TTL', 604_800), // 7d
        'reporting' => [
            'public_throttle' => [
                'requests' => (int) env('PARTNA_REPORT_PUBLIC_THROTTLE_REQUESTS', 5),
                'minutes' => (int) env('PARTNA_REPORT_PUBLIC_THROTTLE_MINUTES', 1),
            ],
            'per_target_throttle' => [
                'requests' => (int) env('PARTNA_REPORT_TARGET_THROTTLE_REQUESTS', 3),
                'window_minutes' => (int) env('PARTNA_REPORT_TARGET_THROTTLE_WINDOW_MIN', 60),
            ],
            'details_max_chars' => 4000,
            'merge_window_minutes' => 60 * 24 * 7,
            'staff_notify_thresholds' => [1, 3, 5, 10],
        ],
        // Dedicated Horizon queue lane for high-priority moderation jobs (suspend, notify on-call).
        // Isolated from the default queue so a moderation burst doesn't starve other workers.
        'queue' => [
            'high_priority_lane' => env('PARTNA_MODERATION_HIGH_LANE', 'moderation_high'),
        ],
        // SLA breach thresholds per severity level (hours). severity_5 = most urgent.
        // breach_warning_min: minutes before the SLA deadline at which to emit an early warning.
        'sla' => [
            'severity_5_hours' => 1,
            'severity_4_hours' => 4,
            'severity_3_hours' => 24,
            'severity_2_hours' => 72,
            'severity_1_hours' => 168,
            'breach_warning_min' => 120,
        ],
    ],

    'analytics' => [
        // CFG-5: LogLeadRateLimits dedup window — auto-retry bursts (browsers firing 2-3
        // retries on one rate-limit hit) within this many seconds collapse into one
        // analytics.lead_submissions row, keyed by (ip_hash, subdomain).
        'lead_rate_limit_dedup_seconds' => (int) env('PARTNA_ANALYTICS_LEAD_RATE_LIMIT_DEDUP_SECONDS', 10),

        // CFG-1: RecordAnalyticsEventJob hygiene. Typed properties can't call config()
        // in their initialiser, so these are read in the job's constructor instead —
        // JobHygienePolicyTest only requires the properties to be *declared*, not
        // assigned a literal default, so this stays config-driven without changing shape.
        'job_tries' => (int) env('PARTNA_ANALYTICS_JOB_TRIES', 3),
        'job_backoff_seconds' => (int) env('PARTNA_ANALYTICS_JOB_BACKOFF_SECONDS', 10),
        'job_timeout_seconds' => (int) env('PARTNA_ANALYTICS_JOB_TIMEOUT_SECONDS', 30),

        // CFG-1: AnalyticsCacheService::summary() cache-key contract TTLs — 5min while
        // today's data is still live, 24h once the range is fully historical.
        'summary_ttl_today_seconds' => (int) env('PARTNA_ANALYTICS_SUMMARY_TTL_TODAY_SECONDS', 300),
        'summary_ttl_historical_seconds' => (int) env('PARTNA_ANALYTICS_SUMMARY_TTL_HISTORICAL_SECONDS', 86400),
        // CFG-1: staffSummary()'s cache-key contract TTL (CACHE-3 guard) — short-lived,
        // the staff view has no hourly toggle so a stale minute is low-stakes.
        'staff_summary_ttl_seconds' => (int) env('PARTNA_ANALYTICS_STAFF_SUMMARY_TTL_SECONDS', 60),

        // CFG-1 / CCH-1: AnalyticsCacheService::bumpVersion() debounce window — at most
        // one summary-version bump per user per this many seconds. Jittered ±20% (see
        // JitteredTtl) so a burst of ingests across many users doesn't re-bump in lockstep.
        'ingest_debounce_seconds' => (int) env('PARTNA_ANALYTICS_INGEST_DEBOUNCE_SECONDS', 30),

        // CFG-1: public ingest dedup TTLs (AnalyticsController). Click window is short
        // (rapid double-taps); section/item dedup windows are long (one view per visit).
        'click_dedup_ttl_seconds' => (int) env('PARTNA_ANALYTICS_CLICK_DEDUP_TTL_SECONDS', 3),
        'section_dedup_ttl_seconds' => (int) env('PARTNA_ANALYTICS_SECTION_DEDUP_TTL_SECONDS', 300),
        'item_dedup_ttl_seconds' => (int) env('PARTNA_ANALYTICS_ITEM_DEDUP_TTL_SECONDS', 300),

        // CFG-1: PurgeRawAnalyticsEvents batch delete size — bounds each DELETE's row
        // count so the purge never holds one long-running transaction.
        'purge_batch_size' => (int) env('PARTNA_ANALYTICS_PURGE_BATCH_SIZE', 10_000),

        // PRIV-1: visitor lat/long precision at the PostgresEventWriter::visitRow()
        // write boundary. 2dp ≈ 1.1km — enough for city/region rollups, not enough to
        // locate a person. (DetectsClientInfo::parseCoordinate() already rounds to 4dp
        // at ingest; this is a second, coarser cut at the persistence boundary so the
        // guarantee holds regardless of ingest path.)
        'location_precision_decimals' => (int) env('PARTNA_ANALYTICS_LOCATION_PRECISION_DECIMALS', 2),

        // PRIV-4: content_popularity_scores is a DERIVED table (ComputeContentPopularityScores
        // upserts a row per site/content-key every run) — gets its OWN retention window
        // rather than reusing analytics_raw_event_retention_days above. A site with no raw
        // events in the last hour is never rescanned (that command's own 60-min
        // RECENT_EVENTS_WINDOW_MINUTES scope), so a dormant site's rows would otherwise sit
        // forever at their last computed_at with no other purge path. Default (180d) is
        // double that command's own HALF_LIFE_DAYS (90) — long past the point a stored
        // score means anything.
        'content_popularity_scores_retention_days' => (int) env('PARTNA_ANALYTICS_CONTENT_POPULARITY_SCORES_RETENTION_DAYS', 180),

        // API-5: ?group_by=hour is forced regardless of the requested range (up to the
        // 730-day cap). Beyond this many days the hourly bucket count is never usefully
        // rendered, so UserAnalyticsController::summary() clamps the LOOKBACK to this
        // window instead of the full range.
        'hourly_bucket_max_days' => (int) env('PARTNA_ANALYTICS_HOURLY_BUCKET_MAX_DAYS', 7),

        // CFG-1 (user-api audit): UserAnalyticsController::summary() date-range
        // clamping constants. max_days_all_time caps the ?days= request param;
        // max_window_days is the hard server-side clamp on the resolved from/to
        // span; default_hourly_lookback_hours is both the implicit "hour" grouping
        // window (no from/to given) and the cutoff used to decide whether a range
        // qualifies for hourly buckets.
        'max_days_all_time' => (int) env('PARTNA_ANALYTICS_MAX_DAYS_ALL_TIME', 3650),
        'max_window_days' => (int) env('PARTNA_ANALYTICS_MAX_WINDOW_DAYS', 730),
        'default_hourly_lookback_hours' => (int) env('PARTNA_ANALYTICS_DEFAULT_HOURLY_LOOKBACK_HOURS', 24),

        // CFG-2 (user-api audit): DevInsightsController's daily-series lookback
        // window. Dev/testing endpoint only (GET /api/professional/dev-insights).
        'dev_insights_series_days' => (int) env('PARTNA_ANALYTICS_DEV_INSIGHTS_SERIES_DAYS', 30),

        // Section-key → display label for the analytics "top sections" chart. Add a new
        // architecture section here — no code deploy needed. Unknown keys fall back to a
        // humanized version of the raw key in AnalyticsQueryService::sectionTitle().
        'section_titles' => [
            // Architecture sitepage sections (v2 tracker keys).
            'home' => 'Home', 'shop' => 'Shop', 'music' => 'Music', 'podcast' => 'Podcast',
            'watch' => 'Watch', 'book' => 'Book', 'events' => 'Events', 'document' => 'Document',
            'subscribe' => 'Subscribe', 'socials' => 'Socials', 'links' => 'Links', 'about' => 'About',
            // Legacy block-era keys.
            'gallery' => 'Gallery of Work', 'services' => 'Services & Pricing', 'booking' => 'Booking',
            'documents' => 'File Preview', 'newsletter' => 'Newsletter', 'contact' => 'Contact',
            'contacts_collection' => 'Contacts', 'barbershop_info' => 'Barbershop Info',
        ],

        // Page-id → display label for the "page views" metric + insight headlines.
        // Keyed by the 15 canonical page-ids (App\Enums\SitepageId) that
        // section_keys fold into via SitepageId::SECTION_KEY_TO_PAGE — the
        // page-model successor to section_titles above (stored section_keys are
        // immutable; folding + relabelling happens at the query layer only).
        // Unknown page-ids fall back to a humanized key in
        // AnalyticsQueryService::pageTitle().
        'page_titles' => [
            'home' => 'Home', 'listen' => 'Listen', 'watch' => 'Watch', 'shop' => 'Shop',
            'menu' => 'Menu', 'book' => 'Book', 'reservations' => 'Reservations',
            'events' => 'Events', 'gallery' => 'Gallery', 'reviews' => 'Reviews',
            'documents' => 'Documents', 'contact' => 'Contact',
            'strava' => 'Strava', 'skool' => 'Skool', 'links' => 'Links',
        ],
    ],
];
