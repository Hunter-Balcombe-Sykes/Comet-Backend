<?php

use App\Mail\Notifications\AchievementMail;
use App\Mail\Notifications\CriticalNotificationMail;
use App\Mail\Notifications\EnquiryReminderMail;
use App\Mail\Notifications\FeatureAnnouncementMail;
use App\Mail\Notifications\IncidentMail;
use App\Mail\Notifications\PolicyUpdateMail;
use App\Mail\Notifications\ProfileTaskMail;
use App\Services\Platforms\Actors\ApifyProfileScraperAdapter;
use App\Services\Platforms\Actors\FigueProfileScraperAdapter;
use App\Services\Platforms\Actors\SoundcloudTracksAdapter;
use App\Services\Platforms\Actors\SpotifyReleasesAdapter;
use App\Services\Platforms\Actors\SpotifyTracksAdapter;
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

    'auth' => [
        // Enforce RequireStrongAuth (see the middleware). Ships FALSE: it is a
        // new denial on a live route and the real amr distribution is unknown.
        // Read `auth.strong_auth.would_deny` in the logs, confirm no legitimate
        // cohort is caught, then flip.
        'strong_auth_enforce' => env('AUTH_STRONG_AUTH_ENFORCE', false),
    ],

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

    'item_slugs' => [
        // 271-PRIV-1: days a retired item slug (site.item_slugs, is_current = false)
        // keeps serving as a 301 alias before slugs:prune-retired hard-deletes it.
        // 90 matches handle.redirect_days, but is its OWN key so the two policies can
        // diverge -- a retired dish/event slug is a weaker claim than a retired
        // identity handle, and item_slugs_unique_slug is non-partial, so every retired
        // row squats its name and pushes a future same-named item onto a -N suffix.
        // Same split-key reasoning as audit.pii_retention_years above.
        'retirement_days' => (int) env('PARTNA_ITEM_SLUG_RETIREMENT_DAYS', 90),
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
        'hiring', 'press', 'media', 'media-dev', 'news', 'blog', 'newsroom',
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
    'platform_links_max' => 15,
    'platform_links_categories' => ['social', 'content', 'events', 'streaming'],

    /*
    |--------------------------------------------------------------------------
    | Shop-brand cap
    |--------------------------------------------------------------------------
    |
    | Hard limit on how many distinct STORES a professional can connect. The
    | reserved individual-products bucket (is_individual = true) never counts
    | against it.
    |
    | Single source of truth on purpose (#CFG-3). This lived as a private
    | MAX_BRANDS const in three classes; T9 (2026-08-20) raised two of them
    | 5 -> 10 and missed StoreBrandSeeder, so a user with 5 brands who pasted a
    | 6th store link got the connection placed but the brand row capped — the
    | store half-existed and never rendered. A cap enforced in three places
    | must be DEFINED in one.
    |
    | NOT the same quantity as ManagesIntegrationConnection::maxAccounts(),
    | which caps connected accounts PER PLATFORM and merely happens to share
    | the value 10. Do not fold them together.
    */
    'shop_brands_max' => (int) env('PARTNA_SHOP_BRANDS_MAX', 10),

    // Platforms that support automatic live status detection via the polling job.
    // Must match keys in social_platforms above. tiktok + youtube joined
    // 2026-09-01 (Item 11d): the poller's new vendor legs stay dormant until
    // a platform is listed here.
    'streaming_platforms' => ['twitch', 'kick', 'tiktok', 'youtube'],

    // Live-status polling tuning knobs. Keeps API call volume bounded.
    'streaming' => [
        // Hard cap on blocks with live_check_enabled=true per site — prevents a single
        // user from monopolizing the polling budget. Enforced in UpdateLinkBlockRequest.
        'max_live_check_per_site' => (int) env('PARTNA_STREAMING_MAX_LIVE_CHECK_PER_SITE', env('SIDEST_STREAMING_MAX_LIVE_CHECK_PER_SITE', 10)),

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
                // A fetched profile is reused across the connect scrape and the eager
                // ingest run for this long (task #18, 2026-08-18) — one Apify run per connect.
                // Read at this FULL path: `partna.instagram.*` is a different block (actor
                // ids), so a lookup there resolves and silently returns the hardcoded
                // fallback instead — which is how this knob sat inert until 2026-08-19.
                'profile_reuse_seconds' => (int) env('PARTNA_INSTAGRAM_PROFILE_REUSE_SECONDS', 900),
                // No per-user cooldown and no per-integration daily cap belong here: the
                // only paid-scrape ceiling is ApifyBudget's per-actor + global caps under
                // `limits.apify`, claimed by InstagramActorDriver and consulted by
                // InstagramController::guardApifyBudget. Do not re-add a key nothing reads.
            ],
        ],

        // Shared Apify cost ceiling (SCALE-2). Caps ×3 2026-09-01 (Item 8 G2,
        // owner-signed): env overrides on Laravel Cloud still win over these
        // defaults; the ledger mechanics are unchanged. Every paid actor (instagram, menu,
        // google-business) claims a slot from ApifyBudget before spending: a
        // per-actor daily cap PLUS a global daily cap so one integration's runaway
        // can't exhaust the account and starve the others. Also carries the shared
        // run-sync HTTP timeout (CFG-9).
        'apify' => [
            // ×10 across the board 2026-09-04 (owner): ceilings against a
            // runaway, never something a normal day should touch.
            'global_daily_cap' => (int) env('PARTNA_APIFY_GLOBAL_DAILY_CAP', 90000),
            'actors' => [
                // Instagram reuses its existing tuned env var (behaviour preserved).
                'instagram' => (int) env('PARTNA_INSTAGRAM_APIFY_DAILY_CAP', 18000),
                'menu' => (int) env('PARTNA_MENU_APIFY_DAILY_CAP', 27000),
                'google-business' => (int) env('PARTNA_GB_APIFY_DAILY_CAP', 27000),
                // Convergence Phase 4. These MUST exist: tryClaim() defaults an
                // unregistered actor's cap to 0, which denies every claim, so a
                // missing entry here reads as "the connector lands nothing"
                // rather than as a configuration error.
                'music-spotify' => (int) env('PARTNA_SPOTIFY_APIFY_DAILY_CAP', 4500),
                'music-soundcloud' => (int) env('PARTNA_SOUNDCLOUD_APIFY_DAILY_CAP', 4500),
                'music-spotify_releases' => (int) env('PARTNA_SPOTIFY_RELEASES_APIFY_DAILY_CAP', 4500),
                // T27c social feed actors (SocialActorDriver claims these).
                'tiktok' => (int) env('PARTNA_TIKTOK_APIFY_DAILY_CAP', 9000),
                'facebook' => (int) env('PARTNA_FACEBOOK_APIFY_DAILY_CAP', 9000),
            ],

            // Item 8 note: with the ScrapeCreators lane in front (see the
            // 'scrapecreators' block below), Apify claims now happen mostly on
            // vendor misses — these caps became fallback headroom, not the
            // primary spend rail.
            // CFG-9: HTTP client timeout for Apify run-sync-get-dataset-items calls, which block
            // until the actor finishes. Raise during an Apify latency incident without a deploy.
            // Must stay UNDER the calling job's own timeout or the job dies first — the binding
            // ceiling is GoogleBusinessEnrichJob's $timeout = 130 (GoogleMenuPhotoScanJob 280).
            // A raise past ~125 turns a slow-Apify incident into a worker kill
            // mid-billed-effect, which is the state EffectLedger has to refuse.
            //
            // The Instagram jobs budget TWO of these, not one: a thin profile earns a single
            // retry (InstagramScraper::fetchProfileResult), so they need 2x this value, not 1x
            // — InstagramConnectJob 300 (raised from 150 on 2026-08-11 for exactly this) and
            // GeneratePreAccountSiteJob 300. Both bounds pinned by HorizonQueueCoverageTest,
            // which fails if a raise here outruns either job.
            'run_sync_timeout_seconds' => (int) env('PARTNA_APIFY_RUN_SYNC_TIMEOUT_SECONDS', 125),
        ],

        // Item 8 (2026-09-01): the ScrapeCreators fast lane's own two-cap
        // budget, ApifyBudget's twin (ScrapeCreatorsBudget). Caps are set
        // ABOVE the Apify equivalents on purpose — this is the primary lane
        // at ~1/10th the latency and the fallback needs its budget intact
        // when this one throttles. A source with no entry here reads cap 0
        // and never claims: adding a vendor-lane source means adding its cap,
        // the same contract the Apify block documents above.
        'scrapecreators' => [
            // ×10 across the board 2026-09-04 (owner): ceilings against a
            // runaway, never something a normal day should touch.
            'global_daily_cap' => (int) env('PARTNA_SC_GLOBAL_DAILY_CAP', 120000),
            'timeout_seconds' => (int) env('PARTNA_SC_TIMEOUT_SECONDS', 20),
            'sources' => [
                'instagram' => (int) env('PARTNA_SC_INSTAGRAM_DAILY_CAP', 54000),
                'tiktok' => (int) env('PARTNA_SC_TIKTOK_DAILY_CAP', 27000),
                'facebook' => (int) env('PARTNA_SC_FACEBOOK_DAILY_CAP', 27000),
                'spotify' => (int) env('PARTNA_SC_SPOTIFY_DAILY_CAP', 13500),
                'soundcloud' => (int) env('PARTNA_SC_SOUNDCLOUD_DAILY_CAP', 13500),
                'linkinbio' => (int) env('PARTNA_SC_LINKINBIO_DAILY_CAP', 13500),
                'youtube' => (int) env('PARTNA_SC_YOUTUBE_DAILY_CAP', 13500),
                // Wave 4 (2026-09-01, Items 10/11): every NEW source lands
                // with its own cap from day one (G2). Ceilings, not budgets —
                // sized to the small-connect lanes they serve, with the
                // multi-call runs (pinterest boards+pins, per-product
                // enrichment) and the per-minute pollers (live status) given
                // their own arithmetic. transcripts and find_social_profiles
                // run deliberately tight: the most speculative spend ships
                // behind the smallest ceilings.
                'twitch' => (int) env('PARTNA_SC_TWITCH_DAILY_CAP', 13500),
                'twitch_live' => (int) env('PARTNA_SC_TWITCH_LIVE_DAILY_CAP', 20000),
                'tiktok_live' => (int) env('PARTNA_SC_TIKTOK_LIVE_DAILY_CAP', 20000),
                'tiktok_shop' => (int) env('PARTNA_SC_TIKTOK_SHOP_DAILY_CAP', 13500),
                'pinterest' => (int) env('PARTNA_SC_PINTEREST_DAILY_CAP', 6750),
                'threads' => (int) env('PARTNA_SC_THREADS_DAILY_CAP', 13500),
                'amazon' => (int) env('PARTNA_SC_AMAZON_DAILY_CAP', 6750),
                'bluesky' => (int) env('PARTNA_SC_BLUESKY_DAILY_CAP', 6750),
                'facebook_events' => (int) env('PARTNA_SC_FACEBOOK_EVENTS_DAILY_CAP', 13500),
                'youtube_shorts' => (int) env('PARTNA_SC_YOUTUBE_SHORTS_DAILY_CAP', 13500),
                'youtube_lives' => (int) env('PARTNA_SC_YOUTUBE_LIVES_DAILY_CAP', 27000),
                'spotify_podcasts' => (int) env('PARTNA_SC_SPOTIFY_PODCASTS_DAILY_CAP', 13500),
                'transcripts' => (int) env('PARTNA_SC_TRANSCRIPTS_DAILY_CAP', 3000),
                // OWNER DECISION (2026-09-01, plan Item 11g): find_social_profiles
                // ships DISABLED — cap 0 means ScrapeCreatorsBudget::tryClaim()
                // refuses every claim before the wire, the hard off-switch the
                // budget layer already enforces. The owner ruled the endpoint
                // out (expensive; duplicates bio-chains + GB-harvest discovery).
                // Re-enabling is a deliberate cap raise, never a code change.
                'find_social_profiles' => (int) env('PARTNA_SC_FIND_SOCIAL_PROFILES_DAILY_CAP', 0),
            ],
            // Pinterest's one connect/refresh run fans out 1 boards call plus
            // up to boards_per_run board reads — both knobs explicit so the
            // per-run spend is legible next to the cap above.
            'pinterest' => [
                'boards_per_run' => (int) env('PARTNA_SC_PINTEREST_BOARDS_PER_RUN', 3),
                'results_limit' => (int) env('PARTNA_SC_PINTEREST_RESULTS_LIMIT', 30),
            ],
            // TikTok Shop's review walk: one products call, then review pages
            // for the top review_products_per_run products (best-selling
            // order) — the whole per-run spend, legible next to the cap.
            'tiktok_shop' => [
                'review_products_per_run' => (int) env('PARTNA_SC_TIKTOK_SHOP_REVIEW_PRODUCTS_PER_RUN', 3),
                'results_limit' => (int) env('PARTNA_SC_TIKTOK_SHOP_RESULTS_LIMIT', 30),
            ],
            // Facebook events: one list call, then per-event detail docs for
            // at most details_per_run upcoming events per run.
            'facebook_events' => [
                'details_per_run' => (int) env('PARTNA_SC_FACEBOOK_EVENTS_DETAILS_PER_RUN', 8),
            ],
            // Item 11b IG depth (reels/highlights/tagged beyond the profile
            // doc): OFF by default — three extra billed calls per IG run is a
            // spend decision the owner flips deliberately, not a side effect
            // of deploying the code.
            'instagram_depth_enabled' => (bool) env('PARTNA_SC_INSTAGRAM_DEPTH_ENABLED', false),
            // 2026-09-02: one reels call per build to give the seed reel (the
            // home background) its best rendition — independent of the depth
            // flag, under the same instagram budget.
            'instagram_seed_reel_best' => (bool) env('PARTNA_SC_INSTAGRAM_SEED_REEL_BEST', true),
            'instagram_depth' => [
                'reels_limit' => (int) env('PARTNA_SC_IG_DEPTH_REELS_LIMIT', 12),
                'highlights_limit' => (int) env('PARTNA_SC_IG_DEPTH_HIGHLIGHTS_LIMIT', 10),
                'tagged_limit' => (int) env('PARTNA_SC_IG_DEPTH_TAGGED_LIMIT', 10),
            ],
        ],

        // AI menu-structuring spend (Mistral OCR + DeepSeek structuring, via
        // MenuAiExtractor) — same two-cap pattern as 'apify' above (per-vendor-call
        // daily cap + a global daily cap), but a separate budget/namespace since
        // these are a different vendor family entirely. Added 2026-07-23: this
        // spend previously had NO budget ceiling at all across its three callers
        // (WebsiteMenuPdfScanJob, GoogleMenuPhotoScanJob, WebsiteMenuHtmlScanJob).
        'ai_spend' => [
            'global_daily_cap' => (int) env('PARTNA_AI_SPEND_GLOBAL_DAILY_CAP', 1500),
            'actors' => [
                'mistral_ocr' => (int) env('PARTNA_MISTRAL_OCR_DAILY_CAP', 900),
                'deepseek_structure' => (int) env('PARTNA_DEEPSEEK_STRUCTURE_DAILY_CAP', 900),
                // T5/T13 (2026-08-27): one bio-intelligence call per signup
                // build (and per empty-bio IG connect) — ~500 tokens each.
                'deepseek_bio' => (int) env('PARTNA_DEEPSEEK_BIO_DAILY_CAP', 900),
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
            // ×10 across the board 2026-09-04 (owner): ceilings against a
            // runaway, never something a normal day should touch.
            'global_daily_cap' => (int) env('PARTNA_PLACES_GLOBAL_DAILY_CAP', 15000),
            'per_user_daily_cap' => (int) env('PARTNA_PLACES_USER_DAILY_CAP', 1800),
            'skus' => [
                'details' => (int) env('PARTNA_PLACES_DETAILS_DAILY_CAP', 6000),
                'photos' => (int) env('PARTNA_PLACES_PHOTOS_DAILY_CAP', 12000),
            ],

            // Photos kept (and billed-resolved) when a listing is accepted at
            // signup. 6 (owner, 2026-09-04): one pooled round trip under
            // refresh.host_limits.google_places.pool_concurrency, ~$0.04.
            'accept_photo_limit' => (int) env('PARTNA_PLACES_ACCEPT_PHOTO_LIMIT', 6),

            // CFG-8: Place Details retry policy. NOTE — attempts MULTIPLY billed spend: every
            // attempt claims its own PlacesBudget slot (see fetchPlaceDetails), so raising this
            // raises per-fetch cost. Clamped 1..3 so an on-call knob can never become a spend
            // multiplier.
            'details_max_attempts' => max(1, min(3, (int) env('PARTNA_PLACES_DETAILS_MAX_ATTEMPTS', 2))),
            'details_retry_delay_microseconds' => (int) env('PARTNA_PLACES_DETAILS_RETRY_DELAY_US', 200_000),
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

    // Apify actor id for the Instagram profile scrape (InstagramScraper::fetchProfileResult).
    // Tilde-separated owner~name form for the API path.
    //
    // Default moved off figue~ on 2026-08-10: it reads Instagram's logged-out
    // profile endpoint, which Meta broke for accounts resolving a business-
    // category subvertical (a deleted internal schema asset). That population
    // skews hard toward Partna's target market — local service professionals
    // who set a specific business category.
    //
    // An actor id is ONLY swappable to one listed in actor_adapters: each actor
    // has its own run-input schema, so the id and its adapter must move together.
    // Setting PARTNA_INSTAGRAM_ACTOR to an unlisted actor fails closed (logged,
    // no network call) rather than posting a body the actor rejects.
    'instagram' => [
        'actor' => env('PARTNA_INSTAGRAM_ACTOR', 'apify~instagram-profile-scraper'),

        'actor_adapters' => [
            'apify~instagram-profile-scraper' => ApifyProfileScraperAdapter::class,
            'figue~instagram-profile-scraper' => FigueProfileScraperAdapter::class,
        ],
    ],

    // T27c social feed actors (SocialActorDriver). results_limit bounds SPEND
    // directly — both actors bill per dataset item, so this is the per-run
    // ceiling, not a pagination preference.
    'social_actors' => [
        'tiktok' => [
            'actor' => env('PARTNA_TIKTOK_ACTOR', 'clockworks~tiktok-profile-scraper'),
            'results_limit' => (int) env('PARTNA_TIKTOK_RESULTS_LIMIT', 30),
        ],
        'facebook' => [
            'actor' => env('PARTNA_FACEBOOK_ACTOR', 'apify~facebook-posts-scraper'),
            'results_limit' => (int) env('PARTNA_FACEBOOK_RESULTS_LIMIT', 30),
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
    | - `handle_pattern`, `host_allowlist`, `url_path_extractor`, and (where
    |   present) `url_templates` are stripped from the public registry response
    |   — they are server-side only.
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
            // Bare-handle default — a typed handle carries no shape signal.
            'url_template' => 'https://linkedin.com/in/{handle}',
            // Shape-specific rebuild for a URL-derived handle (SEM-1): /in/ and
            // /company/ are disjoint LinkedIn namespaces, so a company handle
            // rebuilt against the bare template 404s.
            'url_templates' => [
                'in' => 'https://linkedin.com/in/{handle}',
                'company' => 'https://linkedin.com/company/{handle}',
            ],
            'host_allowlist' => ['linkedin.com', 'www.linkedin.com'],
            // Named groups: matches /in/{handle} (personal) and /company/{handle}
            // (company pages), and preserves which one matched via `shape`.
            'url_path_extractor' => '#^/(?<shape>in|company)/(?<handle>[a-zA-Z0-9-]{3,100})/?$#',
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
            // Bare-handle default — a typed handle carries no shape signal.
            'url_template' => 'https://open.spotify.com/user/{handle}',
            // Shape-specific rebuild for a URL-derived handle (SEM-1): /user/
            // and /artist/ are disjoint Spotify namespaces.
            'url_templates' => [
                'user' => 'https://open.spotify.com/user/{handle}',
                'artist' => 'https://open.spotify.com/artist/{handle}',
            ],
            'host_allowlist' => ['open.spotify.com', 'spotify.com'],
            // Named groups: matches /user/{handle} (profiles) and /artist/{id}
            // (artist pages), and preserves which one matched via `shape`.
            'url_path_extractor' => '#^/(?<shape>user|artist)/(?<handle>[a-zA-Z0-9._-]{3,40})/?$#',
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

    // Music-scraping platform registry (convergence Phase 4) — the same
    // registry shape as 'menu' below. ONE entry per platform whose track
    // catalogue we scrape; adding a platform = one entry here + one
    // MusicActorAdapter class + a `music-<key>` cap in limits.apify.actors.
    //
    // Actor ids were chosen by live probe, not by an actor's marketing copy:
    // the Spotify actor that DOCUMENTS isrc accepts keyword searches only,
    // which cannot be anchored to a connection's artist URL and so risks
    // landing a different artist's catalogue. URL-anchored identity beats a
    // stronger dedup key where the two conflict (convergence-log F29).
    // Pool auto-selection knobs (overnight 2026-08-18, ruling R5). N newest
    // items per auto source that the media pool publishes without a pin;
    // per-connection override is a later slice.
    'pools' => [
        'auto_latest_n' => (int) env('PARTNA_POOL_AUTO_LATEST_N', 5),

        // Per-family smart-score weights (smart ordering v2, 2026-08-23 —
        // App\Services\Analytics\ItemFamily::weightsFor). click/view/dwell
        // weight the decayed day-bucket sums (90-day half-life); fresh is the
        // additive cold-start boost from publishedAt ?? firstSeenAt decaying
        // at half_life_days. `default` = today's 3/1/0/0 for any unnamed
        // family. dwell is per SECOND and only media carries it (the gallery
        // page's section dwell split equally across the served media items —
        // an approximation until item-grain dwell exists).
        'smart' => [
            'default' => ['click' => 3.0, 'view' => 1.0, 'dwell' => 0.0, 'fresh' => 0.0, 'half_life_days' => 14.0],
            'shop_product' => ['click' => 3.0, 'view' => 1.0, 'dwell' => 0.0, 'fresh' => 2.0, 'half_life_days' => 14.0],
            'watch_item' => ['click' => 2.0, 'view' => 1.0, 'dwell' => 0.0, 'fresh' => 3.0, 'half_life_days' => 14.0],
            'listen_item' => ['click' => 2.0, 'view' => 1.0, 'dwell' => 0.0, 'fresh' => 4.0, 'half_life_days' => 21.0],
            'service' => ['click' => 3.0, 'view' => 1.0, 'dwell' => 0.0, 'fresh' => 1.0, 'half_life_days' => 30.0],
            'menu_item' => ['click' => 1.0, 'view' => 1.0, 'dwell' => 0.0, 'fresh' => 0.5, 'half_life_days' => 60.0],
            'gallery_item' => ['click' => 0.5, 'view' => 1.0, 'dwell' => 0.05, 'fresh' => 5.0, 'half_life_days' => 7.0],
            'link_item' => ['click' => 3.0, 'view' => 1.0, 'dwell' => 0.0, 'fresh' => 3.0, 'half_life_days' => 14.0],
            // Events (smart-scoring plan, 2026-08-27): fresh = 0 on purpose —
            // publishedAt-age freshness is the WRONG shape for an event; the
            // additive term is EventTimeRelevance (relevance/relevance
            // half-life below), peaking at the event date and collapsing
            // after (×0.25 once past).
            'event_item' => ['click' => 3.0, 'view' => 1.0, 'dwell' => 0.0, 'fresh' => 0.0, 'half_life_days' => 14.0, 'relevance' => 3.0, 'relevance_half_life_days' => 7.0],
        ],
    ],

    // Paid ingest connectors the scheduler may run (ruling R8). Everything
    // else that costs money runs only on connect (eagerOnConnect) and Resync.
    'ingest_scheduled_paid_sources' => array_values(array_filter(array_map('trim', explode(',', (string) env('PARTNA_INGEST_SCHEDULED_PAID_SOURCES', 'google_business,spotify,soundcloud'))))),

    'music' => [
        'platforms' => [
            'spotify' => [
                'actor' => env('PARTNA_SPOTIFY_ACTOR', 'automation-lab~spotify-scraper'),
                'adapter' => SpotifyTracksAdapter::class,
                'max_tracks' => (int) env('PARTNA_SPOTIFY_MAX_TRACKS', 50),
            ],
            // Spotify RELEASES (listen restructure 2026-08-18): the artist's
            // discography as album/single/compilation rows with cover art —
            // W10 probe, $0.005/start + $0.002/release. Its own budget key.
            'spotify_releases' => [
                'actor' => env('PARTNA_SPOTIFY_RELEASES_ACTOR', 'nifty.codes~spotify-artistdiscography-scraper'),
                'adapter' => SpotifyReleasesAdapter::class,
                'max_tracks' => (int) env('PARTNA_SPOTIFY_MAX_RELEASES', 60),
            ],
            'soundcloud' => [
                'actor' => env('PARTNA_SOUNDCLOUD_ACTOR', 'automation-lab~soundcloud-scraper'),
                'adapter' => SoundcloudTracksAdapter::class,
                'max_tracks' => (int) env('PARTNA_SOUNDCLOUD_MAX_TRACKS', 50),
            ],
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
        // NAMING (C10, 2026-08-26, deliberate): these registry keys are the
        // menu lane's vocabulary and stay HYPHENATED ('uber-eats'), matching
        // offers.platform and the pools wire. Catalog brands underscore
        // ('uber_eats'); the boundary translators are MenuSource::surfaceSlug
        // (surface → registry slug), MenuItemDeepLinks::WIRE_KEYS (registry
        // slug → dashboard wire key) and PoolResolver::wirePlatform. Do not
        // "unify" the spellings — each vocabulary has live storage behind it.
        'platforms' => [
            // Square first — top priority for pricing/images over Uber Eats/DoorDash
            // (merchant-canonical). transport=http (2026-08-26): Square Online's
            // own unauthenticated products API replaces the menus-r-us AI actor —
            // exact ids/prices/links, zero scrape billing. MenuApifyScraper routes
            // transport=http platforms through MenuHttpDriver::fetchMenu().
            'square' => [
                'transport' => 'http',
                // Matched against a bare host (MenuSource::platformOf passes
                // parse_url PHP_URL_HOST), so anchor on $ -- a trailing slash
                // both closed the ~ delimiter early, making the rest of the
                // pattern parse as modifiers, and could never match a host.
                // #SEC-3: the third arm used to be `^order\.(?!online$|toasttab...)`.
                // That lookahead is zero-width with NO terminal anchor, so it was an
                // allowlist-by-exclusion: every host starting `order.` that was not
                // one of five named competitors matched, and `order.attacker.example`
                // was scraped and rendered on a public sitepage under Square's brand.
                // It cannot be repaired by anchoring, because a Square Online custom
                // domain is indistinguishable from an attacker's by hostname alone.
                // (2026-08-27: Square.php now gives square.order a PATH-qualified
                // /s/order detector on square.site — Square's OWN domain, so it
                // does not reopen this hole. Custom domains stay host-undetectable
                // by design and connect via the storefront-marker probe +
                // surface-key stamp instead; MenuSource trusts the stamp, so this
                // host_pattern is only the fallback for square.site hosts.)
                'host_pattern' => '~(^|\.)square\.site$|(^|\.)square\.com$~',
                // NO store_path_pattern, deliberately — do not "complete the
                // set". Uber Eats and DoorDash below carry one because their
                // stores live under /store/; a Square Online store is served
                // at the BARE square.site root (and at /s/order — see
                // Catalog/Definitions/Square.php), so any path rule here would
                // reject every real square store instead of a landing page.
                'driver' => SquareMenuDriver::class,
            ],
            'uber-eats' => [
                'actor' => 'memo23~uber-eats-scraper',
                'host_pattern' => '~(^|\.)ubereats\.com$~',
                // Matched against the PATH by MenuSource::scrapableSlug() — the
                // guard on the lane that actually bills Apify — and by
                // SourceProvisioner::menuStoreUrl(). Host alone is not identity:
                // ubereats.com also serves /brand/ chain landing pages, a bare
                // locale root and /feed, none of which has a menu behind it.
                // guzman-y-gomez, 2026-08-31, connected
                // https://www.ubereats.com/au/brand/guzman-y-gomez and got a
                // live Order button over a permanently empty menu, re-scraped
                // every 15 minutes forever — exactly the hazard the `dice` arm
                // of identifierFor() spells out.
                //
                // The locale alternation is written to agree, character for
                // character, with Catalog/Definitions/UberEats.php's detector —
                // they are two spellings of "is this a store" and a URL must
                // never pass one and fail the other on locale grammar
                // (UberEatsStorePathAgreementTest pins this). The detector is
                // additionally stricter, requiring /<slug>/<id> after /store/,
                // and that difference IS deliberate: it must CAPTURE a store id
                // to mint a connection, while this key only has to reject pages
                // with no menu behind them. Directional, and tested: everything
                // the detector accepts, this accepts.
                'store_path_pattern' => '~^/(?:[a-z]{2}(?:-[a-z]{2})?/)?store/~i',
                'driver' => UberEatsMenuDriver::class,
            ],
            'doordash' => [
                'actor' => 'dz_omar~doordash-scraper',
                'host_pattern' => '~(^|\.)doordash\.com$~',
                // Same rule, same reason: doordash.com's root and its
                // /food-delivery/<city>/ discovery pages are not stores.
                // The locale segment here is the /en-CA/store/… form.
                'store_path_pattern' => '~^/(?:[a-z]{2}(?:-[a-z]{2})?/)?store/~i',
                'driver' => DoorDashMenuDriver::class,
            ],
        ],

        // R4-RES-1: how long a failed menu scrape target is suppressed before it may
        // be re-billed. Kept BELOW the menu:retry-unavailable cadence (15 min) so the
        // scheduled self-heal always sees a clear key on its next tick.
        'blocked_ttl_seconds' => (int) env('PARTNA_MENU_BLOCKED_TTL_SECONDS', 600),

        // B5 / backend-fixes item 3b (2026-08-26): category labels that are
        // marketplace merchandising rails or scan wrappers, not menu taxonomy —
        // dropped at projection (normalized-name compare). Safe because rail
        // membership is additive: every rail item also holds a REAL category,
        // and an item left with none auto-files into the synthesized "More".
        // DATA, not code — extend here when a new rail label appears.
        'category_denylist' => [
            'featured items',
            'save on select items',
            'picked for you',
            'menu',
            'all',
            'home',
        ],

        // Convergence Phase 5 — MenuActorDriver's retry budget on the LEDGERED
        // ('actor','menu') lane. Separate from the legacy scraper's own constants
        // because the two lanes have different enclosing jobs.
        //
        // Retries are not optional here: these actors scrape WAF-protected pages
        // and return an empty dataset on a large fraction of runs for a valid,
        // open store. They also cannot be deferred to the scheduler — EffectLedger
        // settles the digest, so a later RunSourceJob for the same source finds a
        // settled row and never re-attempts until the freshness bucket rolls. The
        // only retries the ledger permits are the ones inside this one call.
        //
        // attempts x attempt_timeout MUST stay under RunSourceJob::$timeout, or the
        // worker is killed mid-billed-run: pinned by HorizonQueueCoverageTest.
        'actor_attempts' => (int) env('PARTNA_MENU_ACTOR_ATTEMPTS', 4),
        'actor_attempt_timeout_seconds' => (int) env('PARTNA_MENU_ACTOR_ATTEMPT_TIMEOUT_SECONDS', 60),
    ],

    // Unified actions (2026-08-23 rebuild — one ranked list of pages,
    // destination platforms, served items and categories; composite smart
    // score). See App\Site\Actions\* + App\Services\Analytics\ActionScorer.
    'actions' => [
        // Slot count — table and lander. Owner-fixed, not a user setting.
        'slots' => (int) env('PARTNA_ACTIONS_SLOTS', 10),
        // Bayesian smoothing constant: rate = (taps + k·prior) / (exposures + k).
        // k=25 ≈ "the first ~25 real sessions outvote the editorial prior."
        'prior_k' => (int) env('PARTNA_ACTIONS_PRIOR_K', 25),
        'default_prior' => 0.03,
        // Importance floor per action id (pages) or per kind. Also the
        // cold-start order: a brand-new site ranks Book > Reserve > Menu >
        // Shop > Events > Contact > platforms > categories > items, and the
        // freshness term lifts anything new above its floor for ~2 weeks.
        'priors' => [
            // The reservations PAGE left the taxonomy 2026-08-27; the Reserve
            // intent's floor now rides the reservation platforms themselves
            // (destination candidates since the same change).
            'platform:opentable' => 0.30,
            'platform:resdiary' => 0.30,
            'platform:nowbookit' => 0.30,
            'page:services' => 0.28,
            'page:menu' => 0.28,
            'page:shop' => 0.15,
            'page:events' => 0.14,
            'page:contact' => 0.12,
            'platform' => 0.05,
            'category' => 0.04,
            'item' => 0.03,
        ],
        // Composite weights — each term is normalised 0..1 within the site
        // before weighting, so a page and a song compare on one scale.
        'weights' => ['demand' => 0.45, 'reach' => 0.30, 'fresh' => 0.25],
        'freshness_half_life_days' => 14.0,
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

        // Whether HttpIo may execute a billed-effect driver at all (slice 0).
        // Default FALSE, per environment.
        //
        // This is ACTIVATION gating, not budget safety — PlacesBudget and
        // ApifyBudget already cap spend, and SourceProvisioner::schedulable()
        // leaves every non-Free connector auto_sync=false so the dispatcher
        // never claims one. It exists because `production` deploys on push, so
        // this seam reaches prod weeks before slice 1 intends paid fetching to
        // be live there. Off, a billed effect raises EffectRefused before the
        // ledger is touched: the stream reads budget_skipped and no row is
        // written.
        'billed_effects_enabled' => (bool) env('PARTNA_INGEST_BILLED_EFFECTS_ENABLED', false),

        // Lander::land() chunk size (SCALE-4). Bind-count arithmetic per chunk
        // (Postgres limit 65,535/statement): record_versions insert = 7 cols
        // -> 500x7 = 3,500; record_state upsert = 8 cols -> 4,000; the
        // `key IN` + `doc_hash IN` lists on the resolving SELECT -> ~1,000.
        // 500 leaves an order of magnitude of headroom. Also bounds memory:
        // $records is already resident (RunExecutor::drain() materialises it
        // before land() is called), so the chunk only adds one json_encode
        // string + hash per record, released between chunks.
        'land_chunk' => (int) env('PARTNA_INGEST_LAND_CHUNK', 500),

        // ProjectionWriter::replaceCollections() chunk size (SCALE-17/#CACHE-2).
        // Bind-count arithmetic per chunk (Postgres 65,535/statement; SQLite
        // >=3.32 32,766): item_media insert = 8 cols -> 500x8 = 4,000; offers
        // = 11 cols -> 5,500; item_tags = 5 cols -> 2,500; the `item_id IN`
        // list on each of the three DELETEs -> 500. 500 clears both engines'
        // limits by an order of magnitude. It also bounds the delete+insert
        // transaction: one chunk's worth of rows is the longest any item is
        // without its collection rows, and serving reads these tables live.
        'projection_write_chunk' => (int) env('PARTNA_INGEST_PROJECTION_WRITE_CHUNK', 500),

        // SCALE-1/SCALE-2: chunkById page size for `ingest:project`'s source
        // walk. Bounds both the source-list result buffer and the per-chunk
        // streams pre-fetch. Overridable so tests can shrink it (3) to make
        // chunk-boundary cases cheap to seed.
        'projection_source_chunk' => (int) env('INGEST_PROJECTION_SOURCE_CHUNK', 200),

        // #SCALE-10/#CACHE-6: caps on Resolver step 5, where every EVIDENTIAL
        // key value pairs its members O(m^2) and each surviving pair becomes a
        // content.identity_candidates row. One over-shared value (a stock
        // title, a shared brand name) is therefore quadratic work AND
        // quadratic writes.
        //
        // TWO knobs, because they bound different things and neither is
        // sufficient alone:
        //   - max_candidates_per_key bounds the pairs APPENDED for one key
        //     value. It does NOT bound the work: a value whose pairs are
        //     nearly all already grouped or cut appends almost nothing while
        //     still walking the full m^2.
        //   - max_members_per_key bounds the MEMBERS paired for one key value,
        //     which is what actually bounds the iteration — 100 members is at
        //     most 4,950 pairs examined, whatever the append rate.
        // Both cut DETERMINISTICALLY (the first N in index order), never by
        // sampling: the resolver is re-runnable and must return the same
        // answer for the same input. Read ONLY by
        // ProjectionWriter::resolveItemsLocked(), which passes them to
        // Resolver::resolve() as ARGUMENTS — the resolver does no I/O of its
        // own, so a resolve stays reproducible from its arguments alone. That
        // same call site logs once per run when a cap bites: a silently capped
        // key means items stop being offered for merge, which is invisible.
        'max_candidates_per_key' => (int) env('PARTNA_INGEST_MAX_CANDIDATES_PER_KEY', 200),
        'max_members_per_key' => (int) env('PARTNA_INGEST_MAX_MEMBERS_PER_KEY', 100),

        // #PRIV-3: grace window before an ORPHANED reviewer-PII row is deleted.
        // "Orphaned" = no live content.source_items row still carries the
        // (item_id, source_id) pair, i.e. the review is gone from the platform.
        // 14 days is slack for a transient projection failure (a run that fetched
        // zero records would otherwise retire every source_item and erase real
        // reviews that come back on the next successful run).
        'review_pii_orphan_grace_days' => (int) env('PARTNA_REVIEW_PII_ORPHAN_GRACE_DAYS', 14),

        // CFG-16 (Lander): consecutive dominated absences before a key is tombstoned.
        'tombstone_runs' => (int) env('PARTNA_INGEST_TOMBSTONE_RUNS', 3),

        // CFG-16 (Lander): share of a stream's live keys that may vanish in one run before
        // the delete-guard trips. A login wall or a vendor outage looks exactly like
        // "everything was deleted", so a large drop must stop deletion and ask a human
        // rather than act.
        // ⚠️ FLOAT, NOT INT. An (int) cast turns 0.4 into 0, the guard then trips on EVERY
        // run with >= 5 dominated-absent keys, deletion freezes platform-wide and a
        // critical anomaly is filed per stream. The `count >= 5` floor beside it in
        // Lander::foldAbsence() is deliberately NOT configurable.
        'delete_guard_threshold' => (float) env('PARTNA_INGEST_DELETE_GUARD_THRESHOLD', 0.4),

        // CFG-16 (EffectLedger): how long a claimed-but-unsettled effect blocks a retry.
        // Money-adjacent — this same number is printed verbatim into the operator-facing
        // effect_abandoned anomaly summary, so it has exactly one read path
        // (EffectLedger::abandonAfterSeconds()).
        // Floor: must stay above the longest job that can still be wrapping a billed
        // effect when this fires — GoogleMenuPhotoScanJob::$timeout = 280s. Lowering
        // this below 280 would flip a still-running, legitimately billed claim to
        // "abandoned" mid-flight: a critical money-adjacent anomaly + page for a call
        // that then settles normally.
        'effect_abandon_after_seconds' => (int) env('PARTNA_INGEST_EFFECT_ABANDON_AFTER_SECONDS', 900),

        // CFG-16 (SourceScheduler): EWMA weight — recent behaviour dominates but does not
        // erase history. ⚠️ FLOAT, NOT INT: an (int) cast makes this 0, change_rate then
        // never moves and every source drifts to its maximum interval forever.
        'source_change_rate_alpha' => (float) env('PARTNA_INGEST_SOURCE_CHANGE_RATE_ALPHA', 0.3),

        // CFG-16 (SourceScheduler): a claim older than this is stranded — a worker died
        // holding it.
        'source_stranded_after_seconds' => (int) env('PARTNA_INGEST_SOURCE_STRANDED_AFTER_SECONDS', 7200),

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

    // #PGR-8/9/10 batching tunables for one-off backfill/prune commands.
    // Overridable so tests can shrink these to make chunk-boundary cases
    // cheap to seed (see IngestProjectChunkingTest for the pattern).
    'content' => [
        // BackfillContentItemSlugs: chunkById() page size for the
        // content.items walk.
        'slug_backfill_chunk' => (int) env('PARTNA_CONTENT_SLUG_BACKFILL_CHUNK', 500),

        // Facet origin scoping (spec 2026-08-26). When ON, replaceCollections()
        // deletes only rows whose source_item_id is one of the coords the write
        // covers (plus NULL rows, which are un-attributed and must keep being
        // replaced as before). When OFF, the delete is byte-for-byte what it
        // always was: item_id IN (batch) AND source_id = ours.
        //
        // This gates the CONNECTOR lane only. The manual source is scoped
        // unconditionally, because that is the lane with the live data-loss bug
        // and it has no comparable traffic to risk.
        'facet_origin_scope' => (bool) env('PARTNA_CONTENT_FACET_ORIGIN_SCOPE', false),

        // Media rows a single merge fold may ADD to an item. Never removes rows
        // the survivor already has — see ProjectionWriter::foldCollections().
        'merge_media_cap' => (int) env('PARTNA_CONTENT_MERGE_MEDIA_CAP', 8),

        // Identity-scope narrowing (#CACHE-2, #CACHE-4). When on,
        // resolveItemsLocked() resolves only the CONNECTED COMPONENT of the
        // coords a run touched instead of the user's whole catalogue for the
        // kind. This is the kill switch, not a tuning knob — but it is
        // PARTIAL, not a full byte-for-byte rollback, and it does NOT take
        // effect without a redeploy. Read both caveats before reaching for it
        // mid-incident:
        //
        // What flipping this OFF reverts: the identity-resolution narrowing
        // itself (resolveItemsLocked() goes back to resolving the whole kind
        // on every call), the closing source_items re-read/UPDATE predicate,
        // and mergeInto()'s anchor-repair block (gated on $narrowed, which
        // this flag controls).
        //
        // What it does NOT revert — four changes that ship UNGATED because
        // each is safe independently of the narrowing:
        //   - refreshItemCaches() being called with only the touched item
        //     id(s) instead of the whole (user, kind) set (ProjectionWriter.php
        //     writeManualItem()/projectStream() call sites, #CACHE-4's cache
        //     half).
        //   - forAccumulator() slimming each accumulator entry to the columns
        //     writeFacets()/replaceCollections() actually read (#SCALE-8).
        //   - the batched slug read in refreshItemCaches(), including its
        //     skip when a chunk has no slugged kind (#SCALE-9/#API-7).
        //   - resolveItemsLocked()'s $rows select carrying si.item_id, used to
        //     seed unbound coords (§A.4 seed source 3).
        //
        // Flipping this off does not take effect on its own: Laravel Cloud
        // runs `php artisan optimize` (config:cache) at BUILD time, so a
        // running instance is serving the config baked in at its last deploy
        // regardless of what the env var says now. A redeploy
        // (`cloud deploy partna development`, or the prod equivalent) is
        // required before this flag's new value is observed. See
        // scripts/launch-check/k6/results/2026-08-10-swr-verification.md:120
        // for the same fact recorded against a different config flag.
        'identity_scope' => (bool) env('PARTNA_CONTENT_IDENTITY_SCOPE', true),

        // Component size past which the narrowing gives up and resolves
        // whole-kind anyway. A PERFORMANCE guard only: it must never
        // truncate, because a truncated component can miss the same-source
        // sibling that poisons a key and so produce a merge the full resolve
        // would not make — and mergeInto() hard-deletes the loser.
        'identity_scope_max' => (int) env('PARTNA_CONTENT_IDENTITY_SCOPE_MAX', 2000),
    ],

    'media' => [
        // BackfillMediaPaletteCommand: chunkById() page size for the
        // palette-backfill walk.
        'palette_backfill_chunk' => (int) env('PARTNA_MEDIA_PALETTE_BACKFILL_CHUNK', 200),

        // BorrowedAssetPruner: take size for each doomed-set delete batch.
        'borrowed_prune_chunk' => (int) env('PARTNA_MEDIA_BORROWED_PRUNE_CHUNK', 500),

        // Mirror budget per pull (owner, 2026-09-04): ONE asset per post —
        // the cover, or the video plus its poster — newest posts first, at
        // most this many image posts and video posts per projection pass.
        // Bounds the bytes we copy and the wave a signup waits on (a 15-post
        // Threads pull carried 105 carousel frames); the scrape itself is
        // one request per platform regardless. Assets are still MINTED for
        // every frame (so item_media keeps its rows); only the byte copy is
        // budgeted. Already-mirrored posts inside the window count against
        // it, so a weekly refresh cannot creep down the backlog. 0 = unlimited.
        // A signup gets ONE eager pass PER CONNECTED SOURCE, so a new site's
        // grid shows at most `images` mirrored pictures per source on arrival
        // — and the rest do NOT trickle in behind them: scoreDue() admits a
        // scheduled refresh only for a PUBLISHED site, and a signup stays
        // unpublished for the setup walk.
        // Nothing further mirrors until it publishes, and even then the window
        // is newest-first with already-mirrored posts consuming slots, so an
        // older backlog need not drain. Not a grid setting — the dashboard
        // renders every item it is given and caps nothing.
        'pull_budget' => [
            'images' => (int) env('PARTNA_MEDIA_PULL_IMAGES', 10),
            'videos' => (int) env('PARTNA_MEDIA_PULL_VIDEOS', 6),
        ],

        // Thumbnail tier written beside every mirrored image master
        // (`{sha}.webp` → `{sha}.640.webp`): setup tiles and cards load ~32 KB
        // instead of the 2400px master's ~260 KB. The EDGE is not configurable
        // — MediaMirror::THUMB_EDGE, frozen with THUMB_SUFFIX. Quality is,
        // because the filename promises nothing about it.
        'thumb_quality' => (int) env('PARTNA_MEDIA_THUMB_QUALITY', 80),
    ],

    // Pre-Account Sites (site-first signup + staff marketing builds).
    'pre_account' => [
        'expiry_days' => (int) env('PARTNA_PRE_ACCOUNT_EXPIRY_DAYS', 30),
        'failed_prune_hours' => (int) env('PARTNA_PRE_ACCOUNT_FAILED_PRUNE_HOURS', 24),
        'max_unclaimed_per_ip' => (int) env('PARTNA_PRE_ACCOUNT_MAX_UNCLAIMED_PER_IP', 3),

        // #W2-SEC-1: a self-serve build has no identity attached at creation, so
        // built_via never said who was entitled to it — any authenticated stranger
        // who guessed the subdomain could claim it. Proof is now required on every
        // lane, not just outreach. Mint-first, enforce-later: while false, tokens
        // are minted and returned but not required, so an old frontend keeps
        // working. Flip ONLY after the claim page persists and forwards
        // claim_token, or self-serve claiming 404s for everyone.
        'require_claim_proof' => (bool) env('PARTNA_PRE_ACCOUNT_REQUIRE_CLAIM_PROOF', false),

        // A.4 kill switch: sign-up builds pre-scrape every auto-band
        // suggestion that has a connector (hidden connection + ingest run,
        // no cap — U22). Off = suggestions still appear, just without items
        // behind them until accepted.
        'pre_scrape_enabled' => (bool) env('PARTNA_PRE_ACCOUNT_PRE_SCRAPE_ENABLED', true),

        // LIFE-4: how long a build may sit in pending/building before it's treated
        // as stuck (worker crash, never reached failed()). Used by both the hourly
        // builds:reconcile-stuck watchdog and PreAccountBuildService::reserve() —
        // deliberately well past GeneratePreAccountSiteJob's 300s timeout and 600s
        // ShouldBeUnique window so a fresh dispatch is never dropped by the unique
        // lock nor races a still-legitimately-running job.
        'stuck_build_sla_minutes' => (int) env('PARTNA_PRE_ACCOUNT_STUCK_BUILD_SLA_MINUTES', 30),

        // CACHE-2/SCALE-7: wall-clock budget for the synchronous CSV batch loop
        // (StaffPreAccountBuildController::batch). Up to 500 rows x one
        // transaction + job dispatch each will outrun the HTTP request timeout,
        // and a timeout returns staff NOTHING — not even the rows that landed.
        // The loop stops STARTING rows past this budget and returns what
        // completed, so re-uploading the remainder is a normal deduped run.
        // The platform's real request ceiling was NOT confirmed when this was
        // set — `cloud command:run` reports the CLI SAPI, which says nothing
        // about php-fpm — so 20s is a conservative guess, not a derived bound.
        // The check runs BEFORE a row, so worst case is budget + one row.
        // Raise it once someone verifies the actual ceiling.
        // 0 = process exactly one row then stop (forward progress is always
        // guaranteed); that is also the test seam.
        'batch_time_budget_seconds' => (int) env('PARTNA_PRE_ACCOUNT_BATCH_TIME_BUDGET_SECONDS', 20),

        // PRIV-3: the pepper for core.pre_account_builds.created_ip_hash
        // (PreAccountBuild::hashIp()). An unsalted sha256 of an IP is a
        // pseudonym only against someone who cannot enumerate — and the whole
        // IPv4 space is 4.3B digests, i.e. minutes of commodity GPU time — so
        // the stored value has to be keyed on a secret the attacker lacks.
        //
        // Defaults to APP_KEY rather than introducing a new secret: it already
        // exists in every environment, differs between dev and prod (so the two
        // keyspaces can't be cross-referenced), and is already handled as a
        // secret. The dedicated env var exists for the case where the IP pepper
        // must rotate WITHOUT rotating APP_KEY (rotating either one invalidates
        // every stored digest, which only costs the per-IP build cap a window).
        // Note APP_KEY is stored 'base64:'-prefixed; hashIp() decodes it.
        //
        // `?:` not a default argument: an env var PRESENT BUT BLANK
        // (`PARTNA_PRE_ACCOUNT_IP_HASH_KEY=` — exactly how .env.example ships
        // it) makes env() return '' rather than the default, which would
        // silently pepper every digest with the empty string, i.e. re-open the
        // finding while looking configured.
        'ip_hash_key' => env('PARTNA_PRE_ACCOUNT_IP_HASH_KEY') ?: env('APP_KEY', ''),

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
        // Manual menu scans bill Mistral OCR + DeepSeek per request — this
        // per-user daily cap stops one account draining the shared
        // ai_spend budget (which remains the global backstop).
        'menu_scan_per_day' => (int) env('PARTNA_MENU_SCAN_PER_DAY', 15),
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
        // True-IP backstops behind the per-visitor limits above (plan 01,
        // 2026-08-27): proxied beacons share Cloudflare colo IPs, so the IP
        // dimension only exists to stop a single-address flood, sized far
        // above any legitimate colo's fan-in. Declared here (not just as the
        // provider's inline fallbacks) so they stay env-tunable like every
        // sibling.
        'analytics_ip_backstop_per_minute' => (int) env('PARTNA_THROTTLE_ANALYTICS_IP_BACKSTOP_PER_MINUTE', 3000),
        'analytics_click_ip_backstop_per_minute' => (int) env('PARTNA_THROTTLE_ANALYTICS_CLICK_IP_BACKSTOP_PER_MINUTE', 120),
        // Authed variant of the signup/availability limiter (UX-rebuild): the
        // dashboard's handle-change checker runs as a logged-in user and gets
        // triple the anonymous allowance.
        'subdomain_availability_authed_per_minute' => (int) env('PARTNA_SUBDOMAIN_AVAILABILITY_AUTHED_PER_MINUTE', 30),
        'leads_per_minute_ip' => (int) env('PARTNA_THROTTLE_LEADS_PER_MINUTE_IP', 3),
        'leads_per_minute_subdomain' => (int) env('PARTNA_THROTTLE_LEADS_PER_MINUTE_SUBDOMAIN', 100),

        // Degraded-mode lead limits, used ONLY when Redis is unreachable and
        // LeadSubmissionRateLimiter counts analytics.lead_submissions instead
        // (see FailOpenThrottleRequests::FALLBACK_LIMITERS). Default to the
        // primary values so visitor behaviour is identical; the separate env
        // vars exist so the limit can be clamped mid-incident without touching
        // the healthy-path numbers.
        'leads_degraded_per_minute_ip' => (int) env(
            'PARTNA_THROTTLE_LEADS_DEGRADED_PER_MINUTE_IP',
            env('PARTNA_THROTTLE_LEADS_PER_MINUTE_IP', 3),
        ),
        'leads_degraded_per_minute_subdomain' => (int) env(
            'PARTNA_THROTTLE_LEADS_DEGRADED_PER_MINUTE_SUBDOMAIN',
            env('PARTNA_THROTTLE_LEADS_PER_MINUTE_SUBDOMAIN', 100),
        ),
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
        'manychat_build_per_minute' => (int) env('PARTNA_THROTTLE_MANYCHAT_BUILD_PER_MINUTE', 10),
        'manychat_build_per_hour' => (int) env('PARTNA_THROTTLE_MANYCHAT_BUILD_PER_HOUR', 120),
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
    // 2026-08-09.1 — the preset-only migration (20260809090001) drops 52
    // columns and adds text_size / spacing / corners. Without this bump,
    // writeDesignKit() would filter against the cached PRE-drop list for up to
    // an hour after deploy: writes to the three new columns silently discarded,
    // writes to the 52 dropped ones attempted.
    'design_kit_columns_version' => env('PARTNA_DESIGN_KIT_COLUMNS_VERSION', '2026-08-09.1'),

    /*
    |----------------------------------------------------------------------
    | Design-kit column group prefix maps
    |----------------------------------------------------------------------
    | IndividualProfilePayloadBuilder::groupKitColumns() uses these to
    | project flat snake_case DB column names (e.g. color_accent,
    | typography_font_heading) into the nested camelCase wire shape
    | (e.g. colors.accent, typography.fontHeading).
    |
    | exact_columns — matched BEFORE either prefix map, by WHOLE column name,
    |   to an explicit [group, key] pair. Added 2026-08-09 for the preset-only
    |   schema: `spacing` and `corners` carry no underscore at all, so the
    |   prefix split (substr up to the first `_`) cannot see them and they were
    |   dropped from the payload with no error — the exact failure mode the
    |   note below warns about, reached without a missing entry.
    |
    | two_token_prefixes — matched after exact_columns, before single-token
    |   (longer prefix wins). Covers responsive companion groups (e.g.
    |   space_desktop_regular → spaceDesktop.regular).
    |
    | single_token_prefixes — fallback after the two-token check. Pluralisation
    |   is NOT mechanical, so the map is explicit. Adding a new design-kit
    |   column family whose prefix isn't listed here means that group is
    |   silently dropped from the API response — add the entry here at the
    |   same time you add the Supabase migration column.
    |
    | LIVE COLUMNS after 20260827080000 (plan 02 removals — was 8 after
    | 20260809090001; border_thickness, theme_mode and theme_night_shift_auto
    | dropped 2026-08-27):
    |   color_accent            → colors.accent          (single-token)
    |   typography_font_family  → typography.fontFamily  (single-token)
    |   text_size               → selections.textSize        (exact)
    |   spacing                 → selections.spacing         (exact)
    |   corners                 → selections.corners         (exact)
    | Every other prefix below is dead — no live column matches it. They are
    | kept rather than pruned (the same call made for `sizing`/`button` when
    | their columns went) because the mapping is a pure function with its own
    | tests, and a returning column family should not also have to re-derive
    | the map.
    */
    'design_kit' => [
        'column_groups' => [
            // The SELECTIONS, grouped together as `selections` to mirror
            // the design system's own KitSelections interface
            // ({textSize, spacing, corners}) key-for-key. (borderThickness
            // rode here 2026-08-09 → 2026-08-27, then died with its column.)
            'exact_columns' => [
                'text_size' => ['selections', 'textSize'],
                'spacing' => ['selections', 'spacing'],
                'corners' => ['selections', 'corners'],
            ],
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
                'theme' => 'theme',     // legacy (theme_mode dropped 2026-08-27 — kept for safety)
                'sizing' => 'sizing',    // legacy (columns dropped — kept for safety)
                'button' => 'buttons',   // legacy (columns dropped — kept for safety)
            ],
        ],
    ],

    'media_disk' => env('PARTNA_MEDIA_DISK', env('SIDEST_MEDIA_DISK', 'media')),

    /*
    |----------------------------------------------------------------------
    | Owned-media mirror — give-up threshold (R8)
    |----------------------------------------------------------------------
    | Consecutive MediaMirror failures after which ProjectionWriter stops
    | re-queuing an asset. Counts dispatches, not HTTP attempts: the job's
    | own $tries = 3 sits underneath, so 5 here is ~15 fetches spread over at
    | least 5 syncs before we call a CDN link dead.
    |
    | The counter resets to 0 on any success, so this only ever ends a run of
    | consecutive failures. Raising it re-opens every capped asset on the next
    | sync — there is no separate reset to run.
    */
    'media_mirror_max_attempts' => (int) env('PARTNA_MEDIA_MIRROR_MAX_ATTEMPTS', 5),

    /*
    |--------------------------------------------------------------------------
    | Media mirror temp directory
    |--------------------------------------------------------------------------
    |
    | Where MediaMirror spools a fetched body before storing it (#SCALE-3).
    | Null = the system temp dir, which is right on Laravel Cloud. Exists as a
    | seam because the spool is a REAL file on a REAL shared directory: a worker
    | box that wants it on a specific volume sets this, and the leak tests point
    | it at a per-test directory so they can assert on an empty glob without
    | seeing a parallel worker's in-flight file.
    |
    */
    'media_mirror_temp_dir' => env('PARTNA_MEDIA_MIRROR_TEMP_DIR'),

    /*
    |----------------------------------------------------------------------
    | Upload usages – per-professional limits
    |----------------------------------------------------------------------
    | A site_media "usage" says what an uploaded file is FOR. It is NOT a
    | content.* pool — those are public page sections (media, shop, events…)
    | and have no relationship to these keys. Renamed off "pool" 2026-09-04.
    |
    | content   = owner photos, bridged into the media pool by ManualMediaWriter
    | design    = logo / favicon / brand assets, never published as a card
    | documents = one downloadable file per site
    |
    | upload_usages = usages accepted by the generic professional upload
    |   endpoint (UploadImageRequest / ReorderPoolImagesRequest). `design` and
    |   `documents` have dedicated controllers with their own authorization.
    |   'gallery' retired 2026-09-01 (Item 5, one media pool): the wire
    |   stopped serving that lane 2026-08-14, the dashboard never sends it,
    |   and every remaining writer moved to the content + pool-item bridge —
    |   accepting it here was the last open write door.
    */
    'upload_usages' => ['content'],

    'upload_limits' => [
        // 6 -> 20 (owner, 2026-08-27): sized for the old gallery-style use;
        // the media pool is a real photo library now that the upload door
        // feeds the sitepage gallery.
        'content' => ['max' => (int) env('PARTNA_CONTENT_IMAGE_MAX', env('SIDEST_CONTENT_IMAGE_MAX', 20))],
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

    // Menu scan upload ceiling (KB). Backend replacement for the FE route's
    // 4MB Vercel cap — a multi-page print-shop PDF fits now. Mistral's own
    // document limit is 50MB, so 20MB base64s (~27MB) with headroom.
    'menu_scan_max_upload_size' => (int) env('PARTNA_MENU_SCAN_MAX_UPLOAD_KB', 20480),

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
        'timeout_seconds' => (int) env('PARTNA_HTTP_FETCH_TIMEOUT_SECONDS', 15),
        // Max redirect hops followed; each hop is re-validated for SSRF before
        // being followed (SafeUrlFetcher::fetch() / fetchMany()).
        'max_redirects' => (int) env('PARTNA_HTTP_FETCH_MAX_REDIRECTS', 8),
        // Hard cap on a fetched terminal response body (bytes). A response whose
        // declared Content-Length OR actual body exceeds this is rejected —
        // fetch() throws SafeUrlException, fetchMany() drops it to null — so a
        // hostile/oversized URL can't feed a multi-hundred-MB body into the
        // link-preview and menu/shop scrapers. 10 MB is generous for the HTML /
        // JSON those parse.
        'max_bytes' => (int) env('PARTNA_HTTP_FETCH_MAX_BYTES', 25 * 1024 * 1024),
        // TCP connect-phase timeout (seconds) — separate from the read timeout
        // above. A SYN-blackholed host would otherwise ride Guzzle's default
        // connect budget on top of timeout_seconds; this caps that leg
        // explicitly. Applied globally to every SafeUrlFetcher call site
        // (menu/shop/events/link-card scrapers included) AND to
        // YoutubeThumbnailResolver's raw pool, not just connect.
        'connect_timeout_seconds' => (int) env('PARTNA_HTTP_CONNECT_TIMEOUT_SECONDS', 6),
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
        'connect_budget_seconds' => (int) env('PARTNA_CONNECT_BUDGET_SECONDS', 45),

        // Wall-clock budget for ONE /routing/preview call (FI-3, 2026-08-20).
        // Tighter than connect_budget_seconds on purpose: the preview fires on
        // every keystroke pause and its only I/O is the short-link expander's
        // capped fetch — a miss degrades to the unexpanded answer, and the
        // paste's route() re-tries under the full connect budget (cached, so
        // usually free).
        'preview_budget_seconds' => (int) env('PARTNA_PREVIEW_BUDGET_SECONDS', 8),

        // Wall-clock budget for the paste route (SCALE-10, 2026-08-25):
        // RoutingController::store()'s item arm (MediaSeeder/EventsSeeder) and
        // its fallthrough route() call were both reusing connect_budget_seconds
        // — a 45s CONNECT budget on an INTERACTIVE paste, which can hold a
        // request worker for the full 45s on a slow/blackholed host. Own key,
        // sized like preview_budget_seconds: the item read and route()'s
        // short-link expansion are each 1–2 capped fetches, not a multi-step
        // connect flow. A miss degrades cleanly — the item arm falls through
        // to the existing card write, route()'s expander never throws (see its
        // docblock) — nothing here is worth 45s of a worker.
        'paste_budget_seconds' => (int) env('PARTNA_PASTE_BUDGET_SECONDS', 10),

        // Wall-clock budget (seconds) for one PlatformRefresher::refresh() call —
        // the cron/manual-refresh mirror of connect_budget_seconds above. Must stay
        // meaningfully below RefreshConnectionJob's 120s $timeout, leaving headroom
        // (~30s) for the non-fetch work a refresh still does after the fetch closes:
        // ScheduledRefresh's Cache::lock($key,10)->block(5,…) acquisition, the
        // projector sync() upserts, the model write + observer purge, the health
        // notifier. If the budget ever meets/exceeds 120s the job's SIGKILL wins
        // and the budget is moot — see RefreshBudgetInvariantTest.
        'refresh_budget_seconds' => (int) env('PARTNA_REFRESH_BUDGET_SECONDS', 100),

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
        // Owned-media byte mirroring (MirrorMediaAssetJob). Split off 'images'
        // 2026-08-18: a build wave's ~300 background mirrors were queued in front
        // of uploads a user was actively waiting on, and one queue name cannot
        // express two urgencies. Ranked directly BELOW 'images' and above
        // 'analytics' in supervisor-1 (config/horizon.php) — see the note there.
        'media_mirror' => env('PARTNA_QUEUE_MEDIA_MIRROR', 'media-mirror'),
        // Queue CONNECTION for image mirrors (2026-09-04). `cloud` on Laravel
        // Cloud = the `media-mirror` managed queue (scale-to-zero Flex
        // workers, up to 20+ in parallel — a signup's wave drains in seconds
        // instead of the 2-process Horizon lane's minutes). Null/unset keeps
        // the app default (redis under Horizon) for local/CI. Videos always
        // stay on Horizon: a 15 MB reel over a cold edge connection can
        // outrun the managed queue's 90s job ceiling.
        'media_mirror_connection' => env('MEDIA_MIRROR_QUEUE_CONNECTION'),
        // Streaming live-status polling (CheckStreamingLiveStatusJob).
        'streaming' => env('PARTNA_QUEUE_STREAMING', 'streaming'),
        // Platform scraping jobs (InstagramConnectJob etc).
        'scraping' => env('PARTNA_QUEUE_SCRAPING', 'scraping'),
        // Signup-priority lane (2026-09-02). Listed FIRST on supervisor-long so a
        // new build's own jobs (generate, prewarm, approve, bio chains, Square/
        // Google enrich) never queue behind the PREVIOUS signup's ~10-job
        // scraping fan-out — measured 34s and 57s of "posted → building" wait.
        'signup' => env('PARTNA_QUEUE_SIGNUP', 'signup'),
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

        // Auto-connect of a booking platform discovered by LinkRouter from a
        // user's own Instagram. DELIBERATELY NOT part of 'deferred' above:
        // that key means "this platform uses the deferred connect flow", and
        // overloading it to also mean "auto-connect is on" would conflate two
        // independent things — flipping it to stop runaway auto-fetches would
        // also break every dashboard connect.
        'auto_booking' => [
            'enabled' => (bool) env('PARTNA_AUTO_BOOKING_ENABLED', true),

            // Ceiling on outbound salon-page scrapes per day across the whole
            // install. Mirrors partna.routing.probe's global_daily_cap: an
            // unbounded outbound request made on a user's say-so is an
            // amplification vector aimed at someone else. Generous, because
            // builds are serialised at one concurrent (supervisor-long,
            // maxProcesses => 1) — but a real ceiling if that ever changes.
            'global_daily_cap' => (int) env('PARTNA_AUTO_BOOKING_DAILY_CAP', 500),

            // Two people at one salon signing up would otherwise scrape the same
            // page twice. Deliberately shorter than team_cache_seconds (86400):
            // this menu feeds DISPLAYED PRICING, not just a picker roster.
            'menu_cache_seconds' => (int) env('PARTNA_AUTO_BOOKING_MENU_CACHE_SECONDS', 3600),
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
            'youtube-music' => (int) env('PARTNA_REFRESH_INTERVAL_YOUTUBE_MUSIC', 12 * 3600),
            'spotify' => (int) env('PARTNA_REFRESH_INTERVAL_SPOTIFY', 12 * 3600),
            'soundcloud' => (int) env('PARTNA_REFRESH_INTERVAL_SOUNDCLOUD', 12 * 3600),
            'bandcamp' => (int) env('PARTNA_REFRESH_INTERVAL_BANDCAMP', 12 * 3600),
            'apple-music' => (int) env('PARTNA_REFRESH_INTERVAL_APPLE_MUSIC', 12 * 3600),
            'apple-podcast' => (int) env('PARTNA_REFRESH_INTERVAL_APPLE_PODCAST', 12 * 3600),
            // Weekly, not 12h like apple-podcast: this refresh is a BILLED
            // vendor call (spotify_podcasts cap) re-pulling a show identity
            // card that rarely changes — episodes ride the ingest lane on
            // their own cadence, so cadence here is spend, not freshness.
            'spotify_podcasts' => (int) env('PARTNA_REFRESH_INTERVAL_SPOTIFY_PODCASTS', 7 * 86400),
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
                'pool_concurrency' => (int) env('PARTNA_REFRESH_YTIMG_POOL', 20),
                // 'hq' verdicts re-probe on this cadence (maxres may appear post-upload);
                // 'maxres' verdicts keep the long CACHE_DAYS TTL (never regresses). 6h default.
                'hq_recheck_ttl_seconds' => (int) env('PARTNA_REFRESH_YTIMG_HQ_RECHECK_TTL', 21600),
            ],
            // Google Places media — BILLED per call. Keep the concurrent burst tight.
            'google_places' => [
                'pool_concurrency' => (int) env('PARTNA_REFRESH_PLACES_POOL', 8),
            ],
            // Shared SafeUrlFetcher::fetchMany pool (Eventbrite/Humanitix HTML scrapes;
            // WAF-ban risk in aggregate). Caps every fetchMany caller globally.
            'fetch_many' => [
                'pool_concurrency' => (int) env('PARTNA_REFRESH_FETCH_MANY_POOL', 12),
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

    'catalog' => [
        // How long the detector kill-switch set is memoised. Short on purpose:
        // this is also the worst-case delay before a suspension that missed
        // its invalidation stops applying, and the read it saves is one
        // primary-key scan of a table that holds a handful of rows.
        'suspension_cache_ttl_seconds' => (int) env('PARTNA_CATALOG_SUSPENSION_CACHE_TTL_SECONDS', 60),

        // Ceiling on the masked path shape recorded in catalog.unmatched_domains.
        // The shape is for triage ("is this a profile URL or a product URL?"),
        // so a handful of segments is all it needs to be useful — and a bound
        // is what stops a pathological URL becoming the row.
        'unmatched_path_shape_segments' => (int) env('PARTNA_CATALOG_UNMATCHED_PATH_SHAPE_SEGMENTS', 4),
    ],

    'routing' => [
        'probe' => [
            // Probes one worker run may spend. Deliberately small: a page with
            // 200 links should not be 200 outbound requests.
            'per_run_cap' => (int) env('PARTNA_ROUTING_PROBE_PER_RUN_CAP', 12),
            'user_daily_cap' => (int) env('PARTNA_ROUTING_PROBE_USER_DAILY_CAP', 120),
            'global_daily_cap' => (int) env('PARTNA_ROUTING_PROBE_GLOBAL_DAILY_CAP', 6000),
            // Wall-clock ceiling for the WHOLE probe cascade, not per probe.
            'budget_seconds' => (int) env('PARTNA_ROUTING_PROBE_BUDGET_SECONDS', 30),
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

        // SLOP-21. A detector regex that won't compile fails closed, which is
        // indistinguishable from "no match" — so LinkProjector reports it. The
        // projector runs on every paste, hence a per-detector+field window
        // rather than one report per request. `catalog:compile` is the real
        // gate; this covers what bypasses it.
        'malformed_pattern_report_ttl_seconds' => (int) env('PARTNA_ROUTING_MALFORMED_PATTERN_REPORT_TTL_SECONDS', 3600),

        'link_in_bio' => [
            // SCALE-5. Paces successive fetches at ONE host inside a single
            // import — 50 rapid sequential requests with no delay is exactly
            // the shape a bio-link host's bot-detection is built to catch, and
            // a block degrades every future import from that host, not just
            // this one. The T9 grant already raised MAX_PAGES 20 -> 50; this
            // is about spacing those requests out, not about how many there are.
            'page_delay_ms' => (int) env('PARTNA_LINK_IN_BIO_PAGE_DELAY_MS', 250),
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

    // Enquiry notification reconciliation (drill 03, 2026-08-06). Drains
    // site.enquiries rows whose notification dispatch failed because Redis was
    // unreachable — see ReconcileEnquiryNotifications.
    'enquiry' => [
        // Past this age, the VISITOR's "we received your message" confirmation
        // is skipped: a receipt arriving hours late reads worse than none. The
        // professional's own notification has no such staleness problem and is
        // always re-dispatched.
        'confirmation_reconcile_window_minutes' => (int) env('PARTNA_ENQUIRY_CONFIRMATION_RECONCILE_WINDOW_MINUTES', 60),

        // Rows drained per run, so a long outage spreads over several ticks
        // instead of flooding the queue on the first tick after recovery.
        'reconcile_batch_size' => (int) env('PARTNA_ENQUIRY_RECONCILE_BATCH_SIZE', 200),

        // A marker older than this means leads were captured and nobody was
        // told. That is the condition worth paging on.
        'notifications_pending_alert_minutes' => (int) env('PARTNA_ENQUIRY_NOTIFICATIONS_PENDING_ALERT_MINUTES', 30),
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
        'enquiry_reminder' => 14,  // superseded by the enquiry being handled either way
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
            'enquiry_reminder' => EnquiryReminderMail::class, // unread-enquiry nudge (partna:notify-unanswered-enquiries)
            'policy_update' => PolicyUpdateMail::class,
            'profile_tasks' => ProfileTaskMail::class,

            // OV-H automatic dispatchers. Only the `critical` ones carry a mailable
            // (email fires only for critical notifications — see NotificationPublisher).
            // The generic CriticalNotificationMail renders the notification through the
            // shared OTP layout family; SendTransactionalNotificationEmailJob also falls
            // back to it for any critical notification whose category is unmapped.
            'achievement' => AchievementMail::class,              // milestones / first-enquiry — celebration mail
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
            //
            // 100, raised from 10 (Nightwatch #371). The floors above carry only
            // a few points of margin — `pro` sits ~7 points under its ~87%
            // observed ceiling — and a margin that thin judged against 10 reads
            // is not a measurement. At 10, a bucket of 13 reads and 3 misses
            // breaches an 80% floor; on a quiet hour, with observer-driven
            // invalidation busting these keys on every dashboard write, two
            // edits are enough to do it with nothing wrong. The sample has to be
            // large enough for the rate to mean anything before the floor can
            // distinguish a real regression from one cache bust.
            'min_sample' => (int) env('PARTNA_CACHE_SLO_MIN_SAMPLE', 100),
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

        // The sitepage wire (`api/public/profiles/*`) takes its OWN short TTL
        // (owner plan, 2026-08-19): the router's HTML cache is purged the moment
        // an edit lands and the next render reads this endpoint through Laravel
        // Cloud's edge (outside our purge reach) — a long s-maxage there re-pins
        // pre-edit data under the router's 24 h key. 5 s bounds that window; the
        // backend's 60 s in-process payload cache keyed on updated_at keeps the
        // origin cheap. No SWR on this prefix.
        'public_profile_max_age' => (int) env('PARTNA_CACHE_PUBLIC_PROFILE_MAX_AGE', 5),

        // CFG-3: seconds an expired edge entry may be served stale while the CDN
        // refreshes it in the background. 0 (the default) omits the directive
        // entirely, so this ships inert and is enabled per environment via env var.
        //
        // Distinct from `swr_defer_recompute` above despite the shared "SWR"
        // shorthand: that one defers an internal cache rebuild past the response,
        // this one is a wire directive on Cache-Control.
        //
        // Purpose is the once-per-TTL blocking revalidation and Cloudflare's
        // concurrent-request collapsing, NOT the multi-second stall investigated
        // on 2026-08-10 — that was the tester's own network path.
        'public_swr' => (int) env('PARTNA_CACHE_PUBLIC_SWR', 0),

        // CFG-3/PGR-17 (public-surface audit): the alias→canonical 301 redirects
        // in PublicSiteController::show()/showByHeader() used to carry a 5-minute
        // Cache-Control max-age here (CFG-3, replacing browsers' "cache an un-timed
        // 301 forever" default). PGR-17 tightened that to a hardcoded
        // `private, max-age=0, must-revalidate` — a rapid handle reclaim could
        // otherwise misdirect a visitor for up to the old TTL — so there is no
        // longer a configurable window; this key was removed rather than left
        // silently unread.

        // Absolute offsets, in seconds FROM THE PRIMARY PURGE, at which follow-up
        // purges land. Not per-hop delays: the primary dispatches all of them
        // up-front, each with its own delay and depth. One entry since the
        // 2026-08-19 prefix-purge rewrite (was 120/300/900): it clears the
        // profile wire's 5 s s-maxage window (public_profile_max_age) for a
        // visitor who raced the primary purge and could have re-pinned a stale
        // render under the router's 24h TTL. Every entry MUST exceed
        // CloudflareCachePurgeJob's follow-up $uniqueFor (5) or a follow-up
        // would coalesce into its own predecessor.
        'purge_followup_schedule' => [15],

        // Ceiling for the shared cloudflare-purge job funnel (jobs/minute
        // across the whole install — each job is ~2 API requests). Sized
        // under Cloudflare's own purge rate limit so a connect burst queues
        // here instead of 429ing there (observed 2026-08-27 bulk run).
        //
        // Raised 20 → 60 on 2026-08-30, with the measurement that justifies it.
        // At 20/min the funnel could not drain a build burst inside the job's
        // own retryUntil(10 min): jobs were RELEASED by the limiter until the
        // window expired, then died MaxAttemptsExceeded. Measured 55 such
        // failures in 6h on 2026-08-28, clustered into the exact minutes a batch
        // was building — and, decisively, 49 more in 90 minutes on 2026-08-30
        // AFTER the 15s purge debounce landed. Cutting the amplification was
        // the right first fix and it was not sufficient on its own; a batch of
        // 39 accounts still outruns 20/min.
        //
        // 60 drains that same burst in under two minutes and stays far under
        // Cloudflare's own ceiling (their general API allows ~240 requests/min,
        // and each job is ~2). Still an env override, so ops can move it
        // without a deploy.
        'purge_api_per_minute' => (int) env('PARTNA_CF_PURGE_API_PER_MINUTE', 60),

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
        // The high-priority moderation lane's NAME lives in
        // App\Jobs\Moderation\ModerationQueue::HIGH (a fixed contract shared
        // with config/horizon.php) — the env-tunable key that stood here had
        // zero readers and an env flip would have stranded suspend jobs on a
        // lane no supervisor consumes (plan 05 pass 3, 2026-08-27).
        // SLA breach thresholds per severity level (hours). severity_5 = most urgent.
        // breach_warning_min: minutes before the SLA deadline at which to emit an early warning.
        'sla' => [
            'severity_5_hours' => 1,
            'severity_4_hours' => 4,
            'severity_3_hours' => 24,
            'severity_2_hours' => 72,
            'severity_1_hours' => 168,
            'breach_warning_min' => 120,
            // moderation:sla-scan runs every 15 min against a 120-min warning
            // window, so a case stays "at risk" for ~8 scan runs before it
            // breaches. Without a cooldown, an un-suppressed alert would page
            // on-call ~8x for the same case (#W1-LIFE-1). Keyed on max
            // severity in the scan, so an escalation to a higher band still
            // pages immediately instead of being muted by the lower band's
            // cooldown.
            'alert_cooldown_seconds' => (int) env('PARTNA_MODERATION_SLA_ALERT_COOLDOWN_SECONDS', 3600),
        ],
    ],

    'analytics' => [
        // CFG-5: LogLeadRateLimits dedup window — auto-retry bursts (browsers firing 2-3
        // retries on one rate-limit hit) within this many seconds collapse into one
        // analytics.lead_submissions row, keyed by (ip_hash, subdomain).
        'lead_rate_limit_dedup_seconds' => (int) env('PARTNA_ANALYTICS_LEAD_RATE_LIMIT_DEDUP_SECONDS', 10),

        // SCALE-13: hard ceiling on a staff segment's resolved user-id set before it
        // becomes a whereIn. Above this, Postgres traverses `= ANY(ARRAY[...])` per
        // candidate row on analytics.site_visits. Chunking is NOT an option — the
        // aggregates are COUNT(DISTINCT ...), which does not sum across chunks.
        'staff_segment_max_users' => (int) env('PARTNA_ANALYTICS_STAFF_SEGMENT_MAX_USERS', 2000),

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
        // Action-beacon dedup TTLs (smart-scoring ingest, plan 01): seen is a
        // per-visit window like section/item above; tap mirrors the rapid
        // double-tap click window.
        'action_dedup_ttl_seconds' => (int) env('PARTNA_ANALYTICS_ACTION_DEDUP_TTL_SECONDS', 300),
        'action_tap_dedup_ttl_seconds' => (int) env('PARTNA_ANALYTICS_ACTION_TAP_DEDUP_TTL_SECONDS', 3),

        // SCALE-3: per-SITE pageview ingest ceiling, per fixed one-minute window.
        // Every OTHER control on the pageview route is per-IP — throttle:analytics
        // (AppServiceProvider::configureRateLimiting, 'analytics') is 120/min per
        // visitor IP plus a 3000/min per-true-IP backstop. A distributed crawler
        // sweep spread across many source IPs against one viral page passes all of
        // them, and the resulting ingest consumes shared `analytics` queue capacity
        // that belongs to every other tenant. This is the only tenant-scoped bound.
        //
        // NOT a bot filter and NOT a dedup: pageview deliberately records bot UAs and
        // genuine refreshes (owner decision — see AnalyticsController::pageview()).
        // Sized far above any plausible organic minute (2000/min ≈ 2.9M/day for ONE
        // site, against a whole-platform target of ~1M beacons/day), so only an
        // abusive or runaway source can reach it. Over the cap the beacon still
        // answers 201 — only the queue write is dropped.
        'pageview_site_cap_per_minute' => (int) env('PARTNA_ANALYTICS_PAGEVIEW_SITE_CAP_PER_MINUTE', 2000),

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
            'menu' => 'Menu', 'book' => 'Book',
            'events' => 'Events', 'gallery' => 'Gallery', 'reviews' => 'Reviews',
            'documents' => 'Documents', 'contact' => 'Contact', 'links' => 'Links',
        ],
    ],
];
