<?php

// R3 (overnight 2026-08-18): proves routing.link_observations.user_id now has
// the ON DELETE CASCADE FK its three routing.* neighbours always had, so
// closing an account takes its observations — and the raw_url PII they carry —
// with it. Before migration 20260819002100 the column had no FK at all and dev
// held 42 orphan rows tagged with dead user ids.
//
// Postgres lane, not SQLite, for two reasons SQLite cannot express:
//   1. link_observations is PARTITION BY RANGE (observed_at). The FK must sit
//      on the PARENT so it propagates to every partition — including ones
//      created after the migration ran.
//   2. PostgreSQL < 18 REJECTS `ADD CONSTRAINT ... FOREIGN KEY ... NOT VALID`
//      on a partitioned parent, which is why 20260819002100 deviates from
//      CONVENTIONS.md §4. A green SQLite run says nothing about whether that
//      ALTER even applies.
//
// Both migrations are read off disk and executed, never retyped — same
// discipline as IngestCascadeDeletionTest, because a hand-copied constraint
// drifts from the file the moment someone edits it.

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

const LINK_OBS_PURGE_SQL_PATH = 'supabase/migrations/20260819002000_link_observations_purge_orphans.sql';
const LINK_OBS_FK_SQL_PATH = 'supabase/migrations/20260819002100_link_observations_user_fk.sql';

// A user id that is deliberately absent from core.users — the orphan shape the
// purge migration exists to clear.
const LINK_OBS_DEAD_USER = '00000000-0000-4000-8000-0000000000ff';

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS core');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS routing');

    // core.users is a SHARED fixture in this lane — create-if-absent, never
    // drop it. Siblings FK to it and whichever file runs first sets the shape.
    $pg->statement('CREATE TABLE IF NOT EXISTS core.users (id uuid PRIMARY KEY DEFAULT gen_random_uuid())');

    // link_observations is owned exclusively by this file, so it is safe to
    // drop. CASCADE takes the partitions with it.
    $pg->statement('DROP TABLE IF EXISTS routing.link_observations CASCADE');

    // Real RANGE-partitioned parent, mirroring 20260727120000. A flat stand-in
    // would void the whole point: the FK's propagation to partitions.
    $pg->statement('CREATE TABLE routing.link_observations (
        id uuid NOT NULL DEFAULT gen_random_uuid(),
        user_id uuid,
        observed_at timestamptz NOT NULL DEFAULT now(),
        source text NOT NULL DEFAULT \'paste\',
        raw_url text NOT NULL DEFAULT \'https://example.test/\',
        PRIMARY KEY (id, observed_at)
    ) PARTITION BY RANGE (observed_at)');

    $pg->statement("CREATE TABLE routing.link_observations_2026_07 PARTITION OF routing.link_observations
        FOR VALUES FROM ('2026-07-01 00:00:00+00') TO ('2026-08-01 00:00:00+00')");
    $pg->statement("CREATE TABLE routing.link_observations_2026_08 PARTITION OF routing.link_observations
        FOR VALUES FROM ('2026-08-01 00:00:00+00') TO ('2026-09-01 00:00:00+00')");

    // Seed the exact three shapes the purge has to tell apart, BEFORE the
    // migrations run: an orphan (must go), a NULL-owner pre-account row (must
    // stay), and a live user's row (must stay).
    $survivor = linkObsUser();
    linkObsInsert(LINK_OBS_DEAD_USER, '2026-07-15 00:00:00+00');
    linkObsInsert(LINK_OBS_DEAD_USER, '2026-08-15 00:00:00+00');
    linkObsInsert(null, '2026-08-16 00:00:00+00');
    linkObsInsert($survivor, '2026-08-17 00:00:00+00');

    linkObsApplyMigration(LINK_OBS_PURGE_SQL_PATH);
    linkObsApplyMigration(LINK_OBS_FK_SQL_PATH);
});

afterAll(function () {
    DB::connection('pgsql')->statement('DROP TABLE IF EXISTS routing.link_observations CASCADE');
});

/**
 * Execute a migration file read off disk, stripping comments and the
 * transaction wrapper (BEGIN/COMMIT/SET LOCAL) — SET LOCAL is transaction
 * -scoped and meaningless when each statement runs on its own here.
 *
 * Named with a linkObs prefix on purpose: helpers in tests/Postgres are global
 * functions sharing one process, so a generic name collides with a sibling.
 */
function linkObsApplyMigration(string $relativePath): void
{
    $sql = (string) file_get_contents(base_path($relativePath));
    $sql = preg_replace('/--.*$/m', '', $sql) ?? $sql;

    $statements = array_filter(array_map('trim', explode(';', $sql)), function (string $s) {
        if ($s === '') {
            return false;
        }
        $upper = strtoupper($s);

        return ! str_starts_with($upper, 'BEGIN')
            && ! str_starts_with($upper, 'COMMIT')
            && ! str_starts_with($upper, 'SET LOCAL');
    });

    expect($statements)->not->toBeEmpty("No executable statements parsed from {$relativePath}");

    foreach ($statements as $statement) {
        DB::connection('pgsql')->statement($statement);
    }
}

function linkObsUser(): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert(['id' => $id]);

    return $id;
}

function linkObsInsert(?string $userId, string $observedAt): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('routing.link_observations')->insert([
        'id' => $id,
        'user_id' => $userId,
        'observed_at' => $observedAt,
        'source' => 'paste',
        'raw_url' => 'https://example.test/'.$id,
    ]);

    return $id;
}

function linkObsCount(?string $userId): int
{
    $q = DB::connection('pgsql')->table('routing.link_observations');

    return $userId === null
        ? $q->whereNull('user_id')->count()
        : $q->where('user_id', $userId)->count();
}

it('A: the FK applies to the partitioned PARENT and reaches every partition', function () {
    $pg = DB::connection('pgsql');

    $onParent = $pg->selectOne(
        "SELECT confdeltype FROM pg_constraint
         WHERE conname = 'link_observations_user_id_fkey'
           AND conrelid = 'routing.link_observations'::regclass"
    );

    expect($onParent)->not->toBeNull('FK missing from the partitioned parent');
    // 'c' = ON DELETE CASCADE. 'n' (SET NULL) would keep raw_url — the PII.
    expect($onParent->confdeltype)->toBe('c');

    $onPartitions = (int) $pg->selectOne(
        "SELECT count(*) AS n FROM pg_constraint c
         JOIN pg_inherits i ON i.inhrelid = c.conrelid
         WHERE i.inhparent = 'routing.link_observations'::regclass
           AND c.contype = 'f'
           AND c.confrelid = 'core.users'::regclass"
    )->n;

    expect($onPartitions)->toBe(2, 'FK did not propagate to both partitions');
});

it('B: the purge migration cleared pre-existing orphans and spared everything else', function () {
    expect(linkObsCount(LINK_OBS_DEAD_USER))->toBe(0, 'orphan rows survived the purge');
    // Pre-account/staff builds have no owner and are not orphans.
    expect(linkObsCount(null))->toBe(1, 'NULL-owner row was wrongly purged');
    expect(DB::connection('pgsql')->table('routing.link_observations')->count())->toBe(2);
});

it('C: a new orphan can no longer be written, in any partition', function () {
    foreach (['2026-07-20 00:00:00+00', '2026-08-20 00:00:00+00'] as $observedAt) {
        $sqlState = null;
        try {
            linkObsInsert(LINK_OBS_DEAD_USER, $observedAt);
        } catch (QueryException $e) {
            $sqlState = $e->getCode();
        }

        // 23503 = foreign_key_violation. Asserting the code, not merely "it
        // threw" — a NOT NULL or CHECK failure would also throw QueryException.
        expect($sqlState)->toBe('23503', "orphan insert was accepted at {$observedAt}");
    }
});

it('D: deleting a user cascades their observations across MULTIPLE partitions', function () {
    $userId = linkObsUser();
    linkObsInsert($userId, '2026-07-10 00:00:00+00');
    linkObsInsert($userId, '2026-07-11 00:00:00+00');
    linkObsInsert($userId, '2026-08-10 00:00:00+00');

    expect(linkObsCount($userId))->toBe(3);

    // Prove the rows really are spread across partitions, or the cascade
    // assertion below could pass on a single-partition technicality.
    $partitionsHit = (int) DB::connection('pgsql')->selectOne(
        'SELECT count(DISTINCT tableoid) AS n FROM routing.link_observations WHERE user_id = ?',
        [$userId]
    )->n;
    expect($partitionsHit)->toBe(2);

    DB::connection('pgsql')->table('core.users')->where('id', $userId)->delete();

    expect(linkObsCount($userId))->toBe(0, 'observations outlived their deleted user');
});

it('E: a NULL owner is still accepted — pre-account and staff builds depend on it', function () {
    $id = linkObsInsert(null, '2026-08-21 00:00:00+00');

    expect(DB::connection('pgsql')->table('routing.link_observations')->where('id', $id)->exists())->toBeTrue();
});
