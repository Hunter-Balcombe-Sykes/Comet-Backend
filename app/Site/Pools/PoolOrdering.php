<?php

namespace App\Site\Pools;

/**
 * Per-pool ordering modes (spec §5.4) — pure functions over the resolved
 * selection, applied by PoolResolver after payloads are built:
 *
 *   newest  DATED items (publishedAt) first by publishedAt desc, then undated
 *           by firstSeenAt desc, id desc on ties — X5: firstSeenAt is when WE
 *           saw the item, not a release date, so an undated song seen today
 *           never outranks last month's single
 *   smart   popularityRank asc (ranked before unranked), then the newest order
 *   manual  untouched — pins by sort_key, then the pool's rule order
 *
 * Pins and auto items reorder TOGETHER in newest/smart (D6: pools are
 * mode-only; "pin" there means hand-added membership, not position).
 * Events never take a mode (soonest-first is the only honest order) and
 * reviews never rank — PoolResolver passes 'manual' for both.
 */
final class PoolOrdering
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public static function order(string $mode, array $items): array
    {
        if ($mode === 'manual' || count($items) < 2) {
            return $items;
        }
        usort($items, static function (array $a, array $b) use ($mode): int {
            if ($mode === 'smart') {
                $ra = $a['popularityRank'] ?? null;
                $rb = $b['popularityRank'] ?? null;
                if ($ra !== $rb) {
                    if ($ra === null) {
                        return 1;
                    }
                    if ($rb === null) {
                        return -1;
                    }

                    return $ra <=> $rb;
                }
            }

            return self::compareNewest($a, $b);
        });

        return $items;
    }

    /**
     * Category/collection blocks follow the mode through their best member:
     * `position` is rewritten so a block with the newest (or top-ranked)
     * dish sits first. Untouched in manual.
     *
     * @param  array<string, array<string, mixed>>  $collections
     * @param  list<array<string, mixed>>  $orderedItems  already ordered by the mode
     * @return array<string, array<string, mixed>>
     */
    public static function orderCollections(string $mode, array $collections, array $orderedItems): array
    {
        if ($mode === 'manual' || count($collections) < 2) {
            return $collections;
        }
        // First appearance in the ordered items = the block's best member.
        $firstSeen = [];
        foreach ($orderedItems as $index => $item) {
            foreach ((array) ($item['collectionIds'] ?? []) as $cid) {
                $firstSeen[(string) $cid] ??= $index;
            }
        }
        $ids = array_keys($collections);
        usort($ids, static function (string $a, string $b) use ($firstSeen, $collections): int {
            $fa = $firstSeen[$a] ?? PHP_INT_MAX;
            $fb = $firstSeen[$b] ?? PHP_INT_MAX;
            if ($fa !== $fb) {
                return $fa <=> $fb;
            }

            return ((int) ($collections[$a]['position'] ?? 0)) <=> ((int) ($collections[$b]['position'] ?? 0));
        });
        $out = [];
        foreach ($ids as $position => $id) {
            $out[$id] = ['position' => $position] + $collections[$id];
            $out[$id]['position'] = $position;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public static function compareNewest(array $a, array $b): int
    {
        [$da, $ta] = self::newestKey($a);
        [$db, $tb] = self::newestKey($b);
        if ($da !== $db) {
            return $da ? -1 : 1; // dated before undated
        }
        if ($ta !== $tb) {
            if ($ta === null) {
                return 1;
            }
            if ($tb === null) {
                return -1;
            }

            return strcmp($tb, $ta);
        }

        return strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: bool, 1: string|null} [dated?, timestamp]
     */
    private static function newestKey(array $item): array
    {
        $published = $item['publishedAt'] ?? null;
        if (is_string($published) && $published !== '') {
            return [true, $published];
        }
        $seen = $item['firstSeenAt'] ?? null;
        $seen = is_string($seen) && $seen !== '' ? $seen : null;

        // A link-pool item is hand-added by definition: first seen IS the add
        // date, so it counts as dated. Synced items without a publishedAt don't.
        return [($item['kind'] ?? null) === 'link' && $seen !== null, $seen];
    }
}
