<?php

use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// #SCALE-5 — the singleton-facet upserts used to fire one statement per facet
// per record, every run. The file's own comment conceded it: "the
// singleton-facet upserts below stay per (item, record)".
//
// Batching them is not a flat "collect and write once". Three properties have to
// survive, and each is asserted below rather than argued:
//
//   1. Rows carrying DIFFERENT columns must not be unioned into one wide row —
//      Laravel's upsert() takes its column list from the FIRST row, so a
//      null-filled union would put columns a record never mentioned into the
//      update list and NULL them on conflict.
//   2. Two records for the SAME (item, source) must fold PER COLUMN, later
//      values winning — which is exactly what the sequential upserts produced.
//   3. …and must not reach the database as two rows with one conflict target,
//      which raises Postgres 21000. That one is pinned in
//      tests/Postgres/ProjectionWriterBatchingTest.php; SQLite cannot see it.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
});

/** @return array{0: string, 1: string} userId + a connection-backed content source id */
function facetBatchFixture(): array
{
    $userId = createTenant('fb-'.Str::lower(Str::random(6)))->id;
    $sourceId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'kind' => 'connection',
        'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$userId, $sourceId];
}

function facetBatchItem(string $userId, string $kind = 'video'): string
{
    $id = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $id, 'user_id' => $userId, 'kind' => $kind,
        'headline_cache' => null, 'facets_cache' => '[]', 'eligible_cache' => '[]',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

/** Drive writeFacets() directly — the batching seam, without a whole ingest run around it. */
function writeFacetsFor(string $sourceId, string $userId, array $projections, array $itemByCoord): void
{
    $m = new ReflectionMethod(app(ProjectionWriter::class), 'writeFacets');
    $m->setAccessible(true);
    $m->invoke(app(ProjectionWriter::class), $sourceId, $userId, $projections, $itemByCoord);
}

it('writes different items in ONE statement per facet instead of one per item', function () {
    [$userId, $sourceId] = facetBatchFixture();

    $projections = [];
    $itemByCoord = [];
    foreach (range(1, 12) as $n) {
        $coord = "c:{$n}";
        $itemByCoord[$coord] = facetBatchItem($userId);
        $projections[$coord] = ['headline' => "Item {$n}", 'facets' => ['f_link' => ['url' => "https://example.com/{$n}"]]];
    }

    $statements = 0;
    DB::listen(function ($q) use (&$statements) {
        if (str_contains($q->sql, 'content"."f_link') || str_contains($q->sql, 'content.f_link')) {
            $statements++;
        }
    });

    writeFacetsFor($sourceId, $userId, $projections, $itemByCoord);

    // 12 items, same column shape — one statement. Before #SCALE-5 this was 12.
    expect($statements)->toBe(1);
    expect(DB::table('content.f_link')->where('source_id', $sourceId)->count())->toBe(12);
});

it('does not let a record that omits a column blank it for a record that supplied one', function () {
    [$userId, $sourceId] = facetBatchFixture();
    $rich = facetBatchItem($userId);
    $sparse = facetBatchItem($userId);

    // Two DIFFERENT column shapes for the same facet in one batch. Unioning
    // them and null-filling would write body = NULL for the sparse row — and,
    // worse, put `body` in the update list so it would clobber on conflict.
    writeFacetsFor($sourceId, $userId, [
        'c:rich' => ['facets' => ['f_text' => ['headline' => 'Rich', 'body' => 'Has a body']]],
        'c:sparse' => ['facets' => ['f_text' => ['headline' => 'Sparse']]],
    ], ['c:rich' => $rich, 'c:sparse' => $sparse]);

    expect(DB::table('content.f_text')->where('item_id', $rich)->value('body'))->toBe('Has a body');
    expect(DB::table('content.f_text')->where('item_id', $rich)->value('headline'))->toBe('Rich');
    expect(DB::table('content.f_text')->where('item_id', $sparse)->value('headline'))->toBe('Sparse');
    expect(DB::table('content.f_text')->where('item_id', $sparse)->value('body'))->toBeNull();
});

it('folds two records for one (item, source) PER COLUMN, later values winning', function () {
    [$userId, $sourceId] = facetBatchFixture();
    $item = facetBatchItem($userId);

    // A same-source merge: two records land on one item — the case writeFacets'
    // own comment calls out. Sequentially the second upsert overwrote only the
    // columns IT named, so the stored row was a per-column union. A
    // last-row-wins de-dup would drop `body` here.
    writeFacetsFor($sourceId, $userId, [
        'c:first' => ['facets' => ['f_text' => ['headline' => 'First', 'body' => 'Only the first has this']]],
        'c:second' => ['facets' => ['f_text' => ['headline' => 'Second']]],
    ], ['c:first' => $item, 'c:second' => $item]);

    $row = DB::table('content.f_text')->where('item_id', $item)->where('source_id', $sourceId)->first();

    expect($row->headline)->toBe('Second')                       // later wins
        ->and($row->body)->toBe('Only the first has this');      // earlier survives

    // And exactly ONE row — not two, and not a duplicate-key explosion.
    expect(DB::table('content.f_text')->where('item_id', $item)->count())->toBe(1);
});

it('still applies the URL denylist and the column allowlist through the batched path', function () {
    [$userId, $sourceId] = facetBatchFixture();
    $item = facetBatchItem($userId, 'review');

    writeFacetsFor($sourceId, $userId, [
        'c:1' => ['facets' => ['f_review' => [
            'author_name' => 'Jane',
            'author_photo_url' => 'https://lh3.googleusercontent.com/a/photo.jpg?sz=128&sig=deadbeef',
            'not_a_real_column' => 'should be dropped',
        ]]],
    ], ['c:1' => $item]);

    $row = DB::table('content.f_review')->where('item_id', $item)->first();

    // The security-relevant halves survive the refactor: minimisation…
    expect($row->author_photo_url)->toBe('https://lh3.googleusercontent.com/a/photo.jpg?sz=128&sig=[redacted]');
    // …and the allowlist (an unknown column must not reach the insert at all,
    // which on SQLite would be a hard error rather than a silent drop).
    expect($row->author_name)->toBe('Jane');
});

it('leaves an unknown facet alone rather than attempting a table that does not exist', function () {
    [$userId, $sourceId] = facetBatchFixture();
    $item = facetBatchItem($userId);

    writeFacetsFor($sourceId, $userId, [
        'c:1' => ['facets' => ['f_not_a_facet' => ['whatever' => 1]]],
    ], ['c:1' => $item]);

    expect(true)->toBeTrue(); // reaching here without a QueryException is the assertion
});
