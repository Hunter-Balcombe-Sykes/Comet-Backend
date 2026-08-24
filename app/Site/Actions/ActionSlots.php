<?php

namespace App\Site\Actions;

/**
 * The pure slot resolver (spec §5): candidates + stored scores + the owner's
 * settings → the ordered top-N the lander renders. No DB, no clock — fixtures
 * are plain arrays, so every mode/lock combination is a unit test.
 *
 *   newest  candidates by connectedAt desc (undated last, id asc), locks hold
 *           their slot, the ranking fills around them
 *   smart   same, but ranked candidates first (stored hysteresis rank asc,
 *           i.e. rank 1 first), unranked trail in newest order
 *   manual  the slots ARE the list; nothing auto-fills; missing ids shorten it
 *
 * A lock whose id is not a candidate (item removed, platform disconnected,
 * page lost presence) is skipped and reported in `unavailable` — the slot is
 * not deleted from settings, so the lock applies again if the candidate
 * returns (§5.3). Positions are renumbered contiguously from 0 after
 * assembly, so a lock past the filled length lands at the end.
 */
final class ActionSlots
{
    /** The slot count when the caller passes none — callers in the app pass config('partna.actions.slots'). */
    public const DEFAULT_LIMIT = 10;

    /**
     * @param  list<array<string, mixed>>  $candidates  ActionCandidates output
     * @param  array<string, int>  $ranks  action id => stored hysteresis rank (1-based; RANK-1)
     * @return array{entries: list<array<string, mixed>>, unavailable: list<string>}
     */
    public static function resolve(array $candidates, array $ranks, ActionSettings $settings, ?int $limit = null): array
    {
        $limit ??= self::DEFAULT_LIMIT;
        $byId = [];
        foreach ($candidates as $c) {
            $byId[(string) $c['id']] = $c;
        }

        $unavailable = [];
        $locks = [];
        foreach ($settings->slots as $slot) {
            if (! isset($byId[$slot['id']])) {
                $unavailable[] = $slot['id'];

                continue;
            }
            $locks[$slot['position']] = $slot['id'];
        }

        if ($settings->mode === 'manual') {
            $entries = [];
            foreach ($locks as $id) {
                $entries[] = self::entry($byId[$id], count($entries), locked: true);
            }

            return ['entries' => $entries, 'unavailable' => $unavailable];
        }

        $lockedIds = array_flip($locks);
        $fill = array_values(array_filter(
            self::order($candidates, $settings->mode === 'smart' ? $ranks : []),
            static fn (array $c): bool => ! isset($lockedIds[$c['id']]),
        ));

        $placed = [];
        for ($p = 0; $p < $limit; $p++) {
            if (isset($locks[$p])) {
                $placed[] = [$byId[$locks[$p]], true];
            } elseif ($fill !== []) {
                $placed[] = [array_shift($fill), false];
            }
        }

        $entries = [];
        foreach ($placed as [$candidate, $locked]) {
            $entries[] = self::entry($candidate, count($entries), $locked);
        }

        return ['entries' => $entries, 'unavailable' => $unavailable];
    }

    /**
     * rank asc (ranked before unranked; rank 1 is best — RANK-1 consumes the
     * stored hysteresis rank instead of re-deriving order from raw score) →
     * dated before undated (X5: an item whose only timestamp is when we first
     * saw it never outranks one with a real date) → connectedAt desc (nulls
     * last) → id asc. Equal ranks (a narrow duplicate-key window — see
     * ContentPopularityReader::pageRanksFromActions()) fall through to the
     * rest of the chain rather than short-circuiting.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<string, int>  $ranks
     * @return list<array<string, mixed>>
     */
    public static function order(array $candidates, array $ranks): array
    {
        usort($candidates, static function (array $a, array $b) use ($ranks): int {
            $ra = $ranks[$a['id']] ?? null;
            $rb = $ranks[$b['id']] ?? null;
            if ($ra !== $rb) {
                if ($ra === null) {
                    return 1;
                }
                if ($rb === null) {
                    return -1;
                }

                return $ra <=> $rb;
            }
            $ua = (bool) ($a['meta']['undated'] ?? false);
            $ub = (bool) ($b['meta']['undated'] ?? false);
            if ($ua !== $ub) {
                return $ua ? 1 : -1;
            }
            $ta = $a['connectedAt'] ?? null;
            $tb = $b['connectedAt'] ?? null;
            if ($ta !== $tb) {
                if ($ta === null) {
                    return 1;
                }
                if ($tb === null) {
                    return -1;
                }

                return strcmp((string) $tb, (string) $ta);
            }

            return strcmp((string) $a['id'], (string) $b['id']);
        });

        return $candidates;
    }

    /** @param  array<string, mixed>  $c */
    private static function entry(array $c, int $position, bool $locked): array
    {
        return [
            'position' => $position,
            'id' => (string) $c['id'],
            'kind' => (string) $c['kind'],
            'label' => (string) $c['label'],
            'url' => (string) $c['url'],
            'thumb' => $c['thumb'] ?? null,
            'locked' => $locked,
            'ref' => $c['ref'] ?? null,
        ];
    }
}
