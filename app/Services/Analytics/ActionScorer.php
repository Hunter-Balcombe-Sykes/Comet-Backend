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

    private const BLEND_PREV = 0.3;

    /**
     * Cadence-aware previous-score weight (smart-scoring plan, 2026-08-27).
     * The 0.7/0.3 blend was tuned for a DAILY run; at the 15-minute cadence
     * a fixed 0.3 made the blend near-cosmetic (routes/console.php's open
     * note). prev_weight = 0.3^(Δt/1day) COMPOUNDS to exactly the daily
     * semantics whatever the cadence (96 15-minute runs multiply out to
     * 0.3/day), clamped to [0.3, 0.99] so a manual back-to-back run can't
     * freeze scores and a long gap can't overshoot the daily weight. Null
     * (first write) returns the daily weight — the blend seeds with itself
     * there anyway. The same weight drives the fade-out path, which
     * therefore decays by TIME rather than run count.
     */
    public static function cadenceBlendPrev(?string $lastComputedAt, \DateTimeInterface $now): float
    {
        if ($lastComputedAt === null || $lastComputedAt === '') {
            return self::BLEND_PREV;
        }
        $minutes = max(0.0, ($now->getTimestamp() - Carbon::parse($lastComputedAt)->getTimestamp()) / 60.0);

        return min(0.99, max(self::BLEND_PREV, self::BLEND_PREV ** ($minutes / 1440.0)));
    }

    private const RANK_SWAP_THRESHOLD = 0.10;

    /**
     * One recipe-ladder rung's worth of earned signal (see
     * rankWithHysteresis): a boosted action's effective rank score is
     * earnedSignal + LADDER_RUNG_STEP × (rungs below it), so overtaking
     * ONE rung of recipe order costs 0.15 of real signal advantage —
     * above the ~0.12 cold-start spread the smoothing priors alone can
     * produce, below a real 2× tap-rate gap (~0.19 at moderate traffic) —
     * and overtaking N rungs costs N× that.
     */
    private const LADDER_RUNG_STEP = 0.15;

    /**
     * The margin band on that effective score (absolute, since the score
     * is an absolute construction): an overtake must clear the incumbent
     * by more than this so scores drifting a hair past each other do not
     * flip ranks run-to-run.
     */
    private const BOOSTED_SWAP_HYSTERESIS = 0.02;

    /**
     * @param  list<array<string, mixed>>  $candidates  ActionCandidates::forSite output
     * @param  array<string, float>  $itemScores  content item key => stored item-family score
     * @param  array<string, float>  $boosts  action id => identity boost (SectorActionRecipes::resolve)
     * @param  array<string, float>  $priorOverrides  action id => sector-keyed prior (SectorActionRecipes::pagePriorsFor)
     * @return array{rows: list<array<string, mixed>>, deletes: list<string>}
     */
    public function computeForSite(Site $site, array $candidates, array $itemScores = [], array $boosts = [], array $priorOverrides = []): array
    {
        $previous = $this->previousRows($site);
        if ($candidates === []) {
            return ['rows' => [], 'deletes' => array_keys($previous)];
        }

        ['exposures' => $exposures, 'taps' => $taps] = $this->aggregate($site);
        $blendPrev = self::cadenceBlendPrev($this->lastComputedAt($previous), now());
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
        $signals = [];
        foreach ($candidates as $c) {
            $id = (string) $c['id'];
            // Sector-keyed page priors override the global table (smart-
            // scoring plan): the identity re-weights cold-start floors.
            $prior = $priorOverrides[$id] ?? self::priorFor($id);
            $e = $exposures[$id] ?? 0.0;
            $t = $taps[$id] ?? 0.0;
            // The smoothing prior inside demand stays for every id — it is a
            // rate floor, not an identity signal.
            $demand = ($t + $k * $prior) / ($e + $k);
            $reach = $reachMax > 0.0 ? $reachRaw[$id] / $reachMax : 0.0;
            $fresh = $this->freshness($c['connectedAt'] ?? null, $now);
            // Identity boost REPLACES the additive prior for recipe-resolved
            // ids (smart-scoring plan, 2026-08-27) — double-counting identity
            // would just muddy the organic component DevInsights reports.
            $floor = $boosts[$id] ?? $prior;
            $score = $wDemand * $demand + $wReach * $reach + $wFresh * $fresh + $floor;
            $prev = $previous[$id]['score'] ?? $score;
            $blended[$id] = (1 - $blendPrev) * $score + $blendPrev * $prev;
            // The EARNED signal — demand + reach only. Freshness is excluded
            // on purpose: a newly-connected recipe-#2 platform must not ride
            // its own novelty over the #1 intent (a barber's fresh Instagram
            // must not outrank booking).
            $signals[$id] = $wDemand * $demand + $wReach * $reach;
        }

        $prevRank = array_map(static fn (array $row): int => $row['rank'], $previous);
        $ranks = $this->rankWithHysteresis($blended, $prevRank, $boosts, $signals);

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
     * An item's pool engagement (its stored item-family score, keyed by item
     * id for every kind) folded into reach; a category is the SUM of its
     * members (D2 — breadth beats one hit).
     *
     * @param  array<string, mixed>  $c
     * @param  array<string, float>  $itemScores
     */
    private function itemSignal(array $c, array $itemScores): float
    {
        if ($c['kind'] === 'item') {
            return $itemScores[(string) ($c['ref']['itemId'] ?? '')] ?? 0.0;
        }
        $sum = 0.0;
        foreach ((array) ($c['meta']['itemIds'] ?? []) as $itemId) {
            $sum += $itemScores[(string) $itemId] ?? 0.0;
        }

        return $sum;
    }

    /** @return array{exposures: array<string, float>, taps: array<string, float>} */
    private function aggregate(Site $site): array
    {
        $day = $this->dayBucketExpr();
        $now = now();
        $exposures = [];
        $taps = [];
        // SCALE-3: bound the scan to ScoringWindow (see that class) — same
        // index-covered predicate as ComputeContentPopularityScores'
        // action_events read.
        DB::connection('pgsql')->table('analytics.action_events')
            ->where('site_id', $site->id)
            ->where('occurred_at', '>=', ScoringWindow::since())
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

    /** @return array<string, array{score: float, rank: int, computed_at: ?string}> */
    private function previousRows(Site $site): array
    {
        $rows = DB::connection('pgsql')->table('analytics.content_popularity_scores')
            ->where('site_id', $site->id)
            ->where('content_type', self::CONTENT_TYPE)
            ->get(['content_key', 'score', 'rank', 'computed_at']);
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->content_key] = ['score' => (float) $row->score, 'rank' => (int) $row->rank, 'computed_at' => $row->computed_at !== null ? (string) $row->computed_at : null];
        }

        return $out;
    }

    /**
     * The newest computed_at among the previous rows — the cadence anchor.
     *
     * @param  array<string, array{score: float, rank: int, computed_at: ?string}>  $previous
     */
    private function lastComputedAt(array $previous): ?string
    {
        $max = null;
        foreach ($previous as $row) {
            $at = $row['computed_at'] ?? null;
            if ($at !== null && ($max === null || strcmp($at, $max) > 0)) {
                $max = $at;
            }
        }

        return $max;
    }

    /**
     * Previous-rank seed (newcomers last, ties by score), then bubble: a row
     * overtakes the one above only when its score beats it by > 10%.
     *
     * BOOSTED PAIRS RANK ON ONE SCALAR (third design, 2026-08-27 — the
     * critic's find): the previous form made each pairwise swap decision
     * from the pair's own boosts and signals, which is antisymmetric per
     * pair but NOT transitive across three or more boosted actions — a
     * constructed triangle (verified with a standalone replica) converged
     * to a stable order with recipe #1 ranked LAST when seeded from a
     * stale pre-boost rank. Every boosted action therefore gets one
     * effective score,
     *
     *   eff = earnedSignal + LADDER_RUNG_STEP × (ladder rungs below it)
     *
     * (earned signal = this run's demand + reach — no freshness, no floor;
     * the rung term encodes recipe order), and the bubble compares THAT,
     * with a small absolute band (BOOSTED_SWAP_HYSTERESIS) for run-to-run
     * stability. A scalar is transitive by construction, so no seed can
     * cycle or wedge; with comparable signals the ladder term dominates
     * and recipe order asserts itself over any stale seed (the live dev
     * find: opentable at blended 2.19 pinned at rank 8 under a
     * 0.63-boosted contact); flipping one rung of recipe order costs 0.15
     * of real signal advantage (> the ~0.12 cold-start prior spread,
     * < a 2× tap-rate gap's ~0.19), and flipping N rungs costs N×.
     * Mixed pairs keep comparing full blended scores, so the boost wall
     * against organic candidates stands.
     *
     * @param  array<string, float>  $blended
     * @param  array<string, int>  $prevRank
     * @param  array<string, float>  $boosts
     * @param  array<string, float>  $signals  this run's earned demand+reach per id
     * @return array<string, int>
     */
    private function rankWithHysteresis(array $blended, array $prevRank, array $boosts = [], array $signals = []): array
    {
        // The ladder term: distinct boost values, ascending — an action's
        // rung count is how many distinct rungs sit strictly below its own.
        $rungValues = array_values(array_unique($boosts));
        sort($rungValues);
        $eff = [];
        foreach ($boosts as $id => $boost) {
            $rungsBelow = count(array_filter($rungValues, static fn (float $v): bool => $v < $boost));
            $eff[$id] = ($signals[$id] ?? 0.0) + self::LADDER_RUNG_STEP * $rungsBelow;
        }

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
                $above = $keys[$i];
                $below = $keys[$i + 1];
                if (isset($eff[$above], $eff[$below])) {
                    $clears = $eff[$below] > $eff[$above] + self::BOOSTED_SWAP_HYSTERESIS;
                } else {
                    $clears = $blended[$below] > $blended[$above] * (1 + self::RANK_SWAP_THRESHOLD);
                }
                if ($clears) {
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
