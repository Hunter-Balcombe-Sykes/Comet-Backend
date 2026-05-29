<?php

use App\Mail\Notifications\FeatureAnnouncementMail;
use App\Mail\Notifications\IncidentMail;
use App\Mail\Notifications\PolicyUpdateMail;
use App\Mail\Notifications\ProfileTaskMail;

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
        // Social link tagging — set by SocialLinkNormalizer when a brand-controlled
        // platform is selected. Soft tag in JSONB rather than a column; promote to
        // a real column (Option B) only when query-ability matters. See docs/social-links.md.
        'platform',
        'handle',
        // Link category — one of config('partna.link_categories'). Required on every
        // write; resolved from the platform's default_category when not supplied.
        // Same JSONB-first rationale as `platform` above.
        'category',
        'live_check_enabled',
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

    // `contact` = visitor-submitted contact form (notification_email lives here).
    // `public_contact` = the professional's own opt-in contact details surfaced
    //                    publicly on the sitepage — distinct domain, distinct toggle.
    // `workplace`      = the professional's business / workplace card backed by
    //                    `sites.settings.google_business_profile` (Google Places-fed).
    'section_block_types' => ['gallery', 'services', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter', 'countdown', 'contact', 'public_contact', 'workplace', 'credentials', 'experience', 'bio'],

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
            // NOTE: 'bio' MUST sit at the end of the list. syncAllowedSections
            // iterates this array and writes sort_order = index, and a unique
            // index on (site_id, block_group, sort_order) where block_group =
            // 'sections' rejects any re-packing that would momentarily shift
            // an existing row's sort_order onto another's. Placing new block
            // types at the tail keeps existing rows at their stored indices.
            'allowed_sections' => ['shop', 'services', 'gallery', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter', 'countdown', 'contact', 'credentials', 'experience', 'bio'],
            'default_sections' => ['shop', 'services', 'gallery'],
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
        'enabled' => (bool) env('PARTNA_THROTTLE_ENABLED', env('SIDEST_THROTTLE_ENABLED', true)),
        // Max notification emails sent per brand inbox per hour regardless of how many enquiries arrive.
        'enquiry_notification_per_hour' => (int) env('PARTNA_ENQUIRY_NOTIFY_PER_HOUR', env('SIDEST_ENQUIRY_NOTIFY_PER_HOUR', 10)),
        // Max visitor-facing confirmation emails per recipient address per hour
        // (shared across enquiry + subscription confirmations). Public forms send
        // to attacker-controllable addresses, so this caps email-bombing.
        'visitor_confirmation_per_hour' => (int) env('PARTNA_VISITOR_CONFIRMATION_PER_HOUR', 5),
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
        // 60s edge TTL for the CacheLockService::rememberLocked payload.
        'cache_ttl_seconds' => (int) env('SIDEST_PUBLIC_PROFILE_CACHE_TTL', 60),
        // Analytics endpoint exposed to the skeleton via data.publicConfig.
        // partna-pages reads this and uses it for client-side beacons.
        // Falls back to the dev API host so dev deploys never ship a null/empty
        // endpoint that breaks the skeleton's PublicConfig contract.
        'analytics_endpoint' => env(
            'PARTNA_PUBLIC_ANALYTICS_ENDPOINT',
            'https://dev-api.partna.au/api/analytics'
        ),
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
            'failure_threshold' => 5,
            'window_seconds' => 60,
            'cooldown_seconds' => 300,
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
        'enabled' => env('PARTNA_MODERATION_ENABLED', true),
        'reporting' => [
            'public_throttle'         => [
                'requests' => (int) env('PARTNA_REPORT_PUBLIC_THROTTLE_REQUESTS', 5),
                'minutes'  => (int) env('PARTNA_REPORT_PUBLIC_THROTTLE_MINUTES', 1),
            ],
            'per_target_throttle'     => [
                'requests'        => (int) env('PARTNA_REPORT_TARGET_THROTTLE_REQUESTS', 3),
                'window_minutes'  => (int) env('PARTNA_REPORT_TARGET_THROTTLE_WINDOW_MIN', 60),
            ],
            'details_max_chars'       => 4000,
            'merge_window_minutes'    => 60 * 24 * 7,
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
            'severity_5_hours'   => 1,
            'severity_4_hours'   => 4,
            'severity_3_hours'   => 24,
            'severity_2_hours'   => 72,
            'severity_1_hours'   => 168,
            'breach_warning_min' => 120,
        ],
    ],
];
