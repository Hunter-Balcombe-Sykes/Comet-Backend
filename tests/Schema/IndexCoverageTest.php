<?php

// Verifies that FK columns known to lack auto-created indexes have the expected
// supporting indexes in place.
//
// THIS FILE RAN NOWHERE FOR ITS ENTIRE LIFE. It lived in tests/Feature and gated
// every one of its assertions on a helper that asked whether the connection was
// Postgres. Tests\TestCase::setUp() repoints the 'pgsql' connection at in-memory
// SQLite unconditionally, so that helper returned false in every lane and all 7
// assertions skipped silently in CI and locally.
//
// It now runs in the applied-schema lane (phpunit.schema.xml / `composer
// test:schema`, see Tests\SchemaTestCase), against a container that the real
// supabase/migrations/ set has been applied to by scripts/db/apply-migrations.sh.

use Illuminate\Support\Facades\DB;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class)->in(__FILE__);

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
    'link_clicks' => ['link_clicks', 'link_clicks_occurred_at_idx'],
    'site_visits' => ['site_visits', 'site_visits_occurred_at_idx'],
    'lead_submissions' => ['lead_submissions', 'lead_submissions_occurred_at_idx'],
    'section_views' => ['section_views', 'section_views_occurred_at_idx'],
    'item_views' => ['item_views', 'item_views_occurred_at_idx'],
    'action_events' => ['action_events', 'action_events_occurred_at_idx'],
    'site_sessions' => ['site_sessions', 'site_sessions_last_seen_at_idx'],
]);

it('has a timestamp-leading purge index on each raw analytics table', function (string $table, string $index) {
    assertIndexExists('analytics', $table, $index);
})->with('analyticsPurgeIndexes');

// ─── analytics erasure/DSAR: user-leading indexes (#DINT-1) ─────────────────
//
// analytics.item_views and analytics.action_events are the only two raw
// analytics tables erased by an explicit `WHERE user_id = ?` DELETE rather than
// an FK cascade (AccountDeletionService::PURGED_PII_TABLES; both carry a
// denormalised nullable user_id with no FK to core.users). The DSAR export
// reads the same two with `WHERE user_id = ? ORDER BY occurred_at`. Every other
// raw analytics table already carried a (user_id, occurred_at) index; these two
// were missed until 20260730130000/20260730130001. Keep in lockstep with
// AccountDeletionService::purgeItemViewsPii()/purgeActionEventsPii().

dataset('analyticsUserScopedIndexes', [
    'item_views' => ['item_views', 'item_views_user_occurred_idx'],
    'action_events' => ['action_events', 'action_events_user_occurred_idx'],
]);

it('has a user-leading erasure index on each explicitly-purged analytics table', function (string $table, string $index) {
    assertIndexExists('analytics', $table, $index);
})->with('analyticsUserScopedIndexes');

// ─── pool candidate sort: item-leading facet indexes (#SCALE-1) ─────────────
//
// SectionCandidates::ruleCandidates() sorts every automatic pool by a
// CORRELATED aggregate over one of these two facet tables — MAX(published_from)
// for recency, MIN(starts_at_utc) for occurrence. It has to stay correlated:
// both tables are keyed (item_id, source_id), so an item carried by two sources
// has two facet rows and the obvious join emits it twice (pinned by
// tests/Feature/Site/SectionCandidateOrderingTest.php).
//
// Correlated means one probe per candidate row, so the probe's cost IS the
// query's cost. The PRIMARY KEY leads with item_id and finds the row, but does
// not carry the sorted column — measured on dev 2026-08-25, the probes were
// 4022 of the query's 4099 shared buffers. These two indexes let Postgres serve
// the aggregate as an Index Only Scan Backward + LIMIT 1 instead.
//
// Neither index has any other reader, which is exactly why this guard exists: a
// future "unused index" sweep would find nothing pointing at them from app/.
// The reader is the ORDER BY in SectionCandidates, and it names them in a
// comment for the same reason.
//
// Write side: both tables are upserted by ProjectionWriter on every connector
// run, so this is two more btree entries per facet row written. Measured on dev
// at the same date — 120 kB against f_published's 192 kB PK over 2953 rows, and
// 16 kB on f_occurrence. Two narrow two-column indexes on tables whose rows are
// written once per sync and read on every public render.

dataset('poolCandidateSortIndexes', [
    'f_published' => ['f_published', 'idx_f_published_item_published'],
    'f_occurrence' => ['f_occurrence', 'idx_f_occurrence_item_starts'],
]);

it('has an item-leading covering index on each pool candidate sort facet', function (string $table, string $index) {
    assertIndexExists('content', $table, $index);
})->with('poolCandidateSortIndexes');
