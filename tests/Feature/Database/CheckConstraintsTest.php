<?php

// Verifies that DB-level CHECK constraints exist and are enforced on enum-like columns.
// Strategy: query pg_constraint to assert each constraint is present and validated
// (rather than inserting bad rows, which would fail on FK constraints before reaching
// the CHECK on tables with foreign keys). Run against real PostgreSQL only.
//
// To run against a Supabase dev DB:
//   DB_CONNECTION=pgsql DB_HOST=... phpunit --filter CheckConstraintsTest

use Illuminate\Support\Facades\DB;

/**
 * Return true if the current connection is a real PostgreSQL instance.
 * Named with prefix to avoid redeclare collision if other test files define isPostgres().
 */
function checkConstraintsSuiteIsPostgres(): bool
{
    return DB::connection()->getDriverName() === 'pgsql';
}

/**
 * Assert that a named CHECK constraint exists on the given table and has been validated.
 *
 * @param  string  $schema  e.g. 'site'
 * @param  string  $table  e.g. 'blocks'
 * @param  string  $constraint  e.g. 'blocks_block_type_check'
 */
function assertCheckConstraintExists(string $schema, string $table, string $constraint): void
{
    $row = DB::selectOne(
        "SELECT convalidated FROM pg_constraint c
          JOIN pg_class t ON c.conrelid = t.oid
          JOIN pg_namespace n ON t.relnamespace = n.oid
         WHERE n.nspname = ?
           AND t.relname = ?
           AND c.conname = ?
           AND c.contype = 'c'",
        [$schema, $table, $constraint]
    );

    expect($row)->not->toBeNull(
        "Expected CHECK constraint [{$schema}.{$table}.{$constraint}] to exist but it was not found."
    );
    expect((bool) $row->convalidated)->toBeTrue(
        "Constraint [{$constraint}] exists but is NOT VALID — run VALIDATE CONSTRAINT."
    );
}

// ─── site.blocks ────────────────────────────────────────────────────────────

it('blocks_group_type_check constraint exists and is validated', function () {
    if (! checkConstraintsSuiteIsPostgres()) {
        $this->markTestSkipped('pg_constraint queries require PostgreSQL.');
    }
    assertCheckConstraintExists('site', 'blocks', 'blocks_group_type_check');
});

// ─── site.site_media ────────────────────────────────────────────────────────

it('site_media_pool_check constraint exists and is validated', function () {
    if (! checkConstraintsSuiteIsPostgres()) {
        $this->markTestSkipped('pg_constraint queries require PostgreSQL.');
    }
    assertCheckConstraintExists('site', 'site_media', 'site_media_pool_check');
});

// ─── notifications.email_subscriptions ──────────────────────────────────────

it('email_subscriptions_status_check constraint exists and is validated', function () {
    if (! checkConstraintsSuiteIsPostgres()) {
        $this->markTestSkipped('pg_constraint queries require PostgreSQL.');
    }
    assertCheckConstraintExists('notifications', 'email_subscriptions', 'email_subscriptions_status_check');
});

// ─── core.partna_staff ──────────────────────────────────────────────────────

it('partna_staff_role_check constraint exists and is validated', function () {
    if (! checkConstraintsSuiteIsPostgres()) {
        $this->markTestSkipped('pg_constraint queries require PostgreSQL.');
    }
    assertCheckConstraintExists('core', 'partna_staff', 'partna_staff_role_check');
});

// ─── feature_flag_overrides scope ───────────────────────────────────────────
// #TEST-1 stale-file cleanup: the original XOR constraint enforced
// "exactly one of brand_id/professional_id set". brand_id was dropped along
// with the brand concept (baseline 20260526000000's comment on this table:
// "brand_id column + the scope_xor constraint + 2 brand indexes dropped; the
// surviving scope constraint is a plain professional_id-not-null check") —
// the constraint was RENAMED, not just left as-is, so the old
// feature_flag_overrides_scope_xor name no longer exists on real Postgres.

it('feature_flag_overrides_scope_set constraint exists and is validated', function () {
    if (! checkConstraintsSuiteIsPostgres()) {
        $this->markTestSkipped('pg_constraint queries require PostgreSQL.');
    }
    assertCheckConstraintExists('core', 'feature_flag_overrides', 'feature_flag_overrides_scope_set');
});

it('legacy feature_flag_overrides_scope_xor constraint has been dropped', function () {
    if (! checkConstraintsSuiteIsPostgres()) {
        $this->markTestSkipped('pg_constraint queries require PostgreSQL.');
    }

    $row = DB::selectOne(
        "SELECT 1 FROM pg_constraint c
          JOIN pg_class t ON c.conrelid = t.oid
          JOIN pg_namespace n ON t.relnamespace = n.oid
         WHERE n.nspname = 'core'
           AND t.relname = 'feature_flag_overrides'
           AND c.conname = 'feature_flag_overrides_scope_xor'",
        []
    );

    expect($row)->toBeNull('Expected legacy constraint to be dropped but it still exists.');
});

// ─── site.platform_connections (CONS-27) ─────────────────────────────────────
// The dedup partial-unique index was never covered by a test; a migration
// refactor that silently dropped it would pass CI. This asserts it still
// exists on the real schema.

// #TEST-1 stale-file cleanup: the platform allow-list CHECK itself was
// deliberately DROPPED by migration 20260629120000_drop_platform_connections_check.sql
// (Platform Integrations Registry Redesign — PlatformRegistry is now the single
// source of truth for valid platforms, not a DB CHECK). The old "exists and is
// validated" assertion below was asserting something no longer true on real
// Postgres; flipped to match the established "has been dropped" pattern.

it('legacy platform_connections_platform_check constraint has been dropped', function () {
    if (! checkConstraintsSuiteIsPostgres()) {
        $this->markTestSkipped('pg_constraint queries require PostgreSQL.');
    }

    $row = DB::selectOne(
        "SELECT 1 FROM pg_constraint c
          JOIN pg_class t ON c.conrelid = t.oid
          JOIN pg_namespace n ON t.relnamespace = n.oid
         WHERE n.nspname = 'site'
           AND t.relname = 'platform_connections'
           AND c.conname = 'platform_connections_platform_check'",
        []
    );

    expect($row)->toBeNull('Expected legacy constraint to be dropped but it still exists.');
});

it('platform_connections unique-active partial index exists and is UNIQUE + partial', function () {
    if (! checkConstraintsSuiteIsPostgres()) {
        $this->markTestSkipped('pg_indexes queries require PostgreSQL.');
    }

    $row = DB::selectOne(
        'SELECT indexdef FROM pg_indexes
          WHERE schemaname = ? AND tablename = ? AND indexname = ?',
        ['site', 'platform_connections', 'idx_platform_connections_unique_active']
    );

    expect($row)->not->toBeNull(
        'Expected partial unique index [idx_platform_connections_unique_active] to exist but it was not found.'
    );
    // Guard the two properties that enforce "one active row per (user, platform, resource)":
    // UNIQUE (the dedup) and the partial WHERE deleted_at IS NULL (so soft-deleted rows don't collide).
    expect($row->indexdef)->toContain('UNIQUE');
    expect($row->indexdef)->toContain('WHERE');
});

// ─── analytics.site_sessions ────────────────────────────────────────────────
// #TEST-1 sub-item 5 — duration_seconds is capped so a stuck client heartbeat
// can't manufacture an absurd average session length (see migration
// 20260610000000_analytics_v2_clicks_sessions.sql's comment on the column).

it('site_sessions_duration_check constraint exists and is validated', function () {
    if (! checkConstraintsSuiteIsPostgres()) {
        $this->markTestSkipped('pg_constraint queries require PostgreSQL.');
    }
    assertCheckConstraintExists('analytics', 'site_sessions', 'site_sessions_duration_check');
});
