<?php

namespace App\Services\Analytics;

use App\Models\Core\Site\Site;
use App\Site\Actions\ActionId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The composite "smart" score for every action candidate (spec §6) —
 * replaces RankedActionsComputer's pure demand rate (2026-08-23) so a Book
 * button, an Instagram profile and a new single compare on ONE scale.
 *
 * Per candidate, each term normalised 0..1 within the site:
 *   demandRate = (T + k·prior) / (E + k)      Bayesian-smoothed tap-through,
 *                                             E/T = 90-day true-half-life,
 *                                             day-bucketed, session-distinct
 *                                             seen/tap from action_events
 *   reach      = decayedTaps / site max       how much traffic it drives;
 *                                             items add their pool click/view
 *                                             score (content_popularity_scores
 *                                             item families, keyed by item id)
 *   freshness  = 2^(-ageDays / 14)            from connectedAt (cold start:
 *                                             new content rises by itself)
 *   prior      = priors[id] ?? priors[kind]   the importance floor
 *
 *   score   = w_demand·demandRate + w_reach·reach + w_fresh·freshness + prior
 *   blended = 0.7·score + 0.3·previous        (anti-thrash)
 *   rank    = previous-rank seed, overtake only when > 10% above the incumbent
 *
 * Storage: analytics.content_popularity_scores, content_type='action',
 * content_key=<action id>. Stale keys (no longer a candidate) are deleted
 * every run — this class owns the 'action' lifecycle; the generic fade-out in
 * ComputeContentPopularityScores excludes it.
 */
class ActionScorer
{
    public const CONTENT_TYPE = 'action';

    private const HALF_LIFE_DAYS = 90.0;

    private const BLEND_NEW = 0.7;

    private const BLEND_PREV = 0.3;

    private const RANK_SWAP_THRESHOLD = 0.10;

    /**
     * @param  list<array<string, mixed>>  $candidates  ActionCandidates::forSite output
     * @param  array<string, float>  $itemScores  content item key => stored item-family score
     * @return array{rows: list<array<string, mixed>>, deletes: list<string>}
     */
    public function computeForSite(Site $site, array $candidates, array $itemScores = []): array
    {
        $previous = $this->previousRows($site);
        if ($candidates === []) {
            return ['rows' => [], 'deletes' => array_keys($previous)];
        }

        ['exposures' => $exposures, 'taps' => $taps] = $this->aggregate($site);
        $k = (float) config('partna.actions.prior_k', 25);
        $weights = (array) config('partna.actions.weights', []);
        $wDemand = (float) ($weights['demand'] ?? 0.45);
        $wReach = (float) ($weights['reach'] ?? 0.30);
        $wFresh = (float) ($weights['fresh'] ?? 0.25);
        $now = now();

        $reachRaw = [];
        foreach ($candidates as $c) {
            $id = (string) $c['id'];
            $raw = $taps[$id] ?? 0.0;
            if (in_array($c['kind'], ['item', 'category'], true)) {
                $raw += $this->itemSignal($c, $itemScores);
            }
            $reachRaw[$id] = $raw;
        }
        $reachMax = max($reachRaw);

        $blended = [];
        foreach ($candidates as $c) {
            $id = (string) $c['id'];
            $prior = self::priorFor($id);
            $e = $exposures[$id] ?? 0.0;
            $t = $taps[$id] ?? 0.0;
            $demand = ($t + $k * $prior) / ($e + $k);
            $reach = $reachMax > 0.0 ? $reachRaw[$id] / $reachMax : 0.0;
            $fresh = $this->freshness($c['connectedAt'] ?? null, $now);
            $score = $wDemand * $demand + $wReach * $reach + $wFresh * $fresh + $prior;
            $prev = $previous[$id]['score'] ?? $score;
            $blended[$id] = self::BLEND_NEW * $score + self::BLEND_PREV * $prev;
        }

        $prevRank = array_map(static fn (array $row): int => $row['rank'], $previous);
        $ranks = $this->rankWithHysteresis($blended, $prevRank);

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

        return ['rows' => $rows, 'deletes' => array_values(array_diff(array_keys($previous), array_keys($blended)))];
    }

    /** priors[id] ?? priors[kind] ?? default — config/partna.php `actions.priors`. */
    public static function priorFor(string $id): float
    {
        $priors = (array) config('partna.actions.priors', []);
        if (isset($priors[$id])) {
            return (float) $priors[$id];
        }
        $kind = ActionId::kind($id);

        return (float) ($priors[$kind ?? ''] ?? config('partna.actions.default_prior', 0.03));
    }

    /** 2^(-ageDays / halfLife), 1.0 when brand new, 0.0 when undated. */
    private function freshness(?string $connectedAt, Carbon $now): float
    {
        if ($connectedAt === null || $connectedAt === '') {
            return 0.0;
        }
        $halfLife = (float) config('partna.actions.freshness_half_life_days', 14.0);
        $ageDays = max(0.0, $now->getTimestamp() - Carbon::parse($connectedAt)->getTimestamp()) / 86400.0;

        return 2 ** (-$ageDays / $halfLife);
    }

    /**
     * An item's pool engagement (its stored item-family score) folded into
     * reach; a category takes the max of its members.
     *
     * @param  array<string, mixed>  $c
     * @param  array<string, float>  $itemScores
     */
    private function itemSignal(array $c, array $itemScores): float
    {
        if ($c['kind'] === 'item') {
            return $itemScores[(string) ($c['ref']['itemId'] ?? '')] ?? 0.0;
        }
        $best = 0.0;
        foreach ((array) ($c['meta']['itemIds'] ?? []) as $itemId) {
            $best = max($best, $itemScores[(string) $itemId] ?? 0.0);
        }

        return $best;
    }

    /** @return array{exposures: array<string, float>, taps: array<string, float>} */
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

    /** @return array<string, array{score: float, rank: int}> */
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
     * Previous-rank seed (newcomers last, ties by score), then bubble: a row
     * overtakes the one above only when its score beats it by > 10%.
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
                if ($blended[$keys[$i + 1]] > $blended[$keys[$i]] * (1 + self::RANK_SWAP_THRESHOLD)) {
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

    private function dayBucketExpr(): string
    {
        return DB::connection('pgsql')->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d', occurred_at)"
            : '(occurred_at::date)::text';
    }

    private function dayWeight(string $day, \DateTimeInterface $now): float
    {
        $ageDays = max(0.0, ($now->getTimestamp() - Carbon::parse($day)->getTimestamp()) / 86400.0);

        return 2 ** (-$ageDays / self::HALF_LIFE_DAYS);
    }
}
