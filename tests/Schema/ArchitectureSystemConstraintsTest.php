<?php

// Verifies structural invariants for the site.sites architecture column and the
// per-site design_kits table:
//   1. The architecture_id TEXT CHECK enum on site.sites is present and validated.
//   2. The ON DELETE CASCADE FK from site.design_kits to site.sites is registered.
//   3. The trg_create_empty_design_kit AFTER INSERT trigger exists on site.sites.
//   4. site.themes stays DROPPED.
//   5. set_default_theme_for_site() stays DROPPED.
//
// 4 and 5 assert an ABSENCE, which is unusual enough to say why out loud: the
// theme system was removed, not disabled. CLAUDE.md's hard rule is "never
// reintroduce site.themes, settings.design.*, or theme-picker machinery" —
// there is no theme column left at all (theme_mode died 2026-08-27, plan
// 02). Design lives in site.design_kits, one nullable column per var, and a
// resurrected themes table would give it a second, competing home.
// A well-meaning re-add is exactly the regression these two guard, so do not
// delete them because "nothing references site.themes" — that IS the invariant.
//
// The CHECK constraint originated in the skeleton-system cleanup migration
// (20260527070000_skeleton_system_cleanup.sql) as sites_skeleton_id_check and was
// renamed to sites_architecture_id_check by
// 20260710230000_rename_skeleton_id_to_architecture_id.sql.
//
// THIS FILE RAN NOWHERE FOR ITS ENTIRE LIFE. It lived in tests/Feature and gated
// every assertion on a helper that asked whether the connection was Postgres.
// Tests\TestCase::setUp() repoints the 'pgsql' connection at in-memory SQLite
// unconditionally, so that helper returned false in every lane and all 3
// assertions skipped silently in CI and locally.
//
// It now runs in the applied-schema lane (phpunit.schema.xml / `composer
// test:schema`, see Tests\SchemaTestCase), against a container that the real
// supabase/migrations/ set has been applied to by scripts/db/apply-migrations.sh.

use Illuminate\Support\Facades\DB;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class)->in(__FILE__);

// ─── 1. architecture_id CHECK constraint ─────────────────────────────────────

it('sites_architecture_id_check constraint exists and is validated', function () {
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

// ─── 4. site.themes stays dropped ────────────────────────────────────────────

it('site.themes does not exist', function () {
    // information_schema.tables rather than to_regclass so a VIEW named
    // site.themes — a plausible "compatibility shim" way back in — fails too.
    $row = DB::selectOne(
        "SELECT table_type
           FROM information_schema.tables
          WHERE table_schema = 'site'
            AND table_name   = 'themes'",
        []
    );

    expect($row)->toBeNull(
        'site.themes exists again (as '.($row->table_type ?? 'unknown').'). It was dropped deliberately: '
        .'design state lives in site.design_kits, one nullable column per var. '
        .'See CLAUDE.md → "Never reintroduce site.themes".'
    );
});

// ─── 5. set_default_theme_for_site() stays dropped ───────────────────────────

it('set_default_theme_for_site() does not exist in any schema', function () {
    // Unqualified by schema on purpose: the function was dropped with CASCADE,
    // and a re-add under a different namespace would be the same regression.
    $row = DB::selectOne(
        "SELECT n.nspname
           FROM pg_proc p
           JOIN pg_namespace n ON n.oid = p.pronamespace
          WHERE p.proname = 'set_default_theme_for_site'
          LIMIT 1",
        []
    );

    expect($row)->toBeNull(
        'set_default_theme_for_site() exists again in schema ['.($row->nspname ?? 'unknown').']. '
        .'It was dropped with CASCADE alongside site.themes; the design_kits auto-insert trigger '
        .'(trg_create_empty_design_kit) is what seeds a new site now.'
    );
});
