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

// ─── commerce.affiliate_product_selections.brand_user_id ────────────

it('affiliate_product_selections table has a supporting index on brand_user_id', function () {
    if (! indexCoverageSuiteIsPostgres()) {
        $this->markTestSkipped('pg_indexes queries require PostgreSQL.');
    }
    assertIndexExists('commerce', 'affiliate_product_selections', 'idx_aps_brand_user_id');
});

// ─── core.wallet_currency_switch_audit.topup_id ─────────────────────────────

it('wallet_currency_switch_audit table has a supporting index on topup_id', function () {
    if (! indexCoverageSuiteIsPostgres()) {
        $this->markTestSkipped('pg_indexes queries require PostgreSQL.');
    }
    assertIndexExists('core', 'wallet_currency_switch_audit', 'idx_wcsa_topup_id');
});
