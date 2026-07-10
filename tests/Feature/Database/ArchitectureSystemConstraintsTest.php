<?php

// Verifies structural invariants for the site.sites architecture column and the
// per-site design_kits table:
//   1. The architecture_id TEXT CHECK enum on site.sites is present and validated.
//   2. The ON DELETE CASCADE FK from site.design_kits to site.sites is registered.
//   3. The trg_create_empty_design_kit AFTER INSERT trigger exists on site.sites.
//
// The CHECK constraint originated in the skeleton-system cleanup migration
// (20260527070000_skeleton_system_cleanup.sql) as sites_skeleton_id_check and was
// renamed to sites_architecture_id_check by
// 20260710230000_rename_skeleton_id_to_architecture_id.sql.
//
// Uses pg_constraint / information_schema queries (rather than inserting bad rows)
// so the tests are read-only, require no clean state, and are safe to run against
// any Supabase environment.
//
// To run against Supabase dev:
//   DB_CONNECTION=pgsql DB_HOST=... php artisan test --filter ArchitectureSystemConstraintsTest

use Illuminate\Support\Facades\DB;

if (! function_exists('architectureSuiteIsPostgres')) {
    function architectureSuiteIsPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}

// ─── 1. architecture_id CHECK constraint ─────────────────────────────────────

it('sites_architecture_id_check constraint exists and is validated', function () {
    if (! architectureSuiteIsPostgres()) {
        $this->markTestSkipped('pg_constraint queries require PostgreSQL.');
    }

    $row = DB::selectOne(
        "SELECT convalidated FROM pg_constraint c
          JOIN pg_class t ON c.conrelid = t.oid
          JOIN pg_namespace n ON t.relnamespace = n.oid
         WHERE n.nspname = 'site'
           AND t.relname = 'sites'
           AND c.conname = 'sites_architecture_id_check'
           AND c.contype = 'c'",
        []
    );

    expect($row)->not->toBeNull(
        'Expected CHECK constraint [site.sites.sites_architecture_id_check] to exist but it was not found.'
    );
    expect((bool) $row->convalidated)->toBeTrue(
        'Constraint [sites_architecture_id_check] exists but is NOT VALID — run VALIDATE CONSTRAINT.'
    );
});

// ─── 2. design_kits → sites CASCADE FK ──────────────────────────────────────

it('design_kits has an ON DELETE CASCADE FK to site.sites', function () {
    if (! architectureSuiteIsPostgres()) {
        $this->markTestSkipped('pg_constraint queries require PostgreSQL.');
    }

    $row = DB::selectOne(
        "SELECT c.conname, c.confdeltype
           FROM pg_constraint c
           JOIN pg_class t   ON c.conrelid  = t.oid
           JOIN pg_namespace n ON t.relnamespace = n.oid
          WHERE n.nspname   = 'site'
            AND t.relname   = 'design_kits'
            AND c.conname   = 'design_kits_site_id_fkey'
            AND c.contype   = 'f'",
        []
    );

    expect($row)->not->toBeNull(
        'Expected FK constraint [site.design_kits.design_kits_site_id_fkey] to exist but it was not found.'
    );
    // confdeltype 'c' = CASCADE; 'a' = NO ACTION; 'r' = RESTRICT; 'n' = SET NULL; 'd' = SET DEFAULT
    expect($row->confdeltype)->toBe('c',
        'FK [design_kits_site_id_fkey] exists but is not ON DELETE CASCADE (got confdeltype='.$row->confdeltype.').'
    );
});

// ─── 3. trg_create_empty_design_kit trigger ──────────────────────────────────

it('trg_create_empty_design_kit AFTER INSERT trigger exists on site.sites', function () {
    if (! architectureSuiteIsPostgres()) {
        $this->markTestSkipped('Trigger queries require PostgreSQL.');
    }

    $row = DB::selectOne(
        "SELECT trigger_name
           FROM information_schema.triggers
          WHERE event_object_schema = 'site'
            AND event_object_table  = 'sites'
            AND trigger_name        = 'trg_create_empty_design_kit'
            AND event_manipulation  = 'INSERT'
            AND action_timing       = 'AFTER'
          LIMIT 1",
        []
    );

    expect($row)->not->toBeNull(
        'Expected AFTER INSERT trigger [trg_create_empty_design_kit] on [site.sites] but it was not found.'
    );
});
