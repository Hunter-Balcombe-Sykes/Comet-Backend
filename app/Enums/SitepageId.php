<?php

namespace App\Enums;

/**
 * Canonical sitepage page-ids for the ONE architecture taxonomy.
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
    case Services = 'services';
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
     * Pages available only to standard (partna) accounts — the lifestyle/creator
     * content Business Partna accounts don't offer: Listen (music), the Community
     * pages (Strava/Skool), and Pinterest ("Other"). Mirrors the dashboard's
     * account-type hiding (Partna-Frontend lib/integrations/platform-registry.ts
     * → HIDDEN_GROUPS = listen/community/shop/other for business). Gate on the
     * derived capability (AccountCapabilities::can_use_lifestyle_pages), never on
     * account_type directly.
     *
     * NOT the frontend-mirrored counterpart of BUSINESS_ONLY: this is a
     * backend-presence-only constant. Presence is computed backend-side
     * (presentPageIds → pageOrder) and apps/pages consumes pageOrder verbatim, so
     * there is nothing to mirror in engines/page-taxonomy.ts.
     *
     * `shop` is intentionally excluded — Business accounts keep Shop (hidden from
     * the integrations LIST but managed via the dedicated Products page).
     *
     * @var list<string>
     */
    public const STANDARD_ONLY = ['listen', 'pinterest', 'strava', 'skool'];

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
        'services' => 'services',
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

        // Services — native services page. Legacy 'book' section_key (the page-id
        // before the 2026-07-13 rename) folds here so historical rows still bucket.
        'book' => 'services',

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
     * Page-ids renamed in place. Read/write paths normalize a legacy id to its
     * current value so settings persisted under the old id (e.g. a
     * manual_page_order saved as 'book') keep resolving after the rename.
     * 'book' → 'services' (2026-07-13): renamed so the sitepage URL matches the
     * "Services" label; content still stores under the 'book' section_key.
     *
     * @var array<string, string>
     */
    public const LEGACY_PAGE_IDS = ['book' => 'services'];

    /** Normalize a possibly-legacy page-id to its canonical current value. */
    public static function normalizePageId(string $id): string
    {
        return self::LEGACY_PAGE_IDS[$id] ?? $id;
    }

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
