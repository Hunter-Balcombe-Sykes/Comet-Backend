# Handoff prompt — T3 follow-up: finish the schema-drift gate for the 5 write-heavy tables

Paste everything below the line into a fresh Claude Code session in this repo.

---

Finish what the T3 triage (`21a18d53`, already on origin/development) deliberately deferred: enforce the last four `core.users` NOT NULL constraints in the SQLite test schema, close the "local-DDL" coverage hole so those inserts can't seed prod-violating rows unseen, and (optional) restore discriminating power to two assertions the T3 pass weakened. Independent-review the shipped T3 change while here.

## Background — what T3 already did (do NOT redo)
`21a18d53 test(schema-drift): T3 — un-grandfather the dangerous constraints on the 5 write-heavy tables` tightened `tests/Pest.php` for `site.platform_connections`, `site.sites`, `site.design_kits`, `site.menus`, `core.users` and shrank `scripts/launch-check/schema-drift-baseline.json` from 330 → 266. It closed 39 of the 43 findings on those 5 tables. The gate is `tests/Feature/Architecture/SchemaDriftGuardTest.php`; ground truth is `scripts/launch-check/schema-snapshot.json` (NOT the baseline — the baseline can lag the snapshot). The central seed helpers `createTenant()` (tests/Pest.php) and `Database\Factories\UserFactory` are ALREADY correct — they supply every name column. Do not touch them.

**The 4 still-grandfathered findings** (confirm with `grep 'core.users' scripts/launch-check/schema-drift-baseline.json`):
`not_null_missing:core.users.handle`, `.handle_lc`, `.display_name`, `.first_name` — all NOT NULL in prod, no DEFAULT (so the SQLite columns become `NOT NULL` with no default, and every insert must supply them).

---

## Task A — tighten the 4 core.users name columns (PRIMARY; the actual T3 follow-up)

**Do this in an isolated worktree** off origin/development: `git worktree add ../backend-wt/t3-followup -b launch-check-t3-followup origin/development`, its OWN `composer install`, and **copy** `.env` from the main checkout (copy, never symlink — symlinked vendor/.env breaks feature tests here). Stage EXPLICIT paths only — NEVER `git add -A`. Forbid `git stash` entirely (subagents included).

1. Baseline the worktree green first: full `composer test` (expect ~5128 passed / 0 failed). Also snapshot the pre-change drift baseline so you can attribute removals later: `SCHEMA_DRIFT_BASELINE=1 php artisan test --filter=SchemaDriftGuardTest` into a scratch copy, then `git checkout scripts/launch-check/schema-drift-baseline.json`.
2. In `tests/Pest.php` `setupUsersTable()`, change `handle`, `handle_lc`, `display_name`, `first_name` from `TEXT NULL` to `TEXT NOT NULL` (no DEFAULT — prod has none). Remove the deferral comment block that grandfathers them.
3. Run the FULL suite and fix fallout FORWARD. Expect ~229 files, dominated by missing `first_name` (~287 insert blocks), then `display_name` (~54), `handle_lc` (~53), `handle` (~29). Every failure is a raw `core.users` insert / `User::create` that omits a name column — a row prod's NOT NULL would reject. Supply a sensible value (derive from an adjacent column, e.g. `first_name => ucfirst($handle)`; keep `handle`/`handle_lc` consistent with each other). **Never loosen or drop the constraint to make a test pass.**
   - Leverage per-file seed helpers: many files funnel inserts through a local `seedUser()`/`createX()` — fix the helper once, not each call. Watch for `array_merge([...], $overrides)` helpers (e.g. the T3 pass missed `ovaSeedInstagram` this way — a naive block scanner won't see them; the full-suite failure will).
   - This is a large uniform sweep — superpowers:subagent-driven-development is a good fit: after step 2's single Pest.php edit, the 229 fixture files are independent and don't collide. Partition by directory, set `model` explicitly on every subagent, forbid `git stash` in every subagent prompt, and NEVER run `composer test` concurrently with a subagent (it thrashes; run it yourself, once, between waves).
4. Regenerate the baseline: `SCHEMA_DRIFT_BASELINE=1 php artisan test --filter=SchemaDriftGuardTest`. Confirm `git diff` on the baseline shows ONLY the 4 `core.users` name findings removed and NOTHING added (reconcile against your step-1 pre-change snapshot — the baseline already carries pre-existing already-fixed entries unrelated to you; regenerate-before-and-after and diff, don't eyeball raw counts).
5. `vendor/bin/pint` the changed files. Commit `tests/Pest.php` + the baseline + every fixture (explicit paths).

**Task A blocker gate:** broad fallout is EXPECTED (that's the bug class), so do NOT stop merely because the failure count is high. STOP and report to Josh only if a fix would require loosening a constraint or editing production (non-test) code, or if the change balloons beyond fixture data.

---

## Task B — close the local-DDL coverage hole (do AFTER A; gated)

~42 test files build their OWN `CREATE TABLE core.users / site.sites / platform_connections / site.menus / design_kits` with permissive DDL instead of the shared `setup*Table()` helpers. `SchemaDriftGuardTest` only introspects tables built by the global `setup*` helpers, so these local copies are invisible to the gate — a test in one of them can seed a prod-violating row and stay green (that's how `SegmentResolverTest` slipped a null `resource_id` past T3). List them: `grep -rln 'CREATE TABLE' tests/ | grep -v tests/Pest.php` then filter to those naming the 5 tables. EXCLUDE `tests/Unit/SchemaDrift/DriftComparatorTest.php` (its `CREATE TABLE` are in-memory string fixtures for the comparator, not a real schema).

Add a guard the codebase already has a pattern for (baseline/allowlist, like the drift gate itself):
1. New `tests/Feature/Architecture/NoLocalCanonicalTableDdlTest.php` — scan `tests/**/*.php` (except `tests/Pest.php`), fail if any file contains `CREATE TABLE ... <one of the 5 canonical tables>`, minus an allowlist JSON seeded with today's ~42 offenders (`NO_LOCAL_DDL_BASELINE=1` to regenerate, mirroring SchemaDriftGuardTest). This makes NEW holes fail CI immediately while grandfathering the backlog.
2. Burn down the allowlist as far as is safe: convert offenders to the shared `setup*Table()` helpers (removing local DDL) so they inherit the tightened schema. Each conversion can re-expose name-column fixtures — fix them the same way as Task A. Convert only where the shared helper covers the file's needs; leave genuinely-special ones on the allowlist with a one-line reason. Commit the guard + baseline even if you don't fully drain the allowlist.

**Task B blocker gate:** if converting a file cascades into unrelated failures or needs schema the shared helper lacks, leave it grandfathered and move on — do NOT expand the helpers speculatively. Landing the guard + a partially-drained allowlist is a complete, valuable unit.

---

## Task C — restore discriminating power to two weakened assertions (OPTIONAL, small)

T3 changed `tests/Feature/Api/Staff/UserSiteManagement/StaffUpdateSiteValidationTest.php` and `tests/Feature/Api/User/SiteManagement/UpdateSiteAuthorizationTest.php` from asserting `architecture_id` is null (a SQLite-only artefact) to `toBe('staple')`. Because prod's CHECK pins that column to `'staple'`, a landed write is now indistinguishable there — the 403/423 status is the only thing proving the write was blocked. If you want the write-didn't-land guarantee back, assert on a DIFFERENT column the endpoint would have changed (e.g. a settings field in the same PATCH), leaving the status assertion in place. Skip if not worth it.

---

## Verify
FULL `composer test` green after each task — this touches the shared test bootstrap, so a filtered green is a FALSE signal; verify the whole suite. After the final commit, run the full suite once more on the EXACT committed tree (a fresh detached worktree at your commit sha), because pint + baseline-regen happen after the last functional run.

## Mode + gates
- superpowers:executing-plans + superpowers:using-git-worktrees. Task A is large → superpowers:subagent-driven-development (set `model` explicitly; forbid `git stash`).
- **Independent review** (superpowers:requesting-code-review): T3 itself merged WITHOUT the independent review its plan required. As part of this session, have a separate reviewer confirm (a) the shipped `21a18d53` loosened no constraint, and (b) your Task A/B changes fix data only, never constraints.
- **Never push by merging into the shared checkout.** A parallel session may switch its branch mid-task (this happened during T3 — a `--ff-only` advanced the wrong branch, which also held another session's staged work). Push straight from your worktree branch: `git push origin launch-check-t3-followup:development` (fast-forward), then sync the local ref with `git branch -f development <sha>` (safe — it's not checked out). Before touching any shared checkout, snapshot `git status --porcelain` + `git diff --cached --stat` so you can prove nothing was lost.
- **Never push without Josh** unless he says otherwise for this run. Present via superpowers:finishing-a-development-branch. Other sessions may hold uncommitted edits to `tests/Pest.php` / `MenuTest.php`; a fast-forward push doesn't disturb them, but flag it.

## Tooling gotchas
Focused: `php artisan test --filter=X`. Full: bare `composer test` (`composer test -- --filter=X` does NOT work; `COMPOSER_PROCESS_TIMEOUT=0` for the full run). Style: `vendor/bin/pint` (NOT `php artisan pint`). Mirror prod DEFAULTS alongside NOT NULLs — a `NOT NULL DEFAULT x` column in Postgres needs that default in SQLite too, or the test schema is stricter than prod and manufactures failures (the name columns have NO default, so they get none). Tests run SQLite, prod is Postgres — verify constraint text against `scripts/launch-check/schema-snapshot.json` / `supabase/migrations/`, not just a passing suite.
