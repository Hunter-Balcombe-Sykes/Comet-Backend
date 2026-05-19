<?php

return [
    // Shared-secret token for GET /api/internal/env-check. Required to enable
    // the endpoint. When unset, the endpoint returns 503 — fail-closed by default
    // so a fresh deploy never accidentally exposes the env-var report.
    'internal_env_check_token' => env('INTERNAL_ENV_CHECK_TOKEN'),

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
    'reserved_subdomains' => [
        'www', 'api', 'admin', 'app', 'staff', 'dashboard',
        'support', 'help', 'billing', 'static', 'cdn', 'assets',
        'auth', 'docs', 'status', 'comet', 'sidest', 'partna',
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

    'section_block_types' => ['gallery', 'services', 'shop', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter', 'countdown', 'contact', 'credentials', 'experience', 'bio'],

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

    'professional_types' => [
        'brand' => 'Brand',
        'professional' => 'Professional',
        'influencer' => 'Influencer',
    ],

    'waitlist' => [
        'enabled' => (bool) env('PARTNA_WAITLIST_ENABLED', env('SIDEST_WAITLIST_ENABLED', false)),
        'types' => [
            'influencer' => 'Influencer',
            'professional' => 'Professional',
            'brand' => 'Brand',
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
    | Brand industries – canonical taxonomy
    |----------------------------------------------------------------------
    | Controlled list of industries a brand may declare on its BrandProfile.
    | Order here is NOT semantic (display order is a frontend concern).
    | Keys are slugs used in storage + API; values are human display names.
    |
    | Additive-only: renaming a slug requires a data migration. Adding a new
    | slug is safe. See docs/brand-industries.md for the stability contract.
    */
    'brand_industries' => [
        'apparel' => 'Apparel',
        'footwear' => 'Footwear',
        'accessories' => 'Accessories',
        'skin_care' => 'Skin Care',
        'haircare' => 'Haircare',
        'makeup' => 'Makeup',
        'fragrance' => 'Fragrance',
        'mens_grooming' => "Men's Grooming",
        'health_wellness_supplements' => 'Health, Wellness & Supplements',
        'activewear_fitness' => 'Activewear & Fitness',
        'home_living' => 'Home & Living',
        'electronics_tech' => 'Electronics & Tech',
        'other' => 'Other',
    ],

    /*
    |----------------------------------------------------------------------
    | Account type defaults – applied during registration
    |----------------------------------------------------------------------
    | 'professional' is the base type. 'influencer' inherits from it.
    | 'brand' has its own distinct config.
    | 'affiliate' is an overlay applied when a professional/influencer
    | connects to a brand (via invite or manual connection).
    */
    'account_type_defaults' => [
        // Influencer is the base type (most basic account)
        'influencer' => [
            // NOTE: 'bio' MUST sit at the end of the list. syncAllowedSections
            // iterates this array and writes sort_order = index, and a unique
            // index on (site_id, block_group, sort_order) where block_group =
            // 'sections' rejects any re-packing that would momentarily shift
            // an existing row's sort_order onto another's. Placing new block
            // types at the tail keeps existing rows at their stored indices.
            'allowed_sections' => ['shop', 'services', 'gallery', 'documents', 'newsletter', 'countdown', 'contact', 'credentials', 'experience', 'bio'],
            'default_sections' => ['shop', 'services', 'gallery'],
            'is_published' => true,
            'allowed_theme_count' => 3,
            'custom_links_allowed' => false,
            'default_contact' => [
                'full_name' => 'Charlie',
                'email' => 'charlie@ai.com',
                'phone' => '1234 567 890',
                'source' => 'system_default',
                'subscribed' => true,
            ],
        ],
        // Professional inherits influencer + adds booking, analytics, custom links
        'professional' => [
            'inherits' => 'influencer',
            // 'bio' stays at the end — see the note on the influencer block
            // for why ordering here is load-bearing.
            'allowed_sections' => ['shop', 'services', 'gallery', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter', 'countdown', 'contact', 'credentials', 'experience', 'bio'],
            'default_sections' => ['shop', 'services', 'gallery'],
            'custom_links_allowed' => true,
        ],
        'brand' => [
            'allowed_sections' => ['shop', 'services', 'gallery', 'booking', 'contacts_collection', 'sitepage_analytics', 'barbershop_info', 'newsletter', 'countdown', 'contact'],
            'default_sections' => [],
            'is_published' => false,
            'allowed_theme_count' => null, // unlimited
            'custom_links_allowed' => true,
            'enforce_handle_equals_display_name' => true,
            'default_checkout_mode' => null,
            'default_site_settings' => [
                'design' => [
                    // System default colours, in-house font
                ],
            ],
        ],
        // Overlay applied when professional/influencer connects to a brand
        'affiliate' => [
            'auto_enable_sections' => ['shop'],
            'use_brand_affiliate_theme' => true,
            'use_brand_affiliate_products' => true,
        ],
    ],

    'soft_delete_retention_days' => (int) env('PARTNA_SOFT_DELETE_RETENTION_DAYS', env('SOFT_DELETE_RETENTION_DAYS', 30)),

    'analytics_raw_event_retention_days' => (int) env('PARTNA_ANALYTICS_RAW_EVENT_RETENTION_DAYS', env('ANALYTICS_RAW_EVENT_RETENTION_DAYS', 90)),

    'commerce_analytics' => [
        // SWR cache TTL (seconds) for the affiliate projections endpoint. Push-invalidated on every commerce
        // webhook write via AnalyticsCacheService::invalidateAnalytics(), so this is the upper bound on staleness
        // when no writes happen — not the typical staleness.
        'projections_ttl_seconds' => (int) env('PARTNA_COMMERCE_PROJECTIONS_TTL_SECONDS', env('COMMERCE_PROJECTIONS_TTL_SECONDS', 300)),

        // Adaptive window tiers, descending. Service picks the largest tier the affiliate has ≥ days of history for.
        // Below the smallest tier, response returns status=insufficient_data.
        'projections_window_tiers' => [90, 60, 30, 14],

        // Confidence band thresholds. CV = stddev / mean of daily net-commission within the window.
        'projections_confidence_high' => ['min_history_days' => 90, 'max_cv' => 0.5],
        'projections_confidence_medium' => ['min_history_days' => 30, 'max_cv' => 1.0],
        // Anything qualifying for the window but not above is "low".
    ],

    // How many days after the billing period ends a past_due subscription retains plan entitlements.
    // After this window, access is revoked until Stripe collects payment or cancels.
    'billing' => [
        'past_due_grace_days' => (int) env('PARTNA_PAST_DUE_GRACE_DAYS', env('SIDEST_PAST_DUE_GRACE_DAYS', 7)),
    ],

    'throttle' => [
        'enabled' => (bool) env('PARTNA_THROTTLE_ENABLED', env('SIDEST_THROTTLE_ENABLED', true)),
        // Max notification emails sent per brand inbox per hour regardless of how many enquiries arrive.
        'enquiry_notification_per_hour' => (int) env('PARTNA_ENQUIRY_NOTIFY_PER_HOUR', env('SIDEST_ENQUIRY_NOTIFY_PER_HOUR', 10)),
    ],

    // Rate-limit config for the brand signup code redemption path (§33 CFG-3).
    // Values are intentionally in config (not inline) so they can be tightened
    // under abuse without a code deploy.
    'brand_signup_code' => [
        'rate_limit' => [
            // Hard cap per IP per minute.
            'per_minute' => (int) env('PARTNA_SIGNUP_CODE_RATE_PER_MINUTE', 10),
            // Hard cap per IP per hour.
            'per_hour' => (int) env('PARTNA_SIGNUP_CODE_RATE_PER_HOUR', 100),
            // After this many failed attempts on the same IP within an hour, responses are delayed.
            'delay_after_failures' => (int) env('PARTNA_SIGNUP_CODE_DELAY_AFTER_FAILURES', 5),
            // Seconds to sleep when the delay threshold is hit.
            'delay_seconds' => (int) env('PARTNA_SIGNUP_CODE_DELAY_SECONDS', 2),
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
        // Affiliate sitepage gallery + content panels both expose 6 slots
        // in the dashboard — keep the env override available, default to 6.
        'gallery' => ['max' => (int) env('PARTNA_GALLERY_IMAGE_MAX', env('SIDEST_GALLERY_IMAGE_MAX', 6))],
        'content' => ['max' => (int) env('PARTNA_CONTENT_IMAGE_MAX', env('SIDEST_CONTENT_IMAGE_MAX', 6))],
        'product' => ['max' => (int) env('PARTNA_PRODUCT_IMAGE_MAX', env('SIDEST_PRODUCT_IMAGE_MAX', 5))],
        'brand_gallery' => ['max' => (int) env('PARTNA_BRAND_GALLERY_IMAGE_MAX', env('SIDEST_BRAND_GALLERY_IMAGE_MAX', 5))],
        'product_custom' => ['max' => (int) env('PARTNA_PRODUCT_CUSTOM_PHOTO_MAX', env('SIDEST_PRODUCT_CUSTOM_PHOTO_MAX', 1))],
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

    'hydrogen' => [
        // GitHub PAT with actions:write scope on the sidest-storefront repo.
        // Used by HydrogenDeploymentService to trigger single-brand Oxygen
        // deployments when a brand saves credentials in the wizard.
        'github_token' => env('PARTNA_HYDROGEN_GITHUB_TOKEN', env('SIDEST_HYDROGEN_GITHUB_TOKEN')),
        'github_repo' => env('PARTNA_HYDROGEN_GITHUB_REPO', env('SIDEST_HYDROGEN_GITHUB_REPO', 'hunterbalcombesykes/sidest-storefront')),
        'github_ref' => env('PARTNA_HYDROGEN_GITHUB_REF', env('SIDEST_HYDROGEN_GITHUB_REF', 'main')),
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
    | video_variants define the two MP4 output tiers.  HLS streams are
    | packaged from these MP4 files (no extra re-encode).
    */
    'video_uploads_enabled' => (bool) env('PARTNA_VIDEO_UPLOADS_ENABLED', env('SIDEST_VIDEO_UPLOADS_ENABLED', false)),

    'video_max_upload_size' => (int) env('PARTNA_VIDEO_MAX_UPLOAD_KB', env('SIDEST_VIDEO_MAX_UPLOAD_KB', 512000)), // 500 MB
    'video_max_duration_seconds' => (int) env('PARTNA_VIDEO_MAX_DURATION_SECONDS', env('SIDEST_VIDEO_MAX_DURATION_SECONDS', 300)), // 5 min

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

    'professional_only_section_types' => [
        'barbershop_info',
        'sitepage_analytics',
        'booking',
    ],

    'store' => [
        'default_commission_rate' => (float) env('PARTNA_STORE_DEFAULT_COMMISSION', env('SIDEST_STORE_DEFAULT_COMMISSION', 15)),
        'max_featured_products' => (int) env('PARTNA_STORE_MAX_FEATURED', env('SIDEST_STORE_MAX_FEATURED', 10)),
        'checkout_session_ttl_minutes' => (int) env('PARTNA_STORE_CHECKOUT_SESSION_TTL_MINUTES', env('SIDEST_STORE_CHECKOUT_SESSION_TTL_MINUTES', 120)),
        // System default applied when a brand hasn't picked a hold period yet.
        // Brands can explicitly choose 0 (instant), 7, 14, or 28 — the allowed values
        // are enforced in UpdateBrandStoreSettingsRequest. There is no longer a
        // server-side min-floor; the dropdown enumerates the only valid options.
        'payout_hold_days' => (int) env('PARTNA_STORE_PAYOUT_HOLD_DAYS', env('SIDEST_STORE_PAYOUT_HOLD_DAYS', 7)),
        'platform_fee_percent' => (float) env('PARTNA_STORE_PLATFORM_FEE_PERCENT', env('SIDEST_STORE_PLATFORM_FEE_PERCENT', 20)),
        // Signup grace deadline. New affiliates get this many days after Stripe
        // Connect onboarding starts before their commissions begin voiding.
        // Used by StripeConnectService::createConnectAccount to set the
        // grace deadline on v1 (stripe_grace_period_ends_at column dropped in v2).
        'signup_grace_period_days' => (int) env('PARTNA_STORE_SIGNUP_GRACE_PERIOD_DAYS', env('SIDEST_STORE_SIGNUP_GRACE_PERIOD_DAYS', 30)),

        // Per-payout grace deadline. Each commission_payouts row's
        // void_at is set to created_at + this many days. After that,
        // if the affiliate's Stripe Connect isn't 'active', the
        // VoidExpiredPayoutsJob cancels the payout (brand keeps the
        // money — never charged).
        'payout_grace_period_days' => (int) env('PARTNA_STORE_PAYOUT_GRACE_PERIOD_DAYS', env('SIDEST_STORE_PAYOUT_GRACE_PERIOD_DAYS', 60)),

        // DEPRECATED — fallback for code that hasn't migrated to the split keys above.
        // Previously conflated "signup grace" (30d) and "payout grace" (60d) under one key,
        // which silently misconfigured one of the two whenever ops tuned the value.
        // Removed callers: StripeConnectService, CommissionPayoutService, VoidExpiredPayoutsJob.
        'grace_period_days' => (int) env('PARTNA_STORE_GRACE_PERIOD_DAYS', env('SIDEST_STORE_GRACE_PERIOD_DAYS', 60)),

        'commission_void_window_days' => (int) env('PARTNA_STORE_COMMISSION_VOID_WINDOW_DAYS', env('SIDEST_STORE_COMMISSION_VOID_WINDOW_DAYS', 30)),
    ],

    'payouts' => [
        // Cap on rows materialised by /stripe/payouts/{payoutId} (payoutDetail).
        // Matches the 500-row limit ExportService passes to forBrand/forAffiliate,
        // so the "export the rest" fallback is the natural overflow path. Frontend
        // reads `has_more` to render a load-more / export affordance.
        'detail_orders_limit' => (int) env('PARTNA_PAYOUTS_DETAIL_ORDERS_LIMIT', 500),
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
        'invite' => 90,
        'commission' => 365,
        'payout' => 365,
        'integration' => 60,
        'analytics_weekly' => 30,
        'analytics_milestones' => 90,
        'profile_task' => 180,
        'brand_status' => 60,
        'subscription' => 365,
        'brand_link' => 60,
        'payout_warning' => 60,
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
            'invites' => \App\Mail\Notifications\InviteNotificationMail::class,
            'commissions' => \App\Mail\Notifications\CommissionNotificationMail::class,
            'payouts' => \App\Mail\Notifications\PayoutNotificationMail::class,
            'integrations' => \App\Mail\Notifications\IntegrationNotificationMail::class,
            'analytics_weekly' => \App\Mail\Notifications\AnalyticsWeeklyMail::class,
            'analytics_milestones' => \App\Mail\Notifications\AnalyticsMilestoneMail::class,
            'profile_tasks' => \App\Mail\Notifications\ProfileTaskMail::class,
            'brand_status' => \App\Mail\Notifications\BrandStatusMail::class,
            'subscriptions' => \App\Mail\Notifications\SubscriptionMail::class,
            'brand_links' => \App\Mail\Notifications\BrandLinkMail::class,
            'payout_warnings' => \App\Mail\Notifications\PayoutWarningMail::class,
            'policy_update' => \App\Mail\Notifications\PolicyUpdateMail::class,
            'incident' => \App\Mail\Notifications\IncidentMail::class,
            'feature_announcement' => \App\Mail\Notifications\FeatureAnnouncementMail::class,
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
        // commissions + payouts used to live here as mandatory transactional
        // categories. The product chose to surface them as opt-in toggles
        // instead — the in-app notifications still always fire (so users
        // never miss money movement on the dashboard), only the EMAIL copy
        // is now silenceable. subscriptions + brand_status stay mandatory:
        // billing/account-state mail is contract-performance under GDPR
        // Art. 6(1)(b) and transactional-primary-purpose under CAN-SPAM §316.3.
        'mandatory_categories' => [
            'subscriptions',
            'brand_status',
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
        // Gates Turnstile CAPTCHA on public lead-capture endpoints. Enable only after
        // the frontend has integrated the Turnstile widget and sends cf_turnstile_response.
        'captcha' => (bool) env('PARTNA_CAPTCHA_ENABLED', env('SIDEST_CAPTCHA_ENABLED', false)),
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

    // Async commission/financial exports. The 'commission' subkey scopes the
    // transactions export (POST /stripe/exports/transactions); other exports
    // (payouts, detailed-commissions, eofy) remain synchronous and don't read these.
    'exports' => [
        'commission' => [
            'queue' => env('PARTNA_COMMISSION_EXPORT_QUEUE', 'exports'),
            'connection' => env('PARTNA_COMMISSION_EXPORT_CONNECTION', 'redis'),
            'chunk_size' => (int) env('PARTNA_COMMISSION_EXPORT_CHUNK_SIZE', 500),
            'signed_url_ttl_days' => (int) env('PARTNA_COMMISSION_EXPORT_TTL_DAYS', 3),
            'retention_days' => (int) env('PARTNA_COMMISSION_EXPORT_RETENTION_DAYS', 30),
            'dedup_window_minutes' => (int) env('PARTNA_COMMISSION_EXPORT_DEDUP_MINUTES', 5),
            'stuck_watchdog_minutes' => (int) env('PARTNA_COMMISSION_EXPORT_STUCK_MINUTES', 60),
            // Shared with the global Stripe SDK binding so the whole app pins one version.
            'stripe_api_version' => env('STRIPE_API_VERSION', '2025-02-24.acacia'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciler
    |--------------------------------------------------------------------------
    |
    | Shopify orders backstop — pulls orders updated since the last reconcile
    | timestamp and dispatches ProcessShopifyOrderWebhookJob for missing/stale
    | local rows. Cron expression is env-overridable so ops can switch to
    | hourly ('0 * * * *') for the first 60 days post-launch without a deploy.
    |
    */

    'reconciler' => [
        'schedule' => env('PARTNA_RECONCILER_SCHEDULE', env('SIDEST_RECONCILER_SCHEDULE', '0 3 * * *')),
    ],

    /*
    |----------------------------------------------------------------------
    | Cache TTL tiers (seconds)
    |----------------------------------------------------------------------
    | All env-overridable so Redis tuning during a hot-key incident or
    | memory pressure event does not require a code redeploy — just flip
    | the env var and run `php artisan config:cache`.
    |
    | Tiers:
    |   public_payload             Hot public-site read; jittered ±20% on write to
    |                              spread expiry bursts across the fleet.
    |   analytics_short            Warm analytics/affiliate-status reads; push-
    |                              invalidated on every write so staleness is bounded.
    |   auth_id_lookup             Immutable Supabase auth UUID → professional_id mapping
    |                              plus profile/services/settings caches (same tier).
    |   professional_model         Hydrated Eloquent model on every authenticated request
    |                              (auth hot path — short TTL, SWR-backed).
    |   professional_handle_lookup Handle → id and payload reads; tuned separately from
    |                              the auth path because they serve different traffic.
    |   webhook_idempotency        Dedup window for inbound webhooks; prevents a Shopify
    |                              retry from processing the same event twice.
    |   brand_admin_catalog        Shopify product catalog fetched for order-commission
    |                              resolution; push-invalidated on catalog webhooks.
    |   collection_gid             Shopify collection GID lookups; long-lived stable data.
    |   product_custom_photos      Per-affiliate custom product photo flags; hot reads on
    |                              every order item, short TTL to limit stale-state window.
    */
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
    ],

    'cache' => [
        'ttls' => [
            'public_payload' => (int) env('PARTNA_CACHE_TTL_PUBLIC_PAYLOAD', env('CACHE_TTL_PUBLIC_PAYLOAD', 900)),                                 // 15m
            'analytics_short' => (int) env('PARTNA_CACHE_TTL_ANALYTICS_SHORT', env('CACHE_TTL_ANALYTICS_SHORT', 300)),                             // 5m
            'auth_id_lookup' => (int) env('PARTNA_CACHE_TTL_AUTH_ID_LOOKUP', env('CACHE_TTL_AUTH_ID_LOOKUP', 1800)),                               // 30m
            'professional_model' => (int) env('PARTNA_CACHE_TTL_PROFESSIONAL_MODEL', env('CACHE_TTL_PROFESSIONAL_MODEL', 60)),                     // 60s
            'professional_handle_lookup' => (int) env('PARTNA_CACHE_TTL_PROFESSIONAL_HANDLE_LOOKUP', env('CACHE_TTL_PROFESSIONAL_HANDLE_LOOKUP', 3600)), // 60m
            'webhook_idempotency' => (int) env('PARTNA_CACHE_TTL_WEBHOOK_IDEMPOTENCY', env('CACHE_TTL_WEBHOOK_IDEMPOTENCY', 86400)),               // 24h
            'brand_admin_catalog' => (int) env('PARTNA_CACHE_TTL_BRAND_ADMIN_CATALOG', env('CACHE_TTL_BRAND_ADMIN_CATALOG', 300)),                 // 5m
            'collection_gid' => (int) env('PARTNA_CACHE_TTL_COLLECTION_GID', env('CACHE_TTL_COLLECTION_GID', 3600)),                               // 60m
            'product_custom_photos' => (int) env('PARTNA_CACHE_TTL_PRODUCT_CUSTOM_PHOTOS', env('CACHE_TTL_PRODUCT_CUSTOM_PHOTOS', 60)),            // 60s
        ],
    ],
];
