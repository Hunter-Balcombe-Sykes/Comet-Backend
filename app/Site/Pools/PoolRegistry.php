<?php

namespace App\Site\Pools;

/**
 * The pools that live on the content library (platforms-as-sources,
 * 2026-08-05): which item kinds each pool owns, and the page/section keys
 * its curation hangs off. Closed on purpose — a pool key arrives from the
 * URL, and this map is what stops it naming an arbitrary kind set.
 *
 * Menus JOINED 2026-08-15 (slice 4): dishes render from the pool, and the
 * legacy site.menu_items lane runs beside it until slice 7 drops the table.
 * Services JOINED 2026-08-12 (slice
 * 3a) for the owner-authored half; the Fresha half and its hiddenServiceIds
 * lane follow in 3b, so both run side by side until then. Sell JOINED
 * 2026-08-13 (slice 5b): products render from the pool and the legacy
 * /integrations shop keys are retired. Watch + listen were the launch
 * set; media joined with the gallery lane; events joined 2026-08-11 (slice
 * 2) and runs ALONGSIDE the legacy hiddenEventIds lane until that lane is
 * retired. Reviews JOINED 2026-08-13 (slice 6) and is the first pool that is
 * not the owner's own content: exclusion only, no pins, no hand-adds — see
 * EXCLUDE_ONLY_POOLS. Custom links JOINED 2026-08-15 (convergence Phase 3 /
 * W5): registry key `custom_links`, kind `link`, fed by the
 * `partna.custom_link` connections only — the other pseudo-platform
 * connections (order links, storefronts, reservations) keep their own lanes
 * until Phase 6, and link items carry no item_links (ItemLinkRules has no
 * roster entry for the pool). `channel` and `article` are deliberately
 * poolless — see PoolRegistryTest for the reasons.
 */
class PoolRegistry
{
    /**
     * pool key → the content kinds it owns. A kind belongs to at most ONE
     * pool — PoolRegistryTest pins that, because an item that answered to
     * two pools would be curated twice and excluded once.
     *
     * @var array<string, list<string>>
     */
    public const POOLS = [
        'watch' => ['video'],
        'listen' => ['track', 'release', 'episode'],
        'media' => ['media'],
        'events' => ['event'],
        'services' => ['service'],
        'shop' => ['product'],
        'reviews' => ['review'],
        'custom_links' => ['link'],
        'menus' => ['menu_item'],
    ];

    /**
     * Pools whose selection carries the single Latest tag (owner). Events is
     * absent on purpose: a dated list is already ordered by when it happens,
     * so a Latest badge would label the soonest event "new".
     */
    public const LATEST_TAG_POOLS = ['watch', 'listen', 'media'];

    /**
     * Listen restructure (owner, 2026-08-18): a source's "newest" is per
     * FORMAT, each behind its own switch. A platform that emits releases AND
     * tracks (Apple Music) publishes its newest release and its newest song,
     * and the owner can turn either off without the other; a track-only
     * platform (Spotify, SoundCloud, YouTube Music) carries only the track
     * switch. Every other kind (release, episode, video, media, product…)
     * rides the original `auto_sync_latest`.
     */
    public const LATEST_TOGGLE_TRACK = 'auto_sync_latest_track';

    public static function latestToggleFor(string $kind): string
    {
        return $kind === 'track' ? self::LATEST_TOGGLE_TRACK : AutoSyncSetting::KEY;
    }

    /**
     * The `latest*` rule ops evaluate one arm per toggle group so "newest per
     * source" is really "newest release AND newest track per source".
     *
     * @param  list<string>  $kinds
     * @return array<string, list<string>> toggle key => kinds it governs
     */
    public static function latestArmsFor(array $kinds): array
    {
        $groups = [];
        foreach ($kinds as $kind) {
            $groups[self::latestToggleFor((string) $kind)][] = (string) $kind;
        }

        return $groups === [] ? [AutoSyncSetting::KEY => []] : $groups;
    }

    /** The page each pool's section lives on (site.pages.key). */
    public const PAGE_KEYS = [
        'watch' => 'watch',
        'listen' => 'listen',
        'media' => 'gallery',
        'events' => 'events',
        'services' => 'services',
        'shop' => 'shop',
        'reviews' => 'reviews',
        // `links` is the page these already advertise — SitepageId maps the
        // legacy `custom` platform to it, so the pool joins the page its own
        // connections built rather than opening a second one beside it.
        'custom_links' => 'links',
        // The page the legacy menu lane already advertises — the pool joins it
        // rather than opening a second page beside it, the same way custom
        // links joined `links`.
        'menus' => 'menu',
    ];

    public const PAGE_LABELS = [
        'watch' => 'Watch',
        'listen' => 'Listen',
        'media' => 'Gallery',
        'events' => 'Events',
        'services' => 'Services',
        'shop' => 'Shop',
        'reviews' => 'Reviews',
        'custom_links' => 'Links',
        'menus' => 'Menu',
    ];

    /**
     * The rule + ordering a pool's section is provisioned with.
     *
     * Watch and Listen want "each auto-source's newest item" — one row per
     * platform, rolling. Events does not: `latest_per_auto_source` emits
     * exactly ONE item per connection source, which for a ticketing platform
     * means a visitor sees one event and never the other four. Dated content
     * wants the whole upcoming list, soonest first.
     *
     * A pool with no entry here gets the watch/listen default, so adding a
     * pool stays a one-line change unless its semantics genuinely differ.
     *
     * @var array<string, array{rule: list<array{op: string}>, order_by: string}>
     */
    public const SECTION_SHAPE = [
        'events' => [
            'rule' => [
                ['op' => 'kind_is'],
                ['op' => 'upcoming_occurrence'],
            ],
            'order_by' => 'occurrence',
        ],
        // A gallery wants EVERY photo. latest_per_auto_source is the
        // watch/listen rolling-latest semantics: one item per connection
        // source, which for media means one Google photo visible and nine
        // hidden (slice 1a §1.3 — the same pathology events hit in slice 2).
        //
        // MEDIA LEFT THIS SET 2026-08-18 (owner ruling R5, overnight run):
        // a connected Instagram or Google listing used to publish EVERY photo
        // (kind_is). Media is now opt-in like shop — pins, plus each source's
        // N newest (config partna.pools.auto_latest_n, default 5) while that
        // source's auto_sync_latest / photos toggles are on. Sync fills the
        // LIBRARY; the owner picks; the N newest keep the page alive on day
        // one. Existing sections are reshaped by content:reshape-pool-sections.
        'media' => [
            'rule' => [
                ['op' => 'kind_is'],
                ['op' => 'latest_n_per_auto_source'],
            ],
            'order_by' => 'recency',
        ],
        // Priced, undated. Services (3a) and shop (5b) reconciled on ONE shape
        // 2026-08-12 so slice 4 inherits a single convention.
        // order_by governs only UNPINNED items; owner ordering is carried by
        // pins, which is why no `position` operator exists and none should be
        // added — the rule DSL spans four registries and missing one is a 500,
        // not a red test.
        //
        // SHOP LEFT THIS SET 2026-08-17 (owner: "B — opt-in"). A connected
        // store used to put its WHOLE catalogue on the site (kind_is), which
        // is 300 products on a page nobody chose. Shop now takes the
        // watch/listen DEFAULT below — pins, plus each store's newest product
        // while that store's auto_sync_latest is on (and new store
        // connections write that toggle OFF). Sync fills the LIBRARY; the
        // owner picks what publishes. Existing sites were grandfathered by
        // migration 20260817_shop_grandfather_pins.
        'services' => [
            'rule' => [['op' => 'kind_is']],
            'order_by' => 'recency',
        ],
        // Slice 4 makes it three (now two — shop left, see above).
        // Deliberately identical to services — a menu is priced and undated
        // exactly as a service is, and dev's largest menu carries 156 dishes,
        // so the default's latest_per_auto_source would publish ONE of them.
        'menus' => [
            'rule' => [['op' => 'kind_is']],
            'order_by' => 'recency',
        ],
        // Vendor-curated Sample: orderField null, never dominates, never
        // deletes. The default's latest_per_auto_source would show one review
        // and hide the rest. order_by recency orders the display; it does not
        // claim the set is complete, which is why reviews is absent from
        // LATEST_TAG_POOLS.
        'reviews' => [
            'rule' => [['op' => 'kind_is']],
            'order_by' => 'recency',
        ],
        // Every custom link lands on the user's ONE manual source, so the
        // default's latest_per_auto_source would publish a single link and
        // hide the rest — the pathology media, events and reviews each hit,
        // sharpened here because the whole pool shares one source. order_by
        // governs the unpinned tail only; the owner's arrangement rides on
        // pins, which is what the backfill writes.
        'custom_links' => [
            'rule' => [['op' => 'kind_is']],
            'order_by' => 'recency',
        ],
    ];

    /**
     * Pools where the owner may hide an item but not promote one.
     *
     * Reviews only. The privacy disclosure justifies republishing a stranger's
     * name and words on the grounds that visitors see "genuine, attributable
     * feedback"; letting the owner arrange a testimonial reel weakens that
     * exactly where it matters, and it is the reviewer — who never consented
     * and holds no account — who carries the cost. Owner decision 2026-08-13.
     *
     * @var list<string>
     */
    public const EXCLUDE_ONLY_POOLS = ['reviews'];

    /**
     * Pools that refuse hand-authored items. Creating an item of kind `review`
     * is fabricating a testimonial attributed to a customer.
     *
     * @var list<string>
     */
    public const MANUAL_ADD_FORBIDDEN_POOLS = ['reviews'];

    /**
     * Pools that render their source's own aggregates (content.source_stats)
     * beside their items — currently the Google star average, review count and
     * review summary, which slice 6 §5.4 retired from
     * PublicIntegrationConnectionResource on the promise this lane serves them.
     *
     * Declared here rather than branched on in PoolResolver because a
     * google_business source feeds the media pool too: without this the rating
     * badge would surface on a pool of photos. Same one-source-of-truth reason
     * as EXCLUDE_ONLY_POOLS above.
     *
     * @var list<string>
     */
    public const SOURCE_STATS_POOLS = ['reviews'];

    private const SECTION_KEY_PREFIX = 'pool:';

    public static function carriesSourceStats(string $pool): bool
    {
        return in_array($pool, self::SOURCE_STATS_POOLS, true);
    }

    public static function isPool(string $key): bool
    {
        return isset(self::POOLS[$key]);
    }

    /** @return list<string> */
    public static function kinds(string $pool): array
    {
        return self::POOLS[$pool] ?? [];
    }

    /** The section key a pool's curation lives under (site.sections.key). */
    public static function sectionKey(string $pool): string
    {
        return self::SECTION_KEY_PREFIX.$pool;
    }

    /**
     * The pool a section key belongs to, or null for a non-pool section.
     *
     * The inverse of sectionKey(), and the reason the prefix is a const: the
     * curation endpoint is reached by section UUID, so a per-pool rule there
     * (allowsPin) has nothing but the key to recover the pool from.
     */
    public static function poolForSectionKey(string $sectionKey): ?string
    {
        if (! str_starts_with($sectionKey, self::SECTION_KEY_PREFIX)) {
            return null;
        }

        $pool = substr($sectionKey, strlen(self::SECTION_KEY_PREFIX));

        return self::isPool($pool) ? $pool : null;
    }

    public static function carriesLatestTag(string $pool): bool
    {
        return in_array($pool, self::LATEST_TAG_POOLS, true);
    }

    /** Whether this pool's curation may write a `pinned` row at all. */
    public static function allowsPin(string $pool): bool
    {
        return ! in_array($pool, self::EXCLUDE_ONLY_POOLS, true);
    }

    /** Whether an owner may hand-author an item into this pool. */
    public static function allowsManualAdd(string $pool): bool
    {
        return ! in_array($pool, self::MANUAL_ADD_FORBIDDEN_POOLS, true);
    }

    /**
     * The rule predicates and ordering to provision a pool's section with.
     * `values` is filled from the pool's kinds here so SECTION_SHAPE stays a
     * declaration of SHAPE and never restates the kind list.
     *
     * @return array{rule: list<array<string, mixed>>, order_by: string}
     */
    public static function sectionShape(string $pool): array
    {
        $shape = self::SECTION_SHAPE[$pool] ?? [
            'rule' => [
                ['op' => 'kind_is'],
                ['op' => 'latest_per_auto_source'],
            ],
            'order_by' => 'recency',
        ];

        $kinds = self::kinds($pool);

        return [
            'rule' => array_map(
                static fn (array $predicate): array => $predicate + ['values' => $kinds],
                $shape['rule'],
            ),
            'order_by' => $shape['order_by'],
        ];
    }

    /** The pool a kind belongs to, or null (channel, article, …). */
    public static function poolForKind(string $kind): ?string
    {
        foreach (self::POOLS as $pool => $kinds) {
            if (in_array($kind, $kinds, true)) {
                return $pool;
            }
        }

        return null;
    }
}
