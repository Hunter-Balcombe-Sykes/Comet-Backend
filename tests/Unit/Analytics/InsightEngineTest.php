<?php

/**
 * Pure-logic coverage for the InsightEngine — the "no fabrication" contract.
 * Each insight is proven three ways: it FIRES with sufficient data, the STAT it
 * reports is numerically correct, and it does NOT fire below its min-sample /
 * min-effect threshold. No DB — the engine is a deterministic function of its
 * inputs, so it's exercised directly.
 */

use App\Services\Analytics\InsightEngine;

function engine(): InsightEngine
{
    return new InsightEngine;
}

function period(): array
{
    return ['from' => '2026-06-27', 'to' => '2026-07-11', 'label' => 'Last 14 days'];
}

// ── time_of_day ─────────────────────────────────────────────────────────────

it('time_of_day fires and reports the correct evening-vs-daytime delta', function () {
    // daytime 06–17 (12h): 1 click each = rate 1.0/h. evening 20:00: 12 clicks = 6h → rate 2.0/h.
    $hourly = array_fill_keys(range(6, 17), 1);
    $hourly[20] = 12;

    $insight = engine()->timeOfDay($hourly, period());

    expect($insight)->not->toBeNull()
        ->and($insight['id'])->toBe('time_of_day')
        ->and($insight['kind'])->toBe('time_of_day')
        ->and($insight['headline'])->toContain('100% more often in the evening')
        ->and($insight['supporting_stat']['value'])->toBe(100.0)
        ->and($insight['supporting_stat']['unit'])->toBe('percent_change')
        ->and($insight['supporting_stat']['detail']['evening_clicks_per_hour'])->toBe(2.0)
        ->and($insight['supporting_stat']['detail']['daytime_clicks_per_hour'])->toBe(1.0)
        ->and($insight['supporting_stat']['detail']['sample'])->toBe(24)
        ->and($insight['period'])->toBe(period());
});

it('time_of_day phrases a daytime-dominant pattern in the other direction', function () {
    // daytime 06–17: 2 each = rate 2.0. evening 18: 6 clicks over 6h = rate 1.0.
    $hourly = array_fill_keys(range(6, 17), 2);
    $hourly[18] = 6;

    $insight = engine()->timeOfDay($hourly, period());

    expect($insight)->not->toBeNull()
        ->and($insight['headline'])->toContain('50% more often during the day')
        ->and($insight['supporting_stat']['value'])->toBe(-50.0);
});

it('time_of_day does NOT fire below the minimum click sample', function () {
    // 6 daytime + 6 evening = 12 clicks (< 20 min).
    $hourly = array_fill_keys(range(6, 11), 1) + [18 => 6];

    expect(engine()->timeOfDay($hourly, period()))->toBeNull();
});

it('time_of_day does NOT fire when the evening/daytime delta is under threshold', function () {
    // Plenty of clicks (36) but identical per-hour rates → 0% delta.
    $hourly = array_fill_keys(range(6, 17), 2) + array_fill_keys(range(18, 23), 2);

    expect(engine()->timeOfDay($hourly, period()))->toBeNull();
});

// ── weekday_peak ─────────────────────────────────────────────────────────────

it('weekday_peak fires and reports the correct above-average percentage', function () {
    // Sat=20, Fri=5, Thu=5 → avg 10 over 3 days, peak 100% above average.
    $weekday = [6 => 20, 5 => 5, 4 => 5];

    $insight = engine()->weekdayPeak($weekday, period());

    expect($insight)->not->toBeNull()
        ->and($insight['id'])->toBe('weekday_peak')
        ->and($insight['headline'])->toBe('Saturday is your busiest day — 100% more visits than your typical day.')
        ->and($insight['supporting_stat']['value'])->toBe(100.0)
        ->and($insight['supporting_stat']['unit'])->toBe('percent_above_average')
        ->and($insight['supporting_stat']['detail']['weekday'])->toBe('Saturday')
        ->and($insight['supporting_stat']['detail']['peak_visits'])->toBe(20)
        ->and($insight['supporting_stat']['detail']['average_visits'])->toBe(10.0)
        ->and($insight['supporting_stat']['detail']['sample'])->toBe(30);
});

it('weekday_peak does NOT fire below the minimum visit sample', function () {
    // Only 10 visits total (< 20).
    expect(engine()->weekdayPeak([6 => 5, 5 => 3, 4 => 2], period()))->toBeNull();
});

it('weekday_peak does NOT fire with too few active weekdays', function () {
    // 25 visits but all on one day (< 3 distinct days) — "peak" is meaningless.
    expect(engine()->weekdayPeak([6 => 25], period()))->toBeNull();
});

it('weekday_peak does NOT fire when the peak is barely above average', function () {
    // 11 vs avg 10 = 10% above (< 25%).
    expect(engine()->weekdayPeak([6 => 11, 5 => 10, 4 => 9], period()))->toBeNull();
});

// ── page_riser / page_faller ─────────────────────────────────────────────────

it('page riser + faller both fire with correct week-over-week percentages', function () {
    $wow = [
        'shop' => ['title' => 'Shop', 'this_week' => 20, 'prior_week' => 10],      // +100%
        'gallery' => ['title' => 'Gallery', 'this_week' => 10, 'prior_week' => 20], // -50%
    ];

    $insights = engine()->pageRisersFallers($wow, period());

    expect($insights)->toHaveCount(2);
    $byKind = collect($insights)->keyBy('kind');

    expect($byKind['page_riser']['headline'])->toBe('Your Shop page views are up 100% this week vs last.')
        ->and($byKind['page_riser']['supporting_stat']['value'])->toBe(100.0)
        ->and($byKind['page_riser']['supporting_stat']['detail']['this_week'])->toBe(20)
        ->and($byKind['page_riser']['supporting_stat']['detail']['prior_week'])->toBe(10)
        ->and($byKind['page_faller']['headline'])->toBe('Your Gallery page views are down 50% this week vs last.')
        ->and($byKind['page_faller']['supporting_stat']['value'])->toBe(-50.0);
});

it('page_riser picks the single biggest riser among several', function () {
    $wow = [
        'shop' => ['title' => 'Shop', 'this_week' => 15, 'prior_week' => 10],   // +50%
        'listen' => ['title' => 'Listen', 'this_week' => 30, 'prior_week' => 10], // +200%
    ];

    $insights = engine()->pageRisersFallers($wow, period());

    expect($insights)->toHaveCount(1)
        ->and($insights[0]['kind'])->toBe('page_riser')
        ->and($insights[0]['headline'])->toContain('Listen')
        ->and($insights[0]['supporting_stat']['value'])->toBe(200.0);
});

it('page riser/faller ignores pages without a real prior-week baseline', function () {
    // prior_week 5 (< 10) → no meaningful %, dropped despite a huge swing.
    $wow = ['shop' => ['title' => 'Shop', 'this_week' => 40, 'prior_week' => 5]];

    expect(engine()->pageRisersFallers($wow, period()))->toBe([]);
});

it('page riser/faller does NOT fire on sub-threshold changes', function () {
    $wow = [
        'shop' => ['title' => 'Shop', 'this_week' => 110, 'prior_week' => 100],   // +10%
        'gallery' => ['title' => 'Gallery', 'this_week' => 90, 'prior_week' => 100], // -10%
    ];

    expect(engine()->pageRisersFallers($wow, period()))->toBe([]);
});

// ── traffic_source_shift ─────────────────────────────────────────────────────

it('traffic_source_shift fires when the leader changes, with the correct share', function () {
    $thisWeek = ['Instagram' => 20, 'Direct Link' => 5];  // total 25, top Instagram 80%
    $priorWeek = ['Direct Link' => 18, 'Instagram' => 4]; // total 22, top Direct Link

    $insight = engine()->trafficSourceShift($thisWeek, $priorWeek, period());

    expect($insight)->not->toBeNull()
        ->and($insight['headline'])->toBe('Instagram overtook Direct Link as your top traffic source this week.')
        ->and($insight['supporting_stat']['value'])->toBe(80.0)
        ->and($insight['supporting_stat']['unit'])->toBe('percent_share')
        ->and($insight['supporting_stat']['detail']['new_top_source'])->toBe('Instagram')
        ->and($insight['supporting_stat']['detail']['previous_top_source'])->toBe('Direct Link')
        ->and($insight['supporting_stat']['detail']['new_top_visits'])->toBe(20);
});

it('traffic_source_shift never treats the Other junk bucket as a source', function () {
    // Other has the most raw visits but must be excluded; Instagram is the real leader.
    $thisWeek = ['Other' => 100, 'Instagram' => 20, 'Direct Link' => 5];
    $priorWeek = ['Direct Link' => 30];

    $insight = engine()->trafficSourceShift($thisWeek, $priorWeek, period());

    expect($insight)->not->toBeNull()
        ->and($insight['supporting_stat']['detail']['new_top_source'])->toBe('Instagram');
});

it('traffic_source_shift does NOT fire when the leader is unchanged', function () {
    $thisWeek = ['Instagram' => 20, 'Direct Link' => 5];
    $priorWeek = ['Instagram' => 18, 'Direct Link' => 4];

    expect(engine()->trafficSourceShift($thisWeek, $priorWeek, period()))->toBeNull();
});

it('traffic_source_shift does NOT fire below the per-week visit floor', function () {
    // This week only 9 visits (< 15).
    $thisWeek = ['Instagram' => 6, 'Direct Link' => 3];
    $priorWeek = ['Direct Link' => 20];

    expect(engine()->trafficSourceShift($thisWeek, $priorWeek, period()))->toBeNull();
});

it('traffic_source_shift does NOT fire when the new leader lacks standalone volume', function () {
    // Leader changed (Direct→Instagram) but Instagram only 7 visits (< 8 min).
    $thisWeek = ['Instagram' => 7, 'Facebook' => 6, 'TikTok' => 5];
    $priorWeek = ['Direct Link' => 20];

    expect(engine()->trafficSourceShift($thisWeek, $priorWeek, period()))->toBeNull();
});

// ── dwell_outlier ────────────────────────────────────────────────────────────

it('dwell_outlier fires with the correct weighted ratio', function () {
    // gallery: 10 samples, 1_600_000ms → 160s avg. home: 10 samples, 400_000ms → 40s avg.
    // site: 2_000_000ms / 20 = 100s avg. gallery ratio = 160/100 = 1.6.
    $stats = [
        'gallery' => ['title' => 'Gallery', 'dwell_sum_ms' => 1_600_000, 'dwell_n' => 10],
        'home' => ['title' => 'Home', 'dwell_sum_ms' => 400_000, 'dwell_n' => 10],
    ];

    $insight = engine()->dwellOutlier($stats, period());

    expect($insight)->not->toBeNull()
        ->and($insight['headline'])->toBe('Visitors spend 1.6× longer on your Gallery page than your site average.')
        ->and($insight['supporting_stat']['value'])->toBe(1.6)
        ->and($insight['supporting_stat']['unit'])->toBe('multiple')
        ->and($insight['supporting_stat']['detail']['page'])->toBe('gallery')
        ->and($insight['supporting_stat']['detail']['page_avg_seconds'])->toBe(160)
        ->and($insight['supporting_stat']['detail']['site_avg_seconds'])->toBe(100);
});

it('dwell_outlier does NOT fire below the minimum dwell-sample count', function () {
    // gallery avg is huge (100s) but only 5 samples (< 8) → skipped, nothing qualifies.
    $stats = [
        'gallery' => ['title' => 'Gallery', 'dwell_sum_ms' => 500_000, 'dwell_n' => 5],
        'home' => ['title' => 'Home', 'dwell_sum_ms' => 200_000, 'dwell_n' => 20],
    ];

    expect(engine()->dwellOutlier($stats, period()))->toBeNull();
});

it('dwell_outlier does NOT fire when no page clears the ratio threshold', function () {
    // gallery 60s vs home 55s → site ~57.5s, ratio 1.04 (< 1.5).
    $stats = [
        'gallery' => ['title' => 'Gallery', 'dwell_sum_ms' => 600_000, 'dwell_n' => 10],
        'home' => ['title' => 'Home', 'dwell_sum_ms' => 550_000, 'dwell_n' => 10],
    ];

    expect(engine()->dwellOutlier($stats, period()))->toBeNull();
});

it('returns nothing for completely empty inputs (no fabrication from zero data)', function () {
    expect(engine()->timeOfDay([], period()))->toBeNull()
        ->and(engine()->weekdayPeak([], period()))->toBeNull()
        ->and(engine()->pageRisersFallers([], period()))->toBe([])
        ->and(engine()->trafficSourceShift([], [], period()))->toBeNull()
        ->and(engine()->dwellOutlier([], period()))->toBeNull();
});
