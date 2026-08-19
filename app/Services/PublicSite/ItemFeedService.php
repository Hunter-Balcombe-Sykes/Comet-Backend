<?php

namespace App\Services\PublicSite;

use App\Models\Core\Site\Site;

/**
 * Item Feed — the item-level ranked list (spec: docs/superpowers/specs/
 * 2026-08-19-item-feed-design.md). PURE resolver: wire pools in, ordered
 * reference entries out. No DB reads — the caller hands it the pools map the
 * payload already serves, which makes "refs never dangle" structural rather
 * than checked (spec §3 forward-compat rule).
 *
 * Sibling of SiteActionsService (buttons); neither touches the other.
 */
class ItemFeedService
{
    public const MODES = ['manual', 'newest', 'score'];

    public const DEFAULT_MODE = 'newest';

    /** Pools the feed draws from — reviews is deliberately absent (spec §3). */
    public const FEED_POOLS = ['watch', 'listen', 'media', 'events', 'services', 'shop', 'custom_links', 'menus'];

    /** Pools whose items group into category blocks; shop floats by owner decision. */
    public const CATEGORY_POOLS = ['menus', 'services'];

    public function mode(?Site $site): string
    {
        $mode = is_array($site?->settings) ? ($site->settings['feed_mode'] ?? null) : null;

        return in_array($mode, self::MODES, true) ? $mode : self::DEFAULT_MODE;
    }

    /** @return list<array<string, mixed>> */
    public function manualFeed(?Site $site): array
    {
        $raw = is_array($site?->settings) ? ($site->settings['manual_feed'] ?? null) : null;

        return is_array($raw) ? array_values(array_filter($raw, 'is_array')) : [];
    }

    /**
     * @param  array<string, array<string, mixed>>  $pools  publicPools() wire map
     * @param  list<array<string, mixed>>  $manual  stored manual_feed entries
     * @param  array<string, float>  $scores  flat content_key => blended score
     * @return array{mode: string, entries: list<array<string, mixed>>}
     */
    public function resolve(array $pools, string $mode, array $manual, array $scores): array
    {
        $candidates = $this->candidates($pools);

        $entries = match ($mode) {
            'manual' => $this->resolveManual($candidates, $manual),
            'score' => $this->orderCandidates($candidates, $scores, withScores: true),
            default => $this->orderCandidates($candidates, [], withScores: false),
        };

        return ['mode' => $mode, 'entries' => $entries];
    }

    /**
     * Enumerate candidates from the wire pools. Each candidate is a wire entry
     * plus private sort facets (stripped before emit): _ts (recency epoch),
     * _score (float|null), _slug/_handle (scoreFor() lookup keys), _items
     * (category members with their own facets).
     *
     * @return list<array<string, mixed>>
     */
    private function candidates(array $pools): array
    {
        $out = [];
        foreach (self::FEED_POOLS as $pool) {
            $items = $pools[$pool]['items'] ?? [];
            if (! is_array($items) || $items === []) {
                continue;
            }
            $collections = is_array($pools[$pool]['collections'] ?? null) ? $pools[$pool]['collections'] : [];

            if (in_array($pool, self::CATEGORY_POOLS, true)) {
                $grouped = [];
                foreach ($items as $item) {
                    // A scraper-sourced dish/service typically carries TWO
                    // memberships: its real category (menu_category /
                    // service_category, collections[cid]['provider'] === null,
                    // no storefront sidecar) and one order-platform/storefront
                    // collection per connected surface (provider non-null,
                    // position hardcoded 0 — see MenuFetchJob::syncOrderPlatforms).
                    // Homing to the first served collection regardless of kind
                    // would put every dish under "Uber Eats" instead of its food
                    // category. Prefer the first collection WITHOUT a provider;
                    // fall back to the first served collection only when every
                    // candidate is provider-bearing (spec §1, §4).
                    $home = null;
                    $fallback = null;
                    foreach ((array) ($item['collectionIds'] ?? []) as $cid) {
                        $cid = (string) $cid;
                        if (! isset($collections[$cid])) {
                            continue;
                        }
                        $fallback ??= $cid;
                        if (($collections[$cid]['provider'] ?? null) === null) {
                            $home = $cid;
                            break;
                        }
                    }
                    $home ??= $fallback;
                    if ($home === null) {
                        $out[] = $this->itemCandidate($pool, $item);   // uncategorised floats
                    } else {
                        $grouped[$home][] = $item;
                    }
                }
                foreach ($grouped as $cid => $members) {
                    $out[] = [
                        'kind' => 'category', 'pool' => $pool, 'collectionId' => $cid,
                        '_items' => array_map(fn (array $i) => $this->itemCandidate($pool, $i), $members),
                    ];
                }
            } else {
                foreach ($items as $item) {
                    $out[] = $this->itemCandidate($pool, $item);
                }
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $item */
    private function itemCandidate(string $pool, array $item): array
    {
        $ts = $item['publishedAt'] ?? $item['firstSeenAt'] ?? null;

        return [
            'kind' => 'item', 'pool' => $pool, 'itemId' => (string) ($item['id'] ?? ''),
            '_ts' => is_string($ts) ? (strtotime($ts) ?: 0) : 0,
            '_slug' => isset($item['slug']) && is_string($item['slug']) ? $item['slug'] : null,
            '_handle' => isset($item['handle']) && is_string($item['handle']) ? $item['handle'] : null,
        ];
    }

    /**
     * Shared ordering for newest + score modes. Sort key per candidate:
     * score desc (scored before unscored), then recency desc, then id asc —
     * fully deterministic. In newest mode $scores is [] so the score facet is
     * null everywhere and recency decides. Category members are ordered by
     * the same comparator; the block inherits max(score) / max(ts).
     *
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<string, float>  $scores
     * @return list<array<string, mixed>>
     */
    private function orderCandidates(array $candidates, array $scores, bool $withScores): array
    {
        $facets = array_map(function (array $c) use ($scores): array {
            if ($c['kind'] === 'item') {
                $c['_score'] = $this->scoreFor($c, $scores);

                return $c;
            }
            usort($c['_items'], fn (array $a, array $b): int => $this->compare(
                [$this->scoreFor($a, $scores), $a['_ts'], $a['itemId']],
                [$this->scoreFor($b, $scores), $b['_ts'], $b['itemId']],
            ));
            $memberScores = array_filter(array_map(fn (array $i) => $this->scoreFor($i, $scores), $c['_items']), fn ($s) => $s !== null);
            $c['_score'] = $memberScores === [] ? null : max($memberScores);
            $c['_ts'] = max([0, ...array_map(fn (array $i) => $i['_ts'], $c['_items'])]);

            return $c;
        }, $candidates);

        usort($facets, fn (array $a, array $b): int => $this->compare(
            [$a['_score'], $a['_ts'], $a['kind'] === 'item' ? $a['itemId'] : $a['collectionId']],
            [$b['_score'], $b['_ts'], $b['kind'] === 'item' ? $b['itemId'] : $b['collectionId']],
        ));

        return array_map(fn (array $c) => $this->toWire($c, $withScores), $facets);
    }

    /** score desc NULLS LAST, ts desc, id asc. */
    private function compare(array $a, array $b): int
    {
        [$sa, $ta, $ia] = $a;
        [$sb, $tb, $ib] = $b;
        if ($sa !== $sb) {
            if ($sa === null) {
                return 1;
            }
            if ($sb === null) {
                return -1;
            }

            return $sb <=> $sa;
        }
        if ($ta !== $tb) {
            return $tb <=> $ta;
        }

        return strcmp((string) $ia, (string) $ib);
    }

    /**
     * Lookup by item id, then by `_handle`, then by `_slug`. Id resolves most
     * families directly (`content_popularity_scores.content_key` = the
     * content item id for everything except shop_product). Shop products key
     * on `f_catalog.handle` instead — `PoolResolver` now emits that as the
     * item's `handle` wire key (2026-08-19 follow-up), which is what the
     * `_handle` facet carries. `_slug` stays as a third fallback: it is a
     * `content.item_slugs` URL slug (`ContentItemSlugAllocator::SLUGGED_KINDS`
     * = `event`/`menu_item` only), never populated for a product, and no
     * popularity row keys on it today — but harmless to keep, and free if a
     * future wire ever carries a matching key.
     */
    private function scoreFor(array $candidate, array $scores): ?float
    {
        return $scores[$candidate['itemId']]
            ?? ($candidate['_handle'] !== null ? ($scores[$candidate['_handle']] ?? null) : null)
            ?? ($candidate['_slug'] !== null ? ($scores[$candidate['_slug']] ?? null) : null);
    }

    /**
     * Strict manual resolution (spec §4): stored order, stale refs dropped,
     * nothing auto-appended — including one level down inside category blocks.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<array<string, mixed>>  $manual
     * @return list<array<string, mixed>>
     */
    private function resolveManual(array $candidates, array $manual): array
    {
        $itemsByRef = [];
        $categoriesByRef = [];
        foreach ($candidates as $c) {
            if ($c['kind'] === 'item') {
                $itemsByRef[$c['pool'].':'.$c['itemId']] = $c;
            } else {
                $categoriesByRef[$c['pool'].':'.$c['collectionId']] = $c;
            }
        }

        $out = [];
        foreach ($manual as $entry) {
            $kind = $entry['kind'] ?? null;
            $key = ($entry['pool'] ?? '').':'.($entry['ref'] ?? '');
            if ($kind === 'item' && isset($itemsByRef[$key])) {
                $out[] = $this->toWire($itemsByRef[$key], withScores: false);
            } elseif ($kind === 'category' && isset($categoriesByRef[$key])) {
                $c = $categoriesByRef[$key];
                $live = [];
                foreach ($c['_items'] as $member) {
                    $live[$member['itemId']] = true;
                }
                $ordered = array_values(array_filter(
                    array_map('strval', (array) ($entry['items'] ?? [])),
                    fn (string $id) => isset($live[$id]),
                ));
                if ($ordered !== []) {
                    $out[] = ['kind' => 'category', 'pool' => $c['pool'], 'collectionId' => $c['collectionId'], 'itemIds' => $ordered, 'score' => null];
                }
            }
        }

        return $out;
    }

    /** Project a candidate to its wire entry, stripping the private facets. */
    private function toWire(array $c, bool $withScores): array
    {
        $score = $withScores && $c['_score'] !== null ? round($c['_score'], 4) : null;
        if ($c['kind'] === 'item') {
            return ['kind' => 'item', 'pool' => $c['pool'], 'itemId' => $c['itemId'], 'score' => $score];
        }

        return [
            'kind' => 'category', 'pool' => $c['pool'], 'collectionId' => $c['collectionId'],
            'itemIds' => array_map(fn (array $i) => $i['itemId'], $c['_items']),
            'score' => $score,
        ];
    }
}
