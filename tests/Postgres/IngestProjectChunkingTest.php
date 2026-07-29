<?php

// SCALE-1/SCALE-2: `ingest:project` used to walk ingest.sources with a single
// `->orderBy('created_at')->get()` — unbounded memory, and one ingest.streams
// query per source (N+1). The fix is `chunkById($size)` keyed on `id`, with
// the pre-existing `orderBy('created_at')` DELETED.
//
// Why that deletion matters, and why this must run on real Postgres:
// Query\Builder::forPageAfterId() (vendor/laravel/framework/src/Illuminate/
// Database/Query/Builder.php) does `$this->orders =
// removeExistingOrdersFor('id')`, which strips ONLY orders on the `id`
// column. An `orderBy('created_at')` left in the chain survives that strip
// and sorts BEFORE the appended `orderBy('id')`, so the emitted SQL becomes
// `WHERE id > ? ORDER BY created_at ASC, id ASC LIMIT ?` — a keyset predicate
// on `id` under a PRIMARY sort on a different column, which does not
// partition the result set. Rows whose id sorts below the cursor but whose
// created_at sorts later are skipped permanently, with no error.
//
// SQLite (the Feature-test mirror) has no real query planner honouring a
// composite ORDER BY consistently across separately-executed paged queries,
// so it cannot reproduce this. This file seeds 7 sources with EXPLICIT,
// hand-written uuids whose `id`-ASC order deliberately DIVERGES from their
// `created_at`-ASC order (source #1 has the lowest-sorting id but the
// LATEST created_at of the set) and runs BOTH query shapes, against real
// Postgres:
//
//   - the buggy shape (`->orderBy('created_at')->chunkById(...)`, reproduced
//     verbatim here since it no longer exists in the command) is asserted to
//     SKIP source #1 — this is executed and observed, not hypothesised;
//   - the fixed shape (`->chunkById(...)`, no leading `orderBy`) is asserted
//     to visit all 7 exactly once;
//   - the real `ingest:project` command (through Artisan, not a stand-in) is
//     separately asserted to process every seeded source exactly once, as
//     an end-to-end guard on top of the differential proof above.

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('DROP TABLE IF EXISTS ingest.record_state CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.streams CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.sources CASCADE');
    $pg->statement('DROP TABLE IF EXISTS core.users CASCADE');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS core');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS ingest');

    $pg->statement('CREATE TABLE core.users (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid()
    )');

    // Minimal stand-in of the real ingest.sources shape (20260727130000) —
    // same PK/UNIQUE/created_at semantics the chunkById walk depends on.
    $pg->statement('CREATE TABLE ingest.sources (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
        connection_id uuid,
        source_key text NOT NULL DEFAULT \'bandcamp\',
        surface_key text NOT NULL DEFAULT \'catalog\',
        identifier text NOT NULL,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT sources_unique_per_connection UNIQUE (connection_id, source_key)
    )');

    $pg->statement('CREATE TABLE ingest.streams (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        source_id uuid NOT NULL REFERENCES ingest.sources (id) ON DELETE CASCADE,
        stream_name text NOT NULL DEFAULT \'releases\',
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT streams_unique_per_source UNIQUE (source_id, stream_name)
    )');

    // --dry-run's live-record count touches only this table (plan §2e).
    $pg->statement('CREATE TABLE ingest.record_state (
        stream_id uuid NOT NULL REFERENCES ingest.streams (id) ON DELETE CASCADE,
        key text NOT NULL,
        tombstoned_at timestamptz,
        PRIMARY KEY (stream_id, key)
    )');
});

afterAll(function () {
    $pg = DB::connection('pgsql');
    foreach (['ingest.record_state', 'ingest.streams', 'ingest.sources', 'core.users'] as $t) {
        $pg->statement("DROP TABLE IF EXISTS {$t} CASCADE");
    }
});

// Explicit, hand-written uuids (NOT Str::uuid()/gen_random_uuid()) so that
// `id`-ASC order is deterministically 1,2,3,4,5,6,7 — and created_at is
// assigned so that #1 (the lowest-sorting id) carries the LATEST
// created_at, while #2..#7 carry successively earlier ones. That pairing
// means:
//   - id ASC order:         1, 2, 3, 4, 5, 6, 7
//   - created_at ASC order: 2, 3, 4, 5, 6, 7, 1   (1 sorts last)
// Under the buggy `ORDER BY created_at ASC, id ASC` with chunk size 3, the
// first page returns {2,3,4} (lowest created_at) and the keyset cursor
// advances to id 4 (the last row of that page). The next page's `WHERE id >
// 4` then permanently excludes id 1 — even though it has never been
// visited — because the cursor is an `id` comparison while the ordering
// that decided what's "already seen" was driven by `created_at`. Source #1
// is lost with no error.
const DIVERGENT_SOURCE_IDS = [
    '00000000-0000-0000-0000-000000000001',
    '00000000-0000-0000-0000-000000000002',
    '00000000-0000-0000-0000-000000000003',
    '00000000-0000-0000-0000-000000000004',
    '00000000-0000-0000-0000-000000000005',
    '00000000-0000-0000-0000-000000000006',
    '00000000-0000-0000-0000-000000000007',
];

/**
 * Seed 7 bandcamp sources (+1 stream each) for one user, using
 * DIVERGENT_SOURCE_IDS with created_at assigned so id-ASC order and
 * created_at-ASC order deliberately disagree (see const doc above).
 */
function seedDivergentSources(string $userId, Carbon $anchor): array
{
    $pg = DB::connection('pgsql');
    $sourceIds = DIVERGENT_SOURCE_IDS;

    // #1 (index 0) gets the LATEST created_at; #2..#7 get successively
    // earlier ones, each still after #1's would-be predecessor slot so the
    // divergence is unambiguous.
    $createdAt = [
        $sourceIds[0] => $anchor->copy(),
        $sourceIds[1] => $anchor->copy()->subDays(6),
        $sourceIds[2] => $anchor->copy()->subDays(5),
        $sourceIds[3] => $anchor->copy()->subDays(4),
        $sourceIds[4] => $anchor->copy()->subDays(3),
        $sourceIds[5] => $anchor->copy()->subDays(2),
        $sourceIds[6] => $anchor->copy()->subDays(1),
    ];

    foreach ($sourceIds as $i => $id) {
        $ts = $createdAt[$id];

        $pg->table('ingest.sources')->insert([
            'id' => $id,
            'user_id' => $userId,
            'connection_id' => (string) Str::uuid(),
            'source_key' => 'bandcamp',
            'surface_key' => 'catalog',
            'identifier' => "divergent-{$i}",
            'created_at' => $ts,
            'updated_at' => $ts,
        ]);

        $pg->table('ingest.streams')->insert([
            'id' => (string) Str::uuid(),
            'source_id' => $id,
            'stream_name' => 'releases',
            'created_at' => $ts,
            'updated_at' => $ts,
        ]);
    }

    return $sourceIds;
}

it('the buggy orderBy(created_at)+chunkById shape skips source #1 under the divergent fixture', function () {
    $userId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert(['id' => $userId]);

    $sourceIds = seedDivergentSources($userId, now());

    // Verbatim reproduction of the shape that used to ship: leading
    // orderBy('created_at') ahead of chunkById. The command itself no
    // longer contains this — it is rebuilt here so the regression it
    // caused can be executed and observed, not just asserted by narration.
    $visited = [];
    DB::table('ingest.sources')
        ->where('user_id', $userId)
        ->orderBy('created_at')
        ->chunkById(3, function ($chunk) use (&$visited): void {
            foreach ($chunk as $row) {
                $visited[] = $row->id;
            }
        });

    expect($visited)->toHaveCount(6);
    expect($visited)->not->toContain($sourceIds[0]);
    expect(array_unique($visited))->toHaveCount(6);
});

it('the fixed chunkById-only shape visits every divergent source exactly once', function () {
    $userId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert(['id' => $userId]);

    $sourceIds = seedDivergentSources($userId, now());

    // The shape the command now runs: chunkById with no leading orderBy.
    $visited = [];
    DB::table('ingest.sources')
        ->where('user_id', $userId)
        ->chunkById(3, function ($chunk) use (&$visited): void {
            foreach ($chunk as $row) {
                $visited[] = $row->id;
            }
        });

    expect($visited)->toEqualCanonicalizing($sourceIds);
    expect(array_unique($visited))->toHaveCount(7);
});

it('the real ingest:project command processes every divergent source exactly once', function () {
    config(['partna.ingest.projection_source_chunk' => 3]);

    $userId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert(['id' => $userId]);

    seedDivergentSources($userId, now());

    // The real command, through the real console entry point — proves the
    // fix as shipped, not a hand-rolled stand-in of it. --dry-run touches
    // only ingest.record_state (all zero live records here), so this stays
    // scoped to ingest.* per the plan.
    Artisan::call('ingest:project', ['--user' => $userId, '--dry-run' => true]);
    $output = Artisan::output();

    $lineCount = substr_count($output, 'would project: bandcamp/releases');

    // Exactly 7: not 6 (a silent skip) and not 8 (a duplicate page overlap).
    expect($lineCount)->toBe(7);
});
