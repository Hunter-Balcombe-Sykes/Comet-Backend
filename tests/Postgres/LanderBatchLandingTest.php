<?php

// Postgres-only proof for SCALE-4's chunked landChunk() (Unit 9,
// audit-fix/pg-u9). Each case here is genuinely constraint-bound and
// invisible under the SQLite mirror (tests/Pest.php's ingest.record_versions
// has no partitioning and a non-unique current-lookup index — see
// LanderRevertProjectionTest.php's header for the same reasoning):
//
//   1. A chunk that changes MANY keys at once must survive the widened
//      demote-then-promote against the REAL partial unique index
//      idx_record_versions_one_current without a transient 23505.
//   2. A chunk carrying a duplicate key must not raise 21000 "ON CONFLICT DO
//      UPDATE command cannot affect row a second time" on the record_state
//      upsert — the NEW hazard batching introduces (never present in the
//      per-record path), guarded by landChunk()'s key-level de-dup.
//   3. A chunk containing a doc with a literal NUL byte must roll the WHOLE
//      chunk transaction back with 22P05, and the per-record fallback in
//      land() must then land the surviving records durably — direct
//      executable proof the transaction-boundary trade (catch OUTSIDE
//      DB::transaction()) is sound.
//   4. WHK-1: that isolation must hold for the records AFTER the poison, not
//      only the ones before it, and a chunk where EVERY record is poisoned
//      must report failed == seen with changed == 0 so RunExecutor can tell a
//      broken stream from a quiet one. Neither is reproducible on SQLite: the
//      whole scenario is a NUL byte that only jsonb rejects.

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
    // this test only exercises ingest.* writes. See
    // LanderRevertProjectionTest.php's beforeEach for the same rationale.
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
        -- LIFE-5: SourceScheduler::scoreDue() selects on this, so a
        -- stand-in without it fails the whole lane with 42703.
        needs_eager_run boolean NOT NULL DEFAULT false,
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
    // non-unique current-lookup index, AND the one-current UNIQUE index
    // (20260729130001) landChunk()'s widened demote-then-promote must
    // respect exactly as the per-record path did.
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

it('lands a chunk that changes many keys at once without a transient 23505 against idx_record_versions_one_current, and exactly one row per key survives as current', function () {
    config(['partna.ingest.land_chunk' => 500]);
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror);
    $lander = app(Lander::class);
    $streamId = $this->streamId;

    // Land 200 keys with an initial doc — all in one chunk.
    $initial = [];
    foreach (range(1, 200) as $i) {
        $initial[] = new Record('releases', "k{$i}", ['title' => "v1-{$i}"]);
    }
    $first = $lander->land($streamId, (string) Str::uuid(), $spec, $initial, null);
    expect($first['changed'])->toBe(200);

    // Change EVERY key in one chunk — the widened demote-then-promote must
    // demote 200 currently-current rows and promote 200 new ones without
    // ever transiently holding two is_current rows for the same key.
    $changedDocs = [];
    foreach (range(1, 200) as $i) {
        $changedDocs[] = new Record('releases', "k{$i}", ['title' => "v2-{$i}"]);
    }
    $second = $lander->land($streamId, (string) Str::uuid(), $spec, $changedDocs, null);
    expect($second['changed'])->toBe(200);

    // Assert in SQL, not by casting in PHP (pdo_pgsql returns 't'/'f' as
    // strings).
    $currentCount = DB::connection('pgsql')->table('ingest.record_versions')
        ->where('stream_id', $streamId)->where('is_current', true)->count();
    expect($currentCount)->toBe(200);

    $distinctKeysCurrent = DB::connection('pgsql')->table('ingest.record_versions')
        ->where('stream_id', $streamId)->where('is_current', true)
        ->distinct()->count('key');
    expect($distinctKeysCurrent)->toBe(200);

    // The current row for every key must be the v2 doc, not v1.
    $stillOnV1 = DB::connection('pgsql')->table('ingest.record_versions')
        ->where('stream_id', $streamId)->where('is_current', true)
        ->whereRaw("doc->>'title' like ?", ['v1-%'])
        ->count();
    expect($stillOnV1)->toBe(0);
});

it('lands a chunk carrying a duplicate key without raising 21000 on the record_state upsert', function () {
    config(['partna.ingest.land_chunk' => 500]);
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror);
    $lander = app(Lander::class);
    $streamId = $this->streamId;

    // k1 appears twice in the same chunk with two different docs — this
    // batches into ONE record_state upsert payload. Pre-de-dup, that payload
    // would carry (stream_id, key) = (streamId, 'k1') twice, and Postgres's
    // ON CONFLICT DO UPDATE raises 21000 for exactly that shape. SQLite
    // does not reproduce this at all.
    $records = [
        new Record('releases', 'k1', ['title' => 'A']),
        new Record('releases', 'k2', ['title' => 'v2']),
        new Record('releases', 'k1', ['title' => 'B']),
    ];

    $result = $lander->land($streamId, (string) Str::uuid(), $spec, $records, null);

    // seen counts every record including the duplicate; changed counts the
    // duplicate key only once (last-occurrence-wins) — the documented delta.
    expect($result['seen'])->toBe(3);
    expect($result['changed'])->toBe(2); // k1 (winner: B) + k2

    // BOTH k1 docs must have been landed as durable log rows (two distinct
    // doc_hash rows for the same key) — only the record_state POINTER
    // de-dups by key, never the version log itself.
    $k1Versions = DB::connection('pgsql')->table('ingest.record_versions')
        ->where('stream_id', $streamId)->where('key', 'k1')->get();
    expect($k1Versions)->toHaveCount(2);

    $k1Current = DB::connection('pgsql')->table('ingest.record_versions')
        ->where('stream_id', $streamId)->where('key', 'k1')->where('is_current', true)->get();
    expect($k1Current)->toHaveCount(1);
    expect(json_decode((string) $k1Current[0]->doc, true)['title'])->toBe('B'); // last occurrence wins

    $state = DB::connection('pgsql')->table('ingest.record_state')
        ->where('stream_id', $streamId)->where('key', 'k1')->first();
    expect((int) $state->current_version_id)->toBe((int) $k1Current[0]->id);
});

it('rolls back the whole chunk on a doc containing a literal NUL byte, then the per-record fallback lands the OTHER records durably and isolates the poisoned one', function () {
    config(['partna.ingest.land_chunk' => 500]);
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror);
    $lander = app(Lander::class);
    $streamId = $this->streamId;

    // Postgres jsonb rejects a literal NUL byte in a text value with 22P05
    // ("unsupported Unicode escape sequence" / invalid text representation)
    // — a plausible shape for a scraped Instagram caption. This must not be
    // caught inside the chunk's DB::transaction() (that would poison it with
    // 25P02 — the savepoint bug this repo has shipped three times).
    $poisonedDoc = ['title' => "bad\0byte"];

    // The poisoned record is LAST in iteration order. Until WHK-1,
    // landRecordsIndividually() had no catch of its own (deliberately deferred
    // when this test was written), so its transaction re-raised the 22P05 and
    // that propagated uncaught out of land() — this assertion used to be
    // ->toThrow(QueryException::class). It no longer throws: the per-record
    // catch isolates the poisoned record and reports it through the `failed`
    // count instead. good1 and good2 were already durable either way, because
    // their OWN transactions committed before the loop reached the poison —
    // that is DINT-9's guarantee at per-record granularity, and it is
    // unchanged. What WHK-1 adds is that records AFTER the poison survive too;
    // this ordering cannot see that — the case below puts the poison FIRST.
    $records = [
        new Record('releases', 'good1', ['title' => 'fine 1']),
        new Record('releases', 'good2', ['title' => 'fine 2']),
        new Record('releases', 'poisoned', $poisonedDoc),
    ];

    $result = $lander->land($streamId, (string) Str::uuid(), $spec, $records, null);

    expect($result['failed'])->toBe(1);
    expect($result['changed'])->toBe(2);

    $good = DB::connection('pgsql')->table('ingest.record_versions')
        ->where('stream_id', $streamId)->whereIn('key', ['good1', 'good2'])->get();
    expect($good)->toHaveCount(2);

    $poisoned = DB::connection('pgsql')->table('ingest.record_versions')
        ->where('stream_id', $streamId)->where('key', 'poisoned')->count();
    expect($poisoned)->toBe(0);

    $goodState = DB::connection('pgsql')->table('ingest.record_state')
        ->where('stream_id', $streamId)->whereIn('key', ['good1', 'good2'])->get();
    expect($goodState)->toHaveCount(2);
});

it('isolates a poisoned record that comes FIRST, so every record after it still lands durably', function () {
    config(['partna.ingest.land_chunk' => 500]);
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror);
    $lander = app(Lander::class);
    $streamId = $this->streamId;

    // Ordering is the whole point. With the poison LAST, the records before it
    // are durable purely because their own transactions had already committed
    // — true even before WHK-1, which is why the sibling case above passed on
    // a Lander that had no per-record catch at all. Put the poison FIRST and
    // nothing after it ever got a transaction: land() threw out of the loop on
    // record 1 and good1/good2 were silently dropped. That is the defect.
    $records = [
        new Record('releases', 'poisoned', ['title' => "bad\0byte"]),
        new Record('releases', 'good1', ['title' => 'fine 1']),
        new Record('releases', 'good2', ['title' => 'fine 2']),
    ];

    $result = $lander->land($streamId, (string) Str::uuid(), $spec, $records, null);

    expect($result['seen'])->toBe(3);
    expect($result['changed'])->toBe(2);
    expect($result['failed'])->toBe(1);

    $landed = DB::connection('pgsql')->table('ingest.record_versions')
        ->where('stream_id', $streamId)->orderBy('key')->pluck('key')->all();
    expect($landed)->toBe(['good1', 'good2']);

    // The pointer rows matter as much as the version rows: a version with no
    // record_state entry is invisible to absence folding and to projection.
    $state = DB::connection('pgsql')->table('ingest.record_state')
        ->where('stream_id', $streamId)->orderBy('key')->pluck('key')->all();
    expect($state)->toBe(['good1', 'good2']);
});

it('reports failed == seen and changed == 0 when every record in the chunk is poisoned', function () {
    config(['partna.ingest.land_chunk' => 500]);
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror);
    $lander = app(Lander::class);
    $streamId = $this->streamId;

    // This is the shape RunExecutor treats as a STREAM failure rather than a
    // degraded run: nothing landed at all, which in practice means the
    // database is unwell rather than one caption being malformed. Reporting it
    // as merely "degraded" would leave a broken stream with no backoff.
    $records = [
        new Record('releases', 'p1', ['title' => "bad\0one"]),
        new Record('releases', 'p2', ['title' => "bad\0two"]),
    ];

    $result = $lander->land($streamId, (string) Str::uuid(), $spec, $records, null);

    expect($result['seen'])->toBe(2);
    expect($result['changed'])->toBe(0);
    expect($result['failed'])->toBe(2);

    expect(DB::connection('pgsql')->table('ingest.record_versions')
        ->where('stream_id', $streamId)->count())->toBe(0);
});
