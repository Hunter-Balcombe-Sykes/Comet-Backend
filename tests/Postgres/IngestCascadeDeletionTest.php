<?php

// #PRIV-1 P0 / DINT-1: proves the ingest.record_versions FK (migration
// 20260729120001) actually cascades an account deletion across MULTIPLE hash
// partitions, and that ingest.effects (migration 20260729120002/3) is
// ON DELETE SET NULL, not CASCADE — a money ledger, not disposable content.
//
// This runs the migrations' REAL SQL read off disk, not retyped — same
// discipline as ItemTombstoneBackfillTest's BACKFILL_SQL_PATH — because a
// hand-copied constraint drifts from the file the moment someone edits it.
//
// Self-provisioned schema like the rest of tests/Postgres/: ingest.record_versions
// is created here as a REAL PARTITION BY HASH (stream_id) table with all 8
// MODULUS 8 partitions — a single-table shortcut would silently void assertion A
// (the multi-partition cascade) and the whole point of testing this FK on
// Postgres instead of SQLite.

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

const RECORD_VERSIONS_FK_SQL_PATH = 'supabase/migrations/20260729120001_record_versions_stream_fk.sql';
const EFFECTS_FK_NOT_VALID_SQL_PATH = 'supabase/migrations/20260729120002_effects_source_fk_not_valid.sql';
const EFFECTS_FK_VALIDATE_SQL_PATH = 'supabase/migrations/20260729120003_effects_source_fk_validate.sql';

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS core');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS ingest');

    $pg->statement('DROP TABLE IF EXISTS ingest.effects CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.record_versions CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.streams CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.sources CASCADE');
    $pg->statement('DROP TABLE IF EXISTS core.users CASCADE');

    $pg->statement("CREATE TABLE core.users (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        status text NOT NULL DEFAULT 'active'
    )");

    $pg->statement('CREATE TABLE ingest.sources (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
        source_key text NOT NULL DEFAULT \'bandcamp\',
        surface_key text NOT NULL DEFAULT \'catalog\',
        identifier text NOT NULL DEFAULT \'x\'
    )');

    $pg->statement('CREATE TABLE ingest.streams (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        source_id uuid NOT NULL REFERENCES ingest.sources (id) ON DELETE CASCADE,
        stream_name text NOT NULL DEFAULT \'releases\'
    )');

    // Real partitioned parent — the FK migration must succeed against THIS
    // shape, not a single unpartitioned stand-in.
    $pg->statement('CREATE TABLE ingest.record_versions (
        id bigserial NOT NULL,
        stream_id uuid NOT NULL,
        key text NOT NULL,
        doc_hash text NOT NULL,
        doc jsonb NOT NULL DEFAULT \'{}\',
        first_seen_run uuid,
        first_seen_at timestamptz NOT NULL DEFAULT now(),
        is_current boolean NOT NULL DEFAULT true,
        PRIMARY KEY (id, stream_id)
    ) PARTITION BY HASH (stream_id)');

    for ($i = 0; $i < 8; $i++) {
        $pg->statement("CREATE TABLE ingest.record_versions_p{$i} PARTITION OF ingest.record_versions FOR VALUES WITH (MODULUS 8, REMAINDER {$i})");
    }

    $pg->statement('CREATE TABLE ingest.effects (
        digest text PRIMARY KEY,
        run_id uuid,
        source_id uuid,
        kind text NOT NULL DEFAULT \'http\',
        cost_tag text,
        cost_units integer NOT NULL DEFAULT 0,
        claimed_at timestamptz NOT NULL DEFAULT now(),
        settled_at timestamptz,
        status text NOT NULL DEFAULT \'ok\',
        body_ref text,
        meta jsonb NOT NULL DEFAULT \'{}\'
    )');

    // Apply the real migration SQL, read off disk — not retyped.
    applyMigrationSql(RECORD_VERSIONS_FK_SQL_PATH);
    applyMigrationSql(EFFECTS_FK_NOT_VALID_SQL_PATH);
    applyMigrationSql(EFFECTS_FK_VALIDATE_SQL_PATH);
});

afterAll(function () {
    $pg = DB::connection('pgsql');
    foreach (['ingest.effects', 'ingest.record_versions', 'ingest.streams', 'ingest.sources', 'core.users'] as $t) {
        $pg->statement("DROP TABLE IF EXISTS {$t} CASCADE");
    }
});

/**
 * Strip the transaction wrapper (BEGIN/COMMIT/SET LOCAL) and comments from a
 * migration file and execute the remaining ALTER statement(s) one at a time.
 * Each of the three files this test applies carries exactly one ALTER.
 */
function applyMigrationSql(string $relativePath): void
{
    $sql = (string) file_get_contents(base_path($relativePath));

    // Strip -- comments (to end of line).
    $sql = preg_replace('/--.*$/m', '', $sql) ?? $sql;

    $statements = array_filter(array_map('trim', explode(';', $sql)), function (string $s) {
        if ($s === '') {
            return false;
        }
        $upper = strtoupper($s);

        return ! str_starts_with($upper, 'BEGIN') && ! str_starts_with($upper, 'COMMIT') && ! str_starts_with($upper, 'SET LOCAL');
    });

    foreach ($statements as $statement) {
        DB::connection('pgsql')->statement($statement);
    }
}

function cascadeUser(): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert(['id' => $id]);

    return $id;
}

function cascadeSource(string $userId): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('ingest.sources')->insert(['id' => $id, 'user_id' => $userId]);

    return $id;
}

function cascadeStream(string $sourceId): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('ingest.streams')->insert(['id' => $id, 'source_id' => $sourceId]);

    return $id;
}

it('E: the migration applies against the real PARTITION BY HASH parent', function () {
    // If setUp above didn't throw, the ADD CONSTRAINT already succeeded on a
    // partitioned parent — this assertion exists to name that fact explicitly,
    // since it is exactly what would have caught the NOT VALID-on-partitioned
    // -table trap before a prod db push.
    $constraint = DB::connection('pgsql')->selectOne(
        "SELECT conname FROM pg_constraint WHERE conname = 'record_versions_stream_id_fk'"
    );
    expect($constraint)->not->toBeNull();
});

it('A: cascades across MULTIPLE partitions — the SQLite-impossible case', function () {
    $userId = cascadeUser();
    $sourceId = cascadeSource($userId);

    // Enough distinct stream_ids to land in >=2 of the 8 hash partitions.
    $streamIds = [];
    for ($i = 0; $i < 12; $i++) {
        $streamIds[] = cascadeStream($sourceId);
    }

    foreach ($streamIds as $i => $streamId) {
        DB::connection('pgsql')->table('ingest.record_versions')->insert([
            'stream_id' => $streamId,
            'key' => "key-{$i}",
            'doc_hash' => hash('sha256', "doc-{$i}"),
            'doc' => json_encode(['n' => $i]),
        ]);
    }

    $partitionsHit = DB::connection('pgsql')->select(
        'SELECT DISTINCT tableoid::regclass::text AS p FROM ingest.record_versions'
    );
    expect(count($partitionsHit))->toBeGreaterThanOrEqual(2);

    DB::connection('pgsql')->table('ingest.sources')->where('id', $sourceId)->delete();

    $remaining = DB::connection('pgsql')->table('ingest.record_versions')->count();
    expect($remaining)->toBe(0);
});

it('B: end-to-end account deletion empties sources, streams, and record_versions', function () {
    $userId = cascadeUser();
    $sourceId = cascadeSource($userId);
    $streamId = cascadeStream($sourceId);

    DB::connection('pgsql')->table('ingest.record_versions')->insert([
        'stream_id' => $streamId,
        'key' => 'key-1',
        'doc_hash' => hash('sha256', 'doc-1'),
        'doc' => json_encode(['n' => 1]),
    ]);

    DB::connection('pgsql')->table('core.users')->where('id', $userId)->delete();

    expect(DB::connection('pgsql')->table('ingest.sources')->where('user_id', $userId)->count())->toBe(0);
    expect(DB::connection('pgsql')->table('ingest.streams')->where('source_id', $sourceId)->count())->toBe(0);
    expect(DB::connection('pgsql')->table('ingest.record_versions')->where('stream_id', $streamId)->count())->toBe(0);
});

it('C: both FKs are VALIDATED, not merely declared', function () {
    $userId = cascadeUser();
    $sourceId = cascadeSource($userId);
    $realStreamId = cascadeStream($sourceId);

    // An unknown stream_id must be REJECTED — proves the constraint is
    // enforced, not just present.
    expect(function () {
        DB::connection('pgsql')->table('ingest.record_versions')->insert([
            'stream_id' => (string) Str::uuid(),
            'key' => 'orphan',
            'doc_hash' => hash('sha256', 'orphan'),
            'doc' => json_encode(['n' => 'orphan']),
        ]);
    })->toThrow(QueryException::class);

    // A known-good stream_id must still succeed (the constraint isn't broken).
    DB::connection('pgsql')->table('ingest.record_versions')->insert([
        'stream_id' => $realStreamId,
        'key' => 'ok',
        'doc_hash' => hash('sha256', 'ok'),
        'doc' => json_encode(['n' => 'ok']),
    ]);

    $recordVersionsValidated = DB::connection('pgsql')->selectOne(
        "SELECT convalidated FROM pg_constraint WHERE conname = 'record_versions_stream_id_fk'"
    );
    expect((bool) $recordVersionsValidated->convalidated)->toBeTrue();

    $effectsValidated = DB::connection('pgsql')->selectOne(
        "SELECT convalidated FROM pg_constraint WHERE conname = 'effects_source_id_fk'"
    );
    expect((bool) $effectsValidated->convalidated)->toBeTrue();
});

it('D: ingest.effects is SET NULL on source delete, not CASCADE — the money ledger survives', function () {
    $userId = cascadeUser();
    $sourceId = cascadeSource($userId);

    $digest = hash('sha256', 'effect-1');
    DB::connection('pgsql')->table('ingest.effects')->insert([
        'digest' => $digest,
        'source_id' => $sourceId,
        'kind' => 'http',
        'cost_tag' => 'places_fetch',
        'cost_units' => 3,
        'status' => 'ok',
    ]);

    DB::connection('pgsql')->table('ingest.sources')->where('id', $sourceId)->delete();

    $row = DB::connection('pgsql')->table('ingest.effects')->where('digest', $digest)->first();

    expect($row)->not->toBeNull();
    expect($row->source_id)->toBeNull();
    expect($row->cost_units)->toBe(3);
    expect($row->cost_tag)->toBe('places_fetch');
});
