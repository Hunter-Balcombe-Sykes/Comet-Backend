<?php

// SCHEMA-1 / DINT-5 regression sentinel. content.source_items.kind is the
// INGRESS for every projected record (ProjectionWriter::upsertSourceItem,
// app/Ingest/Projection/ProjectionWriter.php:211,229); the value is copied
// verbatim into content.items.kind by createItem(). The two domains MUST stay
// set-equal — a superset on one side re-opens exactly the gap this migration
// closed (plan-unit-g.md §SCHEMA-1: "a projector-authoring bug is caught on
// the SECOND write, not the first").
//
// Both CHECK clauses are EXTRACTED from the real migration files at runtime
// (same discipline as FieldBindingsManualPriorityTest::fieldBindingsCheckClause)
// so this test binds the actual migration text, not a hand-copy that can
// drift silently.

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

const CONTENT_ITEMS_KIND_DOMAIN = [
    'video', 'track', 'release', 'episode', 'channel', 'service', 'menu_item',
    'product', 'event', 'link', 'media', 'review', 'document', 'article',
];

function sourceItemsKindCheckClause(): string
{
    $sql = (string) file_get_contents(base_path('supabase/migrations/20260729150000_source_items_kind_check_not_valid.sql'));
    preg_match('/ADD CONSTRAINT "source_items_kind_check" CHECK \("kind" IN \((.*?)\)\) NOT VALID/s', $sql, $m);
    expect($m[1] ?? null)->not->toBeNull('source_items_kind_check CHECK not found in the migration.');

    return $m[1];
}

function itemsKindCheckClause(): string
{
    $sql = (string) file_get_contents(base_path('supabase/migrations/20260727140000_content_schema.sql'));

    // Scope to the CREATE TABLE "content"."items" block first — content.sources
    // ALSO has a "kind" text NOT NULL CHECK (...) column, with a completely
    // different 3-value domain, and it appears earlier in the file. Matching
    // "kind" text NOT NULL CHECK unscoped grabs sources.kind instead of
    // items.kind.
    preg_match('/CREATE TABLE "content"\."items" \(.*?\n\);/s', $sql, $tableMatch);
    expect($tableMatch[0] ?? null)->not->toBeNull('CREATE TABLE "content"."items" not found in the migration.');

    preg_match('/"kind" text NOT NULL CHECK \("kind" IN \((.*?)\)\)/s', $tableMatch[0], $m);
    expect($m[1] ?? null)->not->toBeNull('items.kind CHECK not found within the content.items table block.');

    return $m[1];
}

/** Parse `'a', 'b', 'c'` into ['a', 'b', 'c']. */
function parseKindDomain(string $clause): array
{
    preg_match_all("/'([a-z_]+)'/", $clause, $m);

    return $m[1];
}

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS content');
    $pg->statement('DROP TABLE IF EXISTS content.source_items');
    $pg->statement('CREATE TABLE content.source_items (
        id      uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        item_id uuid,
        kind    text NOT NULL
    )');

    applyAlterFromMigration('20260729150000_source_items_kind_check_not_valid.sql');
});

/**
 * Strip the transaction wrapper (BEGIN/COMMIT/SET LOCAL) and comments from a
 * migration file and execute the remaining ALTER statement(s) one at a time
 * — same pattern as tests/Postgres/IngestCascadeDeletionTest.php.
 */
function applyAlterFromMigration(string $filename): void
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

it('the source_items.kind domain is set-equal to the items.kind domain', function () {
    $sourceItemsDomain = parseKindDomain(sourceItemsKindCheckClause());
    $itemsDomain = parseKindDomain(itemsKindCheckClause());

    sort($sourceItemsDomain);
    sort($itemsDomain);

    expect($sourceItemsDomain)->toBe($itemsDomain);
    expect($sourceItemsDomain)->toBe(collect(CONTENT_ITEMS_KIND_DOMAIN)->sort()->values()->all());
});

it('accepts every value in the 14-value domain', function (string $kind) {
    DB::connection('pgsql')->table('content.source_items')->insert([
        'kind' => $kind,
    ]);

    expect(DB::connection('pgsql')->table('content.source_items')->where('kind', $kind)->exists())->toBeTrue();
})->with(CONTENT_ITEMS_KIND_DOMAIN);

it('rejects a value outside the domain', function () {
    $thrown = null;
    try {
        DB::connection('pgsql')->table('content.source_items')->insert([
            'kind' => 'podcast',
        ]);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getCode())->toBe('23514');
});
