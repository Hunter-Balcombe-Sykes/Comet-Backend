<?php

namespace App\Services\Analytics;

use Illuminate\Support\Carbon;

/**
 * SCALE-3's raw-event queries (ComputeContentPopularityScores::aggregateItems,
 * ActionScorer::aggregate) filtered on site_id only — an unbounded scan of
 * the full event history on every 15-minute run.
 *
 * This is an ENFORCED INVARIANT, not a measured perf win. PurgeRawAnalyticsEvents
 * already deletes raw events on occurred_at at
 * config('partna.analytics_raw_event_retention_days') (90), daily, so the
 * scan was already bounded at 90 days in practice — this constant does not
 * shrink a scan that today runs unbounded, it makes the existing 90-day
 * bound explicit and defended by a query predicate instead of relying on the
 * purge alone.
 *
 * LOOKBACK_DAYS is 120, not 90, for three reasons:
 *   (a) raw events are already purged at 90 days, so 120 truncates nothing
 *       today — every prod score is unchanged by this file.
 *   (b) 120 > 90 by a month of purge slack: the purge is daily and can miss
 *       ticks, so sizing the bound AT retention risks the predicate cutting
 *       an event the purge job simply hasn't reached yet. The decay weight
 *       at 90 days is 0.5 (HALF_LIFE_DAYS = 90.0, dayWeight() = 2^(-age/90)),
 *       not negligible, so silently dropping still-live 90-day-old events
 *       would re-rank every site.
 *   (c) it exists so the scan stays bounded if retention is ever raised —
 *       at which point ComputePopularityScoresTest's ceiling-invariant case
 *       goes red ON PURPOSE, because raising retention past this ceiling IS
 *       a scoring decision, not a free parameter change.
 *
 * Deliberately NOT an env knob: an env-tunable lookback is a silent
 * re-ranking lever. The window is meant to be an explicit, tested decision.
 */
final class ScoringWindow
{
    public const LOOKBACK_DAYS = 120;

    /**
     * Return toISOString(), not toDateTimeString(): occurred_at is
     * timestamptz in prod (either format compares correctly there), but the
     * SQLite test lane stores it as TEXT and every scoring fixture writes
     * toISOString() — a mismatched format would make the lexicographic
     * comparison silently wrong under the test suite.
     */
    public static function since(): string
    {
        return Carbon::now()->subDays(self::LOOKBACK_DAYS)->toISOString();
    }
}
