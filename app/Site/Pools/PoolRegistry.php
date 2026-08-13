<?php

namespace App\Site\Pools;

/**
 * The pools that live on the content library (platforms-as-sources,
 * 2026-08-05): which item kinds each pool owns, and the page/section keys
 * its curation hangs off. Closed on purpose — a pool key arrives from the
 * URL, and this map is what stops it naming an arbitrary kind set.
 *
 * Menu is NOT here: it keeps its existing live lane, which already implements
 * sources→selection in its own machinery. Services JOINED 2026-08-12 (slice
 * 3a) for the owner-authored half; the Fresha half and its hiddenServiceIds
 * lane follow in 3b, so both run side by side until then. Sell JOINED
 * 2026-08-13 (slice 5b): products render from the pool and the legacy
 * /integrations shop keys are retired. Watch + listen were the launch
 * set; media joined with the gallery lane; events joined 2026-08-11 (slice
 * 2) and runs ALONGSIDE the legacy hiddenEventIds lane until that lane is
 * retired. Reviews JOINED 2026-08-13 (slice 6) and is the first pool that is
 * not the owner's own content: exclusion only, no pins, no hand-adds — see
 * EXCLUDE_ONLY_POOLS. `channel` and `article` are deliberately poolless — see
 * PoolRegistryTest for the reasons.
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
    ];

    /**
     * Pools whose selection carries the single Latest tag (owner). Events is
     * absent on purpose: a dated list is already ordered by when it happens,
     * so a Latest badge would label the soonest event "new".
     */
    public const LATEST_TAG_POOLS = ['watch', 'listen', 'media'];

    /** The page each pool's section lives on (site.pages.key). */
    public const PAGE_KEYS = [
        'watch' => 'watch',
        'listen' => 'listen',
        'media' => 'gallery',
        'events' => 'events',
        'services' => 'services',
        'shop' => 'shop',
        'reviews' => 'reviews',
    ];

    public const PAGE_LABELS = [
        'watch' => 'Watch',
        'listen' => 'Listen',
        'media' => 'Gallery',
        'events' => 'Events',
        'services' => 'Services',
        'shop' => 'Shop',
        'reviews' => 'Reviews',
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
        'media' => [
            'rule' => [['op' => 'kind_is']],
            'order_by' => 'recency',
        ],
        // Priced, undated. Services (3a) and shop (5b) reconciled on ONE shape
        // 2026-08-12 so slice 4 inherits a single convention — these two
        // entries are deliberately identical and should stay that way.
        // order_by governs only UNPINNED items; owner ordering is carried by
        // pins, which is why no `position` operator exists and none should be
        // added — the rule DSL spans four registries and missing one is a 500,
        // not a red test.
        'services' => [
            'rule' => [['op' => 'kind_is']],
            'order_by' => 'recency',
        ],
        'shop' => [
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

    private const SECTION_KEY_PREFIX = 'pool:';

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
