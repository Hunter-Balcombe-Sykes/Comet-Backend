<?php

use Illuminate\Support\Facades\DB;

it('has moderation.csam_quarantine table with required columns', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('information_schema queries require PostgreSQL.');
    }

    $cols = collect(DB::select(<<<'SQL'
        SELECT column_name FROM information_schema.columns
        WHERE table_schema = 'moderation' AND table_name = 'csam_quarantine'
    SQL))->pluck('column_name')->all();

    expect($cols)->toContain(
        'id', 'case_id', 'site_media_id', 'content_hash',
        'cloudflare_match_payload', 'r2_quarantine_key',
        'r2_binary_deleted', 'preservation_expires_at',
    );
})->group('postgres');

it('csam_quarantine.site_media_id is UNIQUE (one quarantine row per media)', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('pg_indexes view requires PostgreSQL.');
    }

    $indexes = collect(DB::select(<<<'SQL'
        SELECT indexname FROM pg_indexes WHERE schemaname = 'moderation' AND tablename = 'csam_quarantine'
    SQL))->pluck('indexname')->all();
    expect($indexes)->toContain('csam_quarantine_site_media_uniq');
})->group('postgres');

it('has moderation.ncmec_submissions table', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('information_schema queries require PostgreSQL.');
    }

    $cols = collect(DB::select(<<<'SQL'
        SELECT column_name FROM information_schema.columns
        WHERE table_schema = 'moderation' AND table_name = 'ncmec_submissions'
    SQL))->pluck('column_name')->all();

    expect($cols)->toContain(
        'id', 'csam_quarantine_id', 'payload', 'status', 'attempts',
        'ncmec_tip_id', 'submitted_at',
    );
})->group('postgres');

it('has partial index ncmec_submissions_pending_idx', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('pg_indexes view requires PostgreSQL.');
    }

    $indexes = collect(DB::select(<<<'SQL'
        SELECT indexname FROM pg_indexes WHERE schemaname = 'moderation' AND tablename = 'ncmec_submissions'
    SQL))->pluck('indexname')->all();
    expect($indexes)->toContain('ncmec_submissions_pending_idx');
})->group('postgres');
