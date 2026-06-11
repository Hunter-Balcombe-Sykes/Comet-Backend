<?php

// Verifies that every table with an updated_at timestamptz column has a
// set_updated_at trigger registered. Non-Eloquent write paths (raw DB::update,
// bulk query-builder ops, trigger-fired side effects, Supabase dashboard edits)
// bypass PHP timestamps — the DB trigger is the only reliable guarantor.
//
// To run against Supabase dev:
//   DB_CONNECTION=pgsql DB_HOST=... php artisan test --filter UpdatedAtTriggerCoverageTest

use Illuminate\Support\Facades\DB;

// function_exists guards prevent fatals if Pest loads this file in parallel processes
if (! function_exists('updatedAtSuiteIsPostgres')) {
    function updatedAtSuiteIsPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}

if (! function_exists('assertUpdatedAtTriggerExists')) {
    /**
     * Assert that a BEFORE UPDATE trigger bound to public.set_updated_at() (or any
     * schema-local variant) exists on the given schema.table.
     */
    function assertUpdatedAtTriggerExists(string $schema, string $table): void
    {
        $row = DB::selectOne(
            "SELECT trigger_name
           FROM information_schema.triggers
          WHERE event_object_schema = ?
            AND event_object_table  = ?
            AND trigger_type        = 'BEFORE'
            AND event_manipulation  = 'UPDATE'
            AND action_statement    ILIKE '%set_updated_at%'
          LIMIT 1",
            [$schema, $table]
        );

        expect($row)->not->toBeNull(
            "Expected a BEFORE UPDATE set_updated_at trigger on [{$schema}.{$table}] but none was found."
        );
    }
}

// ─── site schema ────────────────────────────────────────────────────────────

it('site.services has a set_updated_at trigger', function () {
    if (! updatedAtSuiteIsPostgres()) {
        $this->markTestSkipped('Trigger queries require PostgreSQL.');
    }
    assertUpdatedAtTriggerExists('site', 'services');
});

it('site.enquiries has a set_updated_at trigger', function () {
    if (! updatedAtSuiteIsPostgres()) {
        $this->markTestSkipped('Trigger queries require PostgreSQL.');
    }
    assertUpdatedAtTriggerExists('site', 'enquiries');
});

// ─── brand schema ───────────────────────────────────────────────────────────

it('brand.brand_profiles has a set_updated_at trigger', function () {
    if (! updatedAtSuiteIsPostgres()) {
        $this->markTestSkipped('Trigger queries require PostgreSQL.');
    }
    assertUpdatedAtTriggerExists('brand', 'brand_profiles');
});

it('brand.brand_partner_links has a set_updated_at trigger', function () {
    if (! updatedAtSuiteIsPostgres()) {
        $this->markTestSkipped('Trigger queries require PostgreSQL.');
    }
    assertUpdatedAtTriggerExists('brand', 'brand_partner_links');
});

it('brand.brand_affiliate_invites has a set_updated_at trigger', function () {
    if (! updatedAtSuiteIsPostgres()) {
        $this->markTestSkipped('Trigger queries require PostgreSQL.');
    }
    assertUpdatedAtTriggerExists('brand', 'brand_affiliate_invites');
});

it('brand.brand_store_settings has a set_updated_at trigger', function () {
    if (! updatedAtSuiteIsPostgres()) {
        $this->markTestSkipped('Trigger queries require PostgreSQL.');
    }
    assertUpdatedAtTriggerExists('brand', 'brand_store_settings');
});

// ─── commerce schema ────────────────────────────────────────────────────────

it('commerce.affiliate_product_selections has a set_updated_at trigger', function () {
    if (! updatedAtSuiteIsPostgres()) {
        $this->markTestSkipped('Trigger queries require PostgreSQL.');
    }
    assertUpdatedAtTriggerExists('commerce', 'affiliate_product_selections');
});

// ─── notifications schema ───────────────────────────────────────────────────

it('notifications.notifications has a set_updated_at trigger', function () {
    if (! updatedAtSuiteIsPostgres()) {
        $this->markTestSkipped('Trigger queries require PostgreSQL.');
    }
    assertUpdatedAtTriggerExists('notifications', 'notifications');
});

it('notifications.notification_receipts has a set_updated_at trigger', function () {
    if (! updatedAtSuiteIsPostgres()) {
        $this->markTestSkipped('Trigger queries require PostgreSQL.');
    }
    assertUpdatedAtTriggerExists('notifications', 'notification_receipts');
});

it('notifications.notification_email_preferences has a set_updated_at trigger', function () {
    if (! updatedAtSuiteIsPostgres()) {
        $this->markTestSkipped('Trigger queries require PostgreSQL.');
    }
    assertUpdatedAtTriggerExists('notifications', 'notification_email_preferences');
});

it('notifications.notification_email_policies has a set_updated_at trigger', function () {
    if (! updatedAtSuiteIsPostgres()) {
        $this->markTestSkipped('Trigger queries require PostgreSQL.');
    }
    assertUpdatedAtTriggerExists('notifications', 'notification_email_policies');
});

it('notifications.email_subscriptions has a set_updated_at trigger', function () {
    if (! updatedAtSuiteIsPostgres()) {
        $this->markTestSkipped('Trigger queries require PostgreSQL.');
    }
    assertUpdatedAtTriggerExists('notifications', 'email_subscriptions');
});

// ─── core schema ────────────────────────────────────────────────────────────

it('core.gdpr_requests has a set_updated_at trigger', function () {
    if (! updatedAtSuiteIsPostgres()) {
        $this->markTestSkipped('Trigger queries require PostgreSQL.');
    }
    assertUpdatedAtTriggerExists('core', 'gdpr_requests');
});
