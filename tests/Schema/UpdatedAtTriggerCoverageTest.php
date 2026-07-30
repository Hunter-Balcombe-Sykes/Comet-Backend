<?php

// Verifies that specific tables known to need an updated_at trigger have one
// registered. Non-Eloquent write paths (raw DB::update, bulk query-builder ops,
// trigger-fired side effects, Supabase dashboard edits) bypass PHP timestamps —
// the DB trigger is the only reliable guarantor for those tables.
//
// THIS FILE RAN NOWHERE FOR ITS ENTIRE LIFE. It lived in tests/Feature and gated
// every assertion on a helper that asked whether the connection was Postgres.
// Tests\TestCase::setUp() repoints the 'pgsql' connection at in-memory SQLite
// unconditionally, so that helper returned false in every lane and every
// assertion skipped silently in CI and locally — including six that asserted
// tables which do not exist. Nobody ever found out because the guard never ran:
// an unexercised safety net rots into fiction. Those six are gone (see below);
// the seven that assert real tables survive.
//
// It now runs in the applied-schema lane (phpunit.schema.xml / `composer
// test:schema`, see Tests\SchemaTestCase), against a container that the real
// supabase/migrations/ set has been applied to by scripts/db/apply-migrations.sh.
//
// ⚠️ Do NOT invert this into "every table with updated_at must have a trigger".
// The docblock used to claim that was the intent, but it isn't what the file
// checks, and doing so for real would need grandfathering: 77 tables carry an
// updated_at column and only 24 have the trigger. That gap is a separate
// product decision, not something to fix here.

use Illuminate\Support\Facades\DB;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class)->in(__FILE__);

/**
 * Assert that a BEFORE UPDATE trigger bound to public.set_updated_at() (or any
 * schema-local variant) exists on the given schema.table.
 *
 * `action_timing`, NOT `trigger_type`. information_schema.triggers has no
 * `trigger_type` column in PostgreSQL — the original query raised
 * SQLSTATE[42703] on every call, so all 7 surviving assertions in this file
 * were non-functional. Nobody knew, because the file was gated behind
 * `getDriverName() === 'pgsql'` and that gate is unsatisfiable in the Feature
 * suite (Tests\TestCase::setUp() repoints the pgsql alias at in-memory SQLite).
 * The very first real execution — CI's schema-tests lane, 2026-07-30 — surfaced
 * it. Verified against postgres:16: the column set is `action_timing`
 * ('BEFORE'|'AFTER'|'INSTEAD OF') + `event_manipulation`.
 */
function assertUpdatedAtTriggerExists(string $schema, string $table): void
{
    $row = DB::selectOne(
        "SELECT trigger_name
           FROM information_schema.triggers
          WHERE event_object_schema = ?
            AND event_object_table  = ?
            AND action_timing       = 'BEFORE'
            AND event_manipulation  = 'UPDATE'
            AND action_statement    ILIKE '%set_updated_at%'
          LIMIT 1",
        [$schema, $table]
    );

    expect($row)->not->toBeNull(
        "Expected a BEFORE UPDATE set_updated_at trigger on [{$schema}.{$table}] but none was found."
    );
}

// ─── site schema ────────────────────────────────────────────────────────────

it('site.services has a set_updated_at trigger', function () {
    assertUpdatedAtTriggerExists('site', 'services');
});

it('site.enquiries has a set_updated_at trigger', function () {
    assertUpdatedAtTriggerExists('site', 'enquiries');
});

// The six removed here (brand.brand_profiles, brand.brand_partner_links,
// brand.brand_affiliate_invites, brand.brand_store_settings,
// commerce.affiliate_product_selections, core.gdpr_requests) asserted tables
// that do not exist in the pilot baseline: there is no `brand` or `commerce`
// schema (CLAUDE.md — "No brand, commerce, billing"), and core.gdpr_requests
// was retired to supabase/migrations-archive/. This file skipped silently for
// its entire life, so nobody noticed these six had rotted into fiction.

// ─── notifications schema ───────────────────────────────────────────────────

it('notifications.notifications has a set_updated_at trigger', function () {
    assertUpdatedAtTriggerExists('notifications', 'notifications');
});

it('notifications.notification_receipts has a set_updated_at trigger', function () {
    assertUpdatedAtTriggerExists('notifications', 'notification_receipts');
});

it('notifications.notification_email_preferences has a set_updated_at trigger', function () {
    assertUpdatedAtTriggerExists('notifications', 'notification_email_preferences');
});

it('notifications.notification_email_policies has a set_updated_at trigger', function () {
    assertUpdatedAtTriggerExists('notifications', 'notification_email_policies');
});

it('notifications.email_subscriptions has a set_updated_at trigger', function () {
    assertUpdatedAtTriggerExists('notifications', 'email_subscriptions');
});
