<?php

// #WHK-2 residual: Lander::foldAbsence()'s write phase (the guard-trip branch
// and the per-chunk absent_runs increment / tombstone loop) used to be
// several independent statements with no transaction around them. A crash or
// statement timeout mid-fold could leave chunk 1's keys a run closer to
// tombstoning than chunk 2's, or leave guard_tripped_at set with no anomaly
// row explaining it. The fix wraps the whole write phase in one
// DB::transaction().
//
// Not a concurrency claim, so no fork — but Postgres-only, because the
// injection is a PL/pgSQL trigger that SQLite cannot express, and it proves
// something the SQLite mirror structurally cannot: that a failure partway
// through the write phase rolls EVERYTHING in that phase back, not just the
// statement that failed.

use App\Ingest\Landing\Coverage;
use App\Ingest\Landing\Lander;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Covered;
use App\Ingest\Message\Record;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('DROP TRIGGER IF EXISTS trg_poison_record_state ON ingest.record_state');
    $pg->statement('DROP TRIGGER IF EXISTS trg_poison_anomalies ON ingest.anomalies');
    $pg->statement('DROP FUNCTION IF EXISTS ingest_test_poison_record_state()');
    $pg->statement('DROP FUNCTION IF EXISTS ingest_test_poison_anomalies()');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS ingest');
    $pg->statement('DROP TABLE IF EXISTS ingest.anomalies CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.record_state CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.record_versions CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.streams CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.sources CASCADE');

    // Same DDL as LanderBatchLandingTest.php's beforeEach.
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

    // Same shape as the real ingest.anomalies table (20260727130000).
    $pg->statement('CREATE TABLE ingest.anomalies (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        stream_id uuid,
        source_id uuid,
        run_id uuid,
        kind text NOT NULL,
        severity text NOT NULL DEFAULT \'warning\',
        summary text NOT NULL,
        detail jsonb NOT NULL DEFAULT \'{}\'::jsonb,
        detected_at timestamptz NOT NULL DEFAULT now(),
        resolved_at timestamptz,
        resolved_by text,
        resolution text
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
    $pg->statement('DROP TRIGGER IF EXISTS trg_poison_record_state ON ingest.record_state');
    $pg->statement('DROP TRIGGER IF EXISTS trg_poison_anomalies ON ingest.anomalies');
    $pg->statement('DROP FUNCTION IF EXISTS ingest_test_poison_record_state()');
    $pg->statement('DROP FUNCTION IF EXISTS ingest_test_poison_anomalies()');
    foreach (['ingest.anomalies', 'ingest.record_state', 'ingest.record_versions', 'ingest.streams', 'ingest.sources'] as $t) {
        $pg->statement("DROP TABLE IF EXISTS {$t} CASCADE");
    }
});

it('rolls back BOTH chunks of a fold when the second chunk fails mid-write, leaving no key\'s absent_runs advanced', function () {
    $pg = DB::connection('pgsql');
    $streamId = $this->streamId;
    config(['partna.ingest.land_chunk' => 2000]);

    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror, orderField: 'seq');
    $lander = new Lander;

    // 1100 survivor keys (seen every run) + 600 absent keys (never re-seen),
    // same zero-padded digit width within each prefix so lexical order ==
    // numeric order, and 'abs' < 'surv' so the absent keys sort first.
    $initial = [];
    foreach (range(1, 1100) as $i) {
        $initial[] = new Record('releases', sprintf('surv%04d', $i), ['seq' => $i]);
    }
    foreach (range(1, 600) as $i) {
        $initial[] = new Record('releases', sprintf('abs%05d', $i), ['seq' => $i]);
    }
    $lander->land($streamId, (string) Str::uuid(), $spec, $initial, null);
    expect((int) $pg->table('ingest.record_state')->where('stream_id', $streamId)->count())->toBe(1700);

    // Second run only sees the 1100 survivors — 1100 > 1000 forces
    // liveNotSeen()'s paginated, key-ordered branch, which is what makes the
    // resulting candidate (and therefore write-chunk) order deterministic:
    // all 600 'abs*' keys sort before any 'surv*' key, so
    // array_chunk($dominatedAbsent, 500) puts abs00001-abs00500 in the FIRST
    // write chunk and abs00501-abs00600 in the SECOND.
    $survivors = [];
    foreach (range(1, 1100) as $i) {
        $survivors[] = new Record('releases', sprintf('surv%04d', $i), ['seq' => $i]);
    }

    // Poison a key we know lands in the SECOND write chunk (abs00550, the
    // 50th of the 100 second-chunk keys) — the trigger fires on the very
    // UPDATE that increments absent_runs, i.e. inside foldAbsence()'s
    // wrapped write phase, not the initial landing above.
    $pg->statement('
        CREATE FUNCTION ingest_test_poison_record_state() RETURNS trigger AS $$
        BEGIN
            IF NEW.key = \'abs00550\' THEN
                RAISE EXCEPTION \'ingest_test_poison: forced failure on chunk 2 key %\', NEW.key;
            END IF;
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;
    ');
    $pg->statement('
        CREATE TRIGGER trg_poison_record_state
        BEFORE UPDATE ON ingest.record_state
        FOR EACH ROW EXECUTE FUNCTION ingest_test_poison_record_state();
    ');

    $threw = false;
    try {
        $lander->land($streamId, (string) Str::uuid(), $spec, $survivors, new Covered('releases', Coverage::exhaustive()));
    } catch (Throwable $e) {
        $threw = true;
        expect($e->getMessage())->toContain('ingest_test_poison');
    }
    expect($threw)->toBeTrue('land() did not raise — the poison trigger never fired, so this test proves nothing.');

    // THE property: no key's absent_runs advanced AT ALL. Pre-fix (two
    // independent statement pairs per chunk, no transaction), chunk 1's 500
    // keys would already sit at absent_runs = 1 by the time chunk 2 failed.
    $advanced = $pg->table('ingest.record_state')->where('stream_id', $streamId)->where('absent_runs', '>', 0)->count();
    expect($advanced)->toBe(0);

    $tombstoned = $pg->table('ingest.record_state')->where('stream_id', $streamId)->whereNotNull('tombstoned_at')->count();
    expect($tombstoned)->toBe(0);

    // ANTI-VACUITY: the guard must NOT have been what stopped the write
    // (600/1700 ≈ 35.3% < 40%) — confirm the write path, not the guard path,
    // is what we exercised.
    expect((bool) $pg->table('ingest.streams')->where('id', $streamId)->value('guard_tripped_at'))->toBeFalse();
});

it('rolls back the guard-trip streams UPDATE when the paired anomaly INSERT fails, never leaving guard_tripped_at set with no anomaly explaining it', function () {
    $pg = DB::connection('pgsql');
    $streamId = $this->streamId;

    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror, orderField: 'seq');
    $lander = new Lander;

    // 12 live keys, 5 vanish (ratio 5/12 ≈ 41.7% >= 40%, count >= 5) — the
    // exact count=5 boundary case LanderTest.php already covers on SQLite,
    // reused here because it reliably reaches the guard-trip branch.
    $records = [];
    foreach (range(1, 12) as $i) {
        $records[] = new Record('releases', "k{$i}", ['seq' => $i]);
    }
    $lander->land($streamId, (string) Str::uuid(), $spec, $records, null);

    $survivors = array_slice($records, 0, 7);

    // Poison the anomalies INSERT itself — the guard-trip branch's SECOND
    // statement, run AFTER the streams UPDATE that sets guard_tripped_at.
    $pg->statement('
        CREATE FUNCTION ingest_test_poison_anomalies() RETURNS trigger AS $$
        BEGIN
            IF NEW.kind = \'delete_guard\' THEN
                RAISE EXCEPTION \'ingest_test_poison: forced failure on delete_guard anomaly insert\';
            END IF;
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;
    ');
    $pg->statement('
        CREATE TRIGGER trg_poison_anomalies
        BEFORE INSERT ON ingest.anomalies
        FOR EACH ROW EXECUTE FUNCTION ingest_test_poison_anomalies();
    ');

    $threw = false;
    try {
        $lander->land($streamId, (string) Str::uuid(), $spec, $survivors, new Covered('releases', Coverage::exhaustive()));
    } catch (Throwable $e) {
        $threw = true;
        expect($e->getMessage())->toContain('ingest_test_poison');
    }
    expect($threw)->toBeTrue('land() did not raise — the poison trigger never fired, so this test proves nothing.');

    // THE property: never one without the other. Pre-fix, the streams
    // UPDATE (statement 1) would have already committed by the time the
    // anomalies INSERT (statement 2) failed, leaving guard_tripped_at set
    // with no anomaly row to explain it to an operator.
    expect($pg->table('ingest.streams')->where('id', $streamId)->value('guard_tripped_at'))->toBeNull();
    expect((int) $pg->table('ingest.anomalies')->where('stream_id', $streamId)->where('kind', 'delete_guard')->count())->toBe(0);
});
