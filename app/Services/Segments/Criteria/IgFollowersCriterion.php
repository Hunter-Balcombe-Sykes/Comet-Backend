<?php

namespace App\Services\Segments\Criteria;

use App\Rules\MaxNotBelowMin;
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
     * short-circuit. The regex is also bounded to 15 digits: bigint's
     * ceiling is 19 digits, so an unbounded all-digit match (e.g. a
     * corrupted scrape) would itself overflow ::bigint and throw.
     *
     * Pinned by tests/Unit/Segments/IgFollowersExpressionTest.php.
     */
    public static function followersExpression(string $driver): string
    {
        if ($driver === 'sqlite') {
            $json = "json_extract(payload, '\$.followersCount')";

            // Same 15-digit bound as the pgsql branch below, kept in sync so
            // both drivers agree on which values resolve to NULL — SQLite's
            // CAST silently clamps rather than throwing, but a corrupted
            // 16+ digit scrape should still be treated as unusable, not
            // coerced into bigint's max value.
            return "CASE WHEN {$json} GLOB '[0-9]*' AND {$json} NOT GLOB '*[^0-9]*' "
                ."AND LENGTH({$json}) <= 15 THEN CAST({$json} AS INTEGER) ELSE NULL END";
        }

        return "CASE WHEN payload->>'followersCount' ~ '^\\d{1,15}\$' "
            ."THEN (payload->>'followersCount')::bigint ELSE NULL END";
    }

    public function rules(): array
    {
        return [
            'filters.ig_followers' => ['sometimes', 'nullable', 'array', $this->requiresABound('ig_followers requires at least one of min or max.')],
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
}
