<?php

use App\Mail\Notifications\FeatureAnnouncementMail;
use App\Mail\Notifications\IncidentMail;
use App\Mail\Notifications\PolicyUpdateMail;
use App\Mail\Notifications\ProfileTaskMail;
use App\Services\Platforms\DoorDashMenuDriver;
use App\Services\Platforms\UberEatsMenuDriver;

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
        'pinterest',
        'threads',
        'discord',
        'reddit',
        'telegram',
        'whatsapp',
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
        'pinterest' => [
            'display_name' => 'Pinterest',
            'icon_key' => 'pinterest',
            'placeholder' => 'yourname',
            'handle_pattern' => '/^[a-zA-Z0-9_-]{3,30}$/',
            'url_template' => 'https://pinterest.com/{handle}',
            'host_allowlist' => ['pinterest.com', 'www.pinterest.com'],
            'url_path_extractor' => '#^/([a-zA-Z0-9_-]{3,30})/?$#',
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
    // Merged with the affiliate's settings.subject_options at render and
    // submission-validation time. Affiliates can extend but not remove in v1.
    'contact_subject_defaults' => [
        'General enquiry',
        'Booking',
        'Press',
        'Collaboration',
        'Other',
    ],

    'waitlist' => [
        'enabled' => (bool) env('PARTNA_WAITLIST_ENABLED', env('SIDEST_WAITLIST_ENABLED', false)),
        // PRIV-8: hard-delete non-converting applicant rows older than this window.
        'retention_days' => (int) env('PARTNA_WAITLIST_RETENTION_DAYS', 730),
        'types' => [
            'influencer' => 'Influencer',
            'professional' => 'Professional',
            'other' => 'Other',
        ],
        'industries' => [
            'mens_grooming' => 'Mens Grooming',
            'womens_haircare' => 'Womens Haircare',
            'beauty_products' => 'Beauty Products',
            'vitamins_and_supplements' => 'Vitamins and Supplements',
            'services_and_software' => 'Services and Software',
            'other' => 'Other',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Account type defaults – applied during registration
    |----------------------------------------------------------------------
    | All active accounts are `individual`. Config is flat — no `inherits` key.
    */
    'account_type_defaults' => [
        'individual' => [
            // NOTE: new block types MUST be appended at the end of the list.
            // syncAllowedSections iterates this array and writes sort_order =
            // index, and a unique index on (site_id, block_group, sort_order)
            // where block_group = 'sections' rejects any re-packing that would
            // momentarily shift an existing row's sort_order onto another's.
            // Placing new block types at the tail keeps existing rows at
            // their stored indices.
            'allowed_sections' => ['services', 'gallery', 'booking', 'contacts_collection', 'barbershop_info', 'documents', 'newsletter', 'contact'],
            'default_sections' => ['services', 'gallery'],
            'is_published' => true,
            'allowed_theme_count' => 3,
            'custom_links_allowed' => true,
            'default_contact' => [
                'full_name' => 'Charlie',
                'email' => 'charlie@ai.com',
                'phone' => '1234 567 890',
                'source' => 'system_default',
                'subscribed' => true,
            ],
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

    // §28.14 CFG-1 — when true, individual signups (non-brand, no invite_token, no
    // brand_signup_code) are diverted to a waitlist row instead of creating a
    // Professional. Default false (fail-closed). Read via config() — never env()
    // directly — so `php artisan config:cache` is respected.
    'individual_waitlist_enabled' => (bool) env('SIDEST_INDIVIDUAL_WAITLIST_ENABLED', false),

    // Rate-limit config for the individual public profile endpoint (§28.8 CFG-3).
    // Tunable at runtime via env without redeploy. Values are per-IP per-minute.
    'public_profile' => [
        'rate_limit_per_minute' => (int) env('SIDEST_RATE_LIMIT_PUBLIC_PROFILE_PER_MINUTE', 60),
        // Short-TTL resolve-map window (handle → IDs). Bounded staleness without
        // mutation-driven invalidation; low enough to keep rename lag imperceptible.
        'resolve_cache_ttl' => (int) env('SIDEST_PUBLIC_PROFILE_RESOLVE_CACHE_TTL', 30),
        // Slow-request threshold for the Nightwatch P95 warning. Tune up if
        // builder is legitimately slow on cold paths; tune down to tighten alerting.
        'slow_request_threshold_ms' => (int) env('SIDEST_PUBLIC_PROFILE_SLOW_REQUEST_THRESHOLD_MS', 1000),
        // 60s edge TTL for the CacheLockService::rememberLocked payload.
        'cache_ttl_seconds' => (int) env('SIDEST_PUBLIC_PROFILE_CACHE_TTL', 60),
        // Analytics endpoint exposed to the skeleton via data.publicConfig.
        // partna-pages reads this and uses it for client-side beacons.
        // Defaults to the current APP_URL so the fallback is always environment-correct.
        'analytics_endpoint' => env(
            'PARTNA_PUBLIC_ANALYTICS_ENDPOINT',
            rtrim(config('app.url'), '/').'/api/analytics'
        ),
    ],

    // Version token for the design_kits column-list cache
    // (CacheKeyGenerator::designKitColumns). Bump this in the SAME migration PR
    // that adds or drops a site.design_kits column — the old cache key orphans
    // and TTLs out, so picking up the new column set needs no `artisan
    // cache:clear`. (LIFE-2)
    'design_kit_columns_version' => (int) env('PARTNA_DESIGN_KIT_COLUMNS_VERSION', 1),

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
                'text' => 'text',      // text scale (text_xs, text_sm, ...)
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
        // Per-request HTTP client timeout (seconds).
        'timeout_seconds' => (int) env('PARTNA_HTTP_FETCH_TIMEOUT_SECONDS', 8),
        // Max redirect hops followed; each hop is re-validated for SSRF before
        // being followed (SafeUrlFetcher::fetch() / fetchMany()).
        'max_redirects' => (int) env('PARTNA_HTTP_FETCH_MAX_REDIRECTS', 5),
    ],

    /*
    |----------------------------------------------------------------------
    | Brand scanner (website style analysis, self-hosted)
    |----------------------------------------------------------------------
    | WebsiteStyleAnalyzer v2 renders target sites in real Chrome via the
    | partna-brand-scan Worker (Cloudflare Browser Run) with an injected
    | evidence collector. Enabled defaults true but the client fails closed
    | (analysis ok:false → design presets abstain) until URL + token are set,
    | so this is safe to ship unconfigured. Timeout is the HTTP budget; the
    | in-page navigation budget is capped separately by the client.
    */
    'brand_scan' => [
        'enabled' => (bool) env('PARTNA_BRAND_SCAN_ENABLED', true),
        'url' => env('PARTNA_BRAND_SCAN_URL', ''),
        'token' => env('PARTNA_BRAND_SCAN_TOKEN', ''),
        'timeout' => (int) env('PARTNA_BRAND_SCAN_TIMEOUT', 40),
        // In-browser navigation budget (ms) sent to the Worker as `timeoutMs` —
        // the HTTP `timeout` above adds headroom on top of this (BrandScanClient).
        'page_timeout_ms' => (int) env('PARTNA_BRAND_SCAN_PAGE_TIMEOUT_MS', 25_000),

        // EvidenceConclusions confidence-engine thresholds. Values are policy
        // tuning, not secrets — env-overridable so ops can adjust strictness
        // without a redeploy.
        'confidence' => [
            // Signals below this confidence are omitted from the output entirely
            // (see EvidenceConclusions::conclude() — an absent key IS "no confident
            // conclusion", never a wrong value).
            'min_confidence' => (float) env('PARTNA_BRAND_SCAN_MIN_CONFIDENCE', 0.5),
            // Colours within this RGB euclidean distance corroborate each other.
            'agree_dist' => (float) env('PARTNA_BRAND_SCAN_AGREE_DIST', 40.0),
            // Accent candidates within this distance merge into one cluster.
            'cluster_dist' => (float) env('PARTNA_BRAND_SCAN_CLUSTER_DIST', 30.0),
        ],

        // ScreenshotSampler pixel-evidence thresholds.
        'sampler' => [
            // Edge samples must concentrate this hard into one histogram bucket
            // before modalEdgeColor() calls a dominant background colour.
            'bg_modality_min' => (float) env('PARTNA_BRAND_SCAN_BG_MODALITY_MIN', 0.55),
            // Minimum saturated pixel samples before saturatedCluster() will
            // nominate an accent corroborator at all.
            'accent_min_samples' => (int) env('PARTNA_BRAND_SCAN_ACCENT_MIN_SAMPLES', 200),
            // Share of saturated samples the top cluster must hold to qualify.
            'accent_cluster_min_share' => (float) env('PARTNA_BRAND_SCAN_ACCENT_CLUSTER_MIN_SHARE', 0.30),
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Video uploads – feature flag + processing config
    |----------------------------------------------------------------------
    | Set PARTNA_VIDEO_UPLOADS_ENABLED=true only after dedicated video
    | workers are running on the "videos" queue.
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
    'video_uploads_enabled' => (bool) env('PARTNA_VIDEO_UPLOADS_ENABLED', env('SIDEST_VIDEO_UPLOADS_ENABLED', false)),

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
    ],

    // Platform connection refresh (SCALE-1). The dispatcher (integrations:refresh)
    // selects connections due per default_ttl_seconds (or the descriptor override)
    // and fans out RefreshConnectionJob; per-provider rate_limits cap outbound
    // pressure; the backlog command alarms when too many fall overdue.
    'refresh' => [
        // Default re-fetch cadence when a platform declares no descriptor override.
        // 86400 preserves the previous daily cadence.
        'default_ttl_seconds' => (int) env('PARTNA_REFRESH_DEFAULT_TTL', 86400),

        // Circuit breaker: skip connections at/above this many consecutive failures
        // (a dead account stops consuming refresh capacity). Reset to 0 on any
        // successful refresh by ScheduledRefresh::run().
        'max_consecutive_failures' => (int) env('PARTNA_REFRESH_MAX_FAILURES', 10),

        // Per-provider outbound rate limit (requests/minute) for the refresh queue,
        // keyed by platform key; falls back to 'default'. Enforced by the
        // 'platform-refresh' RateLimiter (cache-backed → Redis in prod → shared
        // across ALL workers, so the cap is global, not per-process).
        'rate_limits' => [
            'default' => (int) env('PARTNA_REFRESH_RATE_DEFAULT', 60),
            // e.g. 'google-business' => 30,
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
    ],

    'notifications' => [
        'email_enabled' => (bool) env('PARTNA_NOTIFICATIONS_EMAIL_ENABLED', env('NOTIFICATIONS_EMAIL_ENABLED', false)),

        // DINT-1 / PRIV-7 Gap 2: how long to retain an unsubscribed email_subscriptions row
        // before HARD-DELETING it. email and email_lc are both NOT NULL and email_lc is itself
        // PII, so there is no PII-free skeleton to keep — the whole row goes once consent has
        // been withdrawn for this window. Child broadcast_email_receipts cascade via the DINT-2
        // FK; a later re-subscribe is a fresh double-opt-in, not a reactivation of this row.
        'unsubscribed_retention_days' => (int) env('PARTNA_UNSUBSCRIBED_RETENTION_DAYS', 365),

        // Max jobs per Bus::batch() sub-chunk for fan-out paths. Bounds the
        // size of a single Redis pipeline write so a large affiliate / staff
        // broadcast list can't spike Redis memory. Shared between
        // FanOutBrandStatusNotificationJob and SendStaffBroadcastEmailsJob.
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
    ],

    /*
    |----------------------------------------------------------------------
    | Launch feature flags
    |----------------------------------------------------------------------
    | Master switches for functionality that's coded but not yet live.
    | All default to false; flip in .env once the feature is ready.
    |
    | smart_booking  — gates all /booking/* routes (professional, public,
    |                  analytics) and forbids selecting booking_mode='smart'.
    |                  When off, only manual booking (redirect link) works.
    | square_sync    — gates Square integration (/square/* routes, webhook,
    |                  observer dispatch, sync jobs).
    | fresha_sync    — gates Fresha integration (/fresha/* routes, webhook,
    |                  observer dispatch, sync jobs).
    |
    | Square/Fresha ONLY power smart booking — if smart_booking is off, their
    | flags are largely redundant but kept separate so we can enable one
    | provider before the other post-launch.
    */
    'features' => [
        'smart_booking' => (bool) env('PARTNA_SMART_BOOKING_ENABLED', env('SIDEST_SMART_BOOKING_ENABLED', false)),
        'square_sync' => (bool) env('PARTNA_SQUARE_SYNC_ENABLED', env('SIDEST_SQUARE_SYNC_ENABLED', false)),
        'fresha_sync' => (bool) env('PARTNA_FRESHA_SYNC_ENABLED', env('SIDEST_FRESHA_SYNC_ENABLED', false)),
    ],

    /*
    |--------------------------------------------------------------------------
    | GDPR
    |--------------------------------------------------------------------------
    |
    | Config for Shopify GDPR webhook handlers. Jobs dispatch onto a dedicated
    | queue so they don't contend with the default worker on a mature shop
    | (RedactShopJob can take several minutes). The placeholder domain is used
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
    ],

    'cache' => [
        'ttls' => [
            'public_payload' => (int) env('PARTNA_CACHE_TTL_PUBLIC_PAYLOAD', env('CACHE_TTL_PUBLIC_PAYLOAD', 900)),                                 // 15m
            'analytics_short' => (int) env('PARTNA_CACHE_TTL_ANALYTICS_SHORT', env('CACHE_TTL_ANALYTICS_SHORT', 300)),                             // 5m
            'auth_id_lookup' => (int) env('PARTNA_CACHE_TTL_AUTH_ID_LOOKUP', env('CACHE_TTL_AUTH_ID_LOOKUP', 1800)),                               // 30m
            'professional_model' => (int) env('PARTNA_CACHE_TTL_PROFESSIONAL_MODEL', env('CACHE_TTL_PROFESSIONAL_MODEL', 60)),                     // 60s
            'professional_handle_lookup' => (int) env('PARTNA_CACHE_TTL_PROFESSIONAL_HANDLE_LOOKUP', env('CACHE_TTL_PROFESSIONAL_HANDLE_LOOKUP', 3600)), // 60m
            'webhook_idempotency' => (int) env('PARTNA_CACHE_TTL_WEBHOOK_IDEMPOTENCY', env('CACHE_TTL_WEBHOOK_IDEMPOTENCY', 86400)),               // 24h
            'email_brand' => (int) env('PARTNA_CACHE_TTL_EMAIL_BRAND', 86400),                                                                     // 24h
        ],
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
        'fail_open' => (bool) env('BOT_PROTECTION_FAIL_OPEN', true),

        'enforce_timeout_ms' => 3000,
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

        // Section-key → display label for the analytics "top sections" chart. Add a new
        // skeleton section here — no code deploy needed. Unknown keys fall back to a
        // humanized version of the raw key in AnalyticsQueryService::sectionTitle().
        'section_titles' => [
            // Skeleton sitepage sections (v2 tracker keys).
            'home' => 'Home', 'shop' => 'Shop', 'music' => 'Music', 'podcast' => 'Podcast',
            'watch' => 'Watch', 'book' => 'Book', 'events' => 'Events', 'document' => 'Document',
            'subscribe' => 'Subscribe', 'socials' => 'Socials', 'links' => 'Links', 'about' => 'About',
            // Legacy block-era keys.
            'gallery' => 'Gallery of Work', 'services' => 'Services & Pricing', 'booking' => 'Booking',
            'documents' => 'File Preview', 'newsletter' => 'Newsletter', 'contact' => 'Contact',
            'contacts_collection' => 'Contacts', 'barbershop_info' => 'Barbershop Info',
        ],
    ],
];
