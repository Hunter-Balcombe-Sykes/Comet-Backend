# Cutover-prep — execute prompt: reconcile dev drift + collapse to a fresh baseline

Operationalises the **"Drift reconciliation"** and **"Migration collapse"** sections of
`docs/deploy/production-cutover.md` into a single gated runbook. This is the discrete, calm Phase-0 task
that makes `supabase/migrations/` a trustworthy, reproducible source of truth so the eventual prod
cutover applies a *verified* schema — not a hopeful replay of drifted history.

**Authoritative task list:** `docs/superpowers/plans/2026-07-22-reconcile-and-collapse-baseline.md`
(Tasks 0–10). This file is the gate + the paste-in bootstrap; the plan carries the full step-by-step
detail **including the 2026-07-22 adversarial-review corrections** — the `app_backend` **BYPASSRLS**
stitch + `rolbypassrls` assertion, the `db diff --from local --to linked` fallback (plain `--linked`
shadow-builds from `supabase/migrations/` and aborts `25001` on the 11 CONCURRENTLY bundles
pre-collapse), the grant-matrix parity check (an empty schema diff does NOT prove grant/role parity),
the `pg_trgm` extension stitch, and the accurate guard-marker justification (Checks 2/5, not 1/6).
**Where this file, the runbook, and the plan conflict, the plan wins.**

**What this produces (the deliverable):** dev's migration ledger reconciled to the repo, then a single
new **`<ts>_baseline_pilot.sql`** that is a snapshot of the verified dev schema, with every prior
incremental moved to `supabase/migrations-archive/`, **proven to apply from an empty DB** via
`scripts/db/fresh-reset.sh`, **proven identical to dev** via an empty schema diff, and **proven
posture-identical** via the grant-matrix / role-attribute assertions (plan Task 8). Committed on a
branch, **unpushed** — Josh reviews and owns the merge.

**What this does NOT do:** it does **not** touch the prod Supabase project (`edplucmvkcnokyygxqsb`), does
**not** wake the prod Laravel env, does **not** push to `development`/`production`. The actual prod
apply/go-live stays Phases 1–4 of `production-cutover.md`, executed on cutover day. This prompt ends at
"baseline committed, parity proven, ready for cutover."

---

## GATE — do not start until every one of these is true  (= plan Task 0; the plan re-verifies it)

The baseline is a snapshot of **dev**, so all final schema must already be **applied to dev** first, or
it will silently be absent from the baseline (and therefore from prod).

- [ ] **Pre-pilot RLS slice is MERGED to `development` and applied to dev.** (`audit-fix/pre-pilot-rls-2026-07-22`
      → `audits/sweeps/2026-07-11-full-work-sweep/PROMPT-execute-pre-pilot-rls.md`: SCHEMA-7/9/10/11/12/13,
      **plus SCHEMA-6/DINT-3 if the optional tail was taken**.) It writes NEW `supabase/migrations/` files
      that must be in the snapshot.
- [ ] **B19 migration-safety slice is merged** (guard extensions Checks 5–8 in
      `scripts/guard-no-unsafe-migrations.php` + CONVENTIONS §1–8). Confirmed done by Josh.
- [ ] **The deferred Gate-A P2 schema (B8 + B20's 11) is applied to dev and ledger-aligned** under exact
      repo versions (done 2026-07-22 — `20260722010000` + `20260721010000…040700`). Verify, don't assume.
- [ ] **No other P0/P1/schema-bearing work is in flight.** The 07-11 P0/P1 tier is closed (TRIAGE-1 23/23,
      merged @`54929ef2`). Confirm nothing new since is un-applied to dev.
- [ ] `composer test` is green on `origin/development` and `php scripts/guard-no-unsafe-migrations.php` passes.

If any box is unchecked, **STOP and tell Josh which** — do not reconcile a moving schema.

**How to use:** open a **fresh Claude Code session in this repo on model Opus**, then paste everything
from `=== PROMPT START ===` to the end as your first message.

---

```
=== PROMPT START ===

Execute the cutover-prep task "reconcile dev drift + collapse to a fresh baseline".

The authoritative task list is docs/superpowers/plans/2026-07-22-reconcile-and-collapse-baseline.md —
Read it IN FULL, then execute its Tasks 0-10 in order with the superpowers:executing-plans skill (or
superpowers:subagent-driven-development), ticking checkboxes as you complete steps. For rationale, first
read docs/deploy/production-cutover.md ("Drift reconciliation (detailed steps)" + "Migration collapse
(rationale + method)"). The plan supersedes both docs where they differ — it carries the 2026-07-22
adversarial-review corrections (BYPASSRLS, --from/--to diff fallback, grant-matrix parity, pg_trgm
stitch, guard-marker justification).

Task map (the detail lives in the plan — do NOT improvise from this summary):
- Task 0   Verify the GATE (pre-pilot RLS on dev, B19, B8/B20 ledger-aligned, suite + guard green).
           STOP and name the failing box if any fails.
- Task 1   Worktree ../backend-wt/collapse-baseline on branch chore/collapse-baseline-cutover
           (NOT under .claude/worktrees/ — it poisons the Composer classmap).
- Task 2   Phase A state report: classify the ledger, make drift concrete. Expect plain
           `db diff --linked` to fail 25001 pre-collapse; fall back to scripts/db/fresh-reset.sh +
           `supabase db diff --from local --to linked`.
- Task 3   Phase B ledger reconcile: repair renumbered dupes, adopt real remote-only schema, revert
           proven-phantom rows, apply local-only files surgically (VALIDATE data pre-flight; never bulk
           push). Converge to aligned list + empty diff. Sign-off before Phase C.
- Task 4   Dump the verified dev schema (app schemas only, structure only).
- Task 5   Stitch the cluster-level scaffolding a dump cannot emit: guard disable-file marker with the
           accurate Checks-2/5 justification, CREATE EXTENSION pg_trgm (schema-matched to dev),
           app_backend created NOLOGIN **plus ALTER ROLE app_backend BYPASSRLS** (load-bearing:
           FORCE-RLS tables without an app_backend policy default-deny without it). Sign-off.
- Task 6   Install <ts>_baseline_pilot.sql; git mv EVERY other migration (incl. the 20260526 baseline)
           into supabase/migrations-archive/.
- Task 7   Prove it: fresh-reset.sh from-zero apply clean; `db diff --from local --to linked` EMPTY;
           run the differ ACL-canary (revoke one grant locally, see if the diff notices, restore).
- Task 8   Posture assertions: rolcanlogin=f AND rolbypassrls=t; grant-matrix + default-ACL diff vs dev
           EMPTY; RLS flags + policy lists diff vs dev EMPTY; pinned search_path spot-checks; both views
           resolve.
- Task 9   Repo gates: guard passes, composer test green, baseline greps (one CREATE ROLE, zero
           CONCURRENTLY).
- Task 10  Reference updates (CLAUDE.md, AI_CONTEXT.md, production-cutover.md tick, CONVENTIONS §1,
           scripts/audit/adjudicate-prompt.md, the four app docblocks) + commit. UNPUSHED.

## Standing decisions & discipline (non-negotiable; mirrors the plan's Global Constraints)
- **DEV-ONLY. Never touch prod in this run.** Every `link`/`repair`/`psql`/MCP call targets the DEV
  project `glncumufgaqcmqhzwrxm`, or a THROWAWAY LOCAL DB. The prod project `edplucmvkcnokyygxqsb` is not
  contacted. If you catch yourself typing the prod ref, STOP.
- **A blind `supabase db push` to dev is UNSAFE and FORBIDDEN in this run.** Dozens of repo migrations are
  recorded on dev under DIFFERENT version numbers, so a push re-runs DDL that already exists and can
  VALIDATE-fail against live data. Ledger fixes = `supabase migration repair` (history-only); genuinely
  new files applied surgically one at a time; never bulk push.
- **Present a written plan and WAIT for Josh's sign-off before: (a) any `migration repair`, (b) authoring
  the collapsed baseline, (c) archiving the incrementals.** This is a blocker-gate task end to end.
- **NEVER `git stash` / `git checkout <file>` / `git restore` / `git reset`.** The stash stack is shared
  across worktrees and other live sessions. Read-only git only; `git show <ref>:<path>` for old content.
  Forbid `git stash` explicitly in any subagent prompt you spawn.
- **Pin `model: sonnet` on every implement/verify subagent** (Opus fan-out exhausts the budget). Keep the
  top-level reasoning on Opus.
- **Commit discipline:** verify `git rev-parse --abbrev-ref HEAD` + `git diff --cached --stat` before EVERY
  commit. No `php artisan pint` sweep. Trailers on every commit:
  Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
  Claude-Session: <your session url>
- **Do NOT push to development/production and do NOT apply to prod.** Josh reviews the branch and owns cutover.

## Stop and ask Josh if
- The Task-2 state report shows drift beyond the classes the plan expects (a remote-only REAL object you
  can't confidently adopt, or a drift diff that won't go empty).
- A replay would VALIDATE-fail against live dev data (surface it; mark-applied instead).
- The Task-7 parity diff won't reach empty after two dump/stitch iterations — surface exactly what differs.
- Any pre-pilot-RLS / B8 / B20 migration turns out NOT to be on dev yet (the GATE is violated — schema
  isn't final).
- You're unsure whether an incremental should be archived vs kept (e.g. a migration authored AFTER the
  snapshot timestamp).

## When done — report
- Reconciliation: which rows were repair-ed applied/reverted, which files adopted; final migration list
  aligned + drift diff empty (paste the proof).
- Baseline: filename, line count, exactly one CREATE ROLE (app_backend) confirmed NOLOGIN **and
  BYPASSRLS**, CREATE EXTENSION pg_trgm present, guard marker present, zero CONCURRENTLY.
- Parity: fresh-reset.sh applied clean; `--from local --to linked` diff EMPTY; ACL-canary outcome; Task-8
  grant-matrix + posture assertions all pass; guard + composer test green.
- References updated (CLAUDE.md, AI_CONTEXT.md, production-cutover.md, CONVENTIONS, adjudicate-prompt,
  app docblocks).
- Branch name + `git log --oneline` of your commits (UNPUSHED). Explicitly: prod was never contacted.
- What remains for cutover day (Phases 1–4 of production-cutover.md): wipe+psql-apply the baseline to the
  prod project, ALTER ROLE app_backend LOGIN (and verify BYPASSRLS), secrets, deploy, verify.

=== PROMPT END ===
```
