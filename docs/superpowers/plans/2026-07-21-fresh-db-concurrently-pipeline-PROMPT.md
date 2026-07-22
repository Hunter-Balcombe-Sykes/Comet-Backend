# Fresh-DB provisioning: `CREATE INDEX CONCURRENTLY` fails under the Supabase CLI pipeline

> **▶ To run this:** paste this whole file as the opening prompt of a fresh session.
> This is the one remaining open item from the 2026-05-29 fresh-DB provisioning triage
> (memory: *Fresh-DB Provisioning Broken*). The two **ordering** bugs from that triage are
> already fixed and verified in-tree (see "Already fixed" below) — **do not redo them.**
> This prompt is about issue **#3 only**: the CLI/`CONCURRENTLY` pipeline incompatibility.

---

## The problem

A from-zero `supabase db reset` (and possibly a fresh-project `supabase db push`) does **not**
apply this repo's migrations cleanly. The observed failure: the Supabase CLI sends each
migration file's statements to Postgres as **one libpq pipeline**, which Postgres treats as an
implicit transaction block. `CREATE INDEX CONCURRENTLY` **cannot run inside a transaction**
(`SQLSTATE 25001`), so every migration file that builds concurrent indexes aborts.

The repo has many such files — the convention (`supabase/migrations/CONVENTIONS.md` §1)
*requires* `CONCURRENTLY` for every index, and lets a single file carry many:

| CONCURRENTLY statements | File |
|---|---|
| 18 | `supabase/migrations/20260528000001_create_moderation_indexes.sql` |
| 16 | `supabase/migrations/20260701210000_collapse_cover_singleton_indexes.sql` |
| 7  | `supabase/migrations/20260711160100_add_analytics_purge_indexes.sql` |
| 7  | `supabase/migrations/20260527160001_enquiry_inbox_indexes.sql` |
| 6  | `supabase/migrations/20260720110001_add_auth_factor_events_webhook_id_uk.sql` |
| …  | (a long tail of 2–6-statement files) |

Local Supabase CLI is currently **v2.101.0**. The 2026-05-29 triage recorded the failure on
both v2.98.2 and v2.101.0.

**Why it lay dormant:** remote dev/prod were migrated *incrementally* — the tables, role, and
indexes were built up over 147 migrations, so the from-zero path was never exercised. The
consolidation to a single baseline (`20260526000000_baseline_standalone_user.sql`) plus the
`CONCURRENTLY`-heavy index convention were never validated against a truly empty DB.

## Already fixed — do NOT redo

Both **ordering** bugs from the same triage are resolved and verified in the current tree:

1. **Straggler ordering** — `20260526000001_correct_boolean_defaults.sql` (renamed from
   `...104153...`) now sorts *after* the baseline. Confirmed present.
2. **Role ordering inside the baseline** — `CREATE ROLE app_backend NOLOGIN` is hoisted to
   **line 51** of `20260526000000_baseline_standalone_user.sql`, ahead of the first
   `CREATE POLICY ... TO app_backend` at line 1724. The later block (line 2271) keeps its
   `IF NOT EXISTS` guard and applies `BYPASSRLS`/grants after tables exist. Confirmed present.

Touch neither. If your `db reset` throws `schema "site" does not exist` or
`role "app_backend" does not exist`, something regressed — stop and report, don't re-patch.

## The crux — resolve this BEFORE choosing a fix

The memory's "candidate fix" is *split multi-statement CONCURRENTLY files into
one-statement-per-file*. **Do not start there.** That candidate is only correct under one
specific failure mechanism, and it's expensive (80+ new files, renumbering). Two cheaper
outcomes are plausible and must be ruled out first, and one experiment decides whether the
split even works:

- **Is it already fixed upstream?** Newer CLI versions may apply migrations without the
  offending pipeline/transaction wrap. The cheapest possible fix is "raise the CLI floor."
- **Does a single-statement `CONCURRENTLY` file pass?** If the pipeline wraps *per file*, one
  statement per file works. If it wraps/transacts *even single-statement files*, the split is
  **useless** and the only fix is a non-pipelined applier.
- **Does `db push` pipeline the same way?** This decides blast radius: a **local-only**
  annoyance (fixable with a psql-loop wrapper) vs a **prod-cutover blocker** (the fresh-prod
  `db push` on the pilot cut-over — memory: *Pilot Prod-Cutover Intent* — would fail).

Phases 0–1 answer these empirically. Phase 2 branches on the answers. Resist implementing
until Phase 1 is done.

---

## Phase 0 — Reproduce and characterise (the linchpin)

Work against a **local throwaway stack only**. Never point any step in this prompt at the
shared dev Supabase (`glncumufgaqcmqhzwrxm`) or prod. `supabase db reset` **drops and
recreates** the target DB.

**Env prerequisites**
- The sibling **Comet** stack shares ports 54321–54327 (memory: *Comet Sibling Stack*). Run
  `supabase stop` on any running stack first, or `db reset` will bind-conflict.
- Local `.env` `DB_HOST` is a dead ref (memory: *Env state 2026-07-18*) — irrelevant here;
  the local stack is self-contained.

**Steps**
1. **Cheapest fix first — try a newer CLI.** Record the exact current version
   (`supabase --version` → 2.101.0), then update (`brew upgrade supabase` or the pinned
   install method) and re-run the fresh reset (step 2). If a newer CLI applies all migrations
   clean, **skip to Phase 3** (the fix becomes: document a CLI floor + a fresh-DB CI smoke
   test). Note the exact version that works.
2. **Reproduce the baseline failure.** `supabase start` → `supabase db reset`. Capture the
   exact SQLSTATE, message, and the **first** migration file that fails. Confirm it is a
   `CONCURRENTLY` file and not a regression of the two fixed ordering bugs.
3. **Decisive experiment A — single-statement file.** Add a throwaway migration timestamped
   last (e.g. `29999999999999_probe_single_concurrently.sql`) containing **exactly one**
   `CREATE INDEX CONCURRENTLY IF NOT EXISTS` on an existing baseline table (e.g. an unindexed
   column of `core.users`). `db reset`. Does it pass or fail?
4. **Decisive experiment B — two-statement file.** Same, but **two** `CONCURRENTLY` statements
   in one file. `db reset`. Pass or fail?
5. **Check for a CLI directive.** Before assuming a code change is needed, use the Supabase
   MCP `search_docs` (and the CLI changelog) for a per-migration "no transaction" / "statement
   batching" directive or flag. If one exists, it may be the whole fix. Record what you find.

**Delete both probe migrations before leaving Phase 0.**

**Decision table from A/B:**
| Exp A (1 stmt) | Exp B (2 stmt) | Mechanism | Viable fix |
|---|---|---|---|
| PASS | FAIL | Pipeline wraps **per file** | Split multi-statement files → one `CONCURRENTLY` per file |
| FAIL | FAIL | Pipeline/txn wraps **every** file | Split is useless → non-pipelined applier (psql loop) |
| PASS | PASS | Not reproducible on this CLI | Likely already fixed — go to Phase 3, pin the CLI floor |

## Phase 1 — Determine the prod blast radius (`db push`)

The memory flags this as unverified and it drives the fix's urgency. `db reset` and `db push`
share the applier, but confirm — do **not** assume.

1. Provision a **scratch** empty Postgres the applier can target without touching dev/prod:
   either a fresh Supabase **branch** (`mcp__claude_ai_Supabase__create_branch`, then delete
   it after) or a local throwaway DB, and run `supabase db push --db-url <scratch>` against it.
2. Observe whether the same `CONCURRENTLY`/`25001` failure occurs on `db push`.
3. Tear the scratch DB/branch down.

- **If `db push` fails too:** this is a **prod-cutover blocker**. The fix must produce
  migrations (or an apply procedure) that succeed on a fresh `db push`, and the fresh-prod
  caveat in `CLAUDE.md` ("Fresh prod DB…") must be updated with the resolved procedure.
- **If `db push` is unaffected:** the issue is **local `db reset` only**. A psql-loop wrapper
  for local fresh-provisioning is sufficient; no migration churn required.

## Phase 2 — Implement the fix (branch on Phase 0/1 findings)

Pick exactly one path. **Present the chosen path to Josh before implementing** (this is a
gated area — it touches DB provisioning and, on path B, potentially every index migration).

**Path A — Newer CLI resolves it (Exp A&B PASS on upgrade).**
- No migration changes. Pin a minimum CLI version somewhere enforceable and documented
  (README / `CONVENTIONS.md` / a `supabase/.temp` note as appropriate to the repo's setup).
- Go to Phase 3 (add the fresh-DB smoke test so a future CLI regression is caught).

**Path B — Split works (Exp A PASS / B FAIL) AND `db push` is affected (prod-blocking).**
- Mechanically split every multi-statement `CONCURRENTLY` file so each file holds **exactly
  one** `CREATE [UNIQUE] INDEX CONCURRENTLY`. Keep timestamp prefixes; use sequential suffixes
  (`…000001`, `…000002`, …) exactly as `CONVENTIONS.md` §1's two-file convention shows.
  Content is byte-moved, never rewritten. Preserve original comments with each index.
- Verify ordering is unchanged: `ls supabase/migrations/*.sql` before/after must interleave the
  split files in the same relative position their parent occupied.
- **Add a regression guard** so this can't recur: extend `scripts/guard-no-unsafe-migrations.php`
  with a new check that fails any post-cutoff migration file containing **more than one**
  `CONCURRENTLY` statement, or any file mixing a `CONCURRENTLY` statement with `BEGIN`/other
  DDL. Grandfather pre-existing files by timestamp exactly as the existing checks do
  (`GRANDFATHERED_CUTOFF` pattern) — but after the split there should be no post-cutoff
  offenders, so the new check can be strict. Wire nothing new into `composer.json`; the guard
  already runs (`composer.json:75`, `guard:no-unsafe-migrations`).
- Update `CONVENTIONS.md` §1 to state the **one-`CONCURRENTLY`-per-file** rule explicitly and
  say *why* (CLI pipeline / `25001`), so the next author doesn't re-bundle.

**Path C — Split is useless (Exp A FAIL) OR `db push` unaffected (local-only).**
- Do **not** split files. Add `scripts/db/fresh-reset.sh`: bring up an empty local Postgres,
  then apply `supabase/migrations/*.sql` in filename order via a **`psql -f` loop** (psql's
  simple-query protocol runs each statement as its own top-level command — no pipeline, so
  `CONCURRENTLY` succeeds). Make it idempotent and safe (refuse to run against any non-local
  host).
- Document it in `CONVENTIONS.md` / README as the supported local fresh-provision path, and
  note that `supabase db reset` is not usable from zero here (and why).
- If Phase 1 showed `db push` *is* affected but the split is also useless (A FAIL), escalate to
  Josh: the fresh-prod path then needs an apply procedure (e.g. `db push --db-url` via psql, or
  splitting the baseline's index build into a post-provision step). Do not guess — flag it.

## Phase 3 — Regression coverage + docs (all paths)

- **Fresh-DB smoke test.** Add a CI-runnable check that a from-zero apply succeeds, so this
  never silently regresses again. If the harness can't run a real Postgres in CI, at minimum
  add the guard from Path B and document the manual `fresh-reset.sh` verification step in the
  pre-cutover checklist.
- Update `CLAUDE.md`'s "Fresh prod DB" caveat with the resolved procedure and the CLI floor.
- Update the memory (`project_fresh_db_provisioning.md`): mark #3 resolved, record which
  mechanism was real (A/B result), whether `db push` was affected, and the chosen path.

---

## Hard constraints

- **No Laravel migrations.** Everything stays raw SQL under `supabase/migrations/`; the composer
  guard rejects Laravel migrations.
- **Never target shared dev or prod.** Every `db reset`/`db push`/`psql` in this work hits a
  **local or scratch** DB only. `db reset` is destructive.
- **Don't touch the two fixed files** (`20260526000000_baseline_standalone_user.sql` role hoist,
  `20260526000001_correct_boolean_defaults.sql`) except to read them.
- **Byte-move, don't rewrite** on Path B. A split that "cleans up" SQL while moving it is how you
  ship a subtly different schema. Diff the concatenation of split files against the original.
- **Stop the sibling Comet stack** before `supabase start` (shared ports 54321–54327).
- Tests run **SQLite**, prod is **Postgres** — this whole issue is invisible to `composer test`.
  The verification here is the fresh-apply itself, not the Pest suite.

## Where the code / files are

- `supabase/migrations/CONVENTIONS.md` — §1 is the convention to amend
- `scripts/guard-no-unsafe-migrations.php` — Check 1 already exempts `CONCURRENTLY`; add the
  new "≤1 CONCURRENTLY per file" check here (grandfather via the existing timestamp-cutoff pattern)
- `composer.json:88` — `guard:no-unsafe-migrations` wiring (already runs in the guard bundle at
  `composer.json:75`; no new wiring needed)
- `supabase/migrations/20260528000001_create_moderation_indexes.sql` — the 18-statement worst case
- `supabase/migrations/20260701210000_collapse_cover_singleton_indexes.sql` — 16-statement case
- `CLAUDE.md` — "Push to Supabase" / "Fresh prod DB" caveat to update
- Memory to update: `…/memory/project_fresh_db_provisioning.md`

## Method

Follow `scripts/audit/fix-flow.md`: **plan → implement → independent review by a separate
reviewer who did not write the change.** This is a **blocker-gate** item (DB provisioning +,
on Path B, a repo-wide migration restructure) — **present the plan and the Phase-0/1 findings
to Josh and wait for sign-off before implementing.**

- **Do not use `git stash` in any form** — the stash stack is shared across worktrees. Prove
  before/after by editing and restoring by hand, verifying with `git diff`.
- Branch off `development`. Work in a worktree under `backend-wt/` (**not** `.claude/worktrees/`,
  which poisons the Composer classmap); each worktree needs its own `composer install` + `.env`.
- Commit deliverables immediately — a concurrent merge from another session can silently revert
  uncommitted work (memory: *Concurrent merge wipes uncommitted work*).

---

## Promoted scope (2026-07-22, Josh) — gate-a migration-safety P2s + DISC-1

Routed here because this session owns `scripts/guard-no-unsafe-migrations.php` and the fresh-apply /
cutover path — the same home the CONCURRENTLY work already touches. These are **migration-SAFETY
hygiene of EXISTING files + guard rules**, NOT new schema (that's the separate
`PROMPT-execute-deferred-cutover.md`, which lands B20/B8 schema AT the cutover). Work each as its **own
`fix-flow.md` unit** (plan→implement→independent review), AFTER the Phase 0–3 CONCURRENTLY investigation
above. Full Technical/Evidence for each is in the gate-a sources — read before touching anything.

**Key framing (same as DISC-1):** the cutover re-applies migrations against an **empty, traffic-free**
DB, so the ACCESS-EXCLUSIVE / lock-hazard items are low-risk *at the cutover itself*. The durable value
is (a) **guard-script checks** that stop the next unsafe migration, and (b) a clean **local fresh-apply**.
Prefer extending the guard + documenting the pattern over rewriting already-applied historical files
(an in-place edit does NOT re-run on dev). Several are explicitly "accept the exemption, apply the
pattern going forward."

- **`discovered/DISC-1`** (P2·S) — `DROP INDEX` (no CONCURRENTLY) on hot `site.blocks` inside a txn with a full-table `UPDATE`, `supabase/migrations/20260701180000_…:19`. Extend the guard with a "`DROP INDEX` non-CONCURRENTLY on a HOT_TABLES table" check. Source: CONSOLIDATED `## Discovered during execution`.
- **`migrations-early/MIG-3`** (P2·M) — inline `CHECK` on `site.sites.skeleton_id` validates existing rows under `ACCESS EXCLUSIVE`.
- **`migrations-early/MIG-4`** (P2·S) — unqualified `DROP FUNCTION` leaves an orphaned trigger referencing a dropped column.
- **`migrations-early/MIG-5`** (P2·M) — full-table `UPDATE` backfills run inside migration transactions instead of being extracted (5 files).
- **`migrations-early/MIG-6`** (P2·S) — `NOT VALID` + `VALIDATE` bundled in one long txn spanning six unrelated fixes, incl. hot `site.site_media`.
- **`migrations-recent/MIG-5`** (P2·S) — `VALIDATE CONSTRAINT` in the same txn as `ADD CONSTRAINT NOT VALID` (wastes the two-step optimisation).
- **`migrations-recent/MIG-6`** (P2·S) — non-CONCURRENTLY unique-index build justified only by dev row count, not the prod re-baseline.
- **`migrations-recent/MIG-7`** (P2·S) — design-kit rework drops hot-table columns with no txn wrapper and no documented rollback (5 files).

Sources: `audits/sweeps/2026-07-20-gate-a/sources/migrations-early.md`, `…/migrations-recent.md`, and the
CONSOLIDATED discovered section. Tracked (left `[ ]`) under gate-a Bundle **B19** with a PROMOTED note.
**Not promoted (stay in gate-a B19, same theme if you want them later):** `migrations-early/MIG-7` +
`migrations-recent/MIG-8` (P3 `lock_timeout`/`statement_timeout` guard gap), `pii-schema/SCHEMA-4/5/6`
(P3 accept-exemption).
