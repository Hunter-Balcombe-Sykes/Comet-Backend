<?php

// #PARITY-1 §5.1. site.sections carries SEVEN inline CHECKs (slot, kind, mode,
// group_by, render, on_empty, stale_display) plus site.section_items.state —
// none of which the SQLite stand-in enforced until this unit (see
// tests/Pest.php's setupSectionsTables()). A SQLite mirror can only ever
// assert "this literal is accepted"; it cannot prove the API's Rule::in()
// validator and the database's CHECK constraint agree on the SAME set of
// values. This test binds both: every domain is EXTRACTED from the real
// migration file at runtime (same discipline as ContentKindDomainParityTest),
// then asserted set-equal against StoreSectionRequest / UpdateSectionRequest /
// UpsertSectionItemRequest's Rule::in() calls, ALSO extracted at runtime — so
// a future edit that changes one side and not the other fails here, not in
// prod as a 500 the validator should have caught, or a value the validator
// accepts but the database 23514s on.

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

// Resolved via __DIR__, deliberately NOT base_path(): Pest/PHPUnit evaluate a
// ->with() dataset closure during test COLLECTION, before the app container
// is booted — base_path() (and expect()) are unavailable at that point, so
// every helper usable from a dataset closure below is framework-free and
// raises a plain RuntimeException rather than an expectation.
const REPO_ROOT = __DIR__.'/../..';
const SECTIONS_MIGRATION = REPO_ROOT.'/supabase/migrations/20260727150000_sections_and_documents.sql';

/** Extract the CREATE TABLE "site"."<table>" (...) block from the migration file. */
function sectionsMigrationTableBlock(string $table): string
{
    $sql = (string) file_get_contents(SECTIONS_MIGRATION);
    preg_match('/CREATE TABLE "site"\."'.preg_quote($table, '/').'" \(.*?\n\);/s', $sql, $m);
    if (! isset($m[0])) {
        throw new RuntimeException("CREATE TABLE \"site\".\"{$table}\" not found in {$table}.");
    }

    return $m[0];
}

/**
 * Extract the IN (...) domain list of a named column's CHECK within a table
 * block. Handles both `"col" IN (...)` and the nullable
 * `"col" IS NULL OR "col" IN (...)` shape — `[^()]*?` skips whatever sits
 * between the column name and the first literal "IN (", which is empty for
 * the plain form and "IS NULL OR \"col\" " for the nullable one.
 */
function migrationCheckDomain(string $tableBlock, string $column): array
{
    $col = preg_quote($column, '/');
    preg_match('/"'.$col.'"[^,]*?CHECK\s*\("'.$col.'"[^()]*?IN\s*\((.*?)\)\)/s', $tableBlock, $m);
    if (! isset($m[1])) {
        throw new RuntimeException("CHECK domain for \"{$column}\" not found in the migration table block.");
    }
    preg_match_all("/'([a-z_]+)'/", $m[1], $vals);

    return $vals[1];
}

/** Extract a field's Rule::in([...]) domain from a Form Request source file. */
function requestRuleInDomain(string $relativePath, string $field): array
{
    $src = (string) file_get_contents(REPO_ROOT.'/'.$relativePath);
    preg_match("/'".preg_quote($field, '/')."'\s*=>[^\n]*?Rule::in\(\[(.*?)\]\)/", $src, $m);
    if (! isset($m[1])) {
        throw new RuntimeException("Rule::in domain for '{$field}' not found in {$relativePath}.");
    }
    preg_match_all("/'([a-z_]+)'/", $m[1], $vals);

    return $vals[1];
}

function sorted(array $values): array
{
    sort($values);

    return $values;
}

beforeEach(function () {
    $pg = DB::connection('pgsql');
    $pg->statement('CREATE SCHEMA IF NOT EXISTS site');
    $pg->statement('DROP TABLE IF EXISTS site.section_shape_scratch');
    $pg->statement('DROP TABLE IF EXISTS site.section_items_shape_scratch');

    $sectionsBlock = sectionsMigrationTableBlock('sections');
    $slot = migrationCheckDomain($sectionsBlock, 'slot');
    $kind = migrationCheckDomain($sectionsBlock, 'kind');
    $mode = migrationCheckDomain($sectionsBlock, 'mode');
    $groupBy = migrationCheckDomain($sectionsBlock, 'group_by');
    $render = migrationCheckDomain($sectionsBlock, 'render');
    $onEmpty = migrationCheckDomain($sectionsBlock, 'on_empty');
    $staleDisplay = migrationCheckDomain($sectionsBlock, 'stale_display');

    $inList = fn (array $values) => implode(',', array_map(fn ($v) => "'{$v}'", $values));

    $pg->statement("CREATE TABLE site.section_shape_scratch (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        slot text NOT NULL DEFAULT 'body' CHECK (slot IN ({$inList($slot)})),
        kind text NOT NULL CHECK (kind IN ({$inList($kind)})),
        mode text NOT NULL DEFAULT 'automatic' CHECK (mode IN ({$inList($mode)})),
        group_by text CHECK (group_by IS NULL OR group_by IN ({$inList($groupBy)})),
        render text NOT NULL DEFAULT 'cards' CHECK (render IN ({$inList($render)})),
        on_empty text NOT NULL DEFAULT 'hide' CHECK (on_empty IN ({$inList($onEmpty)})),
        stale_display text NOT NULL DEFAULT 'inherit' CHECK (stale_display IN ({$inList($staleDisplay)}))
    )");

    $sectionItemsBlock = sectionsMigrationTableBlock('section_items');
    $state = migrationCheckDomain($sectionItemsBlock, 'state');

    $pg->statement("CREATE TABLE site.section_items_shape_scratch (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        state text NOT NULL CHECK (state IN ({$inList($state)}))
    )");
});

it('site.sections\' seven CHECK domains are set-equal to the API validators', function () {
    $sectionsBlock = sectionsMigrationTableBlock('sections');

    $columns = ['slot', 'kind', 'mode', 'render', 'on_empty', 'stale_display'];
    foreach ($columns as $column) {
        $migrationDomain = sorted(migrationCheckDomain($sectionsBlock, $column));
        $storeDomain = sorted(requestRuleInDomain('app/Http/Requests/Api/User/Sections/StoreSectionRequest.php', $column));
        $updateDomain = sorted(requestRuleInDomain('app/Http/Requests/Api/User/Sections/UpdateSectionRequest.php', $column));

        expect($storeDomain)->toBe($migrationDomain, "StoreSectionRequest '{$column}' domain diverges from the migration CHECK.");
        expect($updateDomain)->toBe($migrationDomain, "UpdateSectionRequest '{$column}' domain diverges from the migration CHECK.");
    }

    // group_by is nullable both sides — same extraction, the IS NULL prefix
    // doesn't affect the IN (...) list itself.
    $migrationGroupBy = sorted(migrationCheckDomain($sectionsBlock, 'group_by'));
    $storeGroupBy = sorted(requestRuleInDomain('app/Http/Requests/Api/User/Sections/StoreSectionRequest.php', 'group_by'));
    $updateGroupBy = sorted(requestRuleInDomain('app/Http/Requests/Api/User/Sections/UpdateSectionRequest.php', 'group_by'));

    expect($storeGroupBy)->toBe($migrationGroupBy, 'StoreSectionRequest group_by domain diverges from the migration CHECK.');
    expect($updateGroupBy)->toBe($migrationGroupBy, 'UpdateSectionRequest group_by domain diverges from the migration CHECK.');
});

it('site.section_items.state is set-equal to UpsertSectionItemRequest\'s validator', function () {
    $sectionItemsBlock = sectionsMigrationTableBlock('section_items');
    $migrationDomain = sorted(migrationCheckDomain($sectionItemsBlock, 'state'));
    $requestDomain = sorted(requestRuleInDomain('app/Http/Requests/Api/User/Sections/UpsertSectionItemRequest.php', 'state'));

    expect($requestDomain)->toBe($migrationDomain);
    expect($migrationDomain)->toBe(['excluded', 'pinned']);
});

it('accepts every value in each site.sections domain', function (string $column, string $value) {
    DB::connection('pgsql')->table('site.section_shape_scratch')->insert([$column => $value, 'kind' => $column === 'kind' ? $value : 'collection']);

    expect(DB::connection('pgsql')->table('site.section_shape_scratch')->where($column, $value)->exists())->toBeTrue();
})->with(function () {
    $block = sectionsMigrationTableBlock('sections');
    $cases = [];
    foreach (['slot', 'kind', 'mode', 'render', 'on_empty', 'stale_display'] as $column) {
        foreach (migrationCheckDomain($block, $column) as $value) {
            $cases["{$column}={$value}"] = [$column, $value];
        }
    }

    return $cases;
});

it('accepts NULL and every value in the group_by domain', function () {
    $block = sectionsMigrationTableBlock('sections');
    $table = DB::connection('pgsql')->table('site.section_shape_scratch');

    $table->insert(['kind' => 'collection', 'group_by' => null]);
    expect($table->whereNull('group_by')->exists())->toBeTrue();

    foreach (migrationCheckDomain($block, 'group_by') as $value) {
        DB::connection('pgsql')->table('site.section_shape_scratch')->insert(['kind' => 'collection', 'group_by' => $value]);
        expect(DB::connection('pgsql')->table('site.section_shape_scratch')->where('group_by', $value)->exists())->toBeTrue();
    }
});

it('accepts both site.section_items.state values', function (string $state) {
    DB::connection('pgsql')->table('site.section_items_shape_scratch')->insert(['state' => $state]);

    expect(DB::connection('pgsql')->table('site.section_items_shape_scratch')->where('state', $state)->exists())->toBeTrue();
})->with(['pinned', 'excluded']);

it('rejects a value outside each site.sections domain', function (string $column) {
    $thrown = null;
    try {
        DB::connection('pgsql')->table('site.section_shape_scratch')->insert([
            'kind' => 'collection',
            $column => 'not-a-real-value',
        ]);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull("Expected {$column} to reject an out-of-domain value.");
    expect($thrown->getCode())->toBe('23514');
})->with(['slot', 'kind', 'mode', 'group_by', 'render', 'on_empty', 'stale_display']);

it('rejects a value outside the site.section_items.state domain', function () {
    $thrown = null;
    try {
        DB::connection('pgsql')->table('site.section_items_shape_scratch')->insert(['state' => 'archived']);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getCode())->toBe('23514');
});
