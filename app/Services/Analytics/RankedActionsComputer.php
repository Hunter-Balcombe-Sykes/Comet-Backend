<?php

namespace App\Services\Analytics;

use App\Models\Core\Site\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Unified action ranking — turns a site's action pool (page|item|button, see
 * SiteActionsService) into one comparable ranked list, layered ON TOP of the
 * existing popularity scores. Page/item score math is consumed, never touched.
 *
 * Per action:
 *   native  — the action's own signal family, untouched:
 *               page   → this run's blended page score (minus its additive
 *                        freshness boost — recency is a separate term here)
 *               item   → this run's blended item score (link_item likewise
 *                        minus its freshness boost)
 *               button → link_clicks WHERE platform = ref, day-bucketed with the
 *                        same 90-day true-half-life weighting the page/item job uses
 *   norm    — min-max within the site's actions OF THAT KIND (floor 0):
 *             native / kind_max (0 when the kind has no signal at all)
 *   prior   — static kind/ref prior (booking button > shop page > socials ...)
 *   recency — 2^(-age_days/14) from the owning entity's created_at
 *   raw     = 0.60·norm + 0.25·prior + 0.15·recency          ∈ [0, ~1]
 *   blended = 0.7·raw + 0.3·previous stored                  (anti-thrash)
 *   rank    — previous-rank seed + bubble swap only when beating the incumbent
 *             by >10% (same hysteresis pattern as the popularity job)
 *
 * Cold start: with zero events every norm is 0 → ordering falls back to
 * priors + recency, so a brand-new site still yields a full ranked list.
 *
 * Storage reuses analytics.content_popularity_scores verbatim:
 * content_type='action', content_key='<kind>:<ref>'. Stale keys (dropped from
 * the pool) are deleted every run — this class owns the 'action' type's
 * lifecycle; the generic fade-out loop in the command excludes it.
 */
class RankedActionsComputer
{
    public const CONTENT_TYPE = 'action';

    // Comparable-score blend. Native engagement dominates once signals exist;
    // priors + recency carry the cold start.
    private const W_NATIVE = 0.60;

    private const W_PRIOR = 0.25;

    private const W_RECENCY = 0.15;

    // Button clicks decay with the same true half-life as page/item signals.
    private const CLICK_HALF_LIFE_DAYS = 90.0;

    // Recency term half-life — matches ContentFreshness.
    private const RECENCY_HALF_LIFE_DAYS = 14.0;

    // Anti-thrash: same constants as ComputeContentPopularityScores.
    private const BLEND_NEW = 0.7;

    private const BLEND_PREV = 0.3;

    private const RANK_SWAP_THRESHOLD = 0.10;

    /**
     * Ref-specific priors (highest intent first). Anything not listed falls
     * back to ITEM_TYPE_PRIORS (items) then KIND_PRIORS.
     *
     * @var array<string, float>
     */
    private const REF_PRIORS = [
        'button:booking' => 0.95,
        'button:uber-eats' => 0.90,
        'button:doordash' => 0.90,
        'page:book' => 0.85,
        'page:shop' => 0.80,
        'page:menu' => 0.80,
        'page:events' => 0.75,
        'button:instagram' => 0.70,
        'page:listen' => 0.65,
        'page:watch' => 0.65,
        'button:youtube' => 0.60,
        'page:gallery' => 0.60,
        'page:reservations' => 0.60,
        'page:reviews' => 0.55,
        'page:contact' => 0.55,
        'page:documents' => 0.45,
        'page:pinterest' => 0.45,
        'page:strava' => 0.45,
        'page:skool' => 0.45,
        'page:links' => 0.40,
    ];

    /** @var array<string, float> */
    private const ITEM_TYPE_PRIORS = [
        'service' => 0.55,
        'shop_product' => 0.55,
        'engine_item' => 0.50,
    ];

    /** @var array<string, float> */
    private const KIND_PRIORS = [
        'button' => 0.50,
        'page' => 0.50,
        'item' => 0.45,
    ];

    public function __construct(private readonly ContentFreshness $freshness) {}

    /**
     * Static prior for a pool entry — also used by SiteActionsService to order
     * not-yet-scored pool entries on the payload's cold path.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function priorFor(array $entry): float
    {
        $kind = (string) ($entry['kind'] ?? '');

        if ($kind === 'item') {
            $type = (string) ($entry['itemType'] ?? '');

            return self::ITEM_TYPE_PRIORS[$type] ?? self::KIND_PRIORS['item'];
        }

        return self::REF_PRIORS[$kind.':'.($entry['ref'] ?? '')]
            ?? self::KIND_PRIORS[$kind]
            ?? 0.40;
    }

    /**
     * Compute the site's ranked-action rows + stale-key deletions. $pageScores
     * / $itemScores are THIS RUN's blended scores from the popularity job
     * (fresher than the stored rows mid-run).
     *
     * @param  list<array<string, mixed>>  $pool  SiteActionsService::pool() entries
     * @param  array<string, float>  $pageScores  page-id => blended score
     * @param  array<string, array<string, float>>  $itemScores  item_type => item_key => blended score
     * @return array{rows: list<array<string, mixed>>, deletes: list<string>}
     */
    public function computeForSite(Site $site, array $pool, array $pageScores, array $itemScores): array
    {
        $previous = $this->previousRows($site);

        if ($pool === []) {
            // No actions at all — clear any stored rows so nothing stale serves.
            return ['rows' => [], 'deletes' => array_keys($previous)];
        }

        $buttonSums = $this->buttonClickSums($site, $this->allClickPlatforms($pool));
        $fresh = $this->freshness->boostsForSite($site);
        $now = now();

        // Native signal + recency per entry, keyed by the storage key.
        $native = [];
        $recency = [];
        $entries = [];
        foreach ($pool as $entry) {
            $key = $entry['kind'].':'.$entry['ref'];
            $entries[$key] = $entry;
            $native[$key] = $this->nativeScore($entry, $pageScores, $itemScores, $buttonSums, $fresh);
            $recency[$key] = $this->recencyScore($entry, $fresh, $now);
        }

        // Within-kind min-max (floor 0): best-in-kind = 1.0, zero-signal kind = 0.
        $kindMax = [];
        foreach ($entries as $key => $entry) {
            $kindMax[$entry['kind']] = max($kindMax[$entry['kind']] ?? 0.0, $native[$key]);
        }

        $blended = [];
        foreach ($entries as $key => $entry) {
            $max = $kindMax[$entry['kind']];
            $norm = $max > 0.0 ? $native[$key] / $max : 0.0;

            $raw = self::W_NATIVE * $norm
                + self::W_PRIOR * self::priorFor($entry)
                + self::W_RECENCY * $recency[$key];

            $prev = $previous[$key]['score'] ?? $raw;
            $blended[$key] = self::BLEND_NEW * $raw + self::BLEND_PREV * $prev;
        }

        $prevRank = array_map(static fn (array $row): int => $row['rank'], $previous);
        $ranks = $this->rankWithHysteresis($blended, $prevRank);

        $rows = [];
        foreach ($blended as $key => $score) {
            $rows[] = [
                'id' => (string) Str::orderedUuid(),
                'site_id' => $site->id,
                'content_type' => self::CONTENT_TYPE,
                'content_key' => $key,
                'score' => $score,
                'rank' => $ranks[$key],
                'computed_at' => $now->toISOString(),
            ];
        }

        // Stored keys that left the pool are deleted outright — the pool is
        // recomputed from live state every run, so absence IS the fade-out.
        $deletes = array_values(array_diff(array_keys($previous), array_keys($blended)));

        return ['rows' => $rows, 'deletes' => $deletes];
    }

    /**
     * The action's own signal family. Pages + link items SUBTRACT the additive
     * freshness boost baked into their stored score — recency is a separate
     * blend term here, and leaving the boost in native would double-count it
     * (a brand-new zero-engagement page would norm to 1.0 and outrank real
     * engagement).
     *
     * @param  array<string, mixed>  $entry
     * @param  array<string, float>  $pageScores
     * @param  array<string, array<string, float>>  $itemScores
     * @param  array<string, float>  $buttonSums  platform => decayed clicks
     * @param  array{page: array<string, float>, link_item: array<string, float>}  $fresh
     */
    private function nativeScore(array $entry, array $pageScores, array $itemScores, array $buttonSums, array $fresh): float
    {
        return match ($entry['kind']) {
            'page' => max(
                0.0,
                ($pageScores[$entry['ref']] ?? 0.0) - ($fresh['page'][$entry['ref']] ?? 0.0),
            ),
            'item' => max(
                0.0,
                ($itemScores[(string) $entry['itemType']][(string) $entry['itemKey']] ?? 0.0)
                    - ($entry['itemType'] === 'link_item' ? ($fresh['link_item'][(string) $entry['itemKey']] ?? 0.0) : 0.0),
            ),
            'button' => array_sum(array_map(
                static fn (string $platform): float => $buttonSums[$platform] ?? 0.0,
                (array) ($entry['clickPlatforms'] ?? []),
            )),
            default => 0.0,
        };
    }

    /**
     * Recency ∈ [0,1]: pages + link items reuse ContentFreshness (boost ÷ its
     * weight = the pure decay term); buttons decay from their created_at
     * anchor. Other items carry no stable created_at → 0.
     *
     * @param  array<string, mixed>  $entry
     * @param  array{page: array<string, float>, link_item: array<string, float>}  $fresh
     */
    private function recencyScore(array $entry, array $fresh, \DateTimeInterface $now): float
    {
        if ($entry['kind'] === 'page') {
            return min(1.0, ($fresh['page'][$entry['ref']] ?? 0.0) / ContentFreshness::W_PAGE);
        }

        if ($entry['kind'] === 'item' && $entry['itemType'] === 'link_item') {
            return min(1.0, ($fresh['link_item'][(string) $entry['itemKey']] ?? 0.0) / ContentFreshness::W_ITEM);
        }

        if ($entry['kind'] === 'button' && is_string($entry['createdAt'] ?? null) && $entry['createdAt'] !== '') {
            $ageDays = max(0.0, ($now->getTimestamp() - Carbon::parse($entry['createdAt'])->getTimestamp()) / 86400.0);

            return 2 ** (-$ageDays / self::RECENCY_HALF_LIFE_DAYS);
        }

        return 0.0;
    }

    /**
     * Decayed click sum per platform from analytics.link_clicks — the button
     * kind's native family. Day-bucketed, each day weighted 2^(-age/90); same
     * decay form (and driver-portable day bucket) as the popularity job.
     *
     * @param  list<string>  $platforms
     * @return array<string, float>
     */
    private function buttonClickSums(Site $site, array $platforms): array
    {
        if ($platforms === []) {
            return [];
        }

        $day = DB::connection('pgsql')->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d', occurred_at)"
            : '(occurred_at::date)::text';
        $now = now();

        $sums = [];
        DB::connection('pgsql')->table('analytics.link_clicks')
            ->where('site_id', $site->id)
            ->whereIn('platform', $platforms)
            ->selectRaw("platform, {$day} as day, COUNT(*) as clicks")
            ->groupByRaw("platform, {$day}")
            ->get()
            ->each(function ($r) use (&$sums, $now): void {
                $ageDays = max(0.0, ($now->getTimestamp() - Carbon::parse((string) $r->day)->getTimestamp()) / 86400.0);
                $weight = 2 ** (-$ageDays / self::CLICK_HALF_LIFE_DAYS);
                $sums[(string) $r->platform] = ($sums[(string) $r->platform] ?? 0.0) + $weight * (int) $r->clicks;
            });

        return $sums;
    }

    /**
     * @param  list<array<string, mixed>>  $pool
     * @return list<string>
     */
    private function allClickPlatforms(array $pool): array
    {
        $platforms = [];
        foreach ($pool as $entry) {
            foreach ((array) ($entry['clickPlatforms'] ?? []) as $platform) {
                $platforms[$platform] = true;
            }
        }

        return array_keys($platforms);
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
     * algorithm as the popularity job's ranking (kept local: that one is a
     * private method on the command, and importing it would couple the layers).
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
}
