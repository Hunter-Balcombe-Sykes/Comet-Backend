<?php

// V5 Platform System — configuration for the unified Platform → Content Pool architecture.
// Inheritance: base → category → platform. Resolution: check platform override → category default → base.

return [

    /*
    |--------------------------------------------------------------------------
    | Base (System-Wide) Defaults
    |--------------------------------------------------------------------------
    | Every platform inherits these unless its category or the platform itself
    | overrides them.
    */
    'base' => [
        'refresh_interval' => '24 hours',
        'source_method' => 'api',
        'rules' => [
            'release_sync' => ['default' => true, 'description' => 'Auto-select latest item'],
            'full_sync' => ['default' => true, 'description' => 'Auto-sync all items'],
            'auto_sync' => ['default' => true, 'description' => 'Scheduled auto-refresh'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Category Defaults
    |--------------------------------------------------------------------------
    | Each category can override base defaults. Platforms in that category
    | inherit from here unless they specify their own overrides.
    */
    'categories' => [
        'social media' => [
            'refresh_interval' => '12 hours',
            'source_method' => 'apify',
        ],
        'video' => [
            'refresh_interval' => '12 hours',
            'source_method' => 'api',
        ],
        'streaming' => [
            'refresh_interval' => '12 hours',
            'source_method' => 'api',
        ],
        'music' => [
            'refresh_interval' => '12 hours',
            'source_method' => 'api',
        ],
        'podcast' => [
            'refresh_interval' => '12 hours',
            'source_method' => 'api',
        ],
        'booking' => [
            'refresh_interval' => '2 days',
            'source_method' => 'api',
        ],
        'reservations' => [
            'refresh_interval' => '2 days',
            'source_method' => 'api',
        ],
        'ordering' => [
            'refresh_interval' => '6 hours',
            'source_method' => 'api',
        ],
        'events' => [
            'refresh_interval' => '6 hours',
            'source_method' => 'api',
        ],
        'ecommerce' => [
            'refresh_interval' => '6 hours',
            'source_method' => 'api',
        ],
        'education' => [
            'refresh_interval' => '24 hours',
            'source_method' => 'api',
        ],
        'business' => [
            'refresh_interval' => '2 days',
            'source_method' => 'api',
        ],
        'other' => [
            'refresh_interval' => '24 hours',
            'source_method' => 'api',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Manual-Only Overrides
    |--------------------------------------------------------------------------
    | Platforms listed here have auto_sync forced to false and interval forced
    | to null (manual refresh only), regardless of category defaults.
    */
    'manual_only_platforms' => [
        'instagram', // Apify paid actor, cooldown 600s, daily cap 200
    ],

    /*
    |--------------------------------------------------------------------------
    | Known Platform Overrides
    |--------------------------------------------------------------------------
    | Per-platform values that differ from their category defaults.
    | Read by V5PlatformRegistry::resolve() as part of the inheritance chain:
    |   DB column → platform_overrides config → category config → base
    */
    'platform_overrides' => [
        'google-business' => [
            'refresh_interval' => '2 days',
            'source_method' => 'api',
        ],
        'fresha' => [
            'refresh_interval' => '2 days',
            'source_method' => 'api',
        ],
        'eventbrite' => [
            'refresh_interval' => '6 hours',
        ],
        'humanitix' => [
            'refresh_interval' => '6 hours',
        ],

        // Ordering platforms all use Apify actors for menu scraping
        'uber-eats' => [
            'source_method' => 'apify',
        ],
        'doordash' => [
            'source_method' => 'apify',
        ],
        'square-online' => [
            'source_method' => 'apify',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limits & Budgets
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'apify' => [
            'global_daily_cap' => (int) env('V5_APIFY_GLOBAL_DAILY_CAP', 1000),
            'instagram_daily_cap' => (int) env('V5_APIFY_INSTAGRAM_DAILY_CAP', 200),
            'instagram_cooldown' => (int) env('V5_APIFY_INSTAGRAM_COOLDOWN', 600),
            'menu_daily_cap' => (int) env('V5_APIFY_MENU_DAILY_CAP', 300),
            'google_business_daily_cap' => (int) env('V5_APIFY_GBP_DAILY_CAP', 300),
        ],
        'places' => [
            'global_daily_cap' => (int) env('V5_PLACES_GLOBAL_DAILY_CAP', 500),
            'per_user_daily_cap' => (int) env('V5_PLACES_USER_DAILY_CAP', 60),
        ],
        'ai_spend' => [
            'daily_cap' => (int) env('V5_AI_SPEND_DAILY_CAP', 500),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Conflict Resolution — Default Rules by Data Type
    |--------------------------------------------------------------------------
    */
    'conflict_rules' => [
        'text' => 'most_recent',
        'image' => 'highest_resolution',
        'file' => 'largest_file_size',
        'date' => 'earliest',
        'url' => 'https_preferred',
        'number' => 'most_recent',
        'boolean' => 'true_wins',
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft Delete Retention (days before hard-delete in purge sweep)
    |--------------------------------------------------------------------------
    */
    'soft_delete_retention_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Instagram-Specific Config
    |--------------------------------------------------------------------------
    */
    'instagram' => [
        'actor' => env('V5_INSTAGRAM_ACTOR', 'figue~instagram-profile-scraper'),
        'posts_limit' => (int) env('V5_INSTAGRAM_POSTS_LIMIT', 36),
        'extract_caption_urls' => true,
        'extract_bio_urls' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Menu Scraper Config (Apify actors)
    |--------------------------------------------------------------------------
    */
    'menu' => [
        'square_actor' => env('V5_SQUARE_MENU_ACTOR', 'square-menu-scraper'),
        'ubereats_actor' => env('V5_UBEREATS_MENU_ACTOR', 'ubereats-menu-scraper'),
        'doordash_actor' => env('V5_DOORDASH_MENU_ACTOR', 'doordash-menu-scraper'),
    ],

];
