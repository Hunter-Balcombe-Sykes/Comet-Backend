<?php

// Verifies that FK columns known to lack auto-created indexes have the expected
// supporting indexes in place. Run against real PostgreSQL only — SQLite doesn't
// have pg_index / pg_attribute.
//
// To run against a Supabase dev DB:
//   DB_CONNECTION=pgsql DB_HOST=... phpunit --filter IndexCoverageTest

use Illuminate\Support\Facades\DB;

/**
 * Return true if the current connection is a real PostgreSQL instance.
 */
function indexCoverageSuiteIsPostgres(): bool
{
    return DB::connection()->getDriverName() === 'pgsql';
}

/**
 * Assert that a named index exists on the given schema.table and is VALID
 * (not an INVALID stub left over from a cancelled CONCURRENTLY build).
 *
 * @param  string  $schema  e.g. 'site'
 * @param  string  $table  e.g. 'sites'
 * @param  string  $index  e.g. 'idx_aps_brand_user_id'
 */
function assertIndexExists(string $schema, string $table, string $index): void
{
    $row = DB::selectOne(
        'SELECT i.indisvalid
           FROM pg_index i
           JOIN pg_class c ON c.oid = i.indexrelid
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = ?
            AND c.relname = ?',
        [$schema, $index]
    );

    expect($row)->not->toBeNull(
        "Expected index [{$index}] on [{$schema}.{$table}] but it was not found."
    );
    expect((bool) $row->indisvalid)->toBeTrue(
        "Index [{$index}] exists but is INVALID — drop the stub and re-run the migration."
    );
}

// ─── analytics purge: timestamp-leading indexes (#SCALE-2) ──────────────────
//
// PurgeRawAnalyticsEvents filters each raw table by its timestamp column only.
// Every other index on these tables leads with an id/hash column, so without a
// timestamp-leading index the daily purge seq-scans. Migration
// 20260711160100_add_analytics_purge_indexes.sql adds one per table; this guard
// asserts they exist and are VALID (not an INVALID stub from a cancelled
// CONCURRENTLY build). Keep in lockstep with PurgeRawAnalyticsEvents::TABLES.

dataset('analyticsPurgeIndexes', [
    'link_clicks'      => ['link_clicks', 'link_clicks_occurred_at_idx'],
    'site_visits'      => ['site_visits', 'site_visits_occurred_at_idx'],
    'lead_submissions' => ['lead_submissions', 'lead_submissions_occurred_at_idx'],
    'section_views'    => ['section_views', 'section_views_occurred_at_idx'],
    'item_views'       => ['item_views', 'item_views_occurred_at_idx'],
    'site_sessions'    => ['site_sessions', 'site_sessions_last_seen_at_idx'],
]);

it('has a timestamp-leading purge index on each raw analytics table', function (string $table, string $index) {
    if (! indexCoverageSuiteIsPostgres()) {
        $this->markTestSkipped('pg_indexes queries require PostgreSQL.');
    }
    assertIndexExists('analytics', $table, $index);
})->with('analyticsPurgeIndexes');
