<?php

// Characterization sentinel for the P8 blocker B5 backfill
// (supabase/migrations/20260728120000_backfill_item_tombstones.sql).
//
// This runs the migration's REAL SQL — read off disk, not retyped — because a
// hand-copied assertion drifts from the file the moment someone edits it, and
// the whole point of the backfill is that its exclusions are argued rather
// than obvious. Every exclusion in that file gets a fixture here; deleting one
// from the SQL fails a test that names why it existed.
//
// Self-provisioned schema like the rest of tests/Postgres/ — tables copied
// structurally from the baseline / routing schema minus RLS, grants and the
// generated `platform` column (none of which is what this guards).

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

const BACKFILL_SQL_PATH = 'supabase/migrations/20260728120000_backfill_item_tombstones.sql';

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS core');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS site');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS routing');
    $pg->statement('DROP TABLE IF EXISTS routing.item_tombstones CASCADE');
    $pg->statement('DROP TABLE IF EXISTS site.platform_connections CASCADE');
    $pg->statement('DROP TABLE IF EXISTS core.users CASCADE');

    $pg->statement("CREATE TABLE core.users (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        status text NOT NULL DEFAULT 'active'
    )");

    $pg->statement('CREATE TABLE site.platform_connections (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
        surface_key text NOT NULL,
        resource_id text NOT NULL,
        deleted_at timestamptz
    )');
    $pg->statement("ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS visibility text NOT NULL DEFAULT 'visible'");

    $pg->statement("CREATE TABLE routing.item_tombstones (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
        source_ref text NOT NULL,
        scope text NOT NULL DEFAULT 'this_source',
        reason text,
        created_at timestamptz NOT NULL DEFAULT now()
    )");
    $pg->statement('CREATE UNIQUE INDEX idx_item_tombstones_unique
        ON routing.item_tombstones (user_id, source_ref, scope)');
});

afterAll(function () {
    $pg = DB::connection('pgsql');
    foreach (['routing.item_tombstones', 'site.platform_connections', 'core.users'] as $t) {
        $pg->statement("DROP TABLE IF EXISTS {$t} CASCADE");
    }
});

function backfillUser(string $status = 'active'): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert(['id' => $id, 'status' => $status]);

    return $id;
}

function backfillConnection(string $userId, string $surfaceKey, string $resourceId, ?string $deletedAt): void
{
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'surface_key' => $surfaceKey,
        'resource_id' => $resourceId,
        'deleted_at' => $deletedAt,
    ]);
}

function runBackfill(): void
{
    DB::connection('pgsql')->statement(file_get_contents(base_path(BACKFILL_SQL_PATH)));
}

/** @return list<string> */
function tombstoneRefs(string $userId): array
{
    return DB::connection('pgsql')->table('routing.item_tombstones')
        ->where('user_id', $userId)
        ->orderBy('source_ref')
        ->pluck('source_ref')
        ->all();
}

it('records a refusal for every connection the user soft-deleted', function () {
    $user = backfillUser();
    backfillConnection($user, 'instagram.profile', 'theartist', '2026-07-10 09:00:00+00');
    backfillConnection($user, 'spotify.player', '3TVXtAsR1Inumwj472S9r4', '2026-07-11 09:00:00+00');

    runBackfill();

    expect(tombstoneRefs($user))->toBe([
        'instagram.profile:theartist',
        'spotify.player:3TVXtAsR1Inumwj472S9r4',
    ]);
});

it('writes the ref shape PlacementPolicy actually reads', function () {
    // PlacementPolicy::isTombstoned builds [surface_key, "surface_key:identifier"]
    // and matches source_ref against that. A ref in any other shape is a row
    // that can never fire.
    $user = backfillUser();
    backfillConnection($user, 'instagram.profile', 'theartist', '2026-07-10 09:00:00+00');

    runBackfill();

    $row = DB::connection('pgsql')->table('routing.item_tombstones')->where('user_id', $user)->first();
    expect($row->source_ref)->toBe('instagram.profile:theartist')
        ->and($row->scope)->toBe('this_source');
});

it('dates the refusal when the user made it, not when the backfill ran', function () {
    // created_at is the only surviving record of when the user said no — the
    // connection row that carried deleted_at is purged 30 days later.
    $user = backfillUser();
    backfillConnection($user, 'instagram.profile', 'theartist', '2026-07-10 09:00:00+00');

    runBackfill();

    $created = DB::connection('pgsql')->table('routing.item_tombstones')->where('user_id', $user)->value('created_at');
    expect(substr((string) $created, 0, 10))->toBe('2026-07-10');
});

it('leaves a deleted-then-re-added connection alone', function () {
    // Exclusion 1. A user who deleted an account and put it back did not
    // refuse it; tombstoning would strand the live row they currently have.
    $user = backfillUser();
    backfillConnection($user, 'instagram.profile', 'theartist', '2026-07-10 09:00:00+00');
    backfillConnection($user, 'instagram.profile', 'theartist', null);

    runBackfill();

    expect(tombstoneRefs($user))->toBe([]);
});

it('does not record Pinterest as a refusal the user never made', function () {
    // Exclusion 2. 20260728100000 soft-deleted every Pinterest row as an owner
    // retirement decision. The row looks identical to a user delete.
    $user = backfillUser();
    backfillConnection($user, 'pinterest.profile', 'theartist', '2026-07-28 00:00:00+00');
    backfillConnection($user, 'instagram.profile', 'theartist', '2026-07-28 00:00:00+00');

    runBackfill();

    expect(tombstoneRefs($user))->toBe(['instagram.profile:theartist']);
});

it('does not record refusals for a provisional build nobody has claimed', function () {
    // Exclusion 3. An unclaimed user has no session, so every soft-delete on
    // their rows came from PruneExpiredPreAccountBuilds' teardown.
    $unclaimed = backfillUser('unclaimed');
    backfillConnection($unclaimed, 'instagram.profile', 'theartist', '2026-07-10 09:00:00+00');

    runBackfill();

    expect(tombstoneRefs($unclaimed))->toBe([]);
});

it('collapses repeated deletions of the same account into one refusal', function () {
    // Connect, delete, connect, delete leaves two soft-deleted rows for one
    // ref. The unique index would reject the second; GROUP BY means we never
    // offer it.
    $user = backfillUser();
    backfillConnection($user, 'instagram.profile', 'theartist', '2026-07-10 09:00:00+00');
    backfillConnection($user, 'instagram.profile', 'theartist', '2026-07-20 09:00:00+00');

    runBackfill();

    $rows = DB::connection('pgsql')->table('routing.item_tombstones')->where('user_id', $user)->get();
    expect($rows)->toHaveCount(1)
        // The LATEST refusal is the current one.
        ->and(substr((string) $rows[0]->created_at, 0, 10))->toBe('2026-07-20');
});

it('never touches a refusal the suggestions inbox already recorded', function () {
    // The inbox writes its own reason; re-running the backfill must not
    // rewrite it into a "legacy" one.
    $user = backfillUser();
    backfillConnection($user, 'instagram.profile', 'theartist', '2026-07-10 09:00:00+00');
    DB::connection('pgsql')->table('routing.item_tombstones')->insert([
        'user_id' => $user,
        'source_ref' => 'instagram.profile:theartist',
        'scope' => 'this_source',
        'reason' => 'suggestion dismissed',
        'created_at' => '2026-07-01 09:00:00+00',
    ]);

    runBackfill();

    $rows = DB::connection('pgsql')->table('routing.item_tombstones')->where('user_id', $user)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->reason)->toBe('suggestion dismissed');
});

it('is safe to apply twice', function () {
    // Re-running a migration is a real operational event (a repaired history,
    // a re-applied dump). It must be a no-op, not a unique violation.
    $user = backfillUser();
    backfillConnection($user, 'instagram.profile', 'theartist', '2026-07-10 09:00:00+00');

    runBackfill();
    runBackfill();

    expect(DB::connection('pgsql')->table('routing.item_tombstones')->where('user_id', $user)->count())->toBe(1);
});

it('is a no-op on a cold database', function () {
    // From-zero applies run this against empty tables. It must not error, and
    // it must not invent rows.
    runBackfill();

    expect(DB::connection('pgsql')->table('routing.item_tombstones')->count())->toBe(0);
});

it('ignores connections the user still has', function () {
    $user = backfillUser();
    backfillConnection($user, 'instagram.profile', 'theartist', null);

    runBackfill();

    expect(tombstoneRefs($user))->toBe([]);
});

it('keeps one user\'s refusals off another user\'s account', function () {
    $refuser = backfillUser();
    $keeper = backfillUser();
    backfillConnection($refuser, 'instagram.profile', 'theartist', '2026-07-10 09:00:00+00');
    backfillConnection($keeper, 'instagram.profile', 'theartist', null);

    runBackfill();

    expect(tombstoneRefs($refuser))->toBe(['instagram.profile:theartist'])
        ->and(tombstoneRefs($keeper))->toBe([]);
});
