<?php

// Regression sentinel for DINT-16/DINT-9 (Unit 2, audit-fix/pilot-gate-2026-07-29):
// Lander::land() must report changed=1 when a document reverts to a
// previously-seen hash (A -> B -> A), not changed=0.
//
// Pre-fix, `insertOrIgnore` for the A-again landing conflicts on
// idx_record_versions_content (unique on stream_id, key, doc_hash) and
// returns 0 rows inserted; the old code used "did the insert add a row?" as
// its changed signal, so `changed` stayed 0. RunExecutor.php:168 gates
// projectStream() on `changed > 0 || tombstoned > 0`, so the revert's
// projection was skipped and the public page kept serving B forever. It
// also left is_current set on the WRONG row (id 2, hash B) while
// record_state.current_version_id correctly pointed at hash A's row —
// two sources of truth silently disagreeing.
//
// This must run against REAL Postgres: SQLite cannot prove (a) that the new
// UNIQUE partial index idx_record_versions_one_current is legal on a
// partitioned table (leading column must be the partition key), or (b) that
// the demote-then-promote sequence survives that constraint without a
// transient 23505. The SQLite mirror (tests/Pest.php ~2358-2373) has no
// partitioning at all, and its idx_record_versions_current creation is
// non-unique and wrapped in a swallowed try/catch — both hazards are
// invisible there. See tests/Feature/Ingest/LanderTest.php for the sibling
// case that DOES reproduce (and regress) the `changed` half of the defect
// under SQLite.
//
// VERIFIED 2026-07-29 (Unit 9, audit-fix/pg-u9): executed against real
// Postgres via `composer test:pg` BEFORE that unit's Lander.php edits, as its
// own prerequisite step — passed then, and still passes after the SCALE-4/
// SCALE-5 batching rewrite (see tests/Postgres/LanderBatchLandingTest.php for
// the batching-specific Postgres cases this unit added alongside it).

use App\Ingest\Landing\Lander;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Record;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS ingest');
    $pg->statement('DROP TABLE IF EXISTS ingest.record_state CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.record_versions CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.streams CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.sources CASCADE');

    // Deliberately NOT a real core.users/site.platform_connections FK here —
    // this test only exercises ingest.* writes, so user_id/connection_id are
    // bare uuid columns with no reference. (Building local DDL for those
    // canonical tables would trip NoLocalCanonicalTableDdlTest, which is
    // right to ask for it only when a test actually needs the real shape —
    // see tests/Postgres/ItemSlugAllocatorRegressionTest.php for a test that
    // does.)
    $pg->statement('CREATE TABLE ingest.sources (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL,
        connection_id uuid,
        source_key text NOT NULL,
        surface_key text NOT NULL,
        identifier text NOT NULL,
        cost_units integer NOT NULL DEFAULT 1,
        min_interval_secs integer NOT NULL DEFAULT 3600,
        max_interval_secs integer NOT NULL DEFAULT 604800,
        change_rate double precision NOT NULL DEFAULT 0.5,
        next_attempt_at timestamptz NOT NULL DEFAULT now(),
        last_run_at timestamptz,
        visibility double precision NOT NULL DEFAULT 1.0,
        in_flight_since timestamptz,
        in_flight_run_id uuid,
        health text NOT NULL DEFAULT \'ok\',
        consecutive_failures integer NOT NULL DEFAULT 0,
        auto_sync boolean NOT NULL DEFAULT true,
        scope text NOT NULL DEFAULT \'all\',
        scope_n integer,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )');

    $pg->statement('CREATE TABLE ingest.streams (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        source_id uuid NOT NULL REFERENCES ingest.sources (id) ON DELETE CASCADE,
        stream_name text NOT NULL,
        cursor jsonb,
        coverage jsonb,
        observed_schema jsonb NOT NULL DEFAULT \'{}\'::jsonb,
        schema_hash text,
        health text NOT NULL DEFAULT \'ok\',
        consecutive_failures integer NOT NULL DEFAULT 0,
        suppressed_until timestamptz,
        guard_tripped_at timestamptz,
        run_seq bigint NOT NULL DEFAULT 0,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )');

    // Same partitioning as prod: PARTITION BY HASH (stream_id), 8 partitions,
    // the content-addressed UNIQUE index insertOrIgnore conflicts on, the old
    // non-unique current-lookup index, AND the new one-current UNIQUE index
    // this unit adds (20260729130001).
    $pg->statement('CREATE TABLE ingest.record_versions (
        id bigserial NOT NULL,
        stream_id uuid NOT NULL,
        key text NOT NULL,
        doc_hash text NOT NULL,
        doc jsonb NOT NULL,
        first_seen_run uuid,
        first_seen_at timestamptz NOT NULL DEFAULT now(),
        is_current boolean NOT NULL DEFAULT true,
        PRIMARY KEY (id, stream_id)
    ) PARTITION BY HASH (stream_id)');

    for ($i = 0; $i < 8; $i++) {
        $pg->statement("CREATE TABLE ingest.record_versions_p{$i} PARTITION OF ingest.record_versions FOR VALUES WITH (MODULUS 8, REMAINDER {$i})");
    }

    $pg->statement('CREATE UNIQUE INDEX idx_record_versions_content ON ingest.record_versions (stream_id, key, doc_hash)');
    $pg->statement('CREATE INDEX idx_record_versions_current ON ingest.record_versions (stream_id, key) WHERE is_current');
    // The new constraint (20260729130001) — partition key (stream_id) leads,
    // which is what makes a UNIQUE index on a partitioned table legal.
    $pg->statement('CREATE UNIQUE INDEX idx_record_versions_one_current ON ingest.record_versions (stream_id, key) WHERE is_current');

    $pg->statement('CREATE TABLE ingest.record_state (
        stream_id uuid NOT NULL REFERENCES ingest.streams (id) ON DELETE CASCADE,
        key text NOT NULL,
        current_version_id bigint,
        last_seen_run uuid,
        last_seen_at timestamptz NOT NULL DEFAULT now(),
        absent_since timestamptz,
        absent_runs integer NOT NULL DEFAULT 0,
        tombstoned_at timestamptz,
        PRIMARY KEY (stream_id, key)
    )');

    $this->userId = (string) Str::uuid();

    $this->sourceId = (string) Str::uuid();
    $pg->table('ingest.sources')->insert([
        'id' => $this->sourceId,
        'user_id' => $this->userId,
        'source_key' => 'test-source',
        'surface_key' => 'releases',
        'identifier' => 'test',
    ]);

    $this->streamId = (string) Str::uuid();
    $pg->table('ingest.streams')->insert([
        'id' => $this->streamId,
        'source_id' => $this->sourceId,
        'stream_name' => 'releases',
    ]);
});

afterAll(function () {
    $pg = DB::connection('pgsql');
    foreach (['ingest.record_state', 'ingest.record_versions', 'ingest.streams', 'ingest.sources'] as $t) {
        $pg->statement("DROP TABLE IF EXISTS {$t} CASCADE");
    }
});

it('reports changed=1 on a revert to a previously-seen hash, converges is_current onto the reverted row, and does not raise', function () {
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror);
    $lander = app(Lander::class);
    $streamId = $this->streamId;

    // Run ids must be real UUIDs: record_versions.first_seen_run is uuid, and
    // pdo_pgsql rejects a bare label with 22P02 where SQLite silently accepts it.
    $runA = (string) Str::uuid();
    $runB = (string) Str::uuid();
    $runC = (string) Str::uuid();

    $first = $lander->land($streamId, $runA, $spec, [new Record('releases', 'k1', ['title' => 'A'])], null);
    expect($first['changed'])->toBe(1);

    $second = $lander->land($streamId, $runB, $spec, [new Record('releases', 'k1', ['title' => 'B'])], null);
    expect($second['changed'])->toBe(1);

    // The bug: pre-fix, this landing does not raise but silently reports
    // changed=0. Post-fix it converges and demote-then-promote survives the
    // real partial unique index without a 23505.
    $third = $lander->land($streamId, $runC, $spec, [new Record('releases', 'k1', ['title' => 'A'])], null);
    expect($third['changed'])->toBe(1);

    // Assert in SQL, not by casting in PHP (pdo_pgsql returns 't'/'f' as
    // strings — see ItemSlugAllocatorRegressionTest.php:110).
    $currentCount = DB::connection('pgsql')->table('ingest.record_versions')
        ->where('stream_id', $streamId)->where('key', 'k1')
        ->where('is_current', true)
        ->count();
    expect($currentCount)->toBe(1);

    $currentRow = DB::connection('pgsql')->table('ingest.record_versions')
        ->where('stream_id', $streamId)->where('key', 'k1')
        ->where('is_current', true)
        ->first();
    expect(json_decode((string) $currentRow->doc, true)['title'])->toBe('A');

    $state = DB::connection('pgsql')->table('ingest.record_state')
        ->where('stream_id', $streamId)->where('key', 'k1')->first();
    expect((int) $state->current_version_id)->toBe((int) $currentRow->id);
});
