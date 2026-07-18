<?php

namespace App\Services\Segments\Criteria;

use App\Rules\MaxNotBelowMin;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Follower-count band over the synced Instagram connection payload.
 *
 * Matches only ACTIVE instagram rows in site.platform_connections, same
 * correlated-subquery pattern as HasIntegrationCriterion.
 */
final class IgFollowersCriterion implements SegmentCriterion
{
    use ResolvesFilterValues;

    public function keys(): array
    {
        return ['ig_followers'];
    }

    /**
     * SQL expression yielding the follower count as an integer, or NULL when
     * the payload value is missing or non-numeric.
     *
     * The digit guard MUST short-circuit the cast: Postgres throws on
     * ::bigint over non-numeric text, and payloads legitimately carry
     * int|string|null (InstagramPayload::intStringOrNull). A bare
     * `guard AND cast` is NOT safe — Postgres does not guarantee AND operand
     * evaluation order — so this uses CASE, which is documented to
     * short-circuit. bigint, not int, so a garbage-huge value cannot overflow.
     *
     * Pinned by tests/Unit/Segments/IgFollowersExpressionTest.php.
     */
    public static function followersExpression(string $driver): string
    {
        if ($driver === 'sqlite') {
            $json = "json_extract(payload, '\$.followersCount')";

            return "CASE WHEN {$json} GLOB '[0-9]*' AND {$json} NOT GLOB '*[^0-9]*' "
                ."THEN CAST({$json} AS INTEGER) ELSE NULL END";
        }

        return "CASE WHEN payload->>'followersCount' ~ '^\\d+\$' "
            ."THEN (payload->>'followersCount')::bigint ELSE NULL END";
    }

    public function rules(): array
    {
        return [
            'filters.ig_followers' => ['sometimes', 'nullable', 'array', $this->requiresABound()],
            'filters.ig_followers.min' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'filters.ig_followers.max' => ['sometimes', 'nullable', 'integer', 'min:0', new MaxNotBelowMin('filters.ig_followers.min')],
            'filters.ig_followers.synced_within_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
        ];
    }

    public function isActive(array $filters): bool
    {
        $config = $this->objectConfig($filters, 'ig_followers');

        // synced_within_days alone is a freshness check on nothing.
        return isset($config['min']) || isset($config['max']);
    }

    public function apply(Builder $query, array $filters): void
    {
        $config = $this->objectConfig($filters, 'ig_followers');
        $min = $config['min'] ?? null;
        $max = $config['max'] ?? null;
        $syncedWithin = $config['synced_within_days'] ?? null;

        $query->whereHas('integrationConnections', function ($q) use ($min, $max, $syncedWithin): void {
            $q->where('platform', 'instagram')->where('is_active', true);

            $followers = self::followersExpression($q->getConnection()->getDriverName());

            if ($min !== null) {
                $q->whereRaw("{$followers} >= ?", [(int) $min]);
            }

            if ($max !== null) {
                $q->whereRaw("{$followers} <= ?", [(int) $max]);
            }

            if ($syncedWithin !== null) {
                // A never-refreshed row falls back to created_at — a fresh
                // connect is fresh data.
                $q->whereRaw('COALESCE(last_refreshed_at, created_at) >= ?', [
                    now()->subDays((int) $syncedWithin)->toDateTimeString(),
                ]);
            }
        });
    }

    /** At least one of min/max, which no structural rule can express. */
    private function requiresABound(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            if (($value['min'] ?? null) === null && ($value['max'] ?? null) === null) {
                $fail('ig_followers requires at least one of min or max.');
            }
        };
    }
}
