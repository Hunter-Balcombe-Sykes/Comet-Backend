<?php

use Illuminate\Support\Facades\DB;
use Tests\SchemaTestCase;

uses(SchemaTestCase::class)->in(__FILE__);

it('has moderation schema with the seven core tables', function () {
    $tables = collect(DB::select(<<<'SQL'
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = 'moderation'
        ORDER BY table_name
    SQL))->pluck('table_name')->all();

    expect($tables)->toContain(
        'action_log',
        'case_signals',
        'cases',
        'decisions',
        'evidence',
    );
})->group('postgres');

it('has audit schema with moderation_events table', function () {
    $exists = DB::selectOne(<<<'SQL'
        SELECT EXISTS (
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = 'audit' AND table_name = 'moderation_events'
        ) AS present
    SQL);

    expect($exists->present)->toBeTrue();
})->group('postgres');

it('has the hot-path partial indexes for moderation queries', function () {
    $indexes = collect(DB::select(<<<'SQL'
        SELECT indexname FROM pg_indexes
        WHERE schemaname = 'moderation'
        ORDER BY indexname
    SQL))->pluck('indexname')->all();

    expect($indexes)->toContain(
        'cases_open_queue_idx',
        'cases_target_open_idx',
        'cases_sla_due_idx',
        'cases_owner_status_idx',
        'case_signals_dedup_uniq',
        'case_signals_case_idx',
        'evidence_case_idx',
        'decisions_case_idx',
        'action_log_decision_idx',
        'action_log_pending_idx',
    );
})->group('postgres');
