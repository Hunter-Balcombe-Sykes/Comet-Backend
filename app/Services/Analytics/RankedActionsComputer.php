<?php

namespace App\Services\Analytics;

use App\Models\Core\Site\Site;
use App\Services\PublicSite\Actions\ActionVocabulary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demand-rate action ranking (2026-07-23 rebuild) — turns a site's action
 * pool (SiteActionsService::pool()) into one comparable ranked list from raw
 * analytics.action_events, independent of page/item popularity scoring.
 *
 * Per action:
 *   E, T    — decayed (90-day true half-life, day-bucketed) session-DISTINCT
 *             counts of 'seen'/'tap' events for this action id.
 *   rate    = (T + k·prior) / (E + k)                         Bayesian-smoothed
 *             click-through rate. k phantom sessions at the action's prior
 *             CTR dominate at low evidence; real data dominates as E grows.
 *             Zero evidence (cold start) → rate = prior exactly.
 *   blended = 0.7·rate + 0.3·previous stored                  (anti-thrash,
 *             same constants as the page/item popularity job)
 *   rank    — previous-rank seed + bubble swap only when beating the
 *             incumbent by >10% (same hysteresis pattern as that job)
 *
 * Storage reuses analytics.content_popularity_scores verbatim:
 * content_type='action', content_key=<ActionVocabulary id>. Stale keys
 * (dropped from the pool, OR left over from the pre-rebuild '<kind>:<ref>'
 * key format) are deleted every run — this class owns the 'action' type's
 * lifecycle; the generic fade-out loop in ComputeContentPopularityScores
 * excludes it.
 *
 * Design spec: docs/superpowers/plans/2026-07-23-actions-rebuild-demand-rate.md
 */
class RankedActionsComputer
{
    public const CONTENT_TYPE = 'action';

    // TRUE half-life of a day bucket's contribution — matches the page/item
    // popularity job's decay form (2^(-age/90), not a whole-history multiplier).
    private const HALF_LIFE_DAYS = 90.0;

    // Anti-thrash: same constants as ComputeContentPopularityScores.
    private const BLEND_NEW = 0.7;

    private const BLEND_PREV = 0.3;

    private const RANK_SWAP_THRESHOLD = 0.10;

    /**
     * Compute the site's ranked-action rows + stale-key deletions.
     *
     * @param  list<array<string, mixed>>  $pool  SiteActionsService::pool() entries
     * @return array{rows: list<array<string, mixed>>, deletes: list<string>}
     */
    public function computeForSite(Site $site, array $pool): array
    {
        $previous = $this->previousRows($site);

        if ($pool === []) {
            // No actions at all — clear any stored rows so nothing stale serves.
            return ['rows' => [], 'deletes' => array_keys($previous)];
        }

        ['exposures' => $exposures, 'taps' => $taps] = $this->aggregate($site);
        $k = (float) config('partna.actions.prior_k', 25);

        $blended = [];
        foreach ($pool as $entry) {
            $id = (string) $entry['id'];
            $e = $exposures[$id] ?? 0.0;
            $t = $taps[$id] ?? 0.0;
            $rate = ($t + $k * ActionVocabulary::priorFor($id)) / ($e + $k);

            $prev = $previous[$id]['score'] ?? $rate;
            $blended[$id] = self::BLEND_NEW * $rate + self::BLEND_PREV * $prev;
        }

        $prevRank = array_map(static fn (array $row): int => $row['rank'], $previous);
        $ranks = $this->rankWithHysteresis($blended, $prevRank);

        $now = now();
        $rows = [];
        foreach ($blended as $id => $score) {
            $rows[] = [
                'id' => (string) Str::orderedUuid(),
                'site_id' => $site->id,
                'content_type' => self::CONTENT_TYPE,
                'content_key' => $id,
                'score' => $score,
                'rank' => $ranks[$id],
                'computed_at' => $now->toISOString(),
            ];
        }

        // Stored keys that left the pool (or carry the pre-rebuild '<kind>:<ref>'
        // key format) are deleted outright — the pool is recomputed from live
        // state every run, so absence IS the fade-out.
        $deletes = array_values(array_diff(array_keys($previous), array_keys($blended)));

        return ['rows' => $rows, 'deletes' => $deletes];
    }

    /**
     * Decayed, session-distinct exposure/tap counts per action id, from
     * analytics.action_events. Day-bucketed so each day's contribution
     * carries its own half-life weight (a fresh trickle of events doesn't
     * resurrect a dormant action to full weight).
     *
     * COALESCE(session_id, visitor_id, id) for the DISTINCT grouping: plain
     * COUNT(DISTINCT session_id) would silently drop every row with a null
     * session_id from the count (SQL excludes NULL from DISTINCT entirely,
     * rather than merging them into one group) — undercounting real sessions
     * that arrived without one. Falling back to the row's own id makes a
     * session-id-less event count as its own instance, never merged with
     * another anonymous one.
     *
     * @return array{exposures: array<string, float>, taps: array<string, float>}
     */
    private function aggregate(Site $site): array
    {
        $day = $this->dayBucketExpr();
        $now = now();

        $exposures = [];
        $taps = [];

        DB::connection('pgsql')->table('analytics.action_events')
            ->where('site_id', $site->id)
            ->selectRaw("action_id, event, {$day} as day, COUNT(DISTINCT COALESCE(session_id, visitor_id, id)) as sessions")
            ->groupByRaw("action_id, event, {$day}")
            ->get()
            ->each(function ($r) use (&$exposures, &$taps, $now): void {
                $weighted = $this->dayWeight((string) $r->day, $now) * (int) $r->sessions;
                if ($r->event === 'tap') {
                    $taps[$r->action_id] = ($taps[$r->action_id] ?? 0.0) + $weighted;
                } else {
                    $exposures[$r->action_id] = ($exposures[$r->action_id] ?? 0.0) + $weighted;
                }
            });

        return ['exposures' => $exposures, 'taps' => $taps];
    }

    /**
     * Previously stored action rows, keyed by content_key.
     *
     * @return array<string, array{score: float, rank: int}>
     */
    private function previousRows(Site $site): array
    {
        $rows = DB::connection('pgsql')->table('analytics.content_popularity_scores')
            ->where('site_id', $site->id)
            ->where('content_type', self::CONTENT_TYPE)
            ->get(['content_key', 'score', 'rank']);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->content_key] = ['score' => (float) $row->score, 'rank' => (int) $row->rank];
        }

        return $out;
    }

    /**
     * Contiguous 1..n ranks with anti-thrash — previous-rank seed, then bubble
     * a lower action above an upper one only when it beats it by >10%. Same
     * algorithm as the page/item popularity job's ranking (kept local: that
     * one is a private method on the command, and importing it would couple
     * the layers).
     *
     * @param  array<string, float>  $blended
     * @param  array<string, int>  $prevRank
     * @return array<string, int>
     */
    private function rankWithHysteresis(array $blended, array $prevRank): array
    {
        $keys = array_keys($blended);

        usort($keys, static function (string $a, string $b) use ($blended, $prevRank): int {
            $ra = $prevRank[$a] ?? PHP_INT_MAX;
            $rb = $prevRank[$b] ?? PHP_INT_MAX;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            return $blended[$b] <=> $blended[$a];
        });

        $n = count($keys);
        do {
            $swapped = false;
            for ($i = 0; $i < $n - 1; $i++) {
                $upper = $keys[$i];
                $lower = $keys[$i + 1];
                if ($blended[$lower] > $blended[$upper] * (1 + self::RANK_SWAP_THRESHOLD)) {
                    [$keys[$i], $keys[$i + 1]] = [$keys[$i + 1], $keys[$i]];
                    $swapped = true;
                }
            }
        } while ($swapped);

        $ranks = [];
        foreach ($keys as $idx => $key) {
            $ranks[$key] = $idx + 1;
        }

        return $ranks;
    }

    /**
     * Driver-portable day-bucket expression for occurred_at — Postgres in
     * prod, SQLite in the test suite. Both yield 'YYYY-MM-DD' strings. Mirrors
     * ComputeContentPopularityScores::dayBucketExpr() — kept local (small,
     * class-private SQL fragment; not worth a shared helper for two lines).
     */
    private function dayBucketExpr(): string
    {
        return DB::connection('pgsql')->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d', occurred_at)"
            : '(occurred_at::date)::text';
    }

    /** True-half-life weight for one day bucket: 2^(-age_days / HALF_LIFE_DAYS). */
    private function dayWeight(string $day, \DateTimeInterface $now): float
    {
        $ageDays = max(0.0, ($now->getTimestamp() - Carbon::parse($day)->getTimestamp()) / 86400.0);

        return 2 ** (-$ageDays / self::HALF_LIFE_DAYS);
    }
}
