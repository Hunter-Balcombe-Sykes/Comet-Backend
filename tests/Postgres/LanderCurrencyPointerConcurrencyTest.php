<?php

// LIFE-4: Lander::landChunk()/landRecordsIndividually() resolve
// current_version_id from a SELECT the writing transaction does not always
// arbitrate. When our hash is already the current version ($wasCurrent ===
// true) we skip demote/promote entirely, so nothing fences our
// record_state.current_version_id write against a concurrent lander that
// moved currency in the meantime — the DO UPDATE just overwrites whatever
// pointer the real winner set. The fix omits current_version_id from the
// update column list on that branch, so the incumbent pointer (written by
// whoever actually promoted) survives.
//
// Postgres-only for a real reason: idx_record_versions_one_current (the
// UNIQUE partial index that makes the fix provably correct on the
// $wasCurrent === false branch) is ABSENT from the SQLite test mirror
// (tests/Pest.php only creates the non-unique idx_record_versions_current) —
// that alone disqualifies the SQLite lane for this property.

use App\Ingest\Landing\Lander;
use App\Ingest\Manifest\SourceProfile;
use App\Ingest\Manifest\StreamSpec;
use App\Ingest\Message\Record;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

// Checked by every child, immediately after its own commit (own connection,
// so this read is as close to "right after my write" as PHP can get without
// wrapping the check in the same transaction). Logs a row the instant the
// invariant is caught false — a final-state-only check would miss a
// violation that a LATER write happens to paper over before the test ends.
function recordMismatchIfAny(int $childIdx, string $streamId, string $key): void
{
    $pg = DB::connection('pgsql');

    $row = $pg->selectOne('
        SELECT rs.current_version_id AS recorded_id, rv.id AS actual_id
        FROM ingest.record_state rs
        LEFT JOIN ingest.record_versions rv
          ON rv.stream_id = rs.stream_id AND rv.key = rs.key AND rv.is_current = true
        WHERE rs.stream_id = ? AND rs.key = ?
    ', [$streamId, $key]);

    if ($row === null) {
        return;
    }

    if ((int) $row->recorded_id !== (int) $row->actual_id) {
        $pg->table('ingest.mismatch_probe')->insert([
            'child_idx' => $childIdx,
            'recorded_current_version_id' => $row->recorded_id,
            'actual_current_version_id' => $row->actual_id,
        ]);
    }
}

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS ingest');
    $pg->statement('DROP TABLE IF EXISTS ingest.land_probe CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.record_state CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.record_versions CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.streams CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.sources CASCADE');

    // Same DDL as LanderBatchLandingTest.php's beforeEach (deliberately NOT
    // FK'd to core.users — this file only exercises ingest.* writes).
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
    // LOAD-BEARING for this test: the real constraint the LIFE-4 fix relies
    // on to make the $wasCurrent === false branch provably correct.
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

    $pg->statement('CREATE TABLE ingest.land_probe (
        id bigserial PRIMARY KEY,
        child_idx integer NOT NULL,
        outcome text NOT NULL,
        detail text
    )');

    // A final snapshot check has almost no power here: with every child
    // hammering the SAME key, whichever write happens to land last
    // determines the end state regardless of whether an EARLIER write
    // stomped a fresher pointer along the way. mismatch_probe is written by
    // every child, immediately after its own commit, so the invariant gets
    // checked dozens of times per second instead of once at the very end.
    $pg->statement('CREATE TABLE ingest.mismatch_probe (
        id bigserial PRIMARY KEY,
        child_idx integer NOT NULL,
        recorded_current_version_id bigint,
        actual_current_version_id bigint,
        detected_at timestamptz NOT NULL DEFAULT clock_timestamp()
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
    foreach (['ingest.land_probe', 'ingest.mismatch_probe', 'ingest.record_state', 'ingest.record_versions', 'ingest.streams', 'ingest.sources'] as $t) {
        $pg->statement("DROP TABLE IF EXISTS {$t} CASCADE");
    }
});

it('never lets a lander that is NOT the current promoter overwrite the pointer a concurrent winner just set (real fork)', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl_fork is not available in this runtime');
    }

    config(['partna.ingest.land_chunk' => 500]);
    $pg = DB::connection('pgsql');
    $streamId = $this->streamId;
    // Deliberately ONE key: the race needs child 0's resolving SELECT to
    // land on a moment where the key is ALREADY current, and a changing
    // child to promote + commit before child 0's own record_state upsert
    // acquires the row lock. Spread across many keys that window is rare
    // per key per iteration on a fast local connection; concentrating every
    // child onto a single key makes lock contention on record_state (and
    // therefore the race) the common case, not the exception.
    $keyCount = 1;

    // Establish v0 sequentially first.
    $spec = new StreamSpec(name: 'releases', target: 'release', profile: SourceProfile::Mirror);
    $lander = new Lander;
    $initial = [];
    foreach (range(1, $keyCount) as $i) {
        $initial[] = new Record('releases', "k{$i}", ['title' => "v0-{$i}"]);
    }
    $lander->land($streamId, (string) Str::uuid(), $spec, $initial, null);

    $startAt = microtime(true) + 0.2;
    $pids = [];

    // Child 0: re-lands v0 UNCHANGED in a tight loop for ~1s — this is the
    // child that hits the $wasCurrent === true branch every time.
    $pid0 = pcntl_fork();
    if ($pid0 === -1) {
        $this->fail('pcntl_fork failed');
    }
    if ($pid0 === 0) {
        DB::purge('pgsql');
        DB::reconnect('pgsql');

        $sleepUs = (int) max(0, ($startAt - microtime(true)) * 1_000_000);
        usleep($sleepUs);

        $lander = new Lander;
        $deadline = microtime(true) + 2.5;
        $rounds = 0;
        while (microtime(true) < $deadline) {
            $rounds++;
            $records = [];
            foreach (range(1, $keyCount) as $i) {
                $records[] = new Record('releases', "k{$i}", ['title' => "v0-{$i}"]);
            }
            try {
                $lander->land($streamId, (string) Str::uuid(), $spec, $records, null);
                DB::connection('pgsql')->table('ingest.land_probe')->insert([
                    'child_idx' => 0,
                    'outcome' => 'ok',
                    'detail' => "round {$rounds}",
                ]);
                foreach (range(1, $keyCount) as $i) {
                    recordMismatchIfAny(0, $streamId, "k{$i}");
                }
            } catch (Throwable $e) {
                DB::connection('pgsql')->table('ingest.land_probe')->insert([
                    'child_idx' => 0,
                    'outcome' => 'exception',
                    'detail' => substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        exit(0);
    }
    $pids[] = $pid0;

    // Children 1-6: land distinct CHANGING docs for the same small key set,
    // in a loop, for ~1.5s each — these are the ones actually moving
    // currency, and pushing more of them against few keys raises how often
    // one commits in the narrow window between child 0's resolving SELECT
    // and its own record_state upsert.
    for ($c = 1; $c <= 6; $c++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('pcntl_fork failed');
        }
        if ($pid === 0) {
            DB::purge('pgsql');
            DB::reconnect('pgsql');

            $sleepUs = (int) max(0, ($startAt - microtime(true)) * 1_000_000);
            usleep($sleepUs);

            $lander = new Lander;
            $deadline = microtime(true) + 2.5;
            $round = 0;
            while (microtime(true) < $deadline) {
                $round++;
                $records = [];
                foreach (range(1, $keyCount) as $i) {
                    $records[] = new Record('releases', "k{$i}", ['title' => "v{$c}-{$round}-{$i}"]);
                }
                try {
                    $lander->land($streamId, (string) Str::uuid(), $spec, $records, null);
                    DB::connection('pgsql')->table('ingest.land_probe')->insert([
                        'child_idx' => $c,
                        'outcome' => 'ok',
                        'detail' => "round {$round}",
                    ]);
                    foreach (range(1, $keyCount) as $i) {
                        recordMismatchIfAny($c, $streamId, "k{$i}");
                    }
                } catch (Throwable $e) {
                    // A losing child legitimately raises here: two changing
                    // landers racing the same key can hit 23505 against
                    // idx_record_versions_one_current — that is expected,
                    // not a bug (see Fix B's risk note). Record and move on.
                    DB::connection('pgsql')->table('ingest.land_probe')->insert([
                        'child_idx' => $c,
                        'outcome' => 'exception',
                        'detail' => substr($e->getMessage(), 0, 200),
                    ]);
                }
            }

            exit(0);
        }
        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    // Property 1 (the fix) — THE primary check. Not a final-state snapshot:
    // with every child hammering the same key, whichever write lands last
    // sets the end state regardless of what happened earlier, so a
    // final-only check has almost no power to catch a MID-RUN violation
    // that a later legitimate write papers over. mismatch_probe was written
    // by every child immediately after every one of its own commits — this
    // asserts NONE of those observations ever caught the invariant false.
    $observedMismatches = $pg->table('ingest.mismatch_probe')->get();
    expect($observedMismatches)->toHaveCount(0, 'a child observed record_state.current_version_id diverge from the actual is_current row at least once — pointer overwrite race. First: '.json_encode($observedMismatches->first()));

    // Property 1b, the final-state snapshot, asserted IN SQL, not by casting
    // in PHP (pdo_pgsql returns booleans as 't'/'f'; `(bool) 'f'` is TRUE in
    // PHP): kept as a belt-and-braces check, but the continuous check above
    // is the one doing the real work.
    $mismatches = $pg->select('
        SELECT rs.key, rs.current_version_id, rv.id AS actual_current_id
        FROM ingest.record_state rs
        LEFT JOIN ingest.record_versions rv
          ON rv.stream_id = rs.stream_id AND rv.key = rs.key AND rv.is_current = true
        WHERE rs.stream_id = ?
          AND rs.current_version_id IS DISTINCT FROM rv.id
    ', [$streamId]);
    expect($mismatches)->toBe([], 'record_state.current_version_id diverged from the actual is_current row for '.count($mismatches).' key(s) — pointer overwrite race.');

    // Property 2: exactly one is_current row per key — nobody bypassed the
    // partial unique index.
    $duplicateCurrent = $pg->select('
        SELECT key, COUNT(*) AS c
        FROM ingest.record_versions
        WHERE stream_id = ? AND is_current = true
        GROUP BY key
        HAVING COUNT(*) > 1
    ', [$streamId]);
    expect($duplicateCurrent)->toBe([]);

    $currentRowCount = $pg->table('ingest.record_versions')->where('stream_id', $streamId)->where('is_current', true)->count();
    expect($currentRowCount)->toBe($keyCount);

    // ANTI-VACUITY (hard): the race must have actually moved currency at
    // least once, and child 0 must have actually landed successfully at
    // least once, or this proves nothing. Checked against HISTORY (every
    // changing child's own successful land_probe rows), not final state —
    // with keyCount=1 and 6 changing children plus child 0 all hammering the
    // same row, the LAST write can legitimately land back on v0 by chance
    // even when the race was exercised heavily throughout the run, so a
    // final-snapshot check here would itself be flaky independent of the
    // property under test.
    $changingSuccesses = $pg->table('ingest.land_probe')->whereIn('child_idx', [1, 2, 3, 4, 5, 6])->where('outcome', 'ok')->count();
    expect($changingSuccesses)->toBeGreaterThan(0, 'no changing child ever successfully promoted a new version — the race was never exercised.');

    $child0Successes = $pg->table('ingest.land_probe')->where('child_idx', 0)->where('outcome', 'ok')->count();
    expect($child0Successes)->toBeGreaterThan(0, 'child 0 never successfully landed an unchanged re-land — the $wasCurrent === true branch was never exercised.');

    // SOFT WITNESS only (never pass/fail) — real contention (a changing
    // child hitting 23505) is expected but not guaranteed by any single run.
    $contentionExceptions = $pg->table('ingest.land_probe')->whereIn('child_idx', [1, 2, 3, 4, 5, 6])->where('outcome', 'exception')->count();
    if ($contentionExceptions === 0) {
        fwrite(STDERR, "[LanderCurrencyPointerConcurrencyTest] no changing child observed a 23505 contention exception this run.\n");
    }
});
