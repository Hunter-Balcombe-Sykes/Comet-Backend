<?php

// DINT-3's sentinel. The audit's proposed ON DELETE SET NULL FK on
// content.item_merges.{kept,discarded}_item_id was REJECTED
// (20260729150019_item_merges_no_fk_rationale.sql) because
// ItemMerger::merge() inserts the item_merges row
// (app/Services/Content/ItemMerger.php:58-68) and then, IN THE SAME
// TRANSACTION, hard-deletes the discarded item at ItemMerger.php:76 — a
// SET NULL FK would null discarded_item_id on the row just written, for
// EVERY user-driven merge.
//
// This test simulates that exact sequence directly against a MINIMAL,
// FK-FREE content.item_merges/content.items pair (per the plan's decision,
// no migration adds an FK here — there is nothing to apply). If anyone ever
// adds the FK the finding proposed, this is the test that catches it: the
// row would come back with discarded_item_id NULL instead of intact.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS content');
    $pg->statement('DROP TABLE IF EXISTS content.item_merges CASCADE');
    $pg->statement('DROP TABLE IF EXISTS content.items CASCADE');

    $pg->statement('CREATE TABLE content.items (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid()
    )');

    // Deliberately NO FK on kept_item_id / discarded_item_id — that is the
    // point under test (DINT-3).
    $pg->statement('CREATE TABLE content.item_merges (
        id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id            uuid NOT NULL DEFAULT gen_random_uuid(),
        kept_item_id       uuid NOT NULL,
        discarded_item_id  uuid NOT NULL,
        reason             text NOT NULL DEFAULT \'user ruling\',
        merged_at          timestamptz NOT NULL DEFAULT now()
    )');
});

function createMergeItem(): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('content.items')->insert(['id' => $id]);

    return $id;
}

it('the ledger row survives with both ids non-null after the discarded item is hard-deleted in the same transaction', function () {
    $kept = createMergeItem();
    $discarded = createMergeItem();

    DB::connection('pgsql')->transaction(function () use ($kept, $discarded) {
        // Mirrors ItemMerger::merge(): insert the ledger row, then hard-delete
        // the discarded item, in the SAME transaction.
        DB::connection('pgsql')->table('content.item_merges')->insert([
            'kept_item_id' => $kept,
            'discarded_item_id' => $discarded,
        ]);

        DB::connection('pgsql')->table('content.items')->where('id', $discarded)->delete();
    });

    $row = DB::connection('pgsql')->table('content.item_merges')
        ->where('kept_item_id', $kept)
        ->where('discarded_item_id', $discarded)
        ->first();

    expect($row)->not->toBeNull();
    expect($row->kept_item_id)->toBe($kept);
    expect($row->discarded_item_id)->toBe($discarded);

    // The discarded item is genuinely gone — the ledger outlives it.
    expect(DB::connection('pgsql')->table('content.items')->where('id', $discarded)->exists())->toBeFalse();
});

it('a later merge that discards a previously-kept item leaves the older ledger entry intact too', function () {
    // kept_item_id is not exempt either (plan §DINT-3): a second merge that
    // discards a previously-kept item must not disturb the earlier row.
    $a = createMergeItem();
    $b = createMergeItem();
    $c = createMergeItem();

    DB::connection('pgsql')->transaction(function () use ($a, $b) {
        DB::connection('pgsql')->table('content.item_merges')->insert([
            'kept_item_id' => $a,
            'discarded_item_id' => $b,
        ]);
        DB::connection('pgsql')->table('content.items')->where('id', $b)->delete();
    });

    // Now A is discarded in favour of C — a totally separate merge.
    DB::connection('pgsql')->transaction(function () use ($c, $a) {
        DB::connection('pgsql')->table('content.item_merges')->insert([
            'kept_item_id' => $c,
            'discarded_item_id' => $a,
        ]);
        DB::connection('pgsql')->table('content.items')->where('id', $a)->delete();
    });

    $firstEntry = DB::connection('pgsql')->table('content.item_merges')
        ->where('kept_item_id', $a)
        ->where('discarded_item_id', $b)
        ->first();

    expect($firstEntry)->not->toBeNull();
    expect($firstEntry->kept_item_id)->toBe($a);
    expect($firstEntry->discarded_item_id)->toBe($b);
});
