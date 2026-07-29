<?php

// DINT-4 regression sentinel. site.section_items.item_id pointed at
// content.items with nothing enforcing it
// (supabase/migrations/20260727150000_sections_and_documents.sql:70-79) —
// the app/Models/Core/Site/SectionItem.php:22 docblock's stated reason ("no
// FK: content lives in another schema") is not a real Postgres limitation.
// This test binds the REAL migration file (20260729150007), not a hand-copy.
//
// Property (c) proves the two merge paths that already satisfy this FK by
// construction keep working under it: ItemMerger::foldInto()
// (app/Services/Content/ItemMerger.php:258-265) repoints site.section_items
// onto the survivor BEFORE the discarded item is deleted at
// ItemMerger.php:76 — simulated here with the same statement shapes rather
// than invoking the service, since only the FK's tolerance of that exact
// repoint-then-delete order is under test.

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS content');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS site');
    $pg->statement('DROP TABLE IF EXISTS site.section_items CASCADE');
    $pg->statement('DROP TABLE IF EXISTS content.items CASCADE');

    $pg->statement('CREATE TABLE content.items (
        id   uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        kind text NOT NULL DEFAULT \'release\'
    )');

    $pg->statement('CREATE TABLE site.section_items (
        id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        section_id uuid NOT NULL DEFAULT gen_random_uuid(),
        item_id    uuid NOT NULL,
        state      text NOT NULL DEFAULT \'pinned\'
    )');

    applySectionItemsFkMigration('20260729150007_section_items_item_fk_not_valid.sql');
});

/** Strip BEGIN/COMMIT/SET LOCAL/comments and run the remaining statements verbatim. */
function applySectionItemsFkMigration(string $filename): void
{
    $sql = (string) file_get_contents(base_path("supabase/migrations/{$filename}"));
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

function createItem(): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('content.items')->insert(['id' => $id]);

    return $id;
}

it('a: rejects a section_items row naming an unknown item_id', function () {
    $thrown = null;
    try {
        DB::connection('pgsql')->table('site.section_items')->insert([
            'item_id' => (string) Str::uuid(),
        ]);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getCode())->toBe('23503');
});

it('b: deleting the parent content.items row cascades the curation row away', function () {
    $itemId = createItem();
    DB::connection('pgsql')->table('site.section_items')->insert(['item_id' => $itemId]);

    expect(DB::connection('pgsql')->table('site.section_items')->where('item_id', $itemId)->count())->toBe(1);

    DB::connection('pgsql')->table('content.items')->where('id', $itemId)->delete();

    expect(DB::connection('pgsql')->table('site.section_items')->where('item_id', $itemId)->count())->toBe(0);
});

it('c: the merge ordering (repoint then delete) leaves the survivor\'s pin intact', function () {
    // Mirrors ItemMerger::merge(): foldInto() repoints section_items onto the
    // kept item BEFORE the discarded item is hard-deleted.
    $kept = createItem();
    $discarded = createItem();

    $pinId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.section_items')->insert([
        'id' => $pinId,
        'item_id' => $discarded,
    ]);

    // ItemMerger::foldInto() step: repoint onto the survivor.
    DB::connection('pgsql')->table('site.section_items')->where('item_id', $discarded)->update(['item_id' => $kept]);

    // ItemMerger::merge() step: hard-delete the discarded item, same transaction.
    DB::connection('pgsql')->table('content.items')->where('id', $discarded)->delete();

    $pin = DB::connection('pgsql')->table('site.section_items')->where('id', $pinId)->first();

    expect($pin)->not->toBeNull();
    expect($pin->item_id)->toBe($kept);
});
