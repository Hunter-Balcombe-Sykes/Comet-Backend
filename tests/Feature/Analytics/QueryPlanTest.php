<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Postgres-only query plan inspection tests.
 * Asserts that the analytics read-path queries use index scans, not sequential scans,
 * on the commerce tables. Requires the indexes created by
 * supabase/migrations/20260506000000_create_orders_schema.sql to be present.
 *
 * Skipped automatically on SQLite (the test environment default).
 */
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Postgres-only — query plan inspection requires real EXPLAIN.');
    }
});

it('per-affiliate rollup query uses an index scan and not a sequential scan', function () {
    $userId = (string) Str::uuid();
    $from = now()->subDays(30)->toDateString();
    $to = now()->toDateString();
    $currencyCode = 'AUD';

    $sql = <<<'SQL'
        SELECT
            affiliate_user_id,
            SUM(orders_count) AS orders_count,
            SUM(gross_cents) AS gross_cents,
            SUM(gross_cents - refund_cents) AS net_cents,
            SUM(commission_cents - reversed_commission_cents) AS commission_net_cents
        FROM commerce.brand_affiliate_rollup
        WHERE brand_user_id = ?
          AND day BETWEEN ? AND ?
          AND currency_code = ?
        GROUP BY affiliate_user_id
        ORDER BY SUM(commission_cents - reversed_commission_cents) DESC
        LIMIT 100
    SQL;

    $rows = DB::select('EXPLAIN '.$sql, [$userId, $from, $to, $currencyCode]);
    $plan = implode("\n", array_map(fn ($r) => $r->{'QUERY PLAN'}, $rows));

    expect($plan)->toContain('Index Scan');
    expect($plan)->not->toContain('Seq Scan on brand_affiliate_rollup');
});

it('brand totals query on commerce.orders uses an index', function () {
    $userId = (string) Str::uuid();
    $from = now()->subDays(30)->format('Y-m-d').' 00:00:00';
    $to = now()->endOfDay()->format('Y-m-d H:i:s');
    $excluded = "('stub','cancelled','voided','refunded')";

    $sql = <<<SQL
        SELECT
            COUNT(*) AS orders_count,
            COALESCE(SUM(gross_cents), 0) AS gross_cents,
            COALESCE(SUM(refund_cents), 0) AS refunded_cents,
            COALESCE(SUM(net_cents), 0) AS net_cents
        FROM commerce.orders
        WHERE brand_user_id = ?
          AND status NOT IN {$excluded}
          AND occurred_at >= ?
          AND occurred_at <= ?
    SQL;

    $rows = DB::select('EXPLAIN '.$sql, [$userId, $from, $to]);
    $plan = implode("\n", array_map(fn ($r) => $r->{'QUERY PLAN'}, $rows));

    // Expect the planner to pick up the idx_orders_brand_status_occurred composite index
    expect($plan)->toContain('Index Scan');
    expect($plan)->not->toContain('Seq Scan on orders');
});
