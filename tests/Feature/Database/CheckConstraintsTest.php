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

// ─── billing.subscriptions ──────────────────────────────────────────────────

it('subscriptions_status_check constraint exists and is validated', function () {
    if (! checkConstraintsSuiteIsPostgres()) {
        $this->markTestSkipped('pg_constraint queries require PostgreSQL.');
    }
    assertCheckConstraintExists('billing', 'subscriptions', 'subscriptions_status_check');
});

// ─── commerce.commission_movements ──────────────────────────────────────────

it('commission_movements_rate_source_check constraint exists and is validated', function () {
    if (! checkConstraintsSuiteIsPostgres()) {
        $this->markTestSkipped('pg_constraint queries require PostgreSQL.');
    }
    assertCheckConstraintExists('commerce', 'commission_movements', 'commission_movements_rate_source_check');
});

it('legacy commission_ledger_rate_source_not_blank constraint has been dropped', function () {
    if (! checkConstraintsSuiteIsPostgres()) {
        $this->markTestSkipped('pg_constraint queries require PostgreSQL.');
    }

    $row = DB::selectOne(
        "SELECT 1 FROM pg_constraint c
          JOIN pg_class t ON c.conrelid = t.oid
          JOIN pg_namespace n ON t.relnamespace = n.oid
         WHERE n.nspname = 'commerce'
           AND t.relname = 'commission_movements'
           AND c.conname = 'commission_ledger_rate_source_not_blank'",
        []
    );

    expect($row)->toBeNull('Expected legacy constraint to be dropped but it still exists.');
});

// ─── core.professional_integrations ─────────────────────────────────────────

it('professional_integrations_provider_check constraint exists and is validated', function () {
    if (! checkConstraintsSuiteIsPostgres()) {
        $this->markTestSkipped('pg_constraint queries require PostgreSQL.');
    }
    assertCheckConstraintExists('core', 'professional_integrations', 'professional_integrations_provider_check');
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

// ─── core.brand_status_history ──────────────────────────────────────────────

it('brand_status_history_from_status_check constraint exists and is validated', function () {
    if (! checkConstraintsSuiteIsPostgres()) {
        $this->markTestSkipped('pg_constraint queries require PostgreSQL.');
    }
    assertCheckConstraintExists('core', 'brand_status_history', 'brand_status_history_from_status_check');
});

it('brand_status_history_to_status_check constraint exists and is validated', function () {
    if (! checkConstraintsSuiteIsPostgres()) {
        $this->markTestSkipped('pg_constraint queries require PostgreSQL.');
    }
    assertCheckConstraintExists('core', 'brand_status_history', 'brand_status_history_to_status_check');
});

// ─── feature_flag_overrides XOR scope ───────────────────────────────────────

it('feature_flag_overrides_scope_xor constraint exists and is validated', function () {
    if (! checkConstraintsSuiteIsPostgres()) {
        $this->markTestSkipped('pg_constraint queries require PostgreSQL.');
    }
    assertCheckConstraintExists('core', 'feature_flag_overrides', 'feature_flag_overrides_scope_xor');
});

// ─── site.platform_connections (CONS-27) ─────────────────────────────────────
// The platform allow-list CHECK and the dedup partial-unique index were never
// covered by a test; a migration refactor that silently dropped either would
// pass CI. These assert both still exist on the real schema.

it('platform_connections_platform_check constraint exists and is validated', function () {
    if (! checkConstraintsSuiteIsPostgres()) {
        $this->markTestSkipped('pg_constraint queries require PostgreSQL.');
    }
    assertCheckConstraintExists('site', 'platform_connections', 'platform_connections_platform_check');
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
