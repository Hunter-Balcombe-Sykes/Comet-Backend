<?php

// #PRIV-3: regression sentinel for the two content.f_review indexes
// (supabase/migrations/20260729160000_f_review_updated_at_idx.sql,
// .../20260729160001_f_review_author_lookup_idx.sql) that
// content:prune-orphaned-review-pii's cutoff scan and a reviewer's own
// APP 12/13 lookup depend on. Each CREATE INDEX CONCURRENTLY statement is
// read OFF DISK — never retyped — so this test binds the actual migration
// text rather than a hand-copied stand-in that could silently drift from it.
// CONCURRENTLY, and "is this index actually CHOSEN by the planner", can only
// be proven for real against Postgres (see Tests\PostgresTestCase for the
// skip-without-Postgres guard). Runs via `composer test:pg` / phpunit.pg.xml.
//
// Minimal self-provisioned schema (like tests/Postgres/ItemSlugAllocator*
// Test.php): only content.f_review / content.source_items, not the full 33-table
// content schema — those are the two tables the indexes and the orphan
// predicate touch.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

/** Reads a migration file's CREATE INDEX statement verbatim, comments included — never retyped. */
function readIndexMigrationFile(string $filename): string
{
    $path = base_path("supabase/migrations/{$filename}");
    $sql = trim((string) file_get_contents($path));

    expect($sql)->not->toBe('', "{$path} could not be read or is empty.");

    return $sql;
}

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS content');
    $pg->statement('DROP TABLE IF EXISTS content.f_review');
    $pg->statement('DROP TABLE IF EXISTS content.source_items');

    // Real column shapes from 20260727140000_content_schema.sql:307-317
    // (f_review) and :71-84 (source_items) — narrowed to what the indexes
    // and the orphan predicate actually touch.
    $pg->statement('CREATE TABLE content.f_review (
        item_id uuid NOT NULL,
        source_id uuid NOT NULL,
        author_name text,
        author_photo_url text,
        -- author_uri (20260813110000) is reached by neither index nor the orphan
        -- predicate, but it IS reviewer PII deleted by the same row delete this
        -- file is the sentinel for — a retention sentinel blind to a PII column
        -- on its own table is the drift it exists to catch.
        author_uri text,
        rating double precision,
        text text,
        reviewed_at timestamptz,
        updated_at timestamptz NOT NULL DEFAULT now(),
        PRIMARY KEY (item_id, source_id)
    )');

    $pg->statement('CREATE TABLE content.source_items (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        source_id uuid NOT NULL,
        item_id uuid,
        removed_at timestamptz
    )');

    // The migrations THEMSELVES, verbatim off disk — CONCURRENTLY, one
    // statement per file, no transaction wrapper (PostgresTestCase does not
    // wrap test bodies in a transaction for exactly this reason).
    $pg->statement(readIndexMigrationFile('20260729160000_f_review_updated_at_idx.sql'));
    $pg->statement(readIndexMigrationFile('20260729160001_f_review_author_lookup_idx.sql'));
});

it('creates idx_f_review_updated_at', function () {
    $row = DB::connection('pgsql')->selectOne(
        "select indexname from pg_indexes where schemaname = 'content' and tablename = 'f_review' and indexname = 'idx_f_review_updated_at'"
    );

    expect($row)->not->toBeNull();
});

it('creates idx_f_review_author_lookup as a PARTIAL index on lower(author_name)', function () {
    $row = DB::connection('pgsql')->selectOne(
        "select indexdef from pg_indexes where schemaname = 'content' and tablename = 'f_review' and indexname = 'idx_f_review_author_lookup'"
    );

    expect($row)->not->toBeNull()
        ->and($row->indexdef)->toContain('WHERE')
        ->and(strtolower($row->indexdef))->toContain('lower(author_name)');
});

it('idx_f_review_author_lookup is CHOSEN by the planner for a lower(author_name) lookup — an unused index answers no DSAR', function () {
    $pg = DB::connection('pgsql');

    $rows = [];
    for ($i = 0; $i < 3000; $i++) {
        $rows[] = [
            'item_id' => (string) Str::uuid(),
            'source_id' => (string) Str::uuid(),
            'author_name' => 'reviewer-'.$i,
            'updated_at' => now(),
        ];
    }
    $rows[] = [
        'item_id' => (string) Str::uuid(),
        'source_id' => (string) Str::uuid(),
        'author_name' => 'Jane DSAR Target',
        'updated_at' => now(),
    ];
    foreach (array_chunk($rows, 500) as $chunk) {
        $pg->table('content.f_review')->insert($chunk);
    }
    $pg->statement('ANALYZE content.f_review');

    $plan = $pg->select("EXPLAIN SELECT * FROM content.f_review WHERE lower(author_name) = lower('Jane DSAR Target')");
    $planText = implode("\n", array_map(static fn ($r) => $r->{'QUERY PLAN'}, $plan));

    expect($planText)->not->toContain('Seq Scan')
        ->and($planText)->toContain('idx_f_review_author_lookup');
});

it('the orphan predicate (whereNotExists over content.source_items) returns the same row set on Postgres as the SQLite lane', function () {
    // Mirrors tests/Feature/Content/PruneOrphanedReviewPiiTest.php's fixture
    // shape exactly: an orphaned review (its only source_item is tombstoned)
    // past the cutoff, and a live review (source_item.removed_at IS NULL)
    // that is just as old — proving the predicate isn't accidentally a TTL.
    $pg = DB::connection('pgsql');

    $orphanItem = (string) Str::uuid();
    $orphanSource = (string) Str::uuid();
    $liveItem = (string) Str::uuid();
    $liveSource = (string) Str::uuid();
    $old = now()->subDays(30);

    $pg->table('content.f_review')->insert([
        ['item_id' => $orphanItem, 'source_id' => $orphanSource, 'author_name' => 'Orphan', 'updated_at' => $old],
        ['item_id' => $liveItem, 'source_id' => $liveSource, 'author_name' => 'Live', 'updated_at' => $old],
    ]);
    $pg->table('content.source_items')->insert([
        ['source_id' => $orphanSource, 'item_id' => $orphanItem, 'removed_at' => now()->subDays(25)],
        ['source_id' => $liveSource, 'item_id' => $liveItem, 'removed_at' => null],
    ]);

    $cutoff = now()->subDays(14);

    // Same predicate shape as PruneOrphanedReviewPiiCommand::handle().
    $eligible = $pg->table('content.f_review')
        ->where('content.f_review.updated_at', '<', $cutoff)
        ->whereNotExists(fn ($q) => $q
            ->select(DB::raw(1))
            ->from('content.source_items')
            ->whereColumn('content.source_items.item_id', 'content.f_review.item_id')
            ->whereColumn('content.source_items.source_id', 'content.f_review.source_id')
            ->whereNull('content.source_items.removed_at'))
        ->pluck('author_name')
        ->all();

    // Identical outcome to the SQLite-lane fixture: only the orphan is
    // eligible; the live row survives no matter how old.
    expect($eligible)->toBe(['Orphan']);
});
