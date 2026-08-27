<?php

namespace App\Services\Analytics;

use App\Models\Core\Site\Site;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Freshness boost — an ADDITIVE, decaying score term for newly-added pool
 * items, so new content surfaces before it has earned engagement (cold
 * start), then fades back to pure engagement ranking.
 *
 *   boost = w_fresh(family) · 2^(-age_days / half_life_days(family))
 *
 * aged from publishedAt ?? firstSeenAt (content.f_published.published_from,
 * else content.items.first_seen_at). Every scored family is eligible since
 * 2026-08-23 (smart ordering v2) with its own weight and half-life from
 * config/partna.php `pools.smart` (ItemFamily::weightsFor); a family whose
 * w_fresh is 0 yields nothing. event_item is exactly that (w_fresh 0.0) —
 * events DO score since the smart-scoring plan (2026-08-27), but their
 * time-shaped term is EventTimeRelevance, not this age curve.
 *
 * The page family this used to carry was retired 2026-08-23: pages are
 * actions now and get their freshness from ActionScorer.
 *
 * Consumed by ComputeContentPopularityScores (zero-signal keys are seeded so
 * a brand-new item ranks at all) and surfaced by DevInsightsController.
 */
class ContentFreshness
{
    /** Kept for the dev-insights surface; link_item's half-life in config matches. */
    public const HALF_LIFE_DAYS = 14.0;

    /** The link_item boost at age 0 (config `pools.smart.link_item.fresh`). */
    public const W_ITEM = 3.0;

    // Below this the boost is noise; skipping keeps zero-signal seeding bounded.
    private const MIN_BOOST = 0.05;

    /**
     * Freshness boosts for one site, family => item id => boost, for every
     * family with a live (>= MIN_BOOST) boost. Families with no fresh item
     * are absent from the map.
     *
     * @return array<string, array<string, float>>
     */
    public function boostsForSite(Site $site): array
    {
        $now = now();
        $out = [];

        try {
            // f_published is per (item, source), so the LEFT JOIN multiplies
            // rows per item; MIN(published_from) is the earliest any source
            // knows, and SQL can compute it directly instead of PHP
            // re-deriving the minimum across every joined row (SCALE-11).
            $rows = DB::connection('pgsql')->table('content.items as i')
                ->leftJoin('content.f_published as fp', 'fp.item_id', '=', 'i.id')
                ->where('i.user_id', $site->user_id)
                ->whereIn('i.kind', array_keys(ItemFamily::KIND_TO_FAMILY))
                ->whereNull('i.removed_at')
                ->groupBy('i.id', 'i.kind', 'i.first_seen_at')
                ->get([
                    'i.id',
                    'i.kind',
                    'i.first_seen_at',
                    DB::raw('MIN(fp.published_from) as published_from'),
                ]);
        } catch (QueryException) {
            // content.* lane absent (partial test envs) — no boosts.
            return [];
        }

        foreach ($rows as $row) {
            $family = ItemFamily::forKind((string) $row->kind);
            if ($family === null) {
                continue;
            }
            $weights = ItemFamily::weightsFor($family);
            if ($weights['fresh'] <= 0.0) {
                continue;
            }
            $at = ($row->published_from !== null && $row->published_from !== '')
                ? (string) $row->published_from
                : $row->first_seen_at;
            if ($at === null || $at === '') {
                continue;
            }
            $boost = $this->boost($weights['fresh'], $weights['half_life_days'], Carbon::parse($at), $now);
            if ($boost !== null) {
                $out[$family][(string) $row->id] = $boost;
            }
        }

        return $out;
    }

    /** Decayed boost, or null once it has faded below MIN_BOOST. */
    private function boost(float $weight, float $halfLifeDays, Carbon $from, Carbon $now): ?float
    {
        $ageDays = max(0.0, $now->getTimestamp() - $from->getTimestamp()) / 86400.0;
        $boost = $weight * 2 ** (-$ageDays / $halfLifeDays);

        return $boost >= self::MIN_BOOST ? $boost : null;
    }
}
