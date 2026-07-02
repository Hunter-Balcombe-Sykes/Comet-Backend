# Launch-Check Suite Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `scripts/launch-check/` — a runnable suite of five probe groups that verify the *running system* (schema drift, runtime config, Supabase settings, supply chain, manual residue) — the counterpart to the static-code audit pipeline in `scripts/audit/`.

**Architecture:** Five independent probe groups behind one runner (`launch-check.sh`). Group A (schema-drift) is a deterministic Pest gate that runs inside `composer test` in CI, comparing the SQLite test schema against a checked-in JSON snapshot of the live dev Postgres constraints; it uses a baseline file so only *new* drift fails. Groups B–D are on-demand shell/PHP probes against the live env / Supabase Management API / dependency trees. Group E prints the un-automatable checklist. The runner writes a CONSOLIDATED-style report to `audits/launch-check/<date>/REPORT.md`.

**Tech Stack:** PHP 8.2 (Pest test + comparator classes under `Tests\Support\`), bash + curl + jq (probes), Supabase Management API (PAT), GitHub Actions (gitleaks + npm audit steps).

## Global Constraints

- **Never create Laravel migration files** — this plan creates none; it only reads `supabase/migrations/`.
- **Dev Supabase ref is `glncumufgaqcmqhzwrxm`** — the ONLY snapshot/advisor target. Never point any script at prod ref `edplucmvkcnokyygxqsb`.
- **Secrets live in `scripts/launch-check/.env`** (gitignored), mirroring the `scripts/audit/.env` pattern. Never commit a PAT.
- **Baseline-after-triage rule** (house rule): run the drift gate raw first, fix genuinely dangerous drift, then baseline the residual. Never baseline first.
- **Default probe target is `https://dev-api.partna.au`** — prod probing is gated behind explicit `--base-url`.
- 4-space indent, LF endings; Pint-clean PHP.
- All new PHP test-support classes live under `Tests\Support\SchemaDrift\` (autoloaded via the existing `"Tests\\": "tests/"` autoload-dev mapping — verify in Task 2 Step 1).
- Report output dir: `audits/launch-check/<YYYY-MM-DD>/` (sibling of `audits/sweeps/`).

---

## File Structure

```
scripts/launch-check/
  launch-check.sh                  # runner (Task 7)
  refresh-schema-snapshot.php      # Task 1 — dumps dev Postgres constraints → schema-snapshot.json
  schema-snapshot.json             # Task 1 — checked-in snapshot (source of truth for the gate)
  schema-drift-baseline.json       # Task 3 — accepted pre-existing drift
  smoke.sh                         # Task 4 — runtime smoke probe
  supabase-check.sh                # Task 5 — advisors + rowsecurity
  MANUAL-CHECKLIST.md              # Task 7 — un-automatable items
  .env.example                     # Task 1 — SUPABASE_ACCESS_TOKEN=
tests/Support/SchemaDrift/
  Snapshot.php                     # Task 2 — snapshot loader/model
  SqliteIntrospector.php           # Task 2 — PRAGMA/sqlite_master reader
  DriftComparator.php              # Task 2 — produces finding keys
tests/Unit/SchemaDrift/
  DriftComparatorTest.php          # Task 2
tests/Feature/Architecture/
  SchemaDriftGuardTest.php         # Task 3 — the CI gate
.github/workflows/ci.yml           # Task 6 — modify: secrets job + npm audit step
.gitignore                         # Task 1 — modify: add scripts/launch-check/.env
```

### Task boundaries and rationale

| Task | Deliverable | Independently testable via |
|---|---|---|
| 1 | Snapshot refresh script + first snapshot | run script, inspect JSON |
| 2 | Drift comparator (pure logic) | unit tests |
| 3 | Pest CI gate + baseline | `composer test` |
| 4 | Runtime smoke probe | run against dev-api |
| 5 | Supabase config check | run against dev ref |
| 6 | CI supply-chain steps | push branch, watch CI |
| 7 | Runner + manual checklist + README | full run |

---

### Task 1: Schema snapshot refresh script

**Files:**
- Create: `scripts/launch-check/refresh-schema-snapshot.php`
- Create: `scripts/launch-check/.env.example`
- Create: `scripts/launch-check/schema-snapshot.json` (generated output, checked in)
- Modify: `.gitignore` (append `scripts/launch-check/.env`)

**Interfaces:**
- Produces: `schema-snapshot.json` with shape:
  ```json
  {
    "generated_at": "2026-07-02T05:00:00Z",
    "project_ref": "glncumufgaqcmqhzwrxm",
    "latest_migration": "20260701220200",
    "columns": [
      {"schema": "site", "table": "platform_connections", "column": "payload", "not_null": true}
    ],
    "checks": [
      {"schema": "site", "table": "platform_connections", "name": "platform_connections_status_check", "definition": "CHECK ((last_refresh_status = ANY (ARRAY['ok','failed','pending'])))"}
    ]
  }
  ```
- Consumes: `SUPABASE_ACCESS_TOKEN` from `scripts/launch-check/.env` (Supabase PAT — Josh creates it at https://supabase.com/dashboard/account/tokens).

- [ ] **Step 1: Create `.env.example` and gitignore entry**

`scripts/launch-check/.env.example`:
```
# Supabase personal access token (https://supabase.com/dashboard/account/tokens)
# Needs read access to project glncumufgaqcmqhzwrxm. NEVER commit the real .env.
SUPABASE_ACCESS_TOKEN=
```

Append to `.gitignore`:
```
scripts/launch-check/.env
```

- [ ] **Step 2: Write the refresh script**

`scripts/launch-check/refresh-schema-snapshot.php`:
```php
#!/usr/bin/env php
<?php

/**
 * Dumps NOT NULL + CHECK constraints from the LIVE dev Supabase Postgres
 * into schema-snapshot.json. The SchemaDriftGuardTest Pest gate compares the
 * SQLite test schema against this snapshot — so the snapshot, not the
 * migration SQL, is the source of truth (it also catches migrations applied
 * directly to Supabase that never landed in the repo).
 *
 * Usage: php scripts/launch-check/refresh-schema-snapshot.php
 * Requires SUPABASE_ACCESS_TOKEN in scripts/launch-check/.env
 */
const PROJECT_REF = 'glncumufgaqcmqhzwrxm'; // dev ONLY — never the prod ref
const SCHEMAS = "'core','site','notifications','analytics','audit','moderation'";

$dir = __DIR__;

// Minimal .env parse (no framework boot — this is a standalone CLI tool).
$token = getenv('SUPABASE_ACCESS_TOKEN') ?: '';
if ($token === '' && is_file("$dir/.env")) {
    foreach (file("$dir/.env", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), 'SUPABASE_ACCESS_TOKEN=')) {
            $token = trim(explode('=', $line, 2)[1]);
        }
    }
}
if ($token === '') {
    fwrite(STDERR, "SUPABASE_ACCESS_TOKEN missing — copy .env.example to .env and fill it in.\n");
    exit(1);
}

/** POST a SQL query to the Supabase Management API, return decoded rows. */
function pgQuery(string $token, string $sql): array
{
    $ch = curl_init('https://api.supabase.com/v1/projects/'.PROJECT_REF.'/database/query');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}", 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['query' => $sql]),
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status !== 200 && $status !== 201) {
        fwrite(STDERR, "Management API query failed (HTTP {$status}): {$body}\n");
        exit(1);
    }

    return json_decode($body, true) ?? [];
}

$columns = pgQuery($token, '
    SELECT c.table_schema AS schema, c.table_name AS "table",
           c.column_name AS "column", (c.is_nullable = \'NO\') AS not_null
    FROM information_schema.columns c
    WHERE c.table_schema IN ('.SCHEMAS.')
    ORDER BY 1, 2, c.ordinal_position');

$checks = pgQuery($token, '
    SELECT n.nspname AS schema, rel.relname AS "table",
           con.conname AS name, pg_get_constraintdef(con.oid) AS definition
    FROM pg_constraint con
    JOIN pg_class rel ON rel.oid = con.conrelid
    JOIN pg_namespace n ON n.oid = rel.relnamespace
    WHERE con.contype = \'c\' AND n.nspname IN ('.SCHEMAS.')
    ORDER BY 1, 2, 3');

$migration = pgQuery($token,
    'SELECT version FROM supabase_migrations.schema_migrations ORDER BY version DESC LIMIT 1');

$snapshot = [
    'generated_at' => gmdate('c'),
    'project_ref' => PROJECT_REF,
    'latest_migration' => $migration[0]['version'] ?? 'unknown',
    'columns' => $columns,
    'checks' => $checks,
];

file_put_contents("$dir/schema-snapshot.json",
    json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

echo 'Snapshot written: '.count($columns).' columns, '.count($checks)
    ." CHECK constraints, latest migration {$snapshot['latest_migration']}\n";
```

- [ ] **Step 3: Run it and inspect output**

Run: `php scripts/launch-check/refresh-schema-snapshot.php`
Expected: `Snapshot written: <hundreds> columns, <dozens> CHECK constraints, latest migration 20260701220200` (or newer). Spot-check `schema-snapshot.json`: `site.platform_connections.payload` must show `"not_null": true` (the constraint that bit us twice).

If the Management API returns 404 on `/database/query`, verify the current endpoint path against https://api.supabase.com/api/v1 (the docs occasionally move it); adjust the URL in `pgQuery()` — everything else stays identical.

- [ ] **Step 4: Commit**

```bash
git add scripts/launch-check/refresh-schema-snapshot.php scripts/launch-check/.env.example scripts/launch-check/schema-snapshot.json .gitignore
git commit -m "feat(launch-check): schema snapshot refresh script + first dev-DB snapshot"
```

---

### Task 2: Drift comparator (pure logic, unit-tested)

**Files:**
- Create: `tests/Support/SchemaDrift/Snapshot.php`
- Create: `tests/Support/SchemaDrift/SqliteIntrospector.php`
- Create: `tests/Support/SchemaDrift/DriftComparator.php`
- Test: `tests/Unit/SchemaDrift/DriftComparatorTest.php`

**Interfaces:**
- Consumes: `schema-snapshot.json` shape from Task 1.
- Produces (used by Task 3):
  - `Snapshot::fromFile(string $path): self` with `public array $columns` (each `['schema','table','column','not_null']`) and `public array $checks` (each `['schema','table','name','definition']`), `public string $latestMigration`.
  - `SqliteIntrospector::__construct(\Illuminate\Database\Connection $conn)`; `tableExists(string $schema, string $table): bool`; `columnNotNull(string $schema, string $table, string $column): ?bool` (null = column absent); `tableDdl(string $schema, string $table): ?string`.
  - `DriftComparator::compare(Snapshot $snapshot, SqliteIntrospector $sqlite): array` → sorted list of finding-key strings:
    - `"not_null_missing:{schema}.{table}.{column}"` — Postgres NOT NULL, SQLite column exists but nullable.
    - `"check_missing:{schema}.{table}:{constraint_name}"` — Postgres CHECK on a table that exists in SQLite, but the SQLite DDL has no CHECK mentioning any column referenced in the constraint definition.
    - Tables/columns absent from SQLite are SKIPPED (tests never touch them — not drift).

- [ ] **Step 1: Verify autoload-dev covers `Tests\Support\`**

Run: `grep -A4 '"autoload-dev"' composer.json`
Expected: `"Tests\\": "tests/"` mapping exists. (It's the Laravel default; if absent, add it and `composer dump-autoload`.)

- [ ] **Step 2: Write the failing unit test**

`tests/Unit/SchemaDrift/DriftComparatorTest.php`:
```php
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
        public function __construct(private array $tables)
        {
        }

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
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=DriftComparatorTest`
Expected: FAIL — `Class "Tests\Support\SchemaDrift\Snapshot" not found`

- [ ] **Step 4: Implement the three classes**

`tests/Support/SchemaDrift/Snapshot.php`:
```php
<?php

namespace Tests\Support\SchemaDrift;

/**
 * Immutable view over schema-snapshot.json (produced by
 * scripts/launch-check/refresh-schema-snapshot.php).
 */
class Snapshot
{
    /** @param array<int, array{schema:string,table:string,column:string,not_null:bool}> $columns
     *  @param array<int, array{schema:string,table:string,name:string,definition:string}> $checks */
    private function __construct(
        public readonly array $columns,
        public readonly array $checks,
        public readonly string $latestMigration,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            columns: $data['columns'] ?? [],
            checks: $data['checks'] ?? [],
            latestMigration: $data['latest_migration'] ?? 'unknown',
        );
    }

    public static function fromFile(string $path): self
    {
        return self::fromArray(json_decode(file_get_contents($path), true) ?? []);
    }
}
```

`tests/Support/SchemaDrift/SqliteIntrospector.php`:
```php
<?php

namespace Tests\Support\SchemaDrift;

use Illuminate\Database\Connection;

/**
 * Reads the ATTACH-ed SQLite test schema built by tests/Pest.php helpers.
 * Schemas map to attached database names (core, site, ...), so PRAGMA and
 * sqlite_master are addressed per-schema.
 */
class SqliteIntrospector
{
    public function __construct(private ?Connection $conn = null)
    {
    }

    public function tableExists(string $schema, string $table): bool
    {
        return $this->tableDdl($schema, $table) !== null;
    }

    /** null = column absent from the sqlite table. */
    public function columnNotNull(string $schema, string $table, string $column): ?bool
    {
        foreach ($this->conn->select("PRAGMA {$schema}.table_info({$table})") as $col) {
            if ($col->name === $column) {
                return (bool) $col->notnull;
            }
        }

        return null;
    }

    public function tableDdl(string $schema, string $table): ?string
    {
        $row = $this->conn->selectOne(
            "SELECT sql FROM {$schema}.sqlite_master WHERE type = 'table' AND name = ?",
            [$table]
        );

        return $row->sql ?? null;
    }
}
```

`tests/Support/SchemaDrift/DriftComparator.php`:
```php
<?php

namespace Tests\Support\SchemaDrift;

/**
 * Diffs Postgres constraints (snapshot) against the SQLite test schema.
 * Only findings for tables/columns the test schema ACTUALLY DEFINES are
 * emitted — an absent table means no test exercises it, which is a
 * test-coverage question, not schema drift.
 */
class DriftComparator
{
    /** @return string[] sorted finding keys */
    public function compare(Snapshot $snapshot, SqliteIntrospector $sqlite): array
    {
        $findings = [];

        foreach ($snapshot->columns as $col) {
            if (! $col['not_null'] || ! $sqlite->tableExists($col['schema'], $col['table'])) {
                continue;
            }
            $notNull = $sqlite->columnNotNull($col['schema'], $col['table'], $col['column']);
            if ($notNull === false) { // column exists but nullable — the drift that 500s in prod
                $findings[] = "not_null_missing:{$col['schema']}.{$col['table']}.{$col['column']}";
            }
        }

        foreach ($snapshot->checks as $check) {
            if (! $sqlite->tableExists($check['schema'], $check['table'])) {
                continue;
            }
            $ddl = $sqlite->tableDdl($check['schema'], $check['table']) ?? '';
            if (! $this->ddlCoversCheck($ddl, $check['definition'])) {
                $findings[] = "check_missing:{$check['schema']}.{$check['table']}:{$check['name']}";
            }
        }

        sort($findings);

        return $findings;
    }

    /**
     * Heuristic: the SQLite DDL "covers" a Postgres CHECK if it contains any
     * CHECK clause mentioning at least one identifier referenced by the
     * Postgres definition. We compare presence, not expression equivalence —
     * translating Postgres syntax (ANY/ARRAY, ~, ::casts) is out of scope.
     */
    private function ddlCoversCheck(string $ddl, string $pgDefinition): bool
    {
        if (stripos($ddl, 'CHECK') === false) {
            return false;
        }

        preg_match_all('/[a-z_][a-z0-9_]*/i', $pgDefinition, $m);
        $identifiers = array_diff(array_unique($m[0]), ['CHECK', 'ANY', 'ARRAY', 'IS', 'NOT', 'NULL', 'AND', 'OR', 'IN', 'text', 'OTHERS', 'true', 'false']);

        foreach ($identifiers as $ident) {
            if (preg_match('/CHECK[^;]*\b'.preg_quote($ident, '/').'\b/is', $ddl)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=DriftComparatorTest`
Expected: PASS (6 tests)

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint tests/Support/SchemaDrift tests/Unit/SchemaDrift
git add tests/Support/SchemaDrift tests/Unit/SchemaDrift
git commit -m "feat(launch-check): schema-drift comparator with unit tests"
```

---

### Task 3: Pest CI gate + baseline

**Files:**
- Create: `tests/Feature/Architecture/SchemaDriftGuardTest.php`
- Create: `scripts/launch-check/schema-drift-baseline.json` (generated in Step 3)

**Interfaces:**
- Consumes: `Snapshot::fromFile()`, `SqliteIntrospector`, `DriftComparator::compare()` from Task 2; the global `setup*Table()` helpers defined in `tests/Pest.php`.
- Produces: a test that fails `composer test` (and therefore CI) whenever NEW drift appears. Baseline regen: `SCHEMA_DRIFT_BASELINE=1 php artisan test --filter=SchemaDriftGuardTest`.

- [ ] **Step 1: Write the gate test**

`tests/Feature/Architecture/SchemaDriftGuardTest.php`:
```php
<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\SchemaDrift\DriftComparator;
use Tests\Support\SchemaDrift\Snapshot;
use Tests\Support\SchemaDrift\SqliteIntrospector;

/**
 * Schema-drift gate: the SQLite test schema must mirror the NOT NULL / CHECK
 * constraints of the real dev Postgres (schema-snapshot.json), so a write
 * that would violate a prod constraint can never again pass CI green.
 *
 * Pre-existing permissive columns are grandfathered in schema-drift-baseline.json.
 * To accept intentional new drift: SCHEMA_DRIFT_BASELINE=1 php artisan test --filter=SchemaDriftGuardTest
 * To refresh the Postgres snapshot: php scripts/launch-check/refresh-schema-snapshot.php
 */
const SNAPSHOT_PATH = __DIR__.'/../../../scripts/launch-check/schema-snapshot.json';
const BASELINE_PATH = __DIR__.'/../../../scripts/launch-check/schema-drift-baseline.json';

it('sqlite test schema mirrors dev Postgres constraints (or is baselined)', function () {
    // Build EVERY table the test suite knows how to build. The setup helpers
    // are global no-arg functions in tests/Pest.php named setup*Table/Tables.
    foreach (get_defined_functions()['user'] as $fn) {
        $short = str_contains($fn, '\\') ? substr($fn, strrpos($fn, '\\') + 1) : $fn;
        if (str_starts_with($short, 'setup') && (new ReflectionFunction($fn))->getNumberOfRequiredParameters() === 0) {
            $fn();
        }
    }

    $findings = (new DriftComparator)->compare(
        Snapshot::fromFile(SNAPSHOT_PATH),
        new SqliteIntrospector(DB::connection('pgsql')),
    );

    if (getenv('SCHEMA_DRIFT_BASELINE') === '1') {
        file_put_contents(BASELINE_PATH, json_encode($findings, JSON_PRETTY_PRINT)."\n");
        expect(true)->toBeTrue(); // baseline regenerated — always green

        return;
    }

    $baseline = is_file(BASELINE_PATH) ? json_decode(file_get_contents(BASELINE_PATH), true) : [];
    $new = array_values(array_diff($findings, $baseline));
    $fixed = array_values(array_diff($baseline, $findings));

    expect($new)->toBe([], sprintf(
        "NEW schema drift — these Postgres constraints are missing from the SQLite test schema in tests/Pest.php:\n  %s\n".
        "Fix: add the NOT NULL / CHECK to the matching setup*Table() helper (preferred), or if intentional run:\n".
        "  SCHEMA_DRIFT_BASELINE=1 php artisan test --filter=SchemaDriftGuardTest\n".
        "Also refresh the snapshot after schema changes: php scripts/launch-check/refresh-schema-snapshot.php",
        implode("\n  ", $new)
    ));

    if ($fixed !== []) {
        fwrite(STDERR, "\n[schema-drift] ".count($fixed)." baselined finding(s) now fixed — regenerate the baseline to lock them in.\n");
    }
});
```

- [ ] **Step 2: Run raw (no baseline) and TRIAGE — do not skip**

Run: `php artisan test --filter=SchemaDriftGuardTest`
Expected: FAIL with a (probably long) list of `not_null_missing:` / `check_missing:` findings.

**Triage per the house baseline-after-triage rule:** scan the list for tables where tests exercise constraint-bound WRITES (at minimum `site.platform_connections`, `site.sites`, `site.design_kits`, `site.menus`, `core.users`). For those, tighten the `setup*Table()` DDL in `tests/Pest.php` to mirror Postgres (add `NOT NULL` / a `CHECK (... IN (...))`) and fix any factory/test fallout — each tightening that breaks a test has found exactly the class of bug this gate exists for. Timebox: tighten the 5 named tables; leave the rest for the baseline.

- [ ] **Step 3: Generate the baseline for the residual**

Run: `SCHEMA_DRIFT_BASELINE=1 php artisan test --filter=SchemaDriftGuardTest`
Expected: PASS; `scripts/launch-check/schema-drift-baseline.json` written.

- [ ] **Step 4: Verify the gate passes clean and the full suite is green**

Run: `composer test`
Expected: PASS, including SchemaDriftGuardTest. (Full suite, not filtered — house rule.)

- [ ] **Step 5: Prove the gate catches new drift**

Temporarily edit `scripts/launch-check/schema-snapshot.json`: change one column that exists nullable in the test schema to `"not_null": true` (pick one NOT in the baseline). Run `php artisan test --filter=SchemaDriftGuardTest` — Expected: FAIL naming that column. Revert the edit, re-run — Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/Architecture/SchemaDriftGuardTest.php scripts/launch-check/schema-drift-baseline.json tests/Pest.php
git commit -m "feat(launch-check): schema-drift CI gate — SQLite test schema vs dev Postgres snapshot, baselined"
```

---

### Task 4: Runtime smoke probe

**Files:**
- Create: `scripts/launch-check/smoke.sh`

**Interfaces:**
- Produces: `smoke.sh [--base-url URL] [--rate-limit]` — exit 0 all pass, exit 1 any FAIL; prints one `PASS|FAIL|WARN <check>` line each. Default base URL `https://dev-api.partna.au`. Task 7's runner calls it and captures output.

- [ ] **Step 1: Write the probe**

`scripts/launch-check/smoke.sh`:
```bash
#!/usr/bin/env bash
# Runtime smoke probe — verifies the RUNNING env, which no static audit can.
# Usage: smoke.sh [--base-url https://dev-api.partna.au] [--rate-limit]
set -uo pipefail

BASE="https://dev-api.partna.au"
RATE_LIMIT_TEST=false
while [[ $# -gt 0 ]]; do
    case "$1" in
        --base-url) BASE="$2"; shift 2 ;;
        --rate-limit) RATE_LIMIT_TEST=true; shift ;;
        *) echo "unknown arg: $1" >&2; exit 2 ;;
    esac
done

FAILS=0
pass() { echo "PASS  $1"; }
fail() { echo "FAIL  $1"; FAILS=$((FAILS + 1)); }
warn() { echo "WARN  $1"; }

status() { curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$1"; }

# --- 1. Sensitive files must not be HTTP-reachable ---
for path in .env composer.json .git/config storage/logs/laravel.log; do
    code=$(status "$BASE/$path")
    [[ "$code" == "404" || "$code" == "403" ]] \
        && pass "$path not reachable ($code)" \
        || fail "$path returned $code — must be 404/403"
done

# --- 2. Debug leakage: bogus route must return clean JSON, no stack trace ---
body=$(curl -s --max-time 15 -H 'Accept: application/json' "$BASE/api/definitely-not-a-route-xyz")
if echo "$body" | grep -qE 'Stack trace|vendor/laravel|Illuminate\\\\'; then
    fail "bogus route leaks stack trace (APP_DEBUG on?)"
else
    pass "bogus route returns clean error body"
fi

# --- 3. Dev tooling must be gated ---
for path in telescope horizon; do
    code=$(status "$BASE/$path")
    [[ "$code" == "200" ]] && fail "/$path publicly reachable (200)" || pass "/$path gated ($code)"
done

# --- 4. Health endpoint answers ---
code=$(status "$BASE/api/health")
[[ "$code" == "200" ]] && pass "health endpoint 200" || fail "health endpoint returned $code"

# --- 5. Security headers on an API response (WARN — may be Cloudflare's job) ---
headers=$(curl -s -D - -o /dev/null --max-time 15 "$BASE/api/health")
for h in "x-content-type-options" "strict-transport-security" "x-frame-options"; do
    echo "$headers" | grep -qi "^$h:" && pass "header $h present" || warn "header $h missing (set via middleware or Cloudflare)"
done

# --- 6. 404-not-403 enumeration standard, live ---
code=$(status "$BASE/api/public/documents/00000000-0000-4000-8000-000000000000/download")
[[ "$code" == "404" ]] && pass "missing public resource → 404 (anti-enumeration)" \
    || fail "missing public resource returned $code — must be 404, never 403"

# --- 7. Rate limiter actually fires (opt-in: hammers the env) ---
if $RATE_LIMIT_TEST; then
    got429=false
    for _ in $(seq 1 90); do
        [[ "$(status "$BASE/api/ping")" == "429" ]] && { got429=true; break; }
    done
    $got429 && pass "throttle fired (429 within 90 hits on /api/ping)" \
        || fail "no 429 after 90 hits — is the limiter live?"
fi

echo
if [[ $FAILS -gt 0 ]]; then echo "SMOKE: $FAILS failure(s)"; exit 1; fi
echo "SMOKE: all checks passed"
```

- [ ] **Step 2: Make executable, run against dev**

Run: `chmod +x scripts/launch-check/smoke.sh && scripts/launch-check/smoke.sh`
Expected: mostly PASS lines. Treat any FAIL as a real finding to report to Josh (do not soften the check to make it green). WARNs on headers are acceptable at this stage.

- [ ] **Step 3: Run the rate-limit variant once**

Run: `scripts/launch-check/smoke.sh --rate-limit`
Expected: `PASS throttle fired ...` — if the `health-check` limiter allows more than 90/min, raise the loop to 200 or switch the target to `$BASE/api/public/config/social-platforms` (`throttle:public-site`), and note which limiter was verified in the commit message.

- [ ] **Step 4: Commit**

```bash
git add scripts/launch-check/smoke.sh
git commit -m "feat(launch-check): runtime smoke probe (env exposure, debug leakage, 404-not-403, throttle)"
```

---

### Task 5: Supabase config check

**Files:**
- Create: `scripts/launch-check/supabase-check.sh`

**Interfaces:**
- Consumes: `SUPABASE_ACCESS_TOKEN` from `scripts/launch-check/.env` (Task 1).
- Produces: `supabase-check.sh` — exit 0/1, `PASS|FAIL|WARN` lines. Checks: (a) `rowsecurity` on every table in the app schemas, (b) Supabase security advisors, (c) snapshot staleness vs repo migrations.

- [ ] **Step 1: Write the check**

`scripts/launch-check/supabase-check.sh`:
```bash
#!/usr/bin/env bash
# Supabase project-level checks the code audit can never see:
# RLS actually enabled, server-side security advisors, snapshot freshness.
# Targets the DEV project (the de-facto live DB). Requires SUPABASE_ACCESS_TOKEN.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REF="glncumufgaqcmqhzwrxm" # dev ONLY
TOKEN="${SUPABASE_ACCESS_TOKEN:-}"
[[ -z "$TOKEN" && -f "$DIR/.env" ]] && TOKEN=$(grep '^SUPABASE_ACCESS_TOKEN=' "$DIR/.env" | cut -d= -f2-)
[[ -z "$TOKEN" ]] && { echo "FAIL  SUPABASE_ACCESS_TOKEN missing (scripts/launch-check/.env)"; exit 1; }

FAILS=0
pass() { echo "PASS  $1"; }
fail() { echo "FAIL  $1"; FAILS=$((FAILS + 1)); }
warn() { echo "WARN  $1"; }

pg_query() {
    curl -s --max-time 30 -X POST "https://api.supabase.com/v1/projects/$REF/database/query" \
        -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
        -d "$(jq -n --arg q "$1" '{query: $q}')"
}

# --- 1. RLS enabled on every table in the app schemas ---
RLS_OFF=$(pg_query "SELECT schemaname || '.' || tablename AS t FROM pg_tables
    WHERE schemaname IN ('core','site','notifications','analytics','audit','moderation')
    AND rowsecurity = false ORDER BY 1" | jq -r '.[].t' 2>/dev/null)
if [[ -z "$RLS_OFF" ]]; then
    pass "RLS enabled on all app-schema tables"
else
    while IFS= read -r t; do fail "RLS DISABLED: $t"; done <<< "$RLS_OFF"
fi

# --- 2. Supabase security advisors (server-side lint) ---
ADVISORS=$(curl -s --max-time 30 "https://api.supabase.com/v1/projects/$REF/advisors/security" \
    -H "Authorization: Bearer $TOKEN")
if echo "$ADVISORS" | jq -e '.lints' >/dev/null 2>&1; then
    COUNT=$(echo "$ADVISORS" | jq '[.lints[] | select(.level == "ERROR" or .level == "WARN")] | length')
    if [[ "$COUNT" == "0" ]]; then
        pass "security advisors clean"
    else
        fail "security advisors report $COUNT issue(s):"
        echo "$ADVISORS" | jq -r '.lints[] | select(.level == "ERROR" or .level == "WARN") | "      [\(.level)] \(.title): \(.detail // "" | .[0:120])"'
    fi
else
    warn "advisors endpoint unavailable (HTTP shape unexpected) — check manually via Supabase MCP get_advisors"
fi

# --- 3. Snapshot staleness: latest repo migration vs snapshot's recorded one ---
LATEST_REPO=$(ls ../../supabase/migrations/2*.sql 2>/dev/null || ls "$DIR/../../supabase/migrations/"2*.sql)
LATEST_REPO=$(basename "$(echo "$LATEST_REPO" | sort | tail -1)" | cut -d_ -f1)
SNAP=$(jq -r '.latest_migration' "$DIR/schema-snapshot.json" 2>/dev/null || echo "missing")
if [[ "$SNAP" == "$LATEST_REPO" ]]; then
    pass "schema snapshot current (migration $SNAP)"
else
    warn "schema snapshot at $SNAP but repo has $LATEST_REPO — run refresh-schema-snapshot.php (and 'supabase db push' if the migration isn't applied yet)"
fi

echo
if [[ $FAILS -gt 0 ]]; then echo "SUPABASE-CHECK: $FAILS failure(s)"; exit 1; fi
echo "SUPABASE-CHECK: passed"
```

- [ ] **Step 2: Run it and triage**

Run: `chmod +x scripts/launch-check/supabase-check.sh && scripts/launch-check/supabase-check.sh`
Expected: PASS/WARN lines. Known context: the repo applied deliberate RLS migrations (`20260602000000_design_kits_rls.sql`, `20260606030000_moderation_schema_rls.sql`, `20260526210000_b21_public_rls_validation_and_docs.sql`) so many tables should be enabled — any `RLS DISABLED` line is a genuine finding to REPORT to Josh, not auto-fix (RLS changes are DB/migration territory → blocker gate). If the advisors path 404s, verify the current endpoint against https://api.supabase.com/api/v1 and adjust; the MCP fallback keeps the check useful either way.

- [ ] **Step 3: Commit**

```bash
git add scripts/launch-check/supabase-check.sh
git commit -m "feat(launch-check): supabase config check (RLS, security advisors, snapshot staleness)"
```

---

### Task 6: Supply-chain CI steps (gitleaks + npm audit)

**Files:**
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Produces: a new independent `supply-chain` job (doesn't slow the `test` job) with full-history secret scan + worker npm audit. `composer audit` already exists in the `test` job — unchanged.

- [ ] **Step 1: Add the job to ci.yml**

Append under `jobs:` (sibling of `test:`):
```yaml
  supply-chain:
    runs-on: ubuntu-latest
    permissions:
      contents: read
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0   # gitleaks scans full history — a key committed months ago is still live

      - name: Secret scan (gitleaks, full history)
        run: |
          GITLEAKS_VERSION=8.24.3
          curl -sSL "https://github.com/gitleaks/gitleaks/releases/download/v${GITLEAKS_VERSION}/gitleaks_${GITLEAKS_VERSION}_linux_x64.tar.gz" | tar -xz gitleaks
          ./gitleaks detect --source . --no-banner --redact --exit-code 1

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '20'

      - name: npm audit (cloudflare-worker)
        working-directory: cloudflare-worker
        run: npm audit --audit-level=high
```

- [ ] **Step 2: Run both checks locally first (find problems before CI does)**

```bash
# gitleaks full-history — install if absent: brew install gitleaks
gitleaks detect --source . --no-banner --redact || true
cd cloudflare-worker && npm audit --audit-level=high; cd ..
```
Expected: report any gitleaks hits to Josh IMMEDIATELY before pushing the CI step (a hit means a live secret in history → rotation decision is his, and pushing a failing gate first helps nobody). npm audit on a wrangler-only tree is likely clean or low-severity.

- [ ] **Step 3: Validate workflow syntax and commit**

Run: `command -v actionlint >/dev/null && actionlint .github/workflows/ci.yml || echo "actionlint not installed — rely on CI parse"`

```bash
git add .github/workflows/ci.yml
git commit -m "ci: supply-chain job — gitleaks full-history secret scan + worker npm audit"
```

- [ ] **Step 4: Verify on CI**

After the branch is pushed (Josh pushes — house rule), confirm the `supply-chain` job goes green on the PR. If gitleaks flags historical test fixtures/false positives, add a `.gitleaks.toml` with a targeted `[allowlist]` for those paths — never a blanket disable.

---

### Task 7: Manual checklist + runner + README

**Files:**
- Create: `scripts/launch-check/MANUAL-CHECKLIST.md`
- Create: `scripts/launch-check/launch-check.sh`
- Create: `scripts/launch-check/README.md`

**Interfaces:**
- Consumes: `smoke.sh`, `supabase-check.sh` (exit codes + stdout), `SchemaDriftGuardTest` via `php artisan test --filter=`, `composer audit`, `npm audit`.
- Produces: `launch-check.sh [--only schema,smoke,supabase,supply] [--base-url URL] [--rate-limit]` → writes `audits/launch-check/<YYYY-MM-DD>/REPORT.md`, exits non-zero if any group fails.

- [ ] **Step 1: Write MANUAL-CHECKLIST.md**

```markdown
# Launch-Check — Manual Residue

Items no script can verify. Reviewed every run; each needs a human + a date.

## Pre-pilot
- [ ] **k6 load pass** — baseline (10 VU / 5 min, top-5 endpoints) + public `<handle>` spike (50–100 VU): watch edge cache-hit ratio, Supavisor `pg_stat_activity` headroom, p95.
- [ ] **Worker-kill drill** — `horizon:terminate` mid-`ProcessVideoVariantsJob` + mid-`SyncSubdomainToKvJob` on staging; inspect DB/KV state for half-written records; confirm retry converges.
- [ ] **Vendor-outage drill** — force a platform refresh with the vendor faked to 500 (Http::fake or a bad token): connection marked failed cleanly, no retry storm in Horizon.
- [ ] **Nightwatch fires** — throw a deliberate exception on dev; confirm the alert actually arrives.

## Pre-launch
- [ ] **Prod environment decision** — unpause prod Supabase + Laravel Cloud env, or formally commit to dev-serves-both; prod DB re-baseline plan (repo migrations vs pre-standalone prod schema) — gated, Josh decides.
- [ ] **PITR / backups** — confirm plan tier supports PITR; run ONE restore drill to a branch/scratch project.
- [ ] **Cloudflare dashboard** — Cache Deception Armor ON; rate-limiting rules at the edge (not only Laravel); SSL mode Full (strict).
- [ ] **Supabase dashboard** (not fully API-readable) — SSL enforcement ON, network restrictions set, auth rate limits reviewed, custom SMTP.
- [ ] **DAST pass** — OWASP ZAP baseline or Nuclei against staging (headers, cache deception, injection at runtime).
- [ ] **Rollback plan per migration** — every migration since last deploy has a tested reverse path.
- [ ] **Runbooks exist** — DB pool exhausted; queue backed up; vendor API down; Redis down.

## Continuous
- [ ] Re-run launch-check after every migration push and before every promote.
- [ ] Frontend/monorepo has NO audit coverage — separate effort.
```

- [ ] **Step 2: Write the runner**

`scripts/launch-check/launch-check.sh`:
```bash
#!/usr/bin/env bash
# Launch-check runner — "is the RUNNING system right" (counterpart to
# scripts/audit/ which answers "is the code right").
# Usage: launch-check.sh [--only schema,smoke,supabase,supply] [--base-url URL] [--rate-limit]
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$DIR/../.." && pwd)"
ONLY="schema,smoke,supabase,supply"
SMOKE_ARGS=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        --only) ONLY="$2"; shift 2 ;;
        --base-url) SMOKE_ARGS+=(--base-url "$2"); shift 2 ;;
        --rate-limit) SMOKE_ARGS+=(--rate-limit); shift ;;
        *) echo "unknown arg: $1" >&2; exit 2 ;;
    esac
done

OUT_DIR="$ROOT/audits/launch-check/$(date +%F)"
mkdir -p "$OUT_DIR"
REPORT="$OUT_DIR/REPORT.md"
OVERALL=0

run_group() { # $1 name, $2 command string
    echo "════════ $1 ════════"
    local output status
    output=$(eval "$2" 2>&1); status=$?
    echo "$output"
    [[ $status -ne 0 ]] && OVERALL=1
    {
        echo "## $1 — $([[ $status -eq 0 ]] && echo PASS || echo '**FAIL**')"
        echo
        echo '```'
        echo "$output"
        echo '```'
        echo
    } >> "$REPORT"
}

{
    echo "# Launch-Check Report — $(date +%F)"
    echo
    echo "Groups: $ONLY · Target: ${SMOKE_ARGS[*]:-https://dev-api.partna.au (default)}"
    echo
} > "$REPORT"

[[ ",$ONLY," == *",schema,"* ]] && run_group "A · Schema-drift gate" \
    "cd '$ROOT' && php artisan test --filter=SchemaDriftGuardTest --compact"
[[ ",$ONLY," == *",smoke,"* ]] && run_group "B · Runtime smoke probe" \
    "'$DIR/smoke.sh' ${SMOKE_ARGS[*]}"
[[ ",$ONLY," == *",supabase,"* ]] && run_group "C · Supabase config" \
    "'$DIR/supabase-check.sh'"
[[ ",$ONLY," == *",supply,"* ]] && run_group "D · Supply chain" \
    "cd '$ROOT' && composer audit --no-interaction && cd cloudflare-worker && npm audit --audit-level=high"

{
    echo "## E · Manual residue (no script can verify these)"
    echo
    cat "$DIR/MANUAL-CHECKLIST.md"
} >> "$REPORT"

echo
echo "Report: $REPORT"
[[ $OVERALL -eq 0 ]] && echo "LAUNCH-CHECK: all automated groups passed" || echo "LAUNCH-CHECK: FAILURES — see report"
exit $OVERALL
```

- [ ] **Step 3: Write README.md**

```markdown
# launch-check — runtime & config assurance suite

Counterpart to `scripts/audit/` (static code): verifies the **running system**.

| Group | What | How to run alone |
|---|---|---|
| A | Schema drift: SQLite test schema vs live dev Postgres snapshot | `php artisan test --filter=SchemaDriftGuardTest` (runs in CI via composer test) |
| B | Runtime smoke: .env exposure, debug leakage, telescope/horizon gates, 404-not-403, throttle | `scripts/launch-check/smoke.sh [--rate-limit]` |
| C | Supabase: RLS on, security advisors, snapshot staleness | `scripts/launch-check/supabase-check.sh` |
| D | Supply chain: composer audit + worker npm audit (+ gitleaks in CI) | via runner or CI |
| E | Manual residue | `MANUAL-CHECKLIST.md` |

Full run: `scripts/launch-check/launch-check.sh` → `audits/launch-check/<date>/REPORT.md`

**After any schema change:** `supabase db push`, then `php scripts/launch-check/refresh-schema-snapshot.php`, commit the snapshot. If the drift gate then fails, mirror the constraint in `tests/Pest.php` (preferred) or regenerate the baseline (`SCHEMA_DRIFT_BASELINE=1 php artisan test --filter=SchemaDriftGuardTest`).

Setup: `cp .env.example .env` in this dir, add a Supabase PAT.
```

- [ ] **Step 4: Full run + verify report**

Run: `chmod +x scripts/launch-check/launch-check.sh && scripts/launch-check/launch-check.sh`
Expected: four group sections print; `audits/launch-check/<today>/REPORT.md` exists with A–D results + the manual checklist appended; exit code reflects failures honestly.

- [ ] **Step 5: Full test suite green, then commit**

Run: `composer test`
Expected: PASS.

```bash
git add scripts/launch-check/ audits/launch-check/ 2>/dev/null
git add scripts/launch-check/MANUAL-CHECKLIST.md scripts/launch-check/launch-check.sh scripts/launch-check/README.md
git commit -m "feat(launch-check): runner, manual-residue checklist, README"
```

---

## Self-Review (performed)

- **Spec coverage:** all five probe groups have tasks (A→1-3, B→4, C→5, D→6, E→7); runner ties them together (7). CI integration: gate via existing `composer test` (Task 3), supply-chain job (Task 6). ✓
- **Placeholder scan:** all steps carry complete code/commands. Two intentionally flagged uncertainty points (Management API paths in Tasks 1/5) include the exact fallback action, not "TBD". ✓
- **Type consistency:** `Snapshot::fromArray/fromFile`, `SqliteIntrospector` method signatures, and `DriftComparator::compare()` match across Tasks 2/3; finding-key formats identical in comparator, test, and baseline. `SNAPSHOT_PATH`/`BASELINE_PATH` point at `scripts/launch-check/`. ✓

## Blocker-gate notes for the executor

- Task 3 Step 2 (tightening `tests/Pest.php` DDL) may surface real test failures — those are findings, fix the test data, never loosen the constraint back.
- Task 6 Step 2: any gitleaks hit on history → STOP and report to Josh before pushing (rotation decision).
- Task 5: any `RLS DISABLED` finding → report, do not write RLS migrations (DB/migration = blocker gate).
- Josh pushes branches; never push without permission.
