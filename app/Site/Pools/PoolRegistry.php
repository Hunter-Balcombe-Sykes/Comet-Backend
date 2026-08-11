<?php

namespace App\Site\Pools;

/**
 * The pools that live on the content library (platforms-as-sources,
 * 2026-08-05): which item kinds each pool owns, and the page/section keys
 * its curation hangs off. Closed on purpose — a pool key arrives from the
 * URL, and this map is what stops it naming an arbitrary kind set.
 *
 * Sell / Services / Menu are NOT here: they keep their existing live lanes
 * (shop selections, hiddenServiceIds), which already implement
 * sources→selection in their own machinery. Watch + listen were the launch
 * set; media joined with the gallery lane; events joined 2026-08-11 (slice
 * 2) and runs ALONGSIDE the legacy hiddenEventIds lane until that lane is
 * retired. `channel` and `article` are deliberately poolless — see
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
    ];

    public const PAGE_LABELS = [
        'watch' => 'Watch',
        'listen' => 'Listen',
        'media' => 'Gallery',
        'events' => 'Events',
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
    ];

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
        return "pool:{$pool}";
    }

    public static function carriesLatestTag(string $pool): bool
    {
        return in_array($pool, self::LATEST_TAG_POOLS, true);
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

    /** The pool a kind belongs to, or null (service, product, event, …). */
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
