# Gate A — execute prompt: P3 APP POLISH (Bundles B16, B17, B18)

Continues `audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md`. This is the **app-code polish slice** of
gate-a, split out 2026-07-22 (Josh) from the old `PROMPT-execute-P3-remaining.md` so it can run **in
parallel** with the migration-safety session (`PROMPT-execute-migration-safety.md`). It covers the three
mechanical, low-risk P3 bundles that touch app services/controllers/config — **no schema, no migrations.**

**Scope:** Bundle **B16** (pin `DB::transaction()` to pgsql), **B17** (cache-layer helper hygiene),
**B18** (config extraction). Genuine polish — lowest priority in the gate; deferrable.

**How to use:**
1. Open a **fresh Claude Code session in this repo** on model **Opus**.
2. Paste everything from `=== PROMPT START ===` to the end as your first message.

**Parallelism:** safe to run at the same time as `PROMPT-execute-migration-safety.md` — disjoint files
(this = app code + `config/partna.php`; that = `supabase/migrations/` + the guard). Only `CONSOLIDATED.md`
is shared — see the coordination rule.

---

```
=== PROMPT START ===

Execute the P3 APP-POLISH slice of audit audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md: Bundles B16, B17,
B18. Follow scripts/audit/fix-flow.md with the overrides below.

## First: set up an ISOLATED worktree on a NEW branch
- `git fetch origin`
- Create an isolated worktree under `backend-wt/` (NOT `.claude/worktrees/`, which poisons the Composer
  classmap), on a NEW branch off origin/development:
  `git worktree add ../backend-wt/p3-polish -b audit-fix/p3-polish-2026-07-22 origin/development`
  then `cd ../backend-wt/p3-polish`.
- `composer install` and copy a working `.env` into this worktree (each worktree needs its own).
- `git rev-parse --abbrev-ref HEAD` MUST print `audit-fix/p3-polish-2026-07-22`. This is a SEPARATE branch
  from the migration-safety session so both can run in parallel — do NOT reuse `audit-fix/gate-a-2026-07-20`.

## Orient
- Read `audits/sweeps/2026-07-20-gate-a/CONSOLIDATED.md`: the `## Progress` block and Bundles **B16** (line
  ~621), **B17** (~636), **B18** (~651). **Finding lines carry only Where+What; the real evidence is in the
  `sources/<run>.md` files each item points at — read them before planning.** The top "Findings at a
  glance" table is wrong as generated; trust the Progress block.

## Verify every premise (this run's defining lesson)
~40% of gate-a findings had wrong/stale/already-fixed premises — several were closed by prior audit-fix
runs that are ancestors of origin/development. Before touching any file: read the current code, confirm the
defect exists, `git log --oneline --since=2026-07-10 -- <file>`. False premise → `no_change_needed` with
evidence. Line numbers have drifted — locate code by reading, not by the cited line.

## Standing decisions
- **Config lives in `config/partna.php`** (the canonical home for Partna limits/flags) — B18 moves literals
  there. Match the existing block structure; don't invent a new namespace (a prior session hit a finding
  whose suggested `partna.public_site.*` key didn't exist — the real home was `partna.cache`). Verify each
  literal isn't ALREADY config-sourced (drift means some are).
- **SQLite string-literal trap:** unknown quoted identifier = string literal, not an error, so "the query
  ran" tests are vacuous. Assert on returned DATA. Verify columns against `supabase/migrations/` DDL.
- **NEVER `git stash`/`git checkout <file>`/`git restore`/`git reset`** — shared stash across worktrees,
  other sessions live. Read-only git; `git show <ref>:<path>` for old content. Forbid `git stash`
  explicitly in every subagent prompt.
- **Pin `model: sonnet`** on every implement/review spawn (Opus fan-out exhausts the budget).
- **Commit discipline:** verify BRANCH NAME + `git diff --cached --stat` before EVERY commit. Surgical, no
  `php artisan pint` sweep. Commit code + ticked audit file together: `fix(audit): <unit> — <ids>`. Trailers:
  Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
  Claude-Session: <your session url>
- **CONSOLIDATED coordination (parallel-safe):** tick ONLY your boxes (B16/B17/B18 items). Do NOT edit the
  `## Progress` aggregate count line — the parallel migration-safety session edits it too, and a shared-line
  edit is the one thing that conflicts. Report your tick delta; the LAST session (or Josh) reconciles totals.

## Cadence — no blocker gate (all mechanical)
**Combine plan+implement (Sonnet) + single independent review (Sonnet)** per bundle. Tick `[ ]`→`[x]` only
after tests pass AND review PASS; `composer test` is the gate — run per bundle, NEVER while a subagent runs
tests. One exception needs care: `authz-core/SEC-1` (in B18) DELETES code — see B18 note.

## Units

### B16 — pin bare `DB::transaction()` to the pgsql connection (P3) — combine plan+impl
Six sites: `claim-and-provision/TXN-1..4` (`ConfirmationPreferenceService`, `InsertWithSortOrder`,
`ReorderService`, `RenameSubdomainAction`), `state-machines/LIFE-6` (`SendAccountDeletionRequestMailJob`),
`LIFE-7` (`ExportUserDataJob`). `BaseModel` forces pgsql for models, but a bare `DB::transaction()` resolves
the DEFAULT connection (SQLite under test). Pin each to `DB::connection('pgsql')->transaction(...)`. It
MATTERS because advisory/row locks inside these txns on the wrong connection are silent no-ops. Verify each
site actually uses the default connection today (some may already be pinned → `no_change_needed`).

### B17 — cache-layer helper hygiene (P3) — combine plan+impl
⚠️ **B1 already shipped** (it changed the invalidation call sites these helpers wrap) — RE-READ the current
code, don't work from the audit's stale list. Findings: `cache-invalidation/CCH-1/CCH-2/TXN-1`,
`claim-and-provision/CCH-1`, `webhooks-idempotency/CCH-1` + `WHK-2` (both **P2** — JWKS throttle lock not
pinning `cache_locks`; duplicated webhook TTL literals), `webhooks-internal/CFG-1/CFG-2`. Keep raw `Cache::`
calls INSIDE cache services (the repo's GS-1 rule). Don't re-open anything B1 settled.

### B18 — config extraction sweep (P3) — combine plan+impl
Move hardcoded literals into `config/partna.php`: `authz-core/CFG-1/2/3`, `user-api/CFG-1/2/3`,
`staff-api/CFG-1/2/3`, `public-surface/CFG-1/2`. Per finding, verify the literal isn't already config-sourced.
⚠️ **`authz-core/SEC-1` is different — it DELETES** `EnsurePartnaAdmin`'s staff-lookup fallback as dead code
"given the app's fixed middleware order." **Verify that middleware-ordering claim against `bootstrap/app.php`
before deleting anything** — if the ordering isn't actually guaranteed, the fallback isn't dead and deleting
it opens an authz hole. If unsure, `no_change_needed` and flag it.

## When your slice is done
- `composer test` once for your branch — green.
- Tick your boxes; do NOT touch the `## Progress` aggregate line (see coordination rule). Do NOT run
  `archive-done.sh` — the migration-safety (B19 + DISC-1) and gated deferred-cutover items remain, so not
  every box is `[x]`.
- Report: units done / no_change_needed (with evidence) / blocked, test status, branch name, tick delta.
  **Do NOT push to development/production** — Josh reviews and merges.

## Coordination notes
- Runs PARALLEL to `PROMPT-execute-migration-safety.md` (disjoint files). At merge, only `CONSOLIDATED.md`'s
  Progress line may need a one-line reconcile.
- ⚠️ **A `platform-write-locking` session may still be active** (`audit-fix/platform-write-locking-2026-07-21`,
  touches platform controllers/jobs). B18's `authz-core/CFG-3` edits `PlatformRegistryServiceProvider` —
  different file from those controllers, but CHECK for overlap at merge before landing.

## Stop and ask if
- `authz-core/SEC-1`'s middleware-order premise can't be confirmed — do NOT delete; surface it.
- Two review rounds fail on the same bundle — mark it blocked, surface it.
- A finding's premise turns out wrong in a way that suggests the audit misread the architecture.

=== PROMPT END ===
```
