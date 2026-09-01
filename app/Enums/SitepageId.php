<?php

namespace App\Enums;

/**
 * Canonical sitepage page-ids for the ONE architecture taxonomy.
 *
 * The 12 cases below, IN THIS ORDER, are the canonical default page order — the
 * order pages appear before any popularity re-ranking is applied. Every page is
 * presence-gated per site (shown only when the site has content for it); some
 * must additionally prove the content is the OWNER's to show (see
 * ATTRIBUTION_GATED).
 *
 * 2026-08-27 (smart-scoring plan): `reservations`, `strava` and `skool` left
 * the taxonomy. The PLATFORMS stay — a reservation widget, a Strava club or a
 * Skool community is a link/action now, never a page of its own (the frontend
 * had already stopped rendering all three).
 *
 * LOCKSTEP — this enum is mirrored, id-for-id and in the same order, in
 * partna-monorepo/packages/design-system/src/engines/page-taxonomy.ts
 * (SITEPAGE_IDS + PAGE_TO_SECTION_KEY + PAGE_LABELS, plus that file's
 * frontend-only extensions). There is no shared source; change BOTH files
 * together or the frontend/backend taxonomies drift.
 */
enum SitepageId: string
{
    case Home = 'home';
    case Listen = 'listen';
    case Watch = 'watch';
    case Shop = 'shop';
    case Menu = 'menu';
    case Services = 'services';
    case Events = 'events';
    case Gallery = 'gallery';
    case Reviews = 'reviews';
    case Documents = 'documents';
    case Contact = 'contact';
    case Links = 'links';

    /**
     * Pages presence alone may not advertise. Presence proves the site HAS the
     * content; these pages must additionally prove the content is the page
     * OWNER's to show, against the owner's own data, every build. Enforced by
     * SitepageDataResolverService::gateOwnerAttribution(), which asks
     * PoolResolver::hasSelection() — the same arithmetic that decides what is
     * behind the page — so nav can never advertise a page whose pool resolves
     * empty. Fails CLOSED: a probe fault drops the page rather than advertising
     * an unproven one.
     *
     * THE HISTORY, because this constant has now been wrong twice.
     *
     * It was BUSINESS_ONLY = ['menu', 'reviews'], gated on
     * can_use_multipage_site — a flag minted by #30 to answer "may this account
     * select the atlas multi-page skeleton?", i.e. account_type verbatim. That
     * is how ollies (a Google-sourced cafe filed account_type=partna) shipped
     * 105 ingested menu items with no page to render them.
     *
     * On 2026-09-01 it became PAGE_CAPABILITY = ['menu' => 'can_use_menu'] and
     * `reviews` was dropped with only a comment in its place. Both halves were
     * wrong. `menu` was wrong because a render-time capability veto is the
     * exact mechanism PageCapabilities' docblock retired ("A page that exists
     * but is silently dropped at render is the failure mode that produced 'my
     * Menu page disappeared and nothing told me why'") — and can_use_menu reads
     * `sector`, a column three writers move and one path never stamps at all,
     * so any account whose sector goes null or non-food after its menu was
     * ingested loses the page silently with the items still in the payload.
     * That is the incident, re-created by the commit that quoted it. Menu is
     * gated at the WRITE seam instead; see gateOwnerAttribution()'s comment.
     * `reviews` was wrong because the entry it deleted was the only structural
     * thing standing between a resolved review and a page, in the same commit
     * family whose brief is that a review must not appear on a page whose owner
     * is not named in it. A comment is not a gate. This list is.
     *
     * @var list<string>
     */
    public const ATTRIBUTION_GATED = ['reviews'];

    /**
     * Pages available only to standard (partna) accounts — the lifestyle/creator
     * content Business Partna accounts don't offer: Listen (music). Strava and
     * Skool left this list with their pages (2026-08-27) — as plain link
     * platforms they are available to every account type, like any social
     * link. Gate on the derived capability
     * (AccountCapabilities::can_use_lifestyle_pages), never on account_type
     * directly.
     *
     * NOT the frontend-mirrored counterpart of ATTRIBUTION_GATED: this is a
     * backend-presence-only constant. Presence is computed backend-side
     * (presentPageIds → pageOrder) and apps/pages consumes pageOrder verbatim, so
     * there is nothing to mirror in engines/page-taxonomy.ts.
     *
     * `shop` is intentionally excluded — Business accounts keep Shop (hidden from
     * the integrations LIST but managed via the dedicated Products page).
     *
     * @var list<string>
     */
    public const STANDARD_ONLY = ['listen'];

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
        'events' => 'events',
        'gallery' => 'gallery',
        'reviews' => 'reviews',
        'contact' => 'contact',

        // Retired pages (2026-08-27): their platforms live on as links, so
        // historical section rows fold into Links.
        'reservations' => 'links',
        'strava' => 'links',
        'skool' => 'links',

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

        // Community — legacy strava+skool grouping; the pages are gone, so
        // it folds where their links live now.
        'community' => 'links',

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
}
