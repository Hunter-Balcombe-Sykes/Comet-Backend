<?php

use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

// The 42702 shape slice 5a shipped: a bare column in ON CONFLICT DO UPDATE is
// ambiguous on Postgres and fine on SQLite. This test only means anything on
// the real driver.

beforeEach(function () {
    $pg = DB::connection('pgsql');
    $pg->beginTransaction();

    $pg->statement('CREATE SCHEMA IF NOT EXISTS core');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS content');
    $pg->statement('DROP TABLE IF EXISTS content.collections CASCADE');
    $pg->statement('DROP TABLE IF EXISTS core.users CASCADE');

    $pg->statement('CREATE TABLE core.users (id uuid PRIMARY KEY DEFAULT gen_random_uuid())');

    // Faithful to supabase/migrations/20260727140000_content_schema.sql's
    // content.collections plus this slice's external_ref/removed_at.
    $pg->statement('CREATE TABLE content.collections (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL REFERENCES core.users(id) ON DELETE CASCADE,
        parent_id uuid REFERENCES content.collections(id) ON DELETE CASCADE,
        label text NOT NULL,
        kind text,
        external_ref text,
        removed_at timestamptz,
        position integer NOT NULL DEFAULT 0,
        is_user_created boolean NOT NULL DEFAULT false,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )');
    $pg->statement('CREATE UNIQUE INDEX collections_user_kind_external_ref_uq
        ON content.collections (user_id, kind, external_ref)');
});

afterEach(function () {
    DB::connection('pgsql')->rollBack();
});

function collectionsUpsertConflictTestUser(): string
{
    $userId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.users')->insert(['id' => $userId]);

    return $userId;
}

it('upserts a collection twice on its natural key without duplicating', function () {
    $userId = collectionsUpsertConflictTestUser();
    $row = fn (string $label) => [
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'parent_id' => null,
        'label' => $label,
        'kind' => 'service_category',
        'external_ref' => '3282965',
        'position' => 0,
        'is_user_created' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::connection('pgsql')->table('content.collections')->upsert(
        [$row('Haircuts')],
        ['user_id', 'kind', 'external_ref'],
        ['label', 'position', 'updated_at'],
    );
    DB::connection('pgsql')->table('content.collections')->upsert(
        [$row('Haircuts & Styling')],
        ['user_id', 'kind', 'external_ref'],
        ['label', 'position', 'updated_at'],
    );

    $rows = DB::connection('pgsql')->table('content.collections')
        ->where('user_id', $userId)->where('kind', 'service_category')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->label)->toBe('Haircuts & Styling');
});

// Slice 3b Task 5. The two above copy the statement shape by hand; a copy can
// drift from the method it stands in for, which is the same trap CLAUDE.md
// flags for this lane's stand-in DDL. These drive
// ProjectionWriter::upsertCollections() ITSELF against real Postgres, so a
// bare column reintroduced into its ON CONFLICT DO UPDATE surfaces here as
// SQLSTATE 42702 rather than passing on SQLite forever. The default
// connection under phpunit.pg.xml IS pgsql, so the method's DB::table() calls
// land on the same database this file provisions.
//
// @param array<string, list<array<string, mixed>>> $byItem
function upsertCollectionsVia(string $userId, array $byItem): array
{
    $writer = app(ProjectionWriter::class);
    $method = new ReflectionMethod($writer, 'upsertCollections');
    $method->setAccessible(true);

    return $method->invoke($writer, $userId, $byItem);
}

it('runs the real upsertCollections() on Postgres and follows a rename in place', function () {
    $userId = collectionsUpsertConflictTestUser();
    $entry = fn (string $label) => ['item-1' => [
        ['external_ref' => '3282965', 'label' => $label, 'kind' => 'service_category', 'position' => 0],
    ]];

    $first = upsertCollectionsVia($userId, $entry('Haircuts'));
    $second = upsertCollectionsVia($userId, $entry('Haircuts & Styling'));

    $rows = DB::connection('pgsql')->table('content.collections')->where('user_id', $userId)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->label)->toBe('Haircuts & Styling')
        // Same id both runs: the natural key reconciled, it did not re-mint.
        ->and($second)->toBe($first)
        ->and(array_values($second))->toBe([(string) $rows->first()->id]);
});

// removed_at is the owner's word, one-way, exactly like content.items.removed_at.
// It is absent from both the insert and the update list, so a re-scrape that
// re-lists the category must not resurrect it. Nulling it here would leave the
// owner's deletion silently undone on the next connector run.
it('leaves removed_at untouched when the real upsertCollections() re-lists a deleted collection', function () {
    $userId = collectionsUpsertConflictTestUser();
    $byItem = ['item-1' => [
        ['external_ref' => '3282965', 'label' => 'Haircuts', 'kind' => 'service_category', 'position' => 0],
    ]];

    upsertCollectionsVia($userId, $byItem);
    DB::connection('pgsql')->table('content.collections')
        ->where('user_id', $userId)->update(['removed_at' => now()]);

    upsertCollectionsVia($userId, $byItem);

    expect(DB::connection('pgsql')->table('content.collections')->where('user_id', $userId)->count())->toBe(1)
        ->and(DB::connection('pgsql')->table('content.collections')->where('user_id', $userId)->value('removed_at'))
        ->not->toBeNull();
});

// Finding 1 (fix round 1). position is INSERT-ONLY: the vendor seeds an order,
// the owner owns it afterwards. Task 9's ServiceCollections::reposition() does
// not filter is_user_created, so a position left in the update list would undo
// an owner's reorder on the next scheduled run. This runs on Postgres because
// that is where the DO UPDATE branch actually has a conflict target to fire
// against — the SQLite lane cannot settle what the ON CONFLICT clause does.
it('leaves position untouched when the real upsertCollections() re-lists a reordered collection', function () {
    $userId = collectionsUpsertConflictTestUser();
    $at = fn (int $position) => ['item-1' => [
        ['external_ref' => '3282965', 'label' => 'Haircuts', 'kind' => 'service_category', 'position' => $position],
    ]];

    upsertCollectionsVia($userId, $at(0));
    $collections = fn () => DB::connection('pgsql')->table('content.collections')->where('user_id', $userId);
    expect($collections()->value('position'))->toBe(0);

    $collections()->update(['position' => 7]);
    upsertCollectionsVia($userId, $at(3));

    expect($collections()->value('position'))->toBe(7)
        // The rename path is deliberately NOT owner-owned — only position is.
        ->and($collections()->value('label'))->toBe('Haircuts')
        ->and($collections()->count())->toBe(1);
});

// A machine-derived collection with no external_ref has no natural key, so it
// would insert a fresh row on every run. It is dropped instead of guessed at.
it('skips an entry with no external_ref rather than minting a keyless row', function () {
    $userId = collectionsUpsertConflictTestUser();

    $ids = upsertCollectionsVia($userId, ['item-1' => [
        ['external_ref' => null, 'label' => 'Unkeyed', 'kind' => 'service_category', 'position' => 0],
        ['external_ref' => '77', 'label' => '', 'kind' => 'service_category', 'position' => 0],
    ]]);

    expect($ids)->toBe([])
        ->and(DB::connection('pgsql')->table('content.collections')->where('user_id', $userId)->count())->toBe(0);
});

it('allows many user-created collections with a null external_ref', function () {
    $userId = collectionsUpsertConflictTestUser();
    foreach (['Cuts', 'Colour'] as $i => $label) {
        DB::connection('pgsql')->table('content.collections')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $userId, 'parent_id' => null,
            'label' => $label, 'kind' => 'service_category', 'external_ref' => null,
            'position' => $i, 'is_user_created' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(DB::connection('pgsql')->table('content.collections')->where('user_id', $userId)->count())->toBe(2);
});
