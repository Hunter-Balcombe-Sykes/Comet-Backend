# PG-Lane DDL Drift Guard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Catch, in the fast SQLite lane, every case where application code reads a column that a `tests/Postgres/` stand-in table does not declare — the failure that has taken the PG lane red twice.

**Architecture:** A static scanner reads `app/` query-builder chains into a `table → columns read` map, reads each `tests/Postgres/` file into a `table → columns declared` map, and asserts that for every table a lane file **already provisions**, no column read by the `App\` classes that file imports is missing. Scoping to already-provisioned tables is what preserves deliberately minimal stand-ins: the guard never demands a new table, only that an existing one be complete for the code it is asked to serve. Real drift is fixed with the additive `ALTER TABLE … ADD COLUMN IF NOT EXISTS` heal idiom already used in `SectionOccurrenceOrderingTest.php`, which is also immune to "first creator wins".

**Tech Stack:** PHP 8.4, Pest 4, pure filesystem scanning (no DB access). Guard lives in the Feature lane (`composer test`), alongside the existing `PostgresLaneDdlDriftTest`.

**Spec:** This document is self-contained; §Investigation below is the spec. Prior art it builds on: `tests/Support/Architecture/PostgresLaneDdlScanner.php`, `tests/Feature/Architecture/PostgresLaneDdlDriftTest.php`, `tests/Feature/Architecture/DuplicateStandInDdlGuardTest.php`.

---

## Global Constraints

- **No Laravel migration files, ever.** Composer guard rejects them. This plan creates none.
- **The guard must not touch a database.** Pure filesystem scan, so it is order- and worker-independent under `--parallel`. Do NOT make it introspect a live connection (same rule `DuplicateStandInDdlGuardTest` states for itself).
- **The guard runs in the Feature lane** (`composer test`), NOT `composer test:pg`. Its whole value is failing *before* the expensive Postgres lane runs.
- **The PG lane cannot be verified locally** — it needs a live Postgres this checkout does not have. **CI's `postgres-tests` job is the verification.** A green local run proves nothing about this lane. Every task below says so where it matters.
- **No test's assertions may be weakened.** The only permitted fix for a finding is *adding* a column to a stand-in. If a test only passed because a column was missing, that is a finding to REPORT in the plan's output, not to paper over.
- **Fixture tables must be suffixed** `_probe` / `_scratch` / `_test` (`PostgresLaneDdlScanner::SCRATCH_SUFFIXES`). Do not add a table without checking that guard.
- **`scripts/launch-check/schema-snapshot.json` is STALE and is NOT an oracle.** The oracle is `supabase/migrations/` plus the live dev DB (`glncumufgaqcmqhzwrxm`, read-only).
- Code conventions: 4-space indent, LF, `php artisan pint` before commit. Comment for WHY, not what.

## Coordination

- Work happens in the isolated worktree `.worktrees/pg-ddl-guard` on branch `fix/pg-lane-ddl-drift-guard-2026-09-01`, based on `development` (`db544d9ba`). A checkout switched under a previous session mid-investigation in the primary working directory — that is exactly what this worktree prevents. Do not `git checkout` in the primary directory.
- Sibling worktree `.worktrees/greenup` (`fix/clear-development-blockers-2026-09-01`) holds only `tests/Unit/Site/Pools/PersonNameMatchTest.php`. **No overlap** with `tests/Postgres/` or `app/Ingest/`. Re-check with `git -C ../greenup status --porcelain` before Task 4 edits.
- **Unmerged dependency:** branch `fix/pg-lane-record-state-last-seen-run-2026-09-01` carries two PG-lane fixes not yet on `development`:
  - `1f27e8170` — `record_state.last_seen_run` in the five projection stand-ins
  - `7d11811dc` — `core.partna_staff` for the forked claim race

  On `development` today the lane is still red. Task 4 re-derives the `last_seen_run` fix from the guard's own output, so the two will conflict textually. **Resolution: let that branch merge first, then rebase this one onto it.** If it has not merged by the time Task 4 starts, apply the fix here and expect a trivial conflict resolution at merge (identical added lines).

---

## Investigation (the spec)

### What is broken

`tests/Postgres/` provisions its own schema from hand-written DDL inside the test files: **392 `CREATE TABLE` statements across 56 files**, with the same table redefined many times over — `core.users` ×24, `ingest.sources` ×15, `site.platform_connections` ×15, `site.sites` ×14, `content.items` ×13, `content.collections` ×13, `content.media_assets` ×12, `ingest.record_state` ×9, and ~30 more.

Nothing keeps those copies in step with the code that queries them. When a writer starts reading a new column, the stand-in that lacks it raises `SQLSTATE 42703` **before anything under test is evaluated**, and because the lane shares tables within a run ("first creator wins"), one missing column cascades into dozens of unrelated failures. SQLite stays green throughout, so reviews miss it.

Documented twice:
- **Slice 5a** — a `ProjectionWriter` change turned the lane red for 7 tests; two reviews missed it on a green SQLite run.
- **`da958493e` (2026-09-01)** — added `rs.last_seen_run` to `ProjectionWriter`'s select. Five projection stand-ins lacked the column. 55 test classes failed, the lane stayed red ~15 consecutive runs, and `postgres-tests` is a **required check**, so every merge in that window had no PG signal at all. Patched by hand in PR #316 across five files.

### Why the existing guard did not catch it

`tests/Feature/Architecture/PostgresLaneDdlDriftTest.php` already checks **one direction**: every stand-in column must exist in `supabase/migrations/` (stand-in ⊆ real). That catches *invented* columns — it found `site_media.user_id`, `notifications.read_at`, `pre_account_builds.source_key` on its first run.

It cannot catch the opposite. Its own docblock says so:

> **WHAT IT CANNOT CATCH**, stated so nobody reads it as more than it is: the other direction. A writer that starts touching a table this lane never provisions still 42P01s at runtime and no static scan can see it coming.

`last_seen_run` is a *real* column, correctly present in `supabase/migrations/`, correctly written by `Lander` — the stand-in was simply behind. The existing guard is green on that, by construction. **This plan adds the missing direction.**

### The mechanism, proven

Prototyped during investigation against the real tree:

- `ProjectionWriter` reaches `ingest.record_state` as `DB::table('ingest.record_state as rs')` and selects `['rs.key', 'rs.last_seen_run', 'rv.doc', 'rv.first_seen_at']`. Alias-resolved chain parsing recovers `ingest.record_state → {current_version_id, key, last_seen_run, stream_id, tombstoned_at}` exactly.
- Cross-referencing against the 56 lane files yielded **986 real (file, table, column) assertions across 35 distinct tables** — non-vacuous by a wide margin.
- It flags `ingest.record_state.last_seen_run` on precisely the files PR #316 patched by hand. **The acceptance criterion is satisfiable.**

### Confirmed real drift beyond the known bug

- **`content.media_assets.mirror_eligible` — NEW, latent, 4 files.** `ProjectionWriter::healMirrorEligible()` runs `DB::table('content.media_assets')->whereIn('id', $batch)->whereNull('mirror_eligible')->update([...])`. `ProjectionWriterIdentityRaceTest`, `ProjectionWriterManualCoordRaceTest`, `ProjectionWriterMergeAnchorTest` and `ProjectionWriterScopedResolveTest` all provision `content.media_assets` **without** the column; only `ProjectionWriterBatchingTest` has it. This is the same bug class as `da958493e`, currently latent because the heal only runs when a test seeds assets. Verified by reading both sides.

### Confirmed false-positive classes (these are the parser requirements)

The prototype's first passes produced findings that turned out to be parser defects, not drift. Each yields a hard rule for Task 1:

| Observed false positive | Root cause | Required rule |
|---|---|---|
| `routing.item_tombstones.{surface_key, routing_class, is_primary, resource_id}` | Method-scoped alias maps let unqualified columns from one query attach to another query's table. `SourceReconciler` only reads `user_id`/`source_ref` there; those columns belong to a **deliberately poisoned** `site.platform_connections` in the same file. | Attribute unqualified columns **per query chain**, and only when the chain touches exactly one table. Never method-scoped. |
| `content.items.{coord, source_id, item_id}` | Chain-end detection ran **17,499 characters** past the statement. The chain began inside an enclosing `if (`, so paren depth never returned to 0 and no `;` terminator was found. | Terminate a chain at `;` at depth 0 **or** as soon as depth goes negative (chain began nested), whichever comes first. |
| garbage from `'api.deezer.com'`, `'analytics.ingest.dropped'`, `'author.avatar.url'` | 675 dotted string literals in `app/` look like column references and are not. | Only accept `alias.column` when the alias is bound **in that chain**; only accept `schema.table.column` when `schema.table` is referenced in that chain. Discard everything else. |
| over-reach past comments | Apostrophes inside PHP comments (`item's`) open a phantom string literal. | Skip `//`, `#` and `/* */` comments and double-quoted strings while scanning. |

**Conservative bias is mandatory:** an ambiguous alias (the same short name bound to two tables — `fp` is both `f_place` and `f_published`, `c` is both `collections` and `f_catalog`) must be **skipped, not guessed**. A skipped reference is a missed catch; a guessed one is a false alarm that trains people to ignore the guard.

### What must not break

- **Minimal stand-ins are deliberate.** `CREATE TABLE core.users (id uuid PRIMARY KEY)` exists purely as an FK target. Two properties preserve it: the guard only inspects tables a file **already provisions**, and Eloquent access produces no literal column strings, so a model-driven read never demands a column.
- **Deliberate-omission tests must keep passing unfixed.** `StaffFeatureFlagOverrideEndpointTest`'s negative control queries a column absent from `core.feature_flag_overrides` on purpose, and `SourceReconcilerAtomicityTest`'s `site.platform_connections` is **DELIBERATELY POISONED** with a `CHECK` that rejects every INSERT so a mid-transaction rollback can be observed (its "allowlisted in `no-local-canonical-ddl-baseline.json`" comment is stale — that baseline holds no `tests/Postgres/` entries; `NoLocalCanonicalTableDdlTest` excludes the lane by path). Adding columns to that table could defeat the poison. Both need explicit exemption.
- **The lane's DDL guard naming rule** (`_probe`/`_scratch`/`_test`) still applies to anything new.

### Why a guard rather than a refactor

| Option | Verdict |
|---|---|
| **1. Shared per-table DDL helpers** | Rewrites 56 files — the largest blast radius available — to fix a problem a read-only scan detects. Would have to reinvent per-test opt-in minimalism that already works. Deferred. |
| **2. Generate stand-ins from `supabase/migrations/`** | Eliminates drift by construction, but forces full-fat tables (slower lane, hides bugs the user explicitly wants kept visible), and cannot express a deliberately poisoned or deliberately incomplete table at all. Rejected. |
| **3. Drift guard (this plan)** | Purely additive; nothing existing changes behaviour. Turns a silent 15-run outage into an immediate red in the *cheap* lane. Fixes are one-line additive column adds that cannot weaken an assertion. **Recommended, and adopted.** |

Option 3 is the best value per unit of risk, and the `ADD COLUMN IF NOT EXISTS` heal idiom already present in the lane gives it a safe, ready-made fix path that also neutralises "first creator wins".

---

## File Structure

| File | Responsibility |
|---|---|
| `tests/Support/Architecture/AppColumnReadScanner.php` *(create)* | Parse `app/` into `fqcn → table → columns read`. Chain-scoped, conservative. Pure functions, no DB, no Laravel container. |
| `tests/Support/Architecture/PostgresLaneDdlScanner.php` *(modify)* | Add `laneDdlWithHeals()` — per-file `table → declared columns`, including `ALTER TABLE … ADD COLUMN IF NOT EXISTS` and the `foreach ['table' => ['col' => 'type']]` heal idiom. Existing methods untouched. |
| `tests/Feature/Architecture/PostgresLaneReadCoverageTest.php` *(create)* | The guard. Cross-references the two scanners, holds the exemption list and the non-vacuity assertions. |
| `tests/Unit/Architecture/AppColumnReadScannerTest.php` *(create)* | Unit tests for the parser against inline fixture strings — including one per false-positive class in the table above. |
| `tests/Postgres/*.php` *(modify, Task 4 only)* | Additive column heals for confirmed drift. |
| `CLAUDE.md` *(modify, Task 6)* | Point the existing ProjectionWriter warning at the new guard. |

---

## Task 1: The app-side column-read scanner

**Files:**
- Create: `tests/Support/Architecture/AppColumnReadScanner.php`
- Test: `tests/Unit/Architecture/AppColumnReadScannerTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `AppColumnReadScanner::scanSource(string $php): array<string, list<string>>` — `"schema.table" => column names`, for one file's source.
  - `AppColumnReadScanner::scanTree(string $appDir): array<string, array<string, list<string>>>` — `fqcn => table => columns`.
  - `AppColumnReadScanner::fqcnOf(string $php, string $path): string`

- [ ] **Step 1: Write the failing test**

```php
<?php

use Tests\Support\Architecture\AppColumnReadScanner;

it('resolves an aliased table and its qualified select columns', function () {
    $php = <<<'PHP'
    <?php
    class W {
        public function run() {
            $records = DB::table('ingest.record_state as rs')
                ->join('ingest.record_versions as rv', 'rv.id', '=', 'rs.current_version_id')
                ->where('rs.stream_id', $streamId)
                ->select(['rs.key', 'rs.last_seen_run', 'rv.doc'])
                ->get();
        }
    }
    PHP;

    $refs = AppColumnReadScanner::scanSource($php);

    expect($refs['ingest.record_state'])->toContain('key')
        ->and($refs['ingest.record_state'])->toContain('last_seen_run')
        ->and($refs['ingest.record_state'])->toContain('stream_id')
        ->and($refs['ingest.record_versions'])->toContain('doc');
});

it('attributes unqualified columns only on a single-table chain', function () {
    $php = <<<'PHP'
    <?php
    class W {
        public function heal() {
            DB::table('content.media_assets')
                ->whereIn('id', $batch)
                ->whereNull('mirror_eligible')
                ->update(['mirror_eligible' => true]);
        }
    }
    PHP;

    expect(AppColumnReadScanner::scanSource($php)['content.media_assets'])
        ->toContain('mirror_eligible')
        ->toContain('id');
});

it('does not attribute unqualified columns when the chain joins a second table', function () {
    $php = <<<'PHP'
    <?php
    class W {
        public function run() {
            DB::table('content.items')
                ->join('content.source_items as si', 'si.item_id', '=', 'content.items.id')
                ->where('coord', $c)
                ->get();
        }
    }
    PHP;

    // 'coord' is ambiguous across the two tables — must be dropped, not guessed.
    expect(AppColumnReadScanner::scanSource($php)['content.items'] ?? [])->not->toContain('coord');
});

it('terminates a chain that begins inside an enclosing paren', function () {
    // Regression: depth never returns to 0, so a naive scan ran 17499 chars on.
    $php = <<<'PHP'
    <?php
    class W {
        public function run() {
            if (DB::table('content.items')->where('user_id', $u)->exists()) {
                $x = 1;
            }
            DB::table('content.source_items')->where('coord', $c)->get();
        }
    }
    PHP;

    $refs = AppColumnReadScanner::scanSource($php);

    expect($refs['content.items'])->toContain('user_id')
        ->and($refs['content.items'])->not->toContain('coord')
        ->and($refs['content.source_items'])->toContain('coord');
});

it('ignores dotted string literals that are not column references', function () {
    $php = <<<'PHP'
    <?php
    class W {
        public function run() {
            Log::info('analytics.ingest.dropped');
            $host = 'api.deezer.com';
            DB::table('content.items')->where('id', $id)->get();
        }
    }
    PHP;

    $refs = AppColumnReadScanner::scanSource($php);

    expect(array_keys($refs))->toBe(['content.items'])
        ->and($refs['content.items'])->toContain('id');
});

it('skips an alias bound to two different tables rather than guessing', function () {
    $php = <<<'PHP'
    <?php
    class W {
        public function run() {
            DB::table('content.f_place as fp')
                ->join('content.f_published as fp', 'fp.item_id', '=', 'x.id')
                ->select(['fp.name'])
                ->get();
        }
    }
    PHP;

    $refs = AppColumnReadScanner::scanSource($php);

    expect($refs['content.f_place'] ?? [])->not->toContain('name')
        ->and($refs['content.f_published'] ?? [])->not->toContain('name');
});

it('resolves a fully qualified schema.table.column reference', function () {
    $php = <<<'PHP'
    <?php
    class W {
        public function run() {
            DB::table('content.sources')
                ->join('site.platform_connections', 'site.platform_connections.id', '=', 'content.sources.connection_id')
                ->whereNull('site.platform_connections.deleted_at')
                ->get();
        }
    }
    PHP;

    expect(AppColumnReadScanner::scanSource($php)['site.platform_connections'])
        ->toContain('deleted_at')->toContain('id');
});

it('is not confused by an apostrophe inside a PHP comment', function () {
    $php = <<<'PHP'
    <?php
    class W {
        public function run() {
            // Set only by the mapper's per-platform offers pass.
            DB::table('content.items')->where('removed_at', null)->get();
        }
    }
    PHP;

    expect(AppColumnReadScanner::scanSource($php)['content.items'])->toContain('removed_at');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Unit/Architecture/AppColumnReadScannerTest.php`
Expected: FAIL — `Class "Tests\Support\Architecture\AppColumnReadScanner" not found`.

- [ ] **Step 3: Implement the scanner**

Create `tests/Support/Architecture/AppColumnReadScanner.php`. Implement to these rules, in this order:

1. **Strip PHP comments and double-quoted strings** before any scanning (`//`, `#`, `/* */`, `"…"`). Single-quoted strings are the payload — keep them, but treat `''` as an escaped quote.
2. **Find chain starts**: `DB::table('T')`, `DB::table('T as a')`, `->table('T')`.
3. **Find the chain end**: scan forward from the start tracking paren depth, skipping single-quoted literals. Stop at the first `;` at depth 0, **or** the first point where depth goes negative (the chain began inside an enclosing call). Whichever comes first.
4. **Build this chain's table set and alias map** from the primary table plus any `->from(`/`->join(`/`->leftJoin(`/`->rightJoin(`/`->joinSub(`/`->table(` inside the chain. An alias bound to two different tables is poisoned to `null` and every reference through it is discarded.
5. **Attribute columns**, discarding anything that does not match:
   - `'alias.column'` → the alias's table, if bound and not poisoned.
   - `'schema.table.column'` → that table, if it is in this chain's table set.
   - bare `'column'` inside `->where|orWhere|whereNull|whereNotNull|whereIn|whereNotIn|select|addSelect|orderBy|groupBy|value|pluck|increment|decrement(` → the primary table, **only if the chain's table set has exactly one entry**.
   - `'column' =>` keys inside `->update([…])`/`->insert([…])`/`->insertOrIgnore([…])`/`->upsert([…])` → the primary table, same single-table condition.
6. **Strip JSON arrow paths**: `display_settings->auto_sync_latest` contributes `display_settings` only.
7. Return `table => sorted unique column list`.

`scanTree()` walks `app/` recursively, keys results by `fqcnOf()` (namespace + class/trait/interface name), and returns them.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Unit/Architecture/AppColumnReadScannerTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 5: Sanity-check against the real writer**

Run:
```bash
php -r '
require "tests/Support/Architecture/AppColumnReadScanner.php";
$r = Tests\Support\Architecture\AppColumnReadScanner::scanSource(file_get_contents("app/Ingest/Projection/ProjectionWriter.php"));
var_dump($r["ingest.record_state"]);'
```
Expected: contains `key`, `last_seen_run`, `stream_id`, `tombstoned_at`, `current_version_id`. It must **not** contain `coord` or `source_id` (those belong to `content.source_items` — their presence means chain over-reach regressed).

- [ ] **Step 6: Commit**

```bash
php artisan pint tests/Support/Architecture/AppColumnReadScanner.php tests/Unit/Architecture/AppColumnReadScannerTest.php
git add tests/Support/Architecture/AppColumnReadScanner.php tests/Unit/Architecture/AppColumnReadScannerTest.php
git commit -m "test(arch): scanner for columns app code reads per table

Chain-scoped and deliberately conservative: an ambiguous alias is skipped
rather than guessed, because a false alarm trains people to ignore a guard
and a missed catch only costs what we already pay."
```

---

## Task 2: Heal-aware stand-in column reader

**Files:**
- Modify: `tests/Support/Architecture/PostgresLaneDdlScanner.php`
- Test: `tests/Unit/Architecture/PostgresLaneDdlScannerHealTest.php` *(create)*

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: `PostgresLaneDdlScanner::laneDdlByFile(string $laneDir): array<string, array<string, list<string>>>` — `basename.php => table => declared columns`, including additively healed columns.

Existing `realSchema()`, `laneDdl()`, `drift()`, `isScratch()` must keep their current signatures and behaviour — `PostgresLaneDdlDriftTest` depends on them.

- [ ] **Step 1: Write the failing test**

```php
<?php

use Tests\Support\Architecture\PostgresLaneDdlScanner;

it('counts columns added by an ADD COLUMN IF NOT EXISTS heal', function () {
    $dir = sys_get_temp_dir().'/pglane_'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/ExampleTest.php', <<<'PHP'
    <?php
    $pg->statement('CREATE TABLE IF NOT EXISTS site.platform_connections (
        id uuid PRIMARY KEY,
        user_id uuid NULL
    )');
    $pg->statement('ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS is_active boolean NOT NULL DEFAULT true');
    PHP);

    $byFile = PostgresLaneDdlScanner::laneDdlByFile($dir);

    expect($byFile['ExampleTest.php']['site.platform_connections'])
        ->toContain('id')->toContain('user_id')->toContain('is_active');
});

it('counts columns added by the foreach heal-array idiom', function () {
    $dir = sys_get_temp_dir().'/pglane_'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/HealTest.php', <<<'PHP'
    <?php
    $pg->statement('CREATE TABLE IF NOT EXISTS content.sources (
        id uuid PRIMARY KEY
    )');
    foreach ([
        'content.sources' => ['connection_id' => 'uuid', 'kind' => "text NOT NULL DEFAULT 'manual'"],
    ] as $table => $columns) {
        foreach ($columns as $col => $type) {
            $pg->statement("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$col} {$type}");
        }
    }
    PHP);

    $byFile = PostgresLaneDdlScanner::laneDdlByFile($dir);

    expect($byFile['HealTest.php']['content.sources'])
        ->toContain('connection_id')->toContain('kind');
});

it('leaves the existing drift() contract untouched', function () {
    $drift = PostgresLaneDdlScanner::drift(base_path('supabase/migrations'), base_path('tests/Postgres'));

    expect($drift)->toHaveKeys(['tables', 'columns']);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Unit/Architecture/PostgresLaneDdlScannerHealTest.php`
Expected: FAIL — `Call to undefined method … ::laneDdlByFile()`.

- [ ] **Step 3: Implement `laneDdlByFile()`**

Add to `PostgresLaneDdlScanner`, reusing the existing `parseCreateTables()`/`matchingParen()`/`splitTopLevel()` helpers:

```php
/**
 * Per-file declared columns, INCLUDING additively healed ones.
 *
 * The lane heals as well as creates: SectionOccurrenceOrderingTest pairs
 * CREATE TABLE IF NOT EXISTS with ALTER TABLE … ADD COLUMN IF NOT EXISTS
 * precisely because whoever runs first decides the shape, and a bare
 * CREATE IF NOT EXISTS would inherit a thinner earlier table. A reader that
 * counted only CREATE bodies would report those healed columns as missing.
 *
 * @return array<string, array<string, list<string>>> basename => table => columns
 */
public static function laneDdlByFile(string $laneDir): array
```

Implementation notes:
- Start from `parseCreateTables()` on the comment-stripped source.
- Add literal heals: `/alter\s+table\s+([a-z_0-9.]+)\s+add\s+column\s+(?:if\s+not\s+exists\s+)?([a-z_0-9]+)/i`.
- Add interpolated heals from the `foreach ['schema.table' => ['col' => 'type']]` idiom: match `'([a-z_]+\.[a-z_]+)'\s*=>\s*\[` then take the `'col' =>` keys from the balanced array body. Only apply to tables the file already declares.
- Key the outer array by `basename($file)`.

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test tests/Unit/Architecture/PostgresLaneDdlScannerHealTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 5: Confirm the existing drift guard still passes**

Run: `php artisan test tests/Feature/Architecture/PostgresLaneDdlDriftTest.php`
Expected: PASS — 3 tests, unchanged.

- [ ] **Step 6: Commit**

```bash
php artisan pint tests/Support/Architecture/PostgresLaneDdlScanner.php tests/Unit/Architecture/PostgresLaneDdlScannerHealTest.php
git add tests/Support/Architecture/PostgresLaneDdlScanner.php tests/Unit/Architecture/PostgresLaneDdlScannerHealTest.php
git commit -m "test(arch): read PG-lane stand-in columns per file, heals included

ADD COLUMN IF NOT EXISTS is how this lane survives first-creator-wins, so a
reader that counts only CREATE bodies reports healed columns as missing."
```

---

## Task 3: The guard

**Files:**
- Create: `tests/Feature/Architecture/PostgresLaneReadCoverageTest.php`

**Interfaces:**
- Consumes: `AppColumnReadScanner::scanTree()` (Task 1), `PostgresLaneDdlScanner::laneDdlByFile()` (Task 2).
- Produces: nothing consumed downstream.

**This task ships the guard GREEN.** Any real drift it surfaces is recorded in an explicit, documented exemption list here and drained in Task 4 — the list is a **work queue, not a resolution**, exactly as `DuplicateStandInDdlGuardTest` frames its baseline.

- [ ] **Step 1: Write the guard**

```php
<?php

use Tests\Support\Architecture\AppColumnReadScanner;
use Tests\Support\Architecture\PostgresLaneDdlScanner;

/**
 * G — a tests/Postgres/ stand-in must declare every column the app code that
 * file drives actually reads from it.
 *
 * THE COVERAGE HOLE THIS FILLS. PostgresLaneDdlDriftTest checks stand-in ⊆
 * real schema: it catches a column the lane INVENTED. Its own docblock states
 * it cannot catch the other direction, and the other direction is what has
 * taken this lane red twice — slice 5a (7 tests), and da958493e, which added
 * rs.last_seen_run to ProjectionWriter's select and left 55 test classes
 * failing for ~15 consecutive runs while postgres-tests, a REQUIRED check,
 * gave no signal at all. In both cases the schema was right and the code was
 * right; only the hand-written stand-in was behind, and a green SQLite run
 * said nothing about it.
 *
 * SCOPE, and why it is drawn here. Only tables a lane file ALREADY PROVISIONS
 * are checked. This guard never demands a new table, because minimal
 * stand-ins are deliberate — `CREATE TABLE core.users (id uuid PRIMARY KEY)`
 * exists purely as an FK target, and forcing full-fat tables everywhere would
 * slow the lane and hide bugs. Eloquent access emits no literal column
 * strings, so a model-driven read never demands a column either. The
 * remaining failure mode — a writer touching a table this lane never
 * provisions at all — stays uncatchable by any static scan, exactly as
 * PostgresLaneDdlDriftTest says.
 *
 * THE FIX FOR A FINDING IS ALWAYS ADDITIVE: add the column to the stand-in,
 * preferably via ALTER TABLE … ADD COLUMN IF NOT EXISTS so it heals whichever
 * file loses the first-creator-wins race. Never delete an assertion, and never
 * thin a table to silence this. If a test only passed because a column was
 * missing, that is a finding to report, not to paper over.
 */
it('declares every column the app code a PG-lane file drives reads from a table it provisions', function () {
    $appRefs = AppColumnReadScanner::scanTree(base_path('app'));
    $byFile = PostgresLaneDdlScanner::laneDdlByFile(base_path('tests/Postgres'));

    // (file, schema.table) pairs whose stand-in is deliberately not faithful.
    $exempt = [
        // DELIBERATELY POISONED: a CHECK that lets every SELECT succeed and
        // rejects every INSERT, so applyIntent() fails mid-transaction and the
        // rollback can be observed. Completing this table defeats the test.
        // NOTE: that file's own comment claims it is "ALLOWLISTED in
        // no-local-canonical-ddl-baseline.json" — it is not, and never needed
        // to be. That baseline holds no tests/Postgres/ entries at all;
        // NoLocalCanonicalTableDdlTest excludes the lane BY PATH. The comment
        // is stale, verified 2026-09-01. This guard has no path exclusion, so
        // the exemption has to be explicit here.
        'SourceReconcilerAtomicityTest.php|site.platform_connections',
        'SourceReconcilerConnectionRacePgTest.php|site.platform_connections',
    ];

    $findings = [];
    foreach (glob(base_path('tests/Postgres').'/*.php') ?: [] as $path) {
        $file = basename($path);
        $standIns = $byFile[$file] ?? [];
        if ($standIns === []) {
            continue;
        }

        $source = (string) file_get_contents($path);
        preg_match_all('/^use (App\\\\[\w\\\\]+);/m', $source, $imports);

        foreach ($imports[1] as $fqcn) {
            foreach ($appRefs[$fqcn] ?? [] as $table => $columns) {
                if (! isset($standIns[$table]) || PostgresLaneDdlScanner::isScratch($table)) {
                    continue;
                }
                if (in_array($file.'|'.$table, $exempt, true)) {
                    continue;
                }

                foreach ($columns as $column) {
                    if (! in_array($column, $standIns[$table], true)) {
                        $findings[] = sprintf(
                            '%s — %s.%s, read by %s',
                            $file, $table, $column, class_basename($fqcn)
                        );
                    }
                }
            }
        }
    }

    $findings = array_values(array_unique($findings));
    sort($findings);

    expect($findings)->toBe([], "PG-lane stand-ins are missing columns the code under test reads.\n".
        "Each one is an SQLSTATE 42703 that fires BEFORE the assertion it is hiding, and because\n".
        "this lane shares tables, one missing column cascades into dozens of unrelated failures:\n  ".
        implode("\n  ", $findings).
        "\n\nFix by ADDING the column to the stand-in — ideally\n".
        "  ALTER TABLE <table> ADD COLUMN IF NOT EXISTS <column> <type>\n".
        'so it heals whichever file loses the first-creator-wins race. Never thin a table to silence this.');
});

// A scanner that silently matched nothing would make the gate above vacuous and
// permanently green. These pin that both sides actually parsed, and that the
// cross-reference actually evaluated something.
it('cross-references a meaningful number of columns rather than passing on an empty scan', function () {
    $appRefs = AppColumnReadScanner::scanTree(base_path('app'));
    $byFile = PostgresLaneDdlScanner::laneDdlByFile(base_path('tests/Postgres'));

    $appTables = [];
    foreach ($appRefs as $tables) {
        foreach ($tables as $table => $columns) {
            $appTables[$table] = true;
        }
    }

    expect(count($byFile))->toBeGreaterThanOrEqual(50)
        ->and(count($appTables))->toBeGreaterThanOrEqual(40)
        ->and($appRefs['App\Ingest\Projection\ProjectionWriter']['ingest.record_state'] ?? [])
        ->toContain('last_seen_run', 'key', 'stream_id');
});

it('does not mistake a dotted literal for a column reference', function () {
    $refs = AppColumnReadScanner::scanSource("<?php Log::info('analytics.ingest.dropped'); \$h = 'api.deezer.com';");

    expect($refs)->toBe([]);
});
```

- [ ] **Step 2: Run it and record the finding set**

Run: `php artisan test tests/Feature/Architecture/PostgresLaneReadCoverageTest.php`
Expected: **FAIL**, listing findings. Capture the full list — it is Task 4's input.

```bash
php artisan test tests/Feature/Architecture/PostgresLaneReadCoverageTest.php \
  > /tmp/pg-read-coverage-findings.txt 2>&1; cat /tmp/pg-read-coverage-findings.txt
```

- [ ] **Step 3: Triage every finding into real / parser defect**

For each finding, open **both** sides and decide. Do not batch-trust.
- Does the app class genuinely read that column from that table? (`grep -n '<column>' app/<path>`)
- Does the stand-in genuinely lack it? (`awk '/CREATE TABLE <table>/,/\)\x27\)/' tests/Postgres/<file>`)

If the app side is wrong, it is a **parser defect** — go back to Task 1, add a unit test reproducing it, tighten the rule, re-run. Do **not** add it to `$exempt` to make the guard green; an exemption is only for a stand-in that is deliberately unfaithful (the poisoned-table case).

Known-real going in, from investigation — expect these and confirm rather than rediscover:
- `ingest.record_state.last_seen_run` — `ProjectionIdentityKeyAtomicityTest`, `ProjectionWriterBatchingTest`, `ProjectionWriterConnectionSourceRaceTest`, `ProjectionWriterScopedResolveTest`, `IngestProjectChunkingTest` (the `da958493e` regression).
- `content.media_assets.mirror_eligible` — `ProjectionWriterIdentityRaceTest`, `ProjectionWriterManualCoordRaceTest`, `ProjectionWriterMergeAnchorTest`, `ProjectionWriterScopedResolveTest` (new, latent, via `healMirrorEligible()`).

- [ ] **Step 4: Move confirmed-real findings into a documented work queue**

The guard must ship green. Add each confirmed-real `file|table.column` to a `$knownDrift` array **separate from `$exempt`**, with a header comment stating it is a work queue drained in Task 4, and subtract it from `$findings` before the assertion. Emit a `fwrite(STDERR, …)` note listing how many remain, mirroring `DuplicateStandInDdlGuardTest`'s `$drained` signal.

- [ ] **Step 5: Verify green**

Run: `php artisan test tests/Feature/Architecture/PostgresLaneReadCoverageTest.php`
Expected: PASS, 3 tests, with the STDERR work-queue note.

- [ ] **Step 6: Commit**

```bash
php artisan pint tests/Feature/Architecture/PostgresLaneReadCoverageTest.php
git add tests/Feature/Architecture/PostgresLaneReadCoverageTest.php
git commit -m "test(arch): guard that PG-lane stand-ins carry the columns their code reads

PostgresLaneDdlDriftTest checks stand-in ⊆ real schema and says in its own
docblock it cannot check the other direction. The other direction is the one
that took the lane red twice, most recently for ~15 runs behind a required
check. Ships green with the confirmed drift as a documented work queue."
```

---

## Task 4: Drain the work queue

**Files:**
- Modify: `tests/Postgres/*.php` — only the files named by confirmed-real findings.
- Modify: `tests/Feature/Architecture/PostgresLaneReadCoverageTest.php` — remove each drained entry from `$knownDrift`.

**Interfaces:**
- Consumes: the confirmed-real list from Task 3 Step 3.
- Produces: an empty `$knownDrift`.

**Before starting:** re-check the coordination section. Run `git -C ../greenup status --porcelain` and confirm no sibling worktree holds a file you are about to edit. Confirm whether `fix/pg-lane-record-state-last-seen-run-2026-09-01` has merged to `development`; if it has, rebase before editing so the `last_seen_run` entries drop out on their own.

- [ ] **Step 1: Fix one table's drift, additively**

Take the first confirmed-real `(file, table, column)`. Determine the column's real type from the oracle — `supabase/migrations/`, cross-checked against dev if needed:

```bash
grep -rn "mirror_eligible" supabase/migrations/ | head
```

For this one the oracle answer is already known — `20260819004000_media_assets_mirror_eligible.sql` declares `ADD COLUMN "mirror_eligible" boolean`, i.e. **nullable boolean, no default**. The nullability is load-bearing: `healMirrorEligible()` keys off `whereNull('mirror_eligible')` to find rows predating the column, so adding it `NOT NULL DEFAULT false` would silently make that heal a no-op and the test would prove nothing.

Add it to the stand-in. Prefer the additive heal so the fix survives first-creator-wins:

```php
$pg->statement('ALTER TABLE content.media_assets ADD COLUMN IF NOT EXISTS mirror_eligible boolean');
```

Place it immediately after that table's `CREATE TABLE`. If the file already declares the column list inline and owns the table unambiguously (it `DROP`s it at the top of `beforeEach`), adding the column to the `CREATE` body is equally correct — match the file's existing style, and copy type and position from a sibling stand-in that already has it.

- [ ] **Step 2: Confirm the type matches the real schema**

Run: `php artisan test tests/Feature/Architecture/PostgresLaneDdlDriftTest.php`
Expected: PASS. This is the existing guard proving the column you just added is a real column with a real name — the two guards check opposite directions and both must hold.

- [ ] **Step 3: Remove that entry from `$knownDrift` and re-run**

Run: `php artisan test tests/Feature/Architecture/PostgresLaneReadCoverageTest.php`
Expected: PASS with a shorter work queue.

- [ ] **Step 4: Repeat Steps 1–3 until `$knownDrift` is empty**

When it is empty, delete the `$knownDrift` array and the STDERR note entirely, leaving only `$exempt`.

- [ ] **Step 5: Report anything that only passed because a column was missing**

If, while draining, you find a test whose assertion **only held** because a column was absent, do NOT adjust the assertion. Record it in the commit body and in this plan's Findings section below, and raise it. That is a real defect in the test, and it is the user's call how to close it.

- [ ] **Step 6: Full Feature suite, then commit**

Run: `composer test`
Expected: PASS. (This does **not** verify the PG lane — see Task 5.)

```bash
php artisan pint tests/Postgres tests/Feature/Architecture
git add tests/Postgres tests/Feature/Architecture/PostgresLaneReadCoverageTest.php
git commit -m "fix(pg-lane): declare the columns the writers actually read

Drains the read-coverage work queue. Every change is additive — a column
added to a stand-in, never an assertion relaxed."
```

---

## Task 5: Prove the guard catches the regression it exists for

**Files:** none committed. This task produces evidence, in a throwaway commit that is reset.

**Acceptance:** a remedy that does not reproduce a red on the exact `da958493e` change has not been shown to work.

- [ ] **Step 1: Re-introduce the regression in a scratch commit**

```bash
git checkout -b scratch/prove-guard-catches-last-seen-run
# Remove the last_seen_run declaration from every projection stand-in that has it.
for f in ProjectionWriterBatchingTest ProjectionWriterScopedResolveTest \
         ProjectionWriterConnectionSourceRaceTest ProjectionIdentityKeyAtomicityTest \
         IngestProjectChunkingTest; do
  perl -0pi -e 's/^[ \t]*last_seen_run uuid,\n//m' tests/Postgres/$f.php
done
git commit -am "scratch: revert last_seen_run to prove the guard fails red"
```

- [ ] **Step 2: Run the guard and capture the red**

Run: `php artisan test tests/Feature/Architecture/PostgresLaneReadCoverageTest.php`
Expected: **FAIL**, naming `ingest.record_state.last_seen_run` on each of the five files, attributed to `ProjectionWriter`.

Save the output — it is the evidence this plan's acceptance criterion demands:

```bash
php artisan test tests/Feature/Architecture/PostgresLaneReadCoverageTest.php \
  > /tmp/pg-guard-red-proof.txt 2>&1; cat /tmp/pg-guard-red-proof.txt
```

- [ ] **Step 3: Confirm the OLD guard stays green on the same change**

Run: `php artisan test tests/Feature/Architecture/PostgresLaneDdlDriftTest.php`
Expected: **PASS**. This is the point: the pre-existing guard is blind to this regression, which is why the new one had to exist. Record this alongside the red.

- [ ] **Step 4: Discard the scratch branch**

```bash
git checkout fix/pg-lane-ddl-drift-guard-2026-09-01
git branch -D scratch/prove-guard-catches-last-seen-run
git status --porcelain   # must be clean of tests/Postgres changes
```

- [ ] **Step 5: Push and let CI verify the PG lane**

```bash
git push -u origin fix/pg-lane-ddl-drift-guard-2026-09-01
```

**The PG lane cannot be verified locally — it needs a live Postgres this checkout does not have.** Watch the `postgres-tests` job specifically; `PG_LANE_REQUIRED=1` means it fails rather than skips if the container does not come up. A green `composer test` says nothing about it.

```bash
gh pr create --base development --fill
gh pr checks --watch
```

Expected: `postgres-tests` **green**. If it is red, read the failure before touching anything — a stand-in missing a table (`42P01`) is outside this guard's reach by design and is a separate fix.

---

## Task 6: Record the lesson where the next session will read it

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Point the existing warning at the guard**

`CLAUDE.md` currently says, under Workflow:

> **Touching `app/Ingest/Projection/ProjectionWriter.php` means running `tests/Postgres/` (`composer test:pg`), not just `tests/Feature/Ingest/`.** That lane's stand-in DDL is hand-written and drifts silently from writer changes — slice 5a turned it red for 7 tests and two reviews missed it on a green SQLite run.

Extend it — do not replace it — with the guard, keeping the existing text intact:

```markdown
Since 2026-09-01 that drift is caught statically by
`tests/Feature/Architecture/PostgresLaneReadCoverageTest.php`, which runs in the
CHEAP lane (`composer test`) and asserts every column the app code reads from a
table is declared by the stand-ins of the tests that drive it. It is the
complement of `PostgresLaneDdlDriftTest` (stand-in ⊆ real schema), which by its
own docblock cannot see this direction. Fix a finding by ADDING the column —
`ALTER TABLE … ADD COLUMN IF NOT EXISTS` so it survives first-creator-wins —
never by thinning a table or relaxing an assertion. A writer touching a table
the lane never provisions at all (`42P01`) is still only caught by CI.
```

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: point the ProjectionWriter drift warning at the new guard"
git push
```

---

## Findings to report (fill in during Task 4)

Anything discovered that is NOT fixed by this plan, per the "no weakened assertions" constraint:

- *(Task 4 Step 5: record any test that only passed because a column was missing.)*
- *(Task 3 Step 3: record any finding judged a parser defect that was tightened rather than exempted.)*

Carried in from investigation, unresolved by this plan:
- **`content.media_assets.mirror_eligible` was latent drift already on `development`** before this plan started — four projection stand-ins lack a column `ProjectionWriter::healMirrorEligible()` writes. It had not yet fired only because the heal runs on seeded assets. Fixed in Task 4; noted here because it is a third instance of the same bug class and evidence the guard was overdue.
- **A writer touching a table the lane never provisions raises `42P01` and no static scan can catch it.** Unchanged by this plan; contained only by CI and by `PostgresTestCase::setUp()`'s `Queue::fake()`.
