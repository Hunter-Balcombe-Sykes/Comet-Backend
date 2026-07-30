<?php

// #SCHEMA-1 regression guard: the three post-baseline analytics tables must keep
// Row Level Security ENABLED and FORCED, matching the hardening convention on
// site.design_kits, moderation.*, and site.platform_connections. Added in
// 20260711160000_analytics_force_rls_parity.sql.
//
// The practical delta is small today (owner is a superuser, app connects as
// app_backend with BYPASSRLS), so this is consistency/defence-in-depth — but the
// guard stops the FORCE from being silently dropped on a future table recreate.
//
// THIS FILE RAN NOWHERE FOR ITS ENTIRE LIFE. It lived in tests/Feature and gated
// every assertion on a helper that asked whether the connection was Postgres.
// Tests\TestCase::setUp() repoints the 'pgsql' connection at in-memory SQLite
// unconditionally — deliberately, so BaseModel-forced models never dial the real
// Supabase host — so that helper returned false in every lane, and every
// assertion here skipped silently in CI and locally.
//
// Strategy mirrors tests/Schema/ModerationSchemaRlsTest.php: introspect
// pg_class (PostgreSQL-only) rather than exercising RLS, which SQLite cannot
// enforce. It now runs in the applied-schema lane (phpunit.schema.xml /
// `composer test:schema`, see Tests\SchemaTestCase), against a container that
// the real supabase/migrations/ set has been applied to by
// scripts/db/apply-migrations.sh. The base case skips the whole lane when no
// migrated Postgres is present, so a dropped FORCE now fails instead of
// vanishing.

use Illuminate\Support\Facades\DB;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class)->in(__FILE__);

// The three tables hardened by 20260711000000. The four baseline event tables
// (site_visits / link_clicks / section_views / lead_submissions) are
// grandfathered enable-without-force and intentionally excluded.
dataset('analytics_forced_tables', ['site_sessions', 'item_views', 'content_popularity_scores']);

it('keeps RLS enabled and forced on the hardened analytics tables', function (string $table) {
    // Cast to int: PDO_pgsql can return booleans as 't'/'f' strings, and the
    // string 'f' is truthy in PHP — so compare the integer form explicitly.
    $row = DB::selectOne(
        "SELECT (c.relrowsecurity)::int AS rls_enabled,
                (c.relforcerowsecurity)::int AS rls_forced
           FROM pg_class c
           JOIN pg_namespace n ON n.oid = c.relnamespace
          WHERE n.nspname = 'analytics' AND c.relname = ?",
        [$table]
    );

    expect($row)->not->toBeNull("analytics.{$table} table not found.");
    expect((int) $row->rls_enabled)->toBe(1, "RLS is NOT enabled on analytics.{$table}.");
    expect((int) $row->rls_forced)->toBe(1, "FORCE ROW LEVEL SECURITY is not set on analytics.{$table} — see #SCHEMA-1.");
})->with('analytics_forced_tables');
