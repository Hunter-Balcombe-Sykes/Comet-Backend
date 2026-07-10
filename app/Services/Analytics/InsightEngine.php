<?php

namespace App\Services\Analytics;

/**
 * Pure derivation of dashboard insights from pre-fetched analytics aggregates.
 *
 * No DB, no config, no clock — every method is a deterministic function of its
 * inputs, so the full fires / threshold / stat matrix is unit-testable on any
 * platform. AnalyticsQueryService supplies the aggregates; AnalyticsCacheService
 * orchestrates fetch → derive → cache.
 *
 * Each insight is {id, kind, headline, supporting_stat, period}. An insight is
 * emitted ONLY when its inputs clear the min-sample + min-effect thresholds
 * below — the "no fabrication" contract: a stat too thin to be true is dropped,
 * never softened into a vague claim. Thresholds are class constants (not config)
 * so tests are deterministic; lift them to config only if tuning is needed.
 *
 * @phpstan-type Insight array{id:string, kind:string, headline:string, supporting_stat:array<string,mixed>, period:array<string,mixed>}
 */
class InsightEngine
{
    // ── time_of_day ─────────────────────────────────────────────────────────
    private const TIME_OF_DAY_MIN_CLICKS = 20;

    private const TIME_OF_DAY_MIN_DELTA_PCT = 15.0;

    /**
     * Evening = 18:00–23:59 (6h); daytime = 06:00–17:59 (12h). Overnight 0–5 is a
     * different traffic regime and excluded from both windows so a handful of
     * 3am clicks can't inflate the delta. Rates are per-hour, so the unequal
     * window lengths compare fairly.
     */
    private const EVENING_HOURS = [18, 19, 20, 21, 22, 23];

    private const DAYTIME_HOURS = [6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17];

    // ── weekday_peak ────────────────────────────────────────────────────────
    private const WEEKDAY_MIN_VISITS = 20;

    private const WEEKDAY_MIN_DISTINCT_DAYS = 3;

    private const WEEKDAY_MIN_ABOVE_AVG_PCT = 25.0;

    // ── page_riser / page_faller ────────────────────────────────────────────
    private const PAGE_WOW_MIN_PRIOR = 10;

    private const PAGE_WOW_MIN_CHANGE_PCT = 25.0;

    // ── traffic_source_shift ────────────────────────────────────────────────
    private const SOURCE_MIN_WEEK_VISITS = 15;

    private const SOURCE_MIN_TOP_VISITS = 8;

    // ── dwell_outlier ───────────────────────────────────────────────────────
    private const DWELL_MIN_SAMPLES = 8;

    private const DWELL_MIN_RATIO = 1.5;

    /** 0=Sunday … 6=Saturday — matches EXTRACT(DOW) / strftime('%w'). */
    private const DOW_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

    /**
     * Time-of-day popularity: are clicks concentrated in the evening vs the day?
     * Compares per-hour click rate in the evening window against the daytime
     * window (needs a daytime baseline > 0; "all clicks after dark" can't yield a
     * ratio and is dropped).
     *
     * @param  array<int, int>  $hourlyClicks  hour(0–23) => clicks
     * @param  array<string, mixed>  $period
     * @return Insight|null
     */
    public function timeOfDay(array $hourlyClicks, array $period): ?array
    {
        $total = array_sum($hourlyClicks);
        if ($total < self::TIME_OF_DAY_MIN_CLICKS) {
            return null;
        }

        $eveningRate = $this->sumHours($hourlyClicks, self::EVENING_HOURS) / count(self::EVENING_HOURS);
        $daytimeRate = $this->sumHours($hourlyClicks, self::DAYTIME_HOURS) / count(self::DAYTIME_HOURS);

        if ($daytimeRate <= 0.0) {
            return null;
        }

        $deltaPct = (($eveningRate - $daytimeRate) / $daytimeRate) * 100.0;
        if (abs($deltaPct) < self::TIME_OF_DAY_MIN_DELTA_PCT) {
            return null;
        }

        $magnitude = (int) round(abs($deltaPct));
        $headline = $deltaPct > 0
            ? "Your visitors click {$magnitude}% more often in the evening (after 6pm) than during the day."
            : "Your visitors click {$magnitude}% more often during the day than in the evening.";

        return $this->insight('time_of_day', 'time_of_day', $headline, [
            'metric' => 'clicks',
            'value' => round($deltaPct, 1),
            'unit' => 'percent_change',
            'detail' => [
                'evening_clicks_per_hour' => round($eveningRate, 2),
                'daytime_clicks_per_hour' => round($daytimeRate, 2),
                'sample' => $total,
            ],
        ], $period);
    }

    /**
     * Weekday peak: which day of the week outperforms a typical day? The average
     * is taken over days that actually saw traffic (a day the pro never operated
     * shouldn't drag the baseline down).
     *
     * @param  array<int, int>  $weekdayVisits  dow(0=Sun–6=Sat) => visits
     * @param  array<string, mixed>  $period
     * @return Insight|null
     */
    public function weekdayPeak(array $weekdayVisits, array $period): ?array
    {
        $present = array_filter($weekdayVisits, static fn ($n): bool => $n > 0);
        $total = array_sum($present);

        if ($total < self::WEEKDAY_MIN_VISITS || count($present) < self::WEEKDAY_MIN_DISTINCT_DAYS) {
            return null;
        }

        arsort($present);
        $peakDow = (int) array_key_first($present);
        $peakVisits = (int) $present[$peakDow];

        $avg = $total / count($present);
        if ($avg <= 0.0) {
            return null;
        }

        $aboveAvgPct = (($peakVisits - $avg) / $avg) * 100.0;
        if ($aboveAvgPct < self::WEEKDAY_MIN_ABOVE_AVG_PCT) {
            return null;
        }

        $dayName = self::DOW_NAMES[$peakDow] ?? 'A weekday';
        $magnitude = (int) round($aboveAvgPct);
        $headline = "{$dayName} is your busiest day — {$magnitude}% more visits than your typical day.";

        return $this->insight('weekday_peak', 'weekday_peak', $headline, [
            'metric' => 'visits',
            'value' => round($aboveAvgPct, 1),
            'unit' => 'percent_above_average',
            'detail' => [
                'weekday' => $dayName,
                'peak_visits' => $peakVisits,
                'average_visits' => round($avg, 2),
                'sample' => (int) $total,
            ],
        ], $period);
    }

    /**
     * Page riser + faller, week-over-week. Emits at most two insights (the single
     * biggest riser and the single biggest faller). Each needs a prior-week
     * baseline ≥ PAGE_WOW_MIN_PRIOR so the percentage isn't a divide-by-tiny
     * artefact.
     *
     * @param  array<string, array{title:string, this_week:int, prior_week:int}>  $pageWow  page => WoW counts
     * @param  array<string, mixed>  $period
     * @return list<Insight>
     */
    public function pageRisersFallers(array $pageWow, array $period): array
    {
        $riser = null;
        $faller = null;

        foreach ($pageWow as $page => $row) {
            $prior = (int) ($row['prior_week'] ?? 0);
            if ($prior < self::PAGE_WOW_MIN_PRIOR) {
                continue;
            }
            $thisWeek = (int) ($row['this_week'] ?? 0);
            $changePct = (($thisWeek - $prior) / $prior) * 100.0;
            $entry = [
                'page' => (string) $page,
                'title' => (string) ($row['title'] ?? $page),
                'change' => $changePct,
                'this' => $thisWeek,
                'prior' => $prior,
            ];

            if ($changePct >= self::PAGE_WOW_MIN_CHANGE_PCT && ($riser === null || $changePct > $riser['change'])) {
                $riser = $entry;
            }
            if ($changePct <= -self::PAGE_WOW_MIN_CHANGE_PCT && ($faller === null || $changePct < $faller['change'])) {
                $faller = $entry;
            }
        }

        $out = [];
        if ($riser !== null) {
            $mag = (int) round($riser['change']);
            $out[] = $this->insight('page_riser', 'page_riser',
                "Your {$riser['title']} page views are up {$mag}% this week vs last.",
                $this->pageWowStat($riser), $period);
        }
        if ($faller !== null) {
            $mag = (int) round(abs($faller['change']));
            $out[] = $this->insight('page_faller', 'page_faller',
                "Your {$faller['title']} page views are down {$mag}% this week vs last.",
                $this->pageWowStat($faller), $period);
        }

        return $out;
    }

    /**
     * Traffic-source shift: did the top referrer/source change between the prior
     * and current week? 'Other' (the junk ELSE bucket) can never be a "top
     * source"; 'Direct Link' can. Both weeks need real volume and the new leader
     * needs standalone weight.
     *
     * @param  array<string, int>  $thisWeekSources  label => visitors
     * @param  array<string, int>  $priorWeekSources  label => visitors
     * @param  array<string, mixed>  $period
     * @return Insight|null
     */
    public function trafficSourceShift(array $thisWeekSources, array $priorWeekSources, array $period): ?array
    {
        $thisTotal = array_sum($thisWeekSources);
        $priorTotal = array_sum($priorWeekSources);
        if ($thisTotal < self::SOURCE_MIN_WEEK_VISITS || $priorTotal < self::SOURCE_MIN_WEEK_VISITS) {
            return null;
        }

        $topThis = $this->topSource($thisWeekSources);
        $topPrior = $this->topSource($priorWeekSources);
        if ($topThis === null || $topPrior === null) {
            return null;
        }
        if ($topThis['label'] === $topPrior['label'] || $topThis['visitors'] < self::SOURCE_MIN_TOP_VISITS) {
            return null;
        }

        $sharePct = ($topThis['visitors'] / $thisTotal) * 100.0;
        $headline = "{$topThis['label']} overtook {$topPrior['label']} as your top traffic source this week.";

        return $this->insight('traffic_source_shift', 'traffic_source_shift', $headline, [
            'metric' => 'visits',
            'value' => round($sharePct, 1),
            'unit' => 'percent_share',
            'detail' => [
                'new_top_source' => $topThis['label'],
                'previous_top_source' => $topPrior['label'],
                'new_top_visits' => $topThis['visitors'],
                'week_visits' => (int) $thisTotal,
            ],
        ], $period);
    }

    /**
     * Dwell outlier: a page holding attention far longer than the site average.
     * Averages are weighted (sum/count), never an average of per-page averages.
     * Needs ≥ DWELL_MIN_SAMPLES dwell reports on the page for the average to mean
     * something.
     *
     * @param  array<string, array{title:string, dwell_sum_ms:int, dwell_n:int}>  $pageDwellStats
     * @param  array<string, mixed>  $period
     * @return Insight|null
     */
    public function dwellOutlier(array $pageDwellStats, array $period): ?array
    {
        $siteSum = 0;
        $siteN = 0;
        foreach ($pageDwellStats as $row) {
            $siteSum += (int) ($row['dwell_sum_ms'] ?? 0);
            $siteN += (int) ($row['dwell_n'] ?? 0);
        }
        if ($siteN <= 0) {
            return null;
        }
        $siteAvgMs = $siteSum / $siteN;
        if ($siteAvgMs <= 0.0) {
            return null;
        }

        $best = null;
        foreach ($pageDwellStats as $page => $row) {
            $n = (int) ($row['dwell_n'] ?? 0);
            if ($n < self::DWELL_MIN_SAMPLES) {
                continue;
            }
            $pageAvgMs = (int) ($row['dwell_sum_ms'] ?? 0) / $n;
            $ratio = $pageAvgMs / $siteAvgMs;
            if ($ratio >= self::DWELL_MIN_RATIO && ($best === null || $ratio > $best['ratio'])) {
                $best = [
                    'page' => (string) $page,
                    'title' => (string) ($row['title'] ?? $page),
                    'ratio' => $ratio,
                    'pageAvgMs' => $pageAvgMs,
                ];
            }
        }
        if ($best === null) {
            return null;
        }

        $ratioRounded = round($best['ratio'], 1);
        $headline = "Visitors spend {$ratioRounded}× longer on your {$best['title']} page than your site average.";

        return $this->insight('dwell_outlier', 'dwell_outlier', $headline, [
            'metric' => 'dwell_seconds',
            'value' => $ratioRounded,
            'unit' => 'multiple',
            'detail' => [
                'page' => $best['page'],
                'page_avg_seconds' => (int) round($best['pageAvgMs'] / 1000),
                'site_avg_seconds' => (int) round($siteAvgMs / 1000),
            ],
        ], $period);
    }

    /**
     * @param  array{page:string, title:string, change:float, this:int, prior:int}  $entry
     * @return array<string, mixed>
     */
    private function pageWowStat(array $entry): array
    {
        return [
            'metric' => 'page_views',
            'value' => round($entry['change'], 1),
            'unit' => 'percent_change',
            'detail' => [
                'page' => $entry['page'],
                'this_week' => $entry['this'],
                'prior_week' => $entry['prior'],
            ],
        ];
    }

    /**
     * Highest-volume actionable source. 'Other' (the ELSE junk bucket) is never a
     * "source"; zero-visitor labels are skipped.
     *
     * @param  array<string, int>  $sources
     * @return array{label:string, visitors:int}|null
     */
    private function topSource(array $sources): ?array
    {
        $filtered = [];
        foreach ($sources as $label => $visitors) {
            if ($label === 'Other' || (int) $visitors <= 0) {
                continue;
            }
            $filtered[$label] = (int) $visitors;
        }
        if ($filtered === []) {
            return null;
        }

        arsort($filtered);
        $label = (string) array_key_first($filtered);

        return ['label' => $label, 'visitors' => $filtered[$label]];
    }

    /**
     * @param  array<int, int>  $hourly
     * @param  list<int>  $hours
     */
    private function sumHours(array $hourly, array $hours): int
    {
        $sum = 0;
        foreach ($hours as $h) {
            $sum += (int) ($hourly[$h] ?? 0);
        }

        return $sum;
    }

    /**
     * @param  array<string, mixed>  $stat
     * @param  array<string, mixed>  $period
     * @return Insight
     */
    private function insight(string $id, string $kind, string $headline, array $stat, array $period): array
    {
        return [
            'id' => $id,
            'kind' => $kind,
            'headline' => $headline,
            'supporting_stat' => $stat,
            'period' => $period,
        ];
    }
}
