<?php

// Verifies that DB-level CHECK constraints exist and are VALIDATED on enum-like
// columns. Strategy: query pg_constraint for each constraint rather than inserting
// bad rows, which on tables with foreign keys would fail on the FK before ever
// reaching the CHECK.
//
// THIS FILE RAN NOWHERE FOR ITS ENTIRE LIFE. It lived in tests/Feature and gated
// every one of its 22 assertions on a helper that asked whether the connection was
// Postgres. Tests\TestCase::setUp() repoints the 'pgsql' connection at in-memory
// SQLite unconditionally — deliberately, so BaseModel-forced models never dial the
// real Supabase host — so that helper returned false in every lane, and all 22
// assertions skipped silently in CI and locally. A file whose own docblock called
// itself "the safety net for un-applied migrations" was reporting green without
// ever asking the database a question.
//
// It now runs in the applied-schema lane (phpunit.schema.xml / `composer
// test:schema`, see Tests\SchemaTestCase), against a container that the real
// supabase/migrations/ set has been applied to by scripts/db/apply-migrations.sh.
// The per-test skip guards are gone: the base case skips the whole lane when no
// migrated Postgres is present, so a missing constraint now fails instead of
// vanishing.

use Illuminate\Support\Facades\DB;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class)->in(__DIR__);

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

/**
 * Assert that a named ON DELETE CASCADE foreign key exists on the given table
 * and has been validated (mirrors assertCheckConstraintExists() above, plus
 * confdeltype — see ArchitectureSystemConstraintsTest for the same pattern on
 * the design_kits -> sites FK).
 *
 * @param  string  $schema  e.g. 'analytics'
 * @param  string  $table  e.g. 'item_views'
 * @param  string  $constraint  e.g. 'item_views_site_fk'
 */
function assertCascadeFkConstraintExists(string $schema, string $table, string $constraint): void
{
    $row = DB::selectOne(
        "SELECT convalidated, confdeltype FROM pg_constraint c
          JOIN pg_class t ON c.conrelid = t.oid
          JOIN pg_namespace n ON t.relnamespace = n.oid
         WHERE n.nspname = ?
           AND t.relname = ?
           AND c.conname = ?
           AND c.contype = 'f'",
        [$schema, $table, $constraint]
    );

    expect($row)->not->toBeNull(
        "Expected FK constraint [{$schema}.{$table}.{$constraint}] to exist but it was not found."
    );
    expect((bool) $row->convalidated)->toBeTrue(
        "FK [{$constraint}] exists but is NOT VALID — run VALIDATE CONSTRAINT."
    );
    // confdeltype 'c' = CASCADE; 'a' = NO ACTION; 'r' = RESTRICT; 'n' = SET NULL; 'd' = SET DEFAULT
    expect($row->confdeltype)->toBe('c',
        "FK [{$constraint}] exists but is not ON DELETE CASCADE (got confdeltype={$row->confdeltype})."
    );
}

// ─── site.blocks ────────────────────────────────────────────────────────────

it('blocks_group_type_check constraint exists and is validated', function () {
    assertCheckConstraintExists('site', 'blocks', 'blocks_group_type_check');
});

// ─── site.site_media ────────────────────────────────────────────────────────

it('site_media_pool_check constraint exists and is validated', function () {
    assertCheckConstraintExists('site', 'site_media', 'site_media_pool_check');
});

// ─── notifications.email_subscriptions ──────────────────────────────────────

it('email_subscriptions_status_check constraint exists and is validated', function () {
    assertCheckConstraintExists('notifications', 'email_subscriptions', 'email_subscriptions_status_check');
});

// ─── core.partna_staff ──────────────────────────────────────────────────────

it('partna_staff_role_check constraint exists and is validated', function () {
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
    assertCheckConstraintExists('core', 'feature_flag_overrides', 'feature_flag_overrides_scope_set');
});

it('legacy feature_flag_overrides_scope_xor constraint has been dropped', function () {

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
    assertCheckConstraintExists('analytics', 'site_sessions', 'site_sessions_duration_check');
});

// ─── SCHEMA-1..5 / SCHEMA-102 / DINT-101 batch (2026-07-20) ──────────────────
// Postgres-only introspection (this layer alone is NOT sufficient — see
// ConstraintVocabularyLockstepTest for the SQLite-runnable non-vacuous check
// that also asserts the CHECK vocabularies match the app-side source of truth).

// ─── site.sites (SCHEMA-5 + SCHEMA-102) ──────────────────────────────────────

it('sites_shop_link_mode_check constraint exists and is validated', function () {
    assertCheckConstraintExists('site', 'sites', 'sites_shop_link_mode_check');
});

// ─── site.design_kits (DINT-101) ─────────────────────────────────────────────

it('design_kits_typography_tracking_check constraint exists and is validated', function () {
    assertCheckConstraintExists('site', 'design_kits', 'design_kits_typography_tracking_check');
});

it('design_kits_theme_contrast_check constraint exists and is validated', function () {
    assertCheckConstraintExists('site', 'design_kits', 'design_kits_theme_contrast_check');
});

// ─── site.shop_brands (SCHEMA-4) ──────────────────────────────────────────────

it('shop_brands_selection_mode_check constraint exists and is validated', function () {
    assertCheckConstraintExists('site', 'shop_brands', 'shop_brands_selection_mode_check');
});

it('shop_brands_link_mode_check constraint exists and is validated', function () {
    assertCheckConstraintExists('site', 'shop_brands', 'shop_brands_link_mode_check');
});

it('shop_brands_connect_status_check constraint exists and is validated', function () {
    assertCheckConstraintExists('site', 'shop_brands', 'shop_brands_connect_status_check');
});

// ─── analytics.content_popularity_scores (SCHEMA-2) ──────────────────────────

it('content_popularity_scores_content_type_check constraint exists and is validated', function () {
    assertCheckConstraintExists('analytics', 'content_popularity_scores', 'content_popularity_scores_content_type_check');
});

// ─── analytics.item_views (SCHEMA-1) ─────────────────────────────────────────

it('item_views_item_type_check constraint exists and is validated', function () {
    assertCheckConstraintExists('analytics', 'item_views', 'item_views_item_type_check');
});

// ─── analytics.item_views / content_popularity_scores site_id FKs (SCHEMA-3) ─

it('item_views_site_fk exists, is validated, and cascades on delete', function () {
    assertCascadeFkConstraintExists('analytics', 'item_views', 'item_views_site_fk');
});

it('content_popularity_scores_site_fk exists, is validated, and cascades on delete', function () {
    assertCascadeFkConstraintExists('analytics', 'content_popularity_scores', 'content_popularity_scores_site_fk');
});

// ─── analytics.action_events (2026-07-23 actions rebuild) ───────────────────
// Both constraints were added INLINE at table creation (not the NOT VALID ->
// VALIDATE split above) — the table was empty in that same migration, see
// 20260723090000_create_action_events.sql's header comment.

it('action_events_event_check constraint exists and is validated', function () {
    assertCheckConstraintExists('analytics', 'action_events', 'action_events_event_check');
});

it('action_events_site_fk exists, is validated, and cascades on delete', function () {
    assertCascadeFkConstraintExists('analytics', 'action_events', 'action_events_site_fk');
});

// ─── site.field_bindings (#TEST-11) ──────────────────────────────────────────
// WARNING — this entry, like the 21 above it, currently runs in NO lane, and
// that is worse than "skips on SQLite". Independent review (2026-07-29) proved
// it by trying this file's own documented escape hatch: phpunit.pg.xml's suite
// only collects tests/Postgres/, so the postgres-tests job never sees this
// file; and running it directly with DB_CONNECTION=pgsql against a live
// Postgres STILL skips, because tests/TestCase.php rewires database.default to
// in-memory SQLite in setUp() and tests/Pest.php binds everything under
// tests/Feature to that TestCase regardless of DB_CONNECTION.
//
// So do NOT read a green suite as evidence this constraint is applied. The
// claim that it is live on dev (glncumufgaqcmqhzwrxm) was verified out-of-band
// by a manual pg_constraint query on 2026-07-29, NOT by this test passing.
// Real continuous enforcement for #TEST-11 lives in
// tests/Postgres/FieldBindingsManualPriorityTest.php, which does run.
//
// This is a pre-existing, inherited defect affecting all 22 entries — the
// repo's documented "pgsql-gated tests running in NEITHER lane" hazard — and
// relocating the file is its own unit, not a rider on #TEST-11.

it('field_bindings_manual_priority constraint exists and is validated', function () {
    assertCheckConstraintExists('site', 'field_bindings', 'field_bindings_manual_priority');
});
