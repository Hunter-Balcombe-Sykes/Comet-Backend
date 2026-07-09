<?php

namespace App\Enums;

/**
 * Canonical sitepage page-ids for the ONE skeleton taxonomy.
 *
 * The 16 cases below, IN THIS ORDER, are the canonical default page order — the
 * order pages appear before any popularity re-ranking is applied. Every page is
 * presence-gated per site (shown only when the site has content for it); three
 * are additionally Business-only (see BUSINESS_ONLY).
 *
 * LOCKSTEP — this enum is mirrored, id-for-id and in the same order, in
 * partna-monorepo/packages/design-system/src/engines/page-taxonomy.ts
 * (SITEPAGE_IDS + BUSINESS_ONLY_PAGES + SECTION_KEY_TO_PAGE). There is no shared
 * source; change BOTH files together or the frontend/backend taxonomies drift.
 */
enum SitepageId: string
{
    case Home = 'home';
    case Listen = 'listen';
    case Watch = 'watch';
    case Shop = 'shop';
    case Menu = 'menu';
    case Book = 'book';
    case Reservations = 'reservations';
    case Events = 'events';
    case Gallery = 'gallery';
    case Reviews = 'reviews';
    case Documents = 'documents';
    case Contact = 'contact';
    case Pinterest = 'pinterest';
    case Strava = 'strava';
    case Skool = 'skool';
    case Links = 'links';

    /**
     * Pages available only to Business Partna accounts. Gate on the derived
     * capability (AccountCapabilities), never on account_type directly.
     *
     * @var list<string>
     */
    public const BUSINESS_ONLY = ['menu', 'reviews', 'reservations'];

    /**
     * Legacy analytics section_key -> page-id bucketing. The scoring job
     * (analytics:compute-popularity) folds historical section_views / link_clicks
     * rows into page-level scores through this map. Keys absent here contribute
     * no historical signal — new impressions accrue via the page-id-native path.
     * PROVISIONAL entries are legacy keys that span two pages; flagged inline.
     *
     * @var array<string, string>
     */
    public const SECTION_KEY_TO_PAGE = [
        // Direct 1:1 (section_key already equals the page-id)
        'listen' => 'listen',
        'watch' => 'watch',
        'shop' => 'shop',
        'menu' => 'menu',
        'book' => 'book',
        'reservations' => 'reservations',
        'events' => 'events',
        'gallery' => 'gallery',
        'reviews' => 'reviews',
        'contact' => 'contact',
        'pinterest' => 'pinterest',
        'strava' => 'strava',
        'skool' => 'skool',

        // Home — bio + the social icon row live on Home (socials are not a page)
        'bio' => 'home',
        'instagram' => 'home',

        // Documents
        'document' => 'documents',

        // Shop — products, Bandcamp tracks, and Bandcamp itself all sell via Shop
        'shop-products' => 'shop',
        'shop-tracks' => 'shop',
        'bandcamp' => 'shop',

        // Listen — music/audio platforms (deezer + mixcloud retired; legacy rows remain)
        'music' => 'listen',
        'spotify' => 'listen',
        'soundcloud' => 'listen',
        'podcast' => 'listen',
        'deezer' => 'listen',
        'mixcloud' => 'listen',

        // Watch — video/streaming platforms
        'twitch' => 'watch',
        'vimeo' => 'watch',
        'youtube' => 'watch',

        // Events — ticketing platforms + attendance CTAs
        'attend' => 'events',
        'eventbrite' => 'events',
        'humanitix' => 'events',

        // Book — native services
        'services' => 'book',

        // Contact — absorbs hours / location / map / newsletter / workplace
        'hours' => 'contact',
        'location' => 'contact',
        'workplace' => 'contact',
        'newsletter' => 'contact',
        'subscribe' => 'contact',
        'google-business' => 'contact', // PROVISIONAL — GB profile/location bucket

        // Gallery — old About page's Google-photos role folds into Gallery
        'about' => 'gallery',           // PROVISIONAL — old About visual/photos role

        // Links — arbitrary/custom user blocks
        'custom' => 'links',
        'other' => 'links',             // PROVISIONAL — legacy misc grouping

        // Community — legacy strava+skool grouping; best-effort to Skool
        'community' => 'skool',         // PROVISIONAL — legacy grouped section

        // Omitted intentionally: 'player-test' (test-fixture noise)
    ];

    /**
     * Canonical default order as plain strings (enum case order).
     *
     * @return list<string>
     */
    public static function canonicalOrder(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** Is this page gated to Business Partna accounts? */
    public function isBusinessOnly(): bool
    {
        return in_array($this->value, self::BUSINESS_ONLY, true);
    }
}
