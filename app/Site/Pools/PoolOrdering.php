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
     * Owner locks laid over a mode-ordered selection (newest/smart only —
     * PoolResolver never calls this in manual): a locked item holds its
     * position and the rest fill around it in their mode order, exactly like
     * ActionSlots. A lock whose item is not in the selection, OR whose
     * position collides with an earlier lock in the same call, is skipped
     * and reported in `unavailable` (#RANK-2) — mirrors
     * ActionSlots::resolve()'s contract instead of dropping it silently.
     * Positions are renumbered contiguously, so a lock past the end lands last.
     *
     * @param  list<array<string, mixed>>  $items  mode-ordered selection
     * @param  list<array{position: int, id: string}>  $locks
     * @return array{items: list<array<string, mixed>>, unavailable: list<string>}
     */
    public static function applyLocks(array $items, array $locks): array
    {
        if ($locks === [] || $items === []) {
            return ['items' => $items, 'unavailable' => []];
        }
        $byId = [];
        foreach ($items as $item) {
            $byId[(string) ($item['id'] ?? '')] = $item;
        }
        $placed = [];
        $unavailable = [];
        foreach ($locks as $lock) {
            if (! isset($byId[$lock['id']]) || isset($placed[$lock['position']])) {
                $unavailable[] = $lock['id'];

                continue;
            }
            $placed[$lock['position']] = $lock['id'];
        }
        if ($placed === []) {
            return ['items' => $items, 'unavailable' => $unavailable];
        }
        $lockedIds = array_flip($placed);
        $fill = array_values(array_filter($items, static fn (array $i): bool => ! isset($lockedIds[(string) ($i['id'] ?? '')])));
        $out = [];
        $limit = count($items);
        for ($p = 0; $p < $limit; $p++) {
            if (isset($placed[$p])) {
                $out[] = $byId[$placed[$p]];
            } elseif ($fill !== []) {
                $out[] = array_shift($fill);
            }
        }
        // Locks past the selection length: append in position order.
        foreach ($placed as $position => $id) {
            if ($position >= $limit) {
                $out[] = $byId[$id];
            }
        }

        return ['items' => $out, 'unavailable' => $unavailable];
    }

    /**
     * Locks for a category pool (menus / services, D4): the selection is
     * bucketed by each item's home category (the first provider-null
     * collection it belongs to, as ActionCandidates homes it; a
     * provider-bearing collection is only a fallback; no collection at all
     * = uncategorised), a lock's `position` is the index WITHIN that
     * bucket, and the wire is flattened in the collections' (already
     * mode-ordered) `position` order with the uncategorised bucket last.
     *
     * @param  list<array<string, mixed>>  $items  mode-ordered selection
     * @param  list<array{position: int, id: string}>  $locks
     * @param  array<string, array<string, mixed>>  $collections  mode-ordered (position rewritten)
     * @return array{items: list<array<string, mixed>>, unavailable: list<string>}
     */
    public static function applyLocksPerCollection(array $items, array $locks, array $collections): array
    {
        if ($items === []) {
            return ['items' => $items, 'unavailable' => []];
        }
        $buckets = [];
        $loose = [];
        foreach ($items as $item) {
            $home = self::homeCollection($item, $collections);
            if ($home === null) {
                $loose[] = $item;
            } else {
                $buckets[$home][] = $item;
            }
        }
        $locksByBucket = [];
        // #RANK-2: a lock whose item isn't homed in ANY bucket (not in this
        // pool's selection at all) never reaches applyLocks() below, so it
        // has to be reported here or it vanishes with no trace.
        $unavailable = [];
        foreach ($locks as $lock) {
            foreach ($buckets as $cid => $members) {
                foreach ($members as $member) {
                    if ((string) ($member['id'] ?? '') === $lock['id']) {
                        $locksByBucket[$cid][] = $lock;

                        continue 3;
                    }
                }
            }
            foreach ($loose as $member) {
                if ((string) ($member['id'] ?? '') === $lock['id']) {
                    $locksByBucket[''][] = $lock;

                    continue 2;
                }
            }
            $unavailable[] = $lock['id'];
        }
        $order = array_keys($collections);
        usort($order, static fn (string $a, string $b): int => ((int) ($collections[$a]['position'] ?? 0)) <=> ((int) ($collections[$b]['position'] ?? 0)));
        $out = [];
        foreach ($order as $cid) {
            if (isset($buckets[$cid])) {
                $bucketResult = self::applyLocks($buckets[$cid], $locksByBucket[$cid] ?? []);
                array_push($out, ...$bucketResult['items']);
                array_push($unavailable, ...$bucketResult['unavailable']);
                unset($buckets[$cid]);
            }
        }
        // A bucket whose collection is not in the map (defensive) keeps its order.
        foreach ($buckets as $cid => $members) {
            $bucketResult = self::applyLocks($members, $locksByBucket[$cid] ?? []);
            array_push($out, ...$bucketResult['items']);
            array_push($unavailable, ...$bucketResult['unavailable']);
        }
        $looseResult = self::applyLocks($loose, $locksByBucket[''] ?? []);
        array_push($out, ...$looseResult['items']);
        array_push($unavailable, ...$looseResult['unavailable']);

        return ['items' => $out, 'unavailable' => $unavailable];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, array<string, mixed>>  $collections
     */
    public static function homeCollection(array $item, array $collections): ?string
    {
        $fallback = null;
        foreach ((array) ($item['collectionIds'] ?? []) as $cid) {
            $cid = (string) $cid;
            if (! isset($collections[$cid])) {
                continue;
            }
            $fallback ??= $cid;
            if (($collections[$cid]['provider'] ?? null) === null) {
                return $cid;
            }
        }

        return $fallback;
    }

    /**
     * Category/collection blocks follow the mode: in smart by the block's
     * own popularityRank (the SUM of its members' scores, D2 — unranked
     * blocks after, by position); in newest through their best member (the
     * block with the newest dish sits first). `position` is rewritten
     * either way. Untouched in manual (the Categories sheet's drag).
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
        usort($ids, static function (string $a, string $b) use ($mode, $firstSeen, $collections): int {
            if ($mode === 'smart') {
                $ra = $collections[$a]['popularityRank'] ?? null;
                $rb = $collections[$b]['popularityRank'] ?? null;
                if ($ra !== $rb) {
                    if ($ra === null) {
                        return 1;
                    }
                    if ($rb === null) {
                        return -1;
                    }

                    return $ra <=> $rb;
                }
                if ($ra === null) {
                    return ((int) ($collections[$a]['position'] ?? 0)) <=> ((int) ($collections[$b]['position'] ?? 0));
                }
            }
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
