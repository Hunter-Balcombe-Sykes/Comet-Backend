<?php

use Tests\Support\SchemaDrift\DriftComparator;
use Tests\Support\SchemaDrift\Snapshot;
use Tests\Support\SchemaDrift\SqliteIntrospector;

/**
 * Pure-logic tests: fake introspector, in-memory snapshot. No DB boot.
 */
function fakeSnapshot(array $columns = [], array $checks = []): Snapshot
{
    return Snapshot::fromArray([
        'generated_at' => '2026-07-02T00:00:00Z',
        'project_ref' => 'test',
        'latest_migration' => '20260701220200',
        'columns' => $columns,
        'checks' => $checks,
    ]);
}

function fakeSqlite(array $tables): SqliteIntrospector
{
    // $tables: ['site.foo' => ['ddl' => 'CREATE TABLE ...', 'columns' => ['col' => notNullBool]]]
    return new class($tables) extends SqliteIntrospector
    {
        public function __construct(private array $tables) {}

        public function tableExists(string $schema, string $table): bool
        {
            return isset($this->tables["$schema.$table"]);
        }

        public function columnNotNull(string $schema, string $table, string $column): ?bool
        {
            return $this->tables["$schema.$table"]['columns'][$column] ?? null;
        }

        public function tableDdl(string $schema, string $table): ?string
        {
            return $this->tables["$schema.$table"]['ddl'] ?? null;
        }
    };
}

it('flags a Postgres NOT NULL column that is nullable in sqlite', function () {
    $snapshot = fakeSnapshot(columns: [
        ['schema' => 'site', 'table' => 'platform_connections', 'column' => 'payload', 'not_null' => true],
    ]);
    $sqlite = fakeSqlite([
        'site.platform_connections' => ['ddl' => 'CREATE TABLE platform_connections (payload TEXT NULL)', 'columns' => ['payload' => false]],
    ]);

    expect((new DriftComparator)->compare($snapshot, $sqlite))
        ->toBe(['not_null_missing:site.platform_connections.payload']);
});

it('passes when sqlite mirrors the NOT NULL', function () {
    $snapshot = fakeSnapshot(columns: [
        ['schema' => 'site', 'table' => 'platform_connections', 'column' => 'payload', 'not_null' => true],
    ]);
    $sqlite = fakeSqlite([
        'site.platform_connections' => ['ddl' => 'CREATE TABLE platform_connections (payload TEXT NOT NULL)', 'columns' => ['payload' => true]],
    ]);

    expect((new DriftComparator)->compare($snapshot, $sqlite))->toBe([]);
});

it('skips tables and columns absent from the sqlite schema', function () {
    $snapshot = fakeSnapshot(columns: [
        ['schema' => 'site', 'table' => 'never_tested', 'column' => 'x', 'not_null' => true],
        ['schema' => 'site', 'table' => 'partial', 'column' => 'absent_col', 'not_null' => true],
    ]);
    $sqlite = fakeSqlite([
        'site.partial' => ['ddl' => 'CREATE TABLE partial (id TEXT)', 'columns' => ['id' => false]],
    ]);

    expect((new DriftComparator)->compare($snapshot, $sqlite))->toBe([]);
});

it('flags a CHECK constraint with no sqlite counterpart mentioning its columns', function () {
    $snapshot = fakeSnapshot(checks: [
        ['schema' => 'site', 'table' => 'platform_connections', 'name' => 'pc_status_check',
            'definition' => "CHECK ((last_refresh_status = ANY (ARRAY['ok','failed','pending'])))"],
    ]);
    $sqlite = fakeSqlite([
        'site.platform_connections' => ['ddl' => 'CREATE TABLE platform_connections (last_refresh_status TEXT NULL)', 'columns' => ['last_refresh_status' => false]],
    ]);

    expect((new DriftComparator)->compare($snapshot, $sqlite))
        ->toBe(['check_missing:site.platform_connections:pc_status_check']);
});

it('passes when sqlite DDL has a CHECK mentioning a referenced column', function () {
    $snapshot = fakeSnapshot(checks: [
        ['schema' => 'site', 'table' => 'platform_connections', 'name' => 'pc_status_check',
            'definition' => "CHECK ((last_refresh_status = ANY (ARRAY['ok','failed','pending'])))"],
    ]);
    $sqlite = fakeSqlite([
        'site.platform_connections' => [
            'ddl' => "CREATE TABLE platform_connections (last_refresh_status TEXT CHECK (last_refresh_status IN ('ok','failed','pending')))",
            'columns' => ['last_refresh_status' => false],
        ],
    ]);

    expect((new DriftComparator)->compare($snapshot, $sqlite))->toBe([]);
});

it('flags a CHECK on an unconstrained column when an earlier unrelated CHECK exists in the same DDL', function () {
    // Regression guard: sqlite DDL has no internal semicolons, so a naive
    // "CHECK...to end of string" match would wrongly credit last_refresh_status
    // as covered just because it's a later column name — not because any CHECK
    // clause actually mentions it.
    $snapshot = fakeSnapshot(checks: [
        ['schema' => 'site', 'table' => 'platform_connections', 'name' => 'pc_status_check',
            'definition' => "CHECK ((last_refresh_status = ANY (ARRAY['ok','failed','pending'])))"],
    ]);
    $sqlite = fakeSqlite([
        'site.platform_connections' => [
            'ddl' => "CREATE TABLE platform_connections (status TEXT CHECK (status IN ('a','b')), last_refresh_status TEXT)",
            'columns' => ['status' => false, 'last_refresh_status' => false],
        ],
    ]);

    expect((new DriftComparator)->compare($snapshot, $sqlite))
        ->toBe(['check_missing:site.platform_connections:pc_status_check']);
});

it('passes when a single CHECK clause genuinely covers the identifier', function () {
    $snapshot = fakeSnapshot(checks: [
        ['schema' => 'site', 'table' => 'platform_connections', 'name' => 'pc_status_check',
            'definition' => "CHECK ((last_refresh_status = ANY (ARRAY['ok','failed','pending'])))"],
    ]);
    $sqlite = fakeSqlite([
        'site.platform_connections' => [
            'ddl' => "CREATE TABLE platform_connections (status TEXT CHECK (status IN ('a','b')), last_refresh_status TEXT CHECK (last_refresh_status IN ('ok','failed','pending')))",
            'columns' => ['status' => false, 'last_refresh_status' => false],
        ],
    ]);

    expect((new DriftComparator)->compare($snapshot, $sqlite))->toBe([]);
});

it('sorts findings deterministically', function () {
    $snapshot = fakeSnapshot(columns: [
        ['schema' => 'site', 'table' => 'b', 'column' => 'x', 'not_null' => true],
        ['schema' => 'core', 'table' => 'a', 'column' => 'y', 'not_null' => true],
    ]);
    $sqlite = fakeSqlite([
        'site.b' => ['ddl' => 'CREATE TABLE b (x TEXT)', 'columns' => ['x' => false]],
        'core.a' => ['ddl' => 'CREATE TABLE a (y TEXT)', 'columns' => ['y' => false]],
    ]);

    expect((new DriftComparator)->compare($snapshot, $sqlite))
        ->toBe(['not_null_missing:core.a.y', 'not_null_missing:site.b.x']);
});
