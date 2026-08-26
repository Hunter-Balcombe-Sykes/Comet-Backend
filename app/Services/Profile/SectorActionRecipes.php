<?php

namespace App\Services\Profile;

use App\Services\Design\SectorStylePresets;

/**
 * Identity-driven action recipes (smart-scoring plan, 2026-08-27): per-sector
 * ordered ROLE lists, resolved per site into concrete action-candidate ids and
 * geometric boosts. The lander's top slots read like the Linktree this user
 * would have built: a barber leads with booking, a restaurant with
 * reserve → order, a musician with listen → latest event.
 *
 * Roles name INTENT, not ids — a per-site resolver maps each role onto the
 * best concrete candidate the site actually has (deepest single actionable
 * target: one provider → its `platform:*` link; several → the page that offers
 * the choice, when that page is an action candidate at all). Unresolvable
 * roles are skipped and later entries move up, so the boost ladder never
 * wastes a rung on something the site can't do.
 *
 * Boosts are STATIC identity, not a cold-start crutch: boost(i) = B·r^(i−1)
 * with B=2.0, r=0.75 (+2.0, +1.5, +1.13, +0.84, +0.63). The organic score is
 * bounded ≈1.3 (three normalised terms ≤1.0 + prior ≤0.30), so recipe #1–2
 * are organically uncatchable, entries reorder among THEMSELVES on genuine
 * signal (ActionScorer compares the unboosted component for boosted pairs),
 * and #4–5 are contestable by a genuinely popular organic candidate.
 *
 * ROLE VOCABULARY (each resolves only to candidates ActionCandidates emits —
 * `page:*` exists ONLY for services/menu/shop/events/contact, so e.g. listen
 * always resolves to a platform, never a page):
 *   book           one booking-category platform → platform:*, several (or
 *                  none connected but the page present) → page:services
 *   reserve        a reservations-category platform link — ALWAYS a platform
 *                  (the reservations page left the taxonomy 2026-08-27);
 *                  several → the newest connection
 *   order          one ordering-category platform → platform:*, several →
 *                  page:menu (where the order buttons offer the choice)
 *   menu           page:menu
 *   shop           page:shop, else the one shop-ish platform
 *   listen         one Music-category destination → platform:*, several →
 *                  the editorial ranking picks (spotify first)
 *   latest-release the newest DATED listen-pool item → item:*
 *   latest-event   the newest DATED events-pool item → item:*
 *   top-product    the highest-scored shop-pool item → item:*, else page:shop
 *   top-social     the best social destination by editorial rank → platform:*
 *   contact        page:contact
 */
final class SectorActionRecipes
{
    public const BOOST_BASE = 2.0;

    public const BOOST_RATIO = 0.75;

    /**
     * Bucket recipes — the industry's conversion order, ≤5 roles deep.
     * Rationale per bucket is the comment beside it.
     *
     * @var array<string, list<string>>
     */
    private const BUCKET_RECIPES = [
        // A restaurant visitor books a table, orders, or reads the menu — in
        // that order of commitment; events and contact trail.
        SectorStylePresets::FOOD_DRINK => ['reserve', 'order', 'menu', 'latest-event', 'contact'],
        // A salon/barber visitor is here to get an appointment; the look-book
        // (socials) sells it, then contact and retail.
        SectorStylePresets::BEAUTY_PERSONAL_CARE => ['book', 'top-social', 'contact', 'top-product'],
        // A trainer/physio visitor books a session or asks a question.
        SectorStylePresets::HEALTH_FITNESS => ['book', 'contact', 'top-social', 'latest-event'],
        // Professional services convert by enquiry first, consultation second.
        SectorStylePresets::PROFESSIONAL_SERVICES => ['contact', 'book', 'top-social'],
        // A shop visitor shops; the best product is the hook.
        SectorStylePresets::RETAIL_SHOPPING => ['shop', 'top-product', 'top-social', 'contact'],
        // You call the plumber; booking systems are second-order here.
        SectorStylePresets::HOME_SERVICES => ['contact', 'book', 'top-social'],
        // Stays/venues: book first, table second, what's-on third.
        SectorStylePresets::HOSPITALITY => ['book', 'reserve', 'latest-event', 'contact'],
        // Garage: ring them, or book the service slot.
        SectorStylePresets::AUTOMOTIVE => ['contact', 'book', 'top-social'],
        // Creative default is portfolio-led (socials carry the work), selling
        // and enquiries behind it; musician overrides below.
        SectorStylePresets::CREATIVE_ENTERTAINMENT => ['top-social', 'shop', 'contact', 'book'],
        // Coaching/teaching: book the lesson, then ask.
        SectorStylePresets::EDUCATION_COACHING => ['book', 'contact', 'top-social'],
    ];

    /**
     * Slug refinements where a profession's funnel diverges from its bucket.
     *
     * @var array<string, list<string>>
     */
    private const SLUG_RECIPES = [
        // Cafés/bakeries/food trucks rarely take reservations — order-led.
        'cafe' => ['order', 'menu', 'top-social', 'contact'],
        'bakery' => ['order', 'menu', 'top-social', 'contact'],
        'food-truck' => ['order', 'menu', 'top-social', 'latest-event'],
        // Bars sell the night: table, what's-on, then the list.
        'bar' => ['reserve', 'latest-event', 'menu', 'order', 'top-social'],
        // A musician's Linktree: listen first, then the gig, then the drop.
        'musician' => ['listen', 'latest-event', 'latest-release', 'top-social', 'shop'],
        // Content creators live on their channels.
        'content-creator' => ['top-social', 'shop', 'contact'],
    ];

    /** boost(i) = B·r^(i−1) for the 1-based recipe position. */
    public static function boostFor(int $position): float
    {
        return self::BOOST_BASE * self::BOOST_RATIO ** ($position - 1);
    }

    /**
     * Sector-keyed FRONT of the canonical page order — the pages this
     * industry's visitors expect first, in order; every taxonomy page not
     * named follows in enum order. Read by
     * SitepageDataResolverService::buildPageOrder for the rank-less seed, so
     * day one already reads right (menu first for a restaurant, listen for a
     * musician, services for a barber) and data refines within that frame.
     *
     * @var array<string, list<string>>
     */
    private const PAGE_FRONTS = [
        SectorStylePresets::FOOD_DRINK => ['menu', 'events', 'gallery'],
        SectorStylePresets::BEAUTY_PERSONAL_CARE => ['services', 'gallery', 'shop'],
        SectorStylePresets::HEALTH_FITNESS => ['services', 'events', 'gallery'],
        SectorStylePresets::PROFESSIONAL_SERVICES => ['contact', 'services', 'documents'],
        SectorStylePresets::RETAIL_SHOPPING => ['shop', 'gallery', 'events'],
        SectorStylePresets::HOME_SERVICES => ['contact', 'services', 'gallery'],
        SectorStylePresets::HOSPITALITY => ['services', 'events', 'gallery', 'menu'],
        SectorStylePresets::AUTOMOTIVE => ['contact', 'services', 'gallery'],
        SectorStylePresets::CREATIVE_ENTERTAINMENT => ['gallery', 'watch', 'shop', 'events'],
        SectorStylePresets::EDUCATION_COACHING => ['services', 'contact', 'events'],
    ];

    /** @var array<string, list<string>> */
    private const SLUG_PAGE_FRONTS = [
        'musician' => ['listen', 'events', 'watch', 'shop'],
        'content-creator' => ['watch', 'listen', 'shop'],
        'restaurant' => ['menu', 'events', 'gallery'],
        'bar' => ['events', 'menu', 'gallery'],
        'photographer' => ['gallery', 'services', 'contact'],
        'videographer' => ['watch', 'gallery', 'contact'],
    ];

    /**
     * The identity's preferred page order applied over the canonical roster:
     * the sector front first (present-or-not filtering is the caller's job),
     * then every remaining canonical page in enum order. Falls back to the
     * untouched canonical order for unknown/absent identities.
     *
     * @param  list<string>  $canonical  SitepageId::canonicalOrder()
     * @return list<string>
     */
    public static function pageOrderFor(?string $identity, array $canonical): array
    {
        $front = null;
        if ($identity !== null && $identity !== '') {
            $front = self::SLUG_PAGE_FRONTS[$identity]
                ?? self::PAGE_FRONTS[$identity]
                ?? (($bucket = SectorTaxonomy::bucketFor($identity)) !== null ? (self::PAGE_FRONTS[$bucket] ?? null) : null);
        }
        if ($front === null) {
            return $canonical;
        }

        $ordered = array_values(array_intersect($front, $canonical));

        return array_merge($ordered, array_values(array_diff($canonical, $ordered)));
    }

    /**
     * Sector-keyed cold-start page PRIORS — the importance floors for pages
     * the identity re-weights away from the global config table. Consulted
     * by the scoring job before `config('partna.actions.priors')`.
     *
     * @return array<string, float> action id => prior
     */
    public static function pagePriorsFor(?string $identity): array
    {
        if ($identity === null || $identity === '') {
            return [];
        }
        $bucket = SectorTaxonomy::bucketFor($identity) ?? $identity;

        return match ($bucket) {
            // Menu is a restaurant's front door; events matter, shop rarely.
            SectorStylePresets::FOOD_DRINK => ['page:menu' => 0.30, 'page:events' => 0.18, 'page:shop' => 0.08],
            // The work sells creative accounts; events + merch over enquiry.
            SectorStylePresets::CREATIVE_ENTERTAINMENT => ['page:events' => 0.22, 'page:shop' => 0.18, 'page:menu' => 0.01],
            // A store's storefront outranks everything else it could show.
            SectorStylePresets::RETAIL_SHOPPING => ['page:shop' => 0.30, 'page:menu' => 0.01],
            // Stays/venues: what's-on and the venue's menu both carry weight.
            SectorStylePresets::HOSPITALITY => ['page:events' => 0.22, 'page:menu' => 0.20],
            default => [],
        };
    }

    /**
     * Neutral pseudo-identities the INFERENCE ladder may answer with when
     * users.sector is unset — an integration shape is evidence of a funnel,
     * not of a profession, so these stay deliberately generic.
     *
     * @var array<string, list<string>>
     */
    private const PSEUDO_RECIPES = [
        '_booking_led' => ['book', 'contact', 'top-social'],
    ];

    /**
     * The ordered role recipe for an identity string: a sector slug (slug
     * refinement, else its bucket's table), a bare bucket key, or a pseudo
     * identity from inferIdentity(). [] when nothing matches.
     *
     * @return list<string>
     */
    public static function recipeFor(?string $sector): array
    {
        if ($sector === null || $sector === '') {
            return [];
        }
        if (isset(self::SLUG_RECIPES[$sector])) {
            return self::SLUG_RECIPES[$sector];
        }
        if (isset(self::PSEUDO_RECIPES[$sector])) {
            return self::PSEUDO_RECIPES[$sector];
        }
        if (isset(self::BUCKET_RECIPES[$sector])) {
            return self::BUCKET_RECIPES[$sector];
        }
        $bucket = SectorTaxonomy::bucketFor($sector);

        return $bucket !== null ? (self::BUCKET_RECIPES[$bucket] ?? []) : [];
    }

    /**
     * The inference ladder's integration-shape tier (smart-scoring plan):
     * when users.sector is unset, the connected-platform MIX still names a
     * funnel. Food signals (a menu page, ordering or reservation providers)
     * beat booking signals beat music signals — a restaurant with a booking
     * link is still a restaurant. Returns an identity string recipeFor()
     * understands, or null (global priors, no boosts).
     *
     * Pure over the candidate set; EPHEMERAL by design — never written back
     * to users.sector (a wrong write would flip the FOOD capability set).
     *
     * @param  list<array<string, mixed>>  $candidates
     */
    public static function inferIdentity(array $candidates): ?string
    {
        $reservationKeys = ['opentable', 'resdiary', 'nowbookit'];
        $food = false;
        $booking = false;
        $music = false;
        foreach ($candidates as $c) {
            if ($c['kind'] === 'page' && in_array($c['id'], ['page:menu'], true)) {
                $food = true;
            }
            if ($c['kind'] !== 'platform') {
                continue;
            }
            $key = (string) ($c['meta']['platformKey'] ?? '');
            $page = $c['meta']['page'] ?? null;
            if ($page === 'menu' || in_array($key, $reservationKeys, true)) {
                $food = true;
            } elseif ($page === 'services') {
                $booking = true;
            } elseif (in_array($key, self::LISTEN_RANK, true)) {
                $music = true;
            }
        }

        return match (true) {
            $food => SectorStylePresets::FOOD_DRINK,
            $booking => '_booking_led',
            $music => 'musician',
            default => null,
        };
    }

    /**
     * Editorial destination ranking for `top-social` / multi-provider listen —
     * the zero-data tiebreak. Lower index = better.
     *
     * @var list<string>
     */
    private const SOCIAL_RANK = ['instagram', 'tiktok', 'youtube', 'x', 'facebook', 'linkedin', 'threads', 'snapchat'];

    private const LISTEN_RANK = ['spotify', 'apple-music', 'soundcloud', 'youtube-music', 'bandcamp', 'mixcloud', 'tidal'];

    /**
     * Resolve a sector's recipe against one site's candidate set: action id
     * => boost. Pure — candidates are ActionCandidates::forSite output and
     * $itemScores the stored item-family scores; no DB, no clock.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<string, float>  $itemScores  content item id => score
     * @return array<string, float>
     */
    public static function resolve(?string $sector, array $candidates, array $itemScores = []): array
    {
        $recipe = self::recipeFor($sector);
        if ($recipe === []) {
            return [];
        }

        $out = [];
        $position = 0;
        foreach ($recipe as $role) {
            $id = self::resolveRole($role, $candidates, $itemScores);
            if ($id === null || isset($out[$id])) {
                continue; // unresolvable (or double-resolved) — next entry moves up
            }
            $position++;
            $out[$id] = self::boostFor($position);
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<string, float>  $itemScores
     */
    private static function resolveRole(string $role, array $candidates, array $itemScores): ?string
    {
        return match ($role) {
            'book' => self::providerOrPage($candidates, 'services'),
            'reserve' => self::newestPlatformForPage($candidates, null, 'reservations'),
            'order' => self::providerOrPage($candidates, 'menu', pageMeta: 'menu'),
            'menu' => self::page($candidates, 'menu'),
            'shop' => self::page($candidates, 'shop') ?? self::singlePlatformForPage($candidates, 'shop'),
            'listen' => self::rankedPlatform($candidates, 'listen', self::LISTEN_RANK),
            'latest-release' => self::latestItem($candidates, 'listen'),
            'latest-event' => self::nextEvent($candidates),
            'top-product' => self::topItem($candidates, 'shop', $itemScores) ?? self::page($candidates, 'shop'),
            'top-social' => self::rankedPlatform($candidates, null, self::SOCIAL_RANK),
            'contact' => self::page($candidates, 'contact'),
            default => null,
        };
    }

    /** @param  list<array<string, mixed>>  $candidates */
    private static function page(array $candidates, string $pageId): ?string
    {
        foreach ($candidates as $c) {
            if ($c['id'] === 'page:'.$pageId) {
                return $c['id'];
            }
        }

        return null;
    }

    /**
     * Platform candidates whose meta.page names $page (the page their category
     * powers), or — when $page is null — every platform candidate.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>>
     */
    private static function platformsForPage(array $candidates, ?string $page): array
    {
        $out = [];
        foreach ($candidates as $c) {
            if ($c['kind'] !== 'platform') {
                continue;
            }
            if ($page === null || ($c['meta']['page'] ?? null) === $page) {
                $out[] = $c;
            }
        }

        return $out;
    }

    /**
     * Deepest-single-target rule: exactly one provider → its platform link;
     * several (or none) → the page, when it is a candidate.
     *
     * @param  list<array<string, mixed>>  $candidates
     */
    private static function providerOrPage(array $candidates, string $pageId, ?string $pageMeta = null): ?string
    {
        $platforms = self::platformsForPage($candidates, $pageMeta ?? $pageId);
        if (count($platforms) === 1) {
            return $platforms[0]['id'];
        }

        return self::page($candidates, $pageId) ?? ($platforms !== [] ? self::newest($platforms)['id'] : null);
    }

    /** @param  list<array<string, mixed>>  $candidates */
    private static function singlePlatformForPage(array $candidates, string $page): ?string
    {
        $platforms = self::platformsForPage($candidates, $page);

        return $platforms !== [] ? self::newest($platforms)['id'] : null;
    }

    /**
     * The reservations page left the taxonomy, so its platforms carry a null
     * meta.page — they are recognised by key instead.
     *
     * @param  list<array<string, mixed>>  $candidates
     */
    private static function newestPlatformForPage(array $candidates, ?string $page, string $family): ?string
    {
        $reservationKeys = ['opentable', 'resdiary', 'nowbookit'];
        $matches = [];
        foreach ($candidates as $c) {
            if ($c['kind'] !== 'platform') {
                continue;
            }
            $key = (string) ($c['meta']['platformKey'] ?? '');
            if ($family === 'reservations' && in_array($key, $reservationKeys, true)) {
                $matches[] = $c;
            }
        }

        return $matches !== [] ? self::newest($matches)['id'] : null;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<string>  $rank
     */
    private static function rankedPlatform(array $candidates, ?string $page, array $rank): ?string
    {
        $best = null;
        $bestRank = PHP_INT_MAX;
        foreach (self::platformsForPage($candidates, $page) as $c) {
            $key = (string) ($c['meta']['platformKey'] ?? '');
            $idx = array_search($key, $rank, true);
            if ($idx !== false && $idx < $bestRank) {
                $bestRank = $idx;
                $best = $c['id'];
            }
        }

        return $best;
    }

    /**
     * The event the calendar says matters: the SOONEST UPCOMING occurrence,
     * else the most recent past one — the same peak-at-the-date shape
     * EventTimeRelevance scores with. Resolves off meta.startsAt
     * (ActionCandidates carries f_occurrence through), because real
     * ingested events never have a publishedAt — dating them by
     * publish metadata left this role permanently null on production data
     * (critic find, 2026-08-27).
     *
     * @param  list<array<string, mixed>>  $candidates
     */
    private static function nextEvent(array $candidates): ?string
    {
        $now = now()->getTimestamp();
        $bestUpcoming = null;
        $bestUpcomingAt = PHP_INT_MAX;
        $bestPast = null;
        $bestPastAt = PHP_INT_MIN;
        foreach ($candidates as $c) {
            if ($c['kind'] !== 'item' || (($c['ref']['pool'] ?? null) !== 'events')) {
                continue;
            }
            $raw = $c['meta']['startsAt'] ?? null;
            $at = is_string($raw) && $raw !== '' ? strtotime($raw) : false;
            if ($at === false) {
                continue;
            }
            if ($at >= $now && $at < $bestUpcomingAt) {
                $bestUpcomingAt = $at;
                $bestUpcoming = $c['id'];
            } elseif ($at < $now && $at > $bestPastAt) {
                $bestPastAt = $at;
                $bestPast = $c['id'];
            }
        }

        return $bestUpcoming ?? $bestPast;
    }

    /**
     * Newest DATED item in a pool (X5: undated never wins a `latest-*` role).
     *
     * @param  list<array<string, mixed>>  $candidates
     */
    private static function latestItem(array $candidates, string $pool): ?string
    {
        $best = null;
        $bestAt = '';
        foreach ($candidates as $c) {
            if ($c['kind'] !== 'item' || (($c['ref']['pool'] ?? null) !== $pool)) {
                continue;
            }
            if (($c['meta']['undated'] ?? true) === true) {
                continue;
            }
            $at = (string) ($c['connectedAt'] ?? '');
            if ($at !== '' && strcmp($at, $bestAt) > 0) {
                $bestAt = $at;
                $best = $c['id'];
            }
        }

        return $best;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<string, float>  $itemScores
     */
    private static function topItem(array $candidates, string $pool, array $itemScores): ?string
    {
        $best = null;
        $bestScore = -INF;
        foreach ($candidates as $c) {
            if ($c['kind'] !== 'item' || (($c['ref']['pool'] ?? null) !== $pool)) {
                continue;
            }
            $score = $itemScores[(string) ($c['ref']['itemId'] ?? '')] ?? 0.0;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $c['id'];
            }
        }

        return $best;
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $platforms
     * @return array<string, mixed>
     */
    private static function newest(array $platforms): array
    {
        usort($platforms, static fn (array $a, array $b): int => strcmp((string) ($b['connectedAt'] ?? ''), (string) ($a['connectedAt'] ?? '')));

        return $platforms[0];
    }
}
