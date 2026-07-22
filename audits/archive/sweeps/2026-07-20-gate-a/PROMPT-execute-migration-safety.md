# Gate A — execute prompt: MIGRATION SAFETY (Bundle B19 + DISC-1)

Continues `audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md`. This is the **migration-safety slice** of
gate-a, split out 2026-07-22 (Josh) from the old `PROMPT-execute-P3-remaining.md` so it can run **in
parallel** with the app-code polish (`PROMPT-execute-p3-polish.md`). It owns everything that touches
`supabase/migrations/`, `scripts/guard-no-unsafe-migrations.php`, and the migration conventions.

**Scope:** Bundle **B19** (all of it — the 7 promoted P2s + the P3 remainder) **+ `discovered/DISC-1`**.
This is the same migration-safety theme as the already-shipped fresh-DB provisioning work
(`73de8577`: `scripts/db/fresh-reset.sh` + guard Checks 1–6) — build ON that, don't redo it.

**How to use:**
1. Open a **fresh Claude Code session in this repo** on model **Opus**.
2. Paste everything from `=== PROMPT START ===` to the end as your first message.

**Parallelism:** safe to run at the same time as `PROMPT-execute-p3-polish.md` — the two touch disjoint
files (this one = migrations/guard; that one = app services/controllers/config). The ONLY shared file is
`CONSOLIDATED.md` (tick-boxes) — see the coordination rule below.

---

```
=== PROMPT START ===

Execute the MIGRATION-SAFETY slice of audit audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md: Bundle B19
(all items) + discovered/DISC-1. Follow scripts/audit/fix-flow.md with the overrides below.

## First: set up an ISOLATED worktree on a NEW branch
- `git fetch origin`
- Create an isolated worktree under `backend-wt/` (NOT `.claude/worktrees/`, which poisons the Composer
  classmap), on a NEW branch off origin/development:
  `git worktree add ../backend-wt/migration-safety -b audit-fix/migration-safety-2026-07-22 origin/development`
  then `cd ../backend-wt/migration-safety`.
- `composer install` and copy a working `.env` into this worktree (each worktree needs its own).
- `git rev-parse --abbrev-ref HEAD` MUST print `audit-fix/migration-safety-2026-07-22`. This is a
  SEPARATE branch from the app-polish session so both can run in parallel — do NOT reuse
  `audit-fix/gate-a-2026-07-20`.

## Orient
- Read `audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md`: the `## Progress` block, Bundle **B19** (line
  ~672), the `## Discovered during execution` **DISC-1** entry (line ~129, with its Routed note), and
  every `sources/<run>.md` the items point at. **Finding lines carry only Where+What; the real evidence
  is in `sources/migrations-early.md`, `sources/migrations-recent.md`, `sources/pii-schema.md` — read them
  in full before planning.**
- Read `scripts/guard-no-unsafe-migrations.php` end to end. It already has **Checks 1–6** (1: CREATE INDEX
  w/o CONCURRENTLY; 5: hot-table DDL/DML w/o lock/statement timeout, HOT_TABLES = design_kits/sites/blocks/
  core.users; 6: one CONCURRENTLY per file). Most of your work EXTENDS this file or accepts an exemption —
  not rewriting historical migrations.

## Standing decisions (these override the runbook where they conflict)
- **VERIFY EVERY PREMISE.** ~40% of gate-a findings had stale/already-fixed premises. Read the current
  code + `git log --oneline --since=2026-07-10 -- <file>` before touching anything. A `no_change_needed`
  with quoted evidence is a valid, valuable outcome — several B19 items are explicitly "accept the
  exemption" (the `pii-schema/SCHEMA-4/5/6` items say "accept as-is").
- **Cutover context (Josh) — this defines B19's real severity as LOW.** The prod cutover collapses
  migration history into a fresh baseline and re-applies against an EMPTY, traffic-free DB, so the
  ACCESS-EXCLUSIVE / lock-hazard findings cannot bite at the cutover itself. B19 is **hygiene** for local
  `db reset` / preview / DR + **preventing the NEXT unsafe migration**. **Prefer extending the guard
  script + documenting the pattern over rewriting already-applied historical files** (an in-place edit
  does NOT re-run on dev; a NEW `supabase/migrations/` version WOULD be `db push`-ed to the LIVE dev DB —
  do NOT create one). **Apply NO migration to any live DB in this run.**
- **SQLite string-literal trap:** an unknown quoted identifier is a string literal, not an error, so
  "the query ran" proves nothing. Verify columns against `supabase/migrations/` DDL, never `tests/Pest.php`.
- **NEVER `git stash`/`git checkout <file>`/`git restore`/`git reset`** — the stash stack is shared across
  worktrees and other sessions are live. Read-only git only; `git show <ref>:<path>` for old content.
  Forbid `git stash` explicitly in every subagent prompt you spawn.
- **Pin `model: sonnet`** on every implement/review spawn (Opus fan-out exhausts the budget).
- **Commit discipline:** verify the BRANCH NAME (`git rev-parse --abbrev-ref HEAD`) + `git diff --cached
  --stat` before EVERY commit. Surgical commits, no `php artisan pint` sweep. Commit code + ticked audit
  file together: `fix(audit): <unit> — <ids>`. Trailers:
  Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
  Claude-Session: <your session url>
- **CONSOLIDATED coordination (parallel-safe):** tick ONLY your boxes (DISC-1 + the B19 items). Do NOT edit
  the `## Progress` aggregate count line — the parallel app-polish session edits it too, and a shared-line
  edit is the one thing that conflicts. Report your tick delta in the final report; the LAST session (or
  Josh) reconciles the Progress totals.

## Cadence — BLOCKER gate applies (this is all migration/DB work)
Full `plan (Opus) → implement (Sonnet) → independent review (separate Sonnet)` per unit, and **present the
plan for sign-off before implementing** each unit that edits a migration file or the guard script. Tick
`[ ]`→`[x]` only after tests pass AND review says PASS. `composer test` is the gate — run it per unit,
NEVER while a subagent runs tests. (Note: this whole class is invisible to `composer test` — it runs
SQLite; the real verification for a migration edit is a fresh-apply via `scripts/db/fresh-reset.sh`
against a LOCAL throwaway DB, never dev/prod. Stop the sibling Comet stack first — shared ports.)

## Units (work smallest/safest first; present each migration/guard edit before implementing)

### DISC-1 — `DROP INDEX` (no CONCURRENTLY) on hot `site.blocks` (P2·S) — guard check, do first
`supabase/migrations/20260701180000_strip_block_settings_keys_and_views.sql:19` drops an index on
`site.blocks` (a `HOT_TABLES` entry) inside a transaction alongside a full-table `UPDATE site.blocks`.
The migration is ALREADY APPLIED to dev (in-place edit = hygiene for fresh-apply only; do NOT author a new
forward migration). The durable fix: **extend `guard-no-unsafe-migrations.php` with a "`DROP INDEX`
non-CONCURRENTLY on a HOT_TABLES table" check** (grandfather pre-existing files via the timestamp-cutoff
pattern the other checks use). This is what collided with the fresh-DB guard work — it's now yours.

### B19 P2s (the 7 promoted items) — `sources/migrations-early.md`, `sources/migrations-recent.md`
- `migrations-early/MIG-3` (P2·M) — inline `CHECK` on `site.sites.skeleton_id` validates existing rows under `ACCESS EXCLUSIVE`.
- `migrations-early/MIG-4` (P2·S) — unqualified `DROP FUNCTION` leaves an orphaned trigger referencing a dropped column.
- `migrations-early/MIG-5` (P2·M) — full-table `UPDATE` backfills run inside migration transactions instead of being extracted (5 files).
- `migrations-early/MIG-6` (P2·S) — `NOT VALID` + `VALIDATE` bundled in one long txn spanning six unrelated fixes, incl. hot `site.site_media`.
- `migrations-recent/MIG-5` (P2·S) — `VALIDATE CONSTRAINT` in the same txn as `ADD CONSTRAINT NOT VALID` (wastes the two-step optimisation).
- `migrations-recent/MIG-6` (P2·S) — non-CONCURRENTLY unique-index build justified only by dev row count, not the prod re-baseline.
- `migrations-recent/MIG-7` (P2·S) — design-kit rework drops hot-table columns with no txn wrapper and no documented rollback (5 files).

### B19 P3 remainder — mostly accept-exemption
- `migrations-early/MIG-7` (P3·M) — missing `SET LOCAL lock_timeout`/`statement_timeout` guards on DDL touching live-traffic tables (~50 files). **Check 5 already exists** — confirm what it covers, then this is likely "extend the cutoff / document" not a 50-file edit.
- `migrations-recent/MIG-8` (P3·M) — same guard gap across 30+ recent files; consider a runner-level default.
- `pii-schema/SCHEMA-4/5/6` (P3·S each) — explicitly "accept the exemption / accept as-is". Read each source entry; lean toward a documented exemption over churn.

**Regression home:** the `MigrationTransactionBoundaryTest` (created in B2/S1) is the right place for any
new SQLite-runnable regression lock — extend it following its no-Postgres-guard idiom.

## When your slice is done
- `composer test` once for your branch — green. (Plus a local `fresh-reset.sh` apply if you edited any
  migration file.)
- Tick your boxes; do NOT touch the `## Progress` aggregate line (see coordination rule). Do NOT run
  `archive-done.sh` — the app-polish bundles (B16–B18) and the gated deferred-cutover items remain, so
  not every box is `[x]`.
- Report: units done / accepted-as-exemption (with evidence) / blocked, test status, branch name, and your
  tick delta. **Do NOT push to development/production** — Josh reviews and merges.

## Coordination notes
- Runs PARALLEL to `PROMPT-execute-p3-polish.md` (disjoint files). At merge, only `CONSOLIDATED.md`'s
  Progress line may need a one-line reconcile.
- `PROMPT-execute-deferred-cutover.md` (B20 + B8 new schema) ALSO touches `supabase/migrations/` but is
  gated to the cutover window and not run now. If it IS run concurrently, coordinate on the guard script +
  `CONVENTIONS.md` (shared files).

## Stop and ask if
- A migration/guard-edit plan is ready — present it with blast radius + recommendation (blocker gate).
- A finding needs a NEW `supabase/migrations/` version (would hit live dev) — flag it, don't fold it in.
- Two review rounds fail on a unit — mark it blocked and surface it.

=== PROMPT END ===
```
