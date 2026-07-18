<?php

namespace App\Services\Segments\Criteria;

use App\Rules\MaxNotBelowMin;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Visit/click volume over a lookback window, as a correlated subquery against
 * the raw analytics event tables.
 *
 * Source note: analytics.site_metrics_daily would be the natural home for this
 * but is vestigial — it has no writer and zero rows. The raw event tables are
 * where the data actually lands, and both carry a (user_id, occurred_at DESC)
 * index that serves this lookup. Raw events are purged at 90 days, which is
 * why window_days is capped there rather than at a year.
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
            'filters.analytics' => ['sometimes', 'nullable', 'array', $this->requiresABound()],
            'filters.analytics.metric' => ['required_with:filters.analytics', Rule::in(array_keys(self::METRICS))],
            'filters.analytics.window_days' => ['required_with:filters.analytics', 'integer', 'min:1', 'max:90'],
            'filters.analytics.min' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'filters.analytics.max' => ['sometimes', 'nullable', 'integer', 'min:0', new MaxNotBelowMin('filters.analytics.min')],
        ];
    }

    public function isActive(array $filters): bool
    {
        $config = $this->objectConfig($filters, 'analytics');

        return isset(self::METRICS[$config['metric'] ?? ''])
            && isset($config['window_days'])
            && (isset($config['min']) || isset($config['max']));
    }

    public function apply(Builder $query, array $filters): void
    {
        $config = $this->objectConfig($filters, 'analytics');

        $metric = self::METRICS[$config['metric']];
        $table = $metric['table'];
        $aggregate = $metric['aggregate'];

        $min = $config['min'] ?? null;
        $max = $config['max'] ?? null;

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

        // max-only: no rows → no group → NOT EXISTS true → INCLUDED. A user
        // with no events has 0, which is <= max. Deliberate — this is what
        // makes "target low-traffic users" work.
        $query->whereRaw("NOT EXISTS ({$inner}{$aggregate} > ?)", [$cutoff, (int) $max]);
    }

    /** At least one of min/max, which no structural rule can express. */
    private function requiresABound(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            if (($value['min'] ?? null) === null && ($value['max'] ?? null) === null) {
                $fail('analytics requires at least one of min or max.');
            }
        };
    }
}
