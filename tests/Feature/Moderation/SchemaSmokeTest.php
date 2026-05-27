<?php

use Illuminate\Support\Facades\DB;

it('has moderation schema with the seven core tables', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('information_schema queries require PostgreSQL.');
    }

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
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('information_schema queries require PostgreSQL.');
    }

    $exists = DB::selectOne(<<<'SQL'
        SELECT EXISTS (
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = 'audit' AND table_name = 'moderation_events'
        ) AS present
    SQL);

    expect($exists->present)->toBeTrue();
})->group('postgres');
