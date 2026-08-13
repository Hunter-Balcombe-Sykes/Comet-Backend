<?php

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
