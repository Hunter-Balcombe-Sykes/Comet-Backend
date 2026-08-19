<?php

namespace App\Services\Segments\Criteria;

use App\Rules\MaxNotBelowMin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Visit/click volume over a lookback window, as a correlated subquery against
 * the raw analytics event tables.
 *
 * Source note: the pre-standalone design put this on a daily rollup table.
 * That rollup never had a writer and was dropped on 2026-08-19 — the raw
 * event tables are where the data actually lands, and both carry a
 * (user_id, occurred_at DESC) index that serves this lookup. Raw events are
 * purged at 90 days, which is why window_days is capped there rather than at
 * a year.
 */
final class AnalyticsCriterion implements SegmentCriterion
{
    use ResolvesFilterValues;

    /**
     * metric → table + aggregate. This allowlist is the SQL-injection
     * boundary: the incoming `metric` string is only ever used as a key into
     * this map, never interpolated into SQL.
     */
    private const METRICS = [
        'visits' => ['table' => 'analytics.site_visits', 'aggregate' => 'COUNT(*)'],
        'unique_visitors' => ['table' => 'analytics.site_visits', 'aggregate' => 'COUNT(DISTINCT m.visitor_id)'],
        'clicks' => ['table' => 'analytics.link_clicks', 'aggregate' => 'COUNT(*)'],
        'unique_clickers' => ['table' => 'analytics.link_clicks', 'aggregate' => 'COUNT(DISTINCT m.visitor_id)'],
    ];

    public function keys(): array
    {
        return ['analytics'];
    }

    public function rules(): array
    {
        return [
            'filters.analytics' => ['sometimes', 'nullable', 'array', $this->requiresABound('analytics requires at least one of min or max.')],
            'filters.analytics.metric' => ['required_with:filters.analytics', Rule::in(array_keys(self::METRICS))],
            'filters.analytics.window_days' => ['required_with:filters.analytics', 'integer', 'min:1', 'max:90'],
            'filters.analytics.min' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'filters.analytics.max' => ['sometimes', 'nullable', 'integer', 'min:0', new MaxNotBelowMin('filters.analytics.min')],
        ];
    }

    public function isActive(array $filters): bool
    {
        $config = $this->objectConfig($filters, 'analytics');

        // `min: 0` is not a bound (see ResolvesFilterValues::isLowerBound), so
        // it does not activate the criterion on its own. Validation rejects
        // that shape outright rather than letting it go inert here — an inert
        // sole criterion would resolve the whole segment to the EMPTY set.
        return isset(self::METRICS[$config['metric'] ?? ''])
            && isset($config['window_days'])
            && (self::isLowerBound($config['min'] ?? null) || isset($config['max']));
    }

    public function apply(Builder $query, array $filters): void
    {
        $config = $this->objectConfig($filters, 'analytics');

        $metric = self::METRICS[$config['metric']];
        $table = $metric['table'];
        $aggregate = $metric['aggregate'];

        // A count is always >= 0, so `min: 0` bounds nothing — normalise it to
        // null so it takes the NOT EXISTS branch below. This is not just a
        // relaxed threshold: the min branch cannot SEE zero-activity users at
        // all (no rows → no group), so `min: 0, max: N` would otherwise drop
        // exactly the low-traffic users it is meant to find, and
        // `min: 0, max: 0` would be unsatisfiable by construction.
        $min = self::isLowerBound($config['min'] ?? null) ? (int) $config['min'] : null;
        $max = $config['max'] ?? null;

        // Unreachable via the API — validation 422s a bound-less analytics
        // object and isActive() keeps it out of the query. Guarded anyway
        // because the failure mode is silent and wrong in the dangerous
        // direction: a null binding makes `> ?` never true, so NOT EXISTS
        // holds for everyone and the segment quietly matches ALL users.
        if ($min === null && $max === null) {
            return;
        }

        // Cutoff computed in PHP and bound — no SQL date arithmetic, so the
        // predicate is identical on Postgres and SQLite.
        $cutoff = now()->subDays((int) $config['window_days'])->startOfDay()->toDateTimeString();

        // GROUP BY is required: Postgres tolerates a bare HAVING, SQLite
        // rejects it ("HAVING clause on a non-aggregate query"). Grouping by
        // the correlating column yields exactly one group, or none at all when
        // the user has no rows in the window — which is what produces the
        // zero-row semantics below.
        $inner = "SELECT 1 FROM {$table} m"
            .' WHERE m.user_id = core.users.id AND m.occurred_at >= ?'
            .' GROUP BY m.user_id HAVING ';

        if ($min !== null) {
            // No rows → no group → EXISTS false → excluded.
            $having = "{$aggregate} >= ?";
            $bindings = [$cutoff, (int) $min];

            if ($max !== null) {
                $having .= " AND {$aggregate} <= ?";
                $bindings[] = (int) $max;
            }

            $query->whereRaw("EXISTS ({$inner}{$having})", $bindings);

            return;
        }

        // No lower bound (max alone, or a normalised `min: 0`): invert, so the
        // subquery looks for a DISQUALIFYING group instead of a qualifying
        // one. No rows → no group → NOT EXISTS true → INCLUDED. A user with no
        // events has 0, which is <= max. Deliberate — this is what makes
        // "target low-traffic users" work, and it is the only branch that can
        // return zero-activity users at all.
        $query->whereRaw("NOT EXISTS ({$inner}{$aggregate} > ?)", [$cutoff, (int) $max]);
    }
}
