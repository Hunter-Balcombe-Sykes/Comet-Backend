<?php

// tests/Feature/Analytics/QueryPlanTest.php
//
// #TEST-1 stale-file cleanup — REWRITTEN, not deleted. The original file EXPLAIN'd
// commerce.brand_affiliate_rollup / commerce.orders, both of which were dropped
// along with the whole commerce schema in the standalone strip-down (2026-05-22 —
// CLAUDE.md: "No brand, commerce, or billing schemas"). Those tables don't exist in
// supabase/migrations/ at all anymore (archive-only), so the old assertions could
// only ever fail-or-skip; the file was dead weight.
//
// Rewritten to give #SCALE-2's new timestamp-leading purge indexes
// (20260711020000_add_analytics_purge_indexes.sql) a PLAN-level guard.
// tests/Feature/Database/IndexCoverageTest.php already proves each index EXISTS
// and is VALID (pg_index introspection); this file proves the query planner can
// actually USE one for PurgeRawAnalyticsEvents' exact predicate shape — a wrong
// column, wrong operator, or a type mismatch would pass IndexCoverageTest but
// still force a sequential scan in production.
//
// `SET enable_seqscan = off`: these tables are pre-beta and near-empty, so on row
// count alone the planner's cost estimate would happily pick a Seq Scan over any
// index regardless of whether the index actually applies — telling us nothing.
// Disabling seq scans forces Postgres to use any index that CAN service the
// predicate; if the predicate shape stopped matching the index, Postgres would be
// forced back to Seq Scan anyway (disabled, not forbidden) — that's the exact
// regression this test exists to catch. Reset in afterEach so the session-level
// GUC never leaks to another test file sharing the same connection.
//
// Postgres-only — skipped on the SQLite test default (no real EXPLAIN/planner).

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Postgres-only — query plan inspection requires a real EXPLAIN/planner.');
    }

    DB::statement('SET enable_seqscan = off');
});

afterEach(function () {
    if (DB::connection()->getDriverName() === 'pgsql') {
        DB::statement('SET enable_seqscan = on');
    }
});

/**
 * Assert that PurgeRawAnalyticsEvents' retention-cutoff predicate on
 * analytics.$table uses the SCALE-2 timestamp-leading index, not a Seq Scan.
 *
 * Mirrors the actual query shape Laravel's Postgres grammar compiles for the
 * command's batched delete — `->where($tsColumn, '<', $cutoff)->limit($batchSize)
 * ->delete()` becomes `delete from "table" where "ctid" in (select "ctid" from
 * "table" where "<ts>" < ? limit ?)` — so this EXPLAINs the inner SELECT directly.
 */
function assertPurgeCutoffUsesIndex(string $table, string $column, string $index): void
{
    $cutoff = now()->subDays(90)->toDateTimeString();
    $batchSize = (int) config('partna.analytics.purge_batch_size', 10_000);

    $rows = DB::select(
        "EXPLAIN SELECT ctid FROM analytics.{$table} WHERE {$column} < ? LIMIT {$batchSize}",
        [$cutoff]
    );
    $plan = implode("\n", array_map(fn ($r) => $r->{'QUERY PLAN'}, $rows));

    expect($plan)->toContain($index)
        ->and($plan)->not->toContain("Seq Scan on {$table}");
}

// Keep in lockstep with PurgeRawAnalyticsEvents::TABLES + the SCALE-2 migration —
// same dataset shape as IndexCoverageTest's analyticsPurgeIndexes.
dataset('purgeCutoffPredicates', [
    'link_clicks'      => ['link_clicks', 'occurred_at', 'link_clicks_occurred_at_idx'],
    'site_visits'      => ['site_visits', 'occurred_at', 'site_visits_occurred_at_idx'],
    'lead_submissions' => ['lead_submissions', 'occurred_at', 'lead_submissions_occurred_at_idx'],
    'section_views'    => ['section_views', 'occurred_at', 'section_views_occurred_at_idx'],
    'item_views'       => ['item_views', 'occurred_at', 'item_views_occurred_at_idx'],
    'site_sessions'    => ['site_sessions', 'last_seen_at', 'site_sessions_last_seen_at_idx'],
]);

it('purge retention-cutoff predicate uses the timestamp-leading index, not a sequential scan', function (string $table, string $column, string $index) {
    assertPurgeCutoffUsesIndex($table, $column, $index);
})->with('purgeCutoffPredicates');
