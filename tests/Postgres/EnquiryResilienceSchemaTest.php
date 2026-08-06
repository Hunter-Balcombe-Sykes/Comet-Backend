<?php

use Illuminate\Support\Facades\DB;

/**
 * Runs in the APPLIED-SCHEMA lane (composer test:schema), not composer test.
 * A green Feature suite says nothing about either of these — the SQLite
 * stand-in has no indexes at all.
 */
it('has the partial pending-notifications index on site.enquiries', function () {
    $row = DB::connection('pgsql')->selectOne(
        "SELECT indexdef FROM pg_indexes
         WHERE schemaname = 'site'
           AND tablename = 'enquiries'
           AND indexname = 'enquiries_notifications_pending_idx'"
    );

    expect($row)->not->toBeNull();

    // The WHERE clause is the whole reason this index is cheap. An index that
    // lost its predicate would silently cover every enquiry ever written.
    expect($row->indexdef)->toContain('WHERE (notifications_pending_since IS NOT NULL)');
});

it('has the subdomain/time index on analytics.lead_submissions', function () {
    $exists = DB::connection('pgsql')->selectOne(
        "SELECT 1 AS present FROM pg_indexes
         WHERE schemaname = 'analytics'
           AND tablename = 'lead_submissions'
           AND indexname = 'lead_submissions_subdomain_time_idx'"
    );

    expect($exists)->not->toBeNull();
});

it('has the notifications_pending_since column', function () {
    $column = DB::connection('pgsql')->selectOne(
        "SELECT data_type, is_nullable FROM information_schema.columns
         WHERE table_schema = 'site'
           AND table_name = 'enquiries'
           AND column_name = 'notifications_pending_since'"
    );

    expect($column)->not->toBeNull()
        ->and($column->data_type)->toBe('timestamp with time zone')
        ->and($column->is_nullable)->toBe('YES');
});
