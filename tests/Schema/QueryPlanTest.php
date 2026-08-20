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
// (20260711160100_add_analytics_purge_indexes.sql) a PLAN-level guard.
// tests/Schema/IndexCoverageTest.php already proves each index EXISTS
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
// Postgres-only — requires a real EXPLAIN/planner, hence tests/Schema + SchemaTestCase.

use Illuminate\Support\Facades\DB;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class)->in(__FILE__);

beforeEach(function () {
    DB::statement('SET enable_seqscan = off');
});

afterEach(function () {
    DB::statement('SET enable_seqscan = on');
});

/**
 * Assert that PurgeRawAnalyticsEvents' retention-cutoff predicate on
 * analytics.$table is serviced by AN index, not a Seq Scan.
 *
 * Mirrors the actual query shape Laravel's Postgres grammar compiles for the
 * command's batched delete — `->where($tsColumn, '<', $cutoff)->limit($batchSize)
 * ->delete()` becomes `delete from "table" where "ctid" in (select "ctid" from
 * "table" where "<ts>" < ? limit ?)` — so this EXPLAINs the inner SELECT directly.
 *
 * `$acceptableIndexes` is deliberately a LIST, not one name. These tables are
 * pre-beta and empty, so `enable_seqscan = off` forces Postgres to pick *some*
 * index-based path, but with zero rows every index that structurally CAN
 * service `$column < ?` costs the same near-nothing (`0.12..8.14` in the
 * observed CI failures) — there are no stats to break the tie. Which one wins
 * is arbitrary per run: dev CI alternated between site_visits_occurred_at_idx
 * and site_visits_site_time_idx across otherwise-identical runs (#TEST-1
 * follow-up, 2026-08-19). Pinning a single name turned that tie into a coin
 * flip on a REQUIRED check. `IndexCoverageTest` already proves each SCALE-2
 * index exists and is VALID (pg_index introspection) — this file's job is
 * proving the predicate is index-servable at all, not which of several
 * equally-valid indexes the planner happens to prefer on an empty table.
 * Every name in the list is a real, non-partial index containing $column as
 * a genuine key column (verified against the baseline migration) — a plan
 * naming anything outside this list, or falling back to Seq Scan, is still
 * a real regression.
 */
function assertPurgeCutoffUsesIndex(string $table, string $column, array $acceptableIndexes): void
{
    $cutoff = now()->subDays(90)->toDateTimeString();
    $batchSize = (int) config('partna.analytics.purge_batch_size', 10_000);

    $rows = DB::select(
        "EXPLAIN SELECT ctid FROM analytics.{$table} WHERE {$column} < ? LIMIT {$batchSize}",
        [$cutoff]
    );
    $plan = implode("\n", array_map(fn ($r) => $r->{'QUERY PLAN'}, $rows));

    $usedIndex = collect($acceptableIndexes)->first(fn (string $index) => str_contains($plan, $index));

    expect($usedIndex)->not->toBeNull(
        'Expected plan to use one of ['.implode(', ', $acceptableIndexes)."] but got:\n{$plan}"
    );
    expect($plan)->not->toContain("Seq Scan on {$table}");
}

// Keep in lockstep with PurgeRawAnalyticsEvents::TABLES + the SCALE-2 migration.
// Acceptable-index lists are every non-partial btree index in
// 20260726000000_baseline_pilot.sql that carries $column as a real key column
// (any position) — partial indexes are excluded because their WHERE clause
// isn't implied by this predicate, so the planner can never legally pick them.
dataset('purgeCutoffPredicates', [
    // link_clicks_occurred_at_idx:2655 (SCALE-2, occurred_at leading),
    // analytics_link_clicks_user_occurred_idx:2607 (user_id, occurred_at),
    // link_clicks_link_time_idx:2651 (link_block_id, occurred_at),
    // link_clicks_site_time_idx:2663 (site_id, occurred_at),
    // link_clicks_pro_date_range_idx:2659 (user_id, occurred_at DESC) INCLUDE (link_block_id).
    // Excluded (partial, unusable without a platform/product_id filter):
    // link_clicks_user_platform_idx:2667, link_clicks_user_product_idx:2671.
    'link_clicks' => ['link_clicks', 'occurred_at', [
        'link_clicks_occurred_at_idx',
        'analytics_link_clicks_user_occurred_idx',
        'link_clicks_link_time_idx',
        'link_clicks_site_time_idx',
        'link_clicks_pro_date_range_idx',
    ]],
    // site_visits_occurred_at_idx:2723 (SCALE-2, occurred_at leading),
    // site_visits_site_time_idx:2731 (site_id, occurred_at) — the observed
    // alternate winner in the CI flake this test fixes,
    // analytics_site_visits_user_occurred_idx:2611 (user_id, occurred_at),
    // site_visits_pro_date_range_idx:2727 (user_id, occurred_at DESC) INCLUDE (country_code, device_type).
    'site_visits' => ['site_visits', 'occurred_at', [
        'site_visits_occurred_at_idx',
        'site_visits_site_time_idx',
        'analytics_site_visits_user_occurred_idx',
        'site_visits_pro_date_range_idx',
    ]],
    // lead_submissions_occurred_at_idx:2639 (SCALE-2, occurred_at leading),
    // lead_submissions_ip_time_idx:2635 (ip_hash, occurred_at DESC),
    // lead_submissions_prof_time_idx:2643 (user_id, occurred_at DESC),
    // lead_submissions_site_time_idx:2647 (site_id, occurred_at DESC).
    // Excluded (partial, doesn't carry occurred_at anyway): lead_submissions_customer_id_idx:2631.
    'lead_submissions' => ['lead_submissions', 'occurred_at', [
        'lead_submissions_occurred_at_idx',
        'lead_submissions_ip_time_idx',
        'lead_submissions_prof_time_idx',
        'lead_submissions_site_time_idx',
    ]],
    // section_views_occurred_at_idx:2679 (SCALE-2, occurred_at leading),
    // section_views_user_occurred_idx:2691 (user_id, occurred_at DESC),
    // section_views_site_section_occurred_idx:2687 (site_id, section_key, occurred_at DESC).
    // section_views_block_id_idx:2675 and section_views_session_section_idx:2683
    // don't carry occurred_at at all — not candidates.
    'section_views' => ['section_views', 'occurred_at', [
        'section_views_occurred_at_idx',
        'section_views_user_occurred_idx',
        'section_views_site_section_occurred_idx',
    ]],
    // item_views_occurred_at_idx:2619 (SCALE-2, occurred_at leading),
    // item_views_site_occurred_idx:2627 (site_id, occurred_at).
    // item_views_site_item_idx:2623 doesn't carry occurred_at — not a candidate.
    'item_views' => ['item_views', 'occurred_at', [
        'item_views_occurred_at_idx',
        'item_views_site_occurred_idx',
    ]],
    // site_sessions_last_seen_at_idx:2711 (SCALE-2, last_seen_at leading),
    // site_sessions_user_last_seen_idx:2715 (user_id, last_seen_at DESC).
    // site_sessions_user_started_idx:2719 is on started_at, a different column — not a candidate.
    'site_sessions' => ['site_sessions', 'last_seen_at', [
        'site_sessions_last_seen_at_idx',
        'site_sessions_user_last_seen_idx',
    ]],
]);

it('purge retention-cutoff predicate uses the timestamp-leading index, not a sequential scan', function (string $table, string $column, array $acceptableIndexes) {
    assertPurgeCutoffUsesIndex($table, $column, $acceptableIndexes);
})->with('purgeCutoffPredicates');
