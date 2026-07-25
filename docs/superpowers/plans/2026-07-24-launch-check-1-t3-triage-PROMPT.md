# Handoff prompt 1 of 3 — T3-Step-2 triage (do soonest; hardens the gate for ongoing dev)

Paste everything below the line into a fresh Claude Code session in this repo.

---

Un-grandfather the 5 dangerous tables in the schema-drift gate: tighten the SQLite test schema so their real Postgres NOT NULL / CHECK constraints are ENFORCED (a write that would violate one can no longer pass CI green), then shrink the baseline.

## Context
The schema-drift gate is already shipped on origin/development (`tests/Feature/Architecture/SchemaDriftGuardTest.php` + `scripts/launch-check/schema-drift-baseline.json`). It landed **baseline-first**: all ~317 current drift findings are grandfathered, INCLUDING the constraints on the 5 write-heavy tables. This task tightens `tests/Pest.php` for just those 5 tables, then regenerates the baseline so their findings drop out. The two prod incidents this closes: `site.platform_connections.payload` NOT NULL and `last_refresh_status` CHECK.

**The 5 tables:** `site.platform_connections`, `site.sites`, `site.design_kits`, `site.menus`, `core.users`.

## Steps
1. **Isolated worktree** off origin/development (the shared checkout is contended by other sessions): `git worktree add ../backend-wt/t3-triage -b launch-check-t3-triage origin/development`, then its OWN `composer install` + copy `.env` from the main checkout. Stage EXPLICIT paths only — NEVER `git add -A`.
2. **Get the exact constraints to add** (they're already enumerated in the checked-in artifacts):
   - `grep -E "platform_connections|:site\.sites|design_kits|:site\.menus|core\.users" scripts/launch-check/schema-drift-baseline.json` — each `not_null_missing:<schema>.<table>.<col>` = a NOT NULL to add; each `check_missing:<schema>.<table>:<name>` = a CHECK to add.
   - The CHECK definitions are in `scripts/launch-check/schema-snapshot.json` under `"checks"` (find by `name`).
3. **Tighten `tests/Pest.php`**: for each of the 5 tables, edit its `setup*Table()` helper — add `NOT NULL` to the flagged columns and the `CHECK (...)` clauses. Translate the Postgres CHECK definition to SQLite-compatible syntax (usually identical for `IN (...)` / simple predicates; simplify if a Postgres-ism like `ANY(ARRAY[...])` appears → `IN (...)`).
4. **Run tests and fix fallout FORWARD**: run the affected feature/unit tests, then the FULL `composer test`. Every failure is a test inserting a row that violates a real prod constraint — fix the test DATA (supply the missing value / a valid enum), **never loosen or drop the constraint to make a test pass**. That break IS the bug class this gate exists for.
5. **Regenerate the baseline**: `SCHEMA_DRIFT_BASELINE=1 php artisan test --filter=SchemaDriftGuardTest`. Confirm `git diff scripts/launch-check/schema-drift-baseline.json` shows ONLY removals for the 5 tables (nothing else changed).
6. `vendor/bin/pint` the changed files. Commit `tests/Pest.php` + `scripts/launch-check/schema-drift-baseline.json` (explicit paths).

## Verify
FULL `composer test` must be green — this touches the shared test bootstrap, so verify the WHOLE suite, not a filtered subset (a filtered green is a false signal here).

## Mode + gates
- Small enough to do directly, but because it touches shared test infra, have it **independently reviewed** — a separate reviewer confirms no constraint was loosened and the baseline shrank by exactly the 5 tables' findings. (superpowers:subagent-driven-development for one task + review is fine; set `model` explicitly on any subagent.)
- **BLOCKER GATE:** if the fallout cascades into many unrelated failures or the change balloons past tightening these 5 tables, STOP and report to Josh. Fix forward, never loosen.
- **Never push** (Josh pushes). Present via superpowers:finishing-a-development-branch.

## Tooling gotchas
Focused: `php artisan test --filter=X`. Full: bare `composer test` (`composer test -- --filter=X` does NOT work). Style: `vendor/bin/pint` (NOT `php artisan pint`).
