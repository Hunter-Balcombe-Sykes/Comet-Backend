<?php

namespace App\Site\Pools;

/**
 * The pools that live on the content library (platforms-as-sources,
 * 2026-08-05): which item kinds each pool owns, and the page/section keys
 * its curation hangs off. Closed on purpose — a pool key arrives from the
 * URL, and this map is what stops it naming an arbitrary kind set.
 *
 * Events / Sell / Services / Menu are NOT here: they keep their existing
 * live lanes (hiddenEventIds, shop selections, hiddenServiceIds), which
 * already implement sources→selection in their own machinery. Media joins
 * once the gallery lane folds in; watch + listen are the launch set.
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
    ];

    /** Pools whose selection carries the single Latest tag (owner). */
    public const LATEST_TAG_POOLS = ['watch', 'listen', 'media'];

    /** The page each pool's section lives on (site.pages.key). */
    public const PAGE_KEYS = [
        'watch' => 'watch',
        'listen' => 'listen',
        'media' => 'gallery',
    ];

    public const PAGE_LABELS = [
        'watch' => 'Watch',
        'listen' => 'Listen',
        'media' => 'Gallery',
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
