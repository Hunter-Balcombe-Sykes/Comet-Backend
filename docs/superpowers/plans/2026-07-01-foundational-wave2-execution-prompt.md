# Wave-2 Execution Prompt (foundational audit) — automated per-item implement → review → deploy loop

**Purpose:** one prompt that drives the finalized Wave-2 implementation plan to completion, one PR at a
time: spawn a Sonnet implementer for an item, then an **independent** Sonnet reviewer, gate on the full
test suite, then **auto-push to `development` and apply the Supabase migration**, then move to the next
item. It stops for the things that genuinely must not be automated.

**Confirmed settings baked in (Josh, 2026-07-01):**
- Deploy mode = **auto-push after review passes** (hands-off) — EXCEPT the two frontend-gated Phase-2
  strips, which **hard-pause**.
- All 8 plan decisions are CONFIRMED (see the plan's `## DECISIONS` banner). Notably FOUND-4 visibility =
  **keep name-OR-address (no behavior change)**.

**Paste the fenced block below into a fresh session** (default output style — not explanatory). It runs
the loop and reports at the end (or when it hard-pauses).

---

```
Execute the finalized Wave-2 implementation plan end-to-end, ONE PR at a time, with an automated
implement → independent-review → full-suite-gate → deploy loop. You are the DRIVER: you orchestrate
subagents and own the git/deploy/DB steps yourself; you do NOT write feature code directly.

## Read first (in this order)
- docs/superpowers/plans/2026-06-30-foundational-wave2-implementation.md  ← THE PLAN. Its `## DECISIONS`
  banner (all 8 CONFIRMED), the `## Execution progress` checklist, the PR-ordering table, and one
  `## PRn` section per item are the source of truth. Each `## PRn` section is a complete, self-contained
  implementation plan (full code, final migration SQL + rollback, tests/Pest.php edits, dev-Supabase
  verification, TDD tasks, golden masters).
- CLAUDE.md — house rules. Critical: raw SQL migrations in supabase/migrations/ ONLY (a guard rejects
  Laravel migrations); Resource classes; Policies via authorizeForUser; `$backoff` on every ShouldQueue
  job; SQLite tests do NOT enforce Postgres CHECK/NOT NULL/partial-unique/FK-cascade; **pushing
  `development` deploys BOTH api domains incl. prod**; Supabase dev ref `glncumufgaqcmqhzwrxm` is the
  live DB for everything; `migrate --force` is commented out so migrations are applied via MCP, not on
  deploy.
- scripts/audit/fix-flow.md — the plan→implement→independent-review→commit discipline you are automating.

## Setup (once)
1. `git fetch origin && git log --oneline -5 origin/development`. Confirm a clean tree.
2. Ensure you are on a Wave-2 working branch based on up-to-date origin/development:
   `git checkout -B audit-fix/wave2-exec origin/development` (or reuse it if it already exists and is
   ahead). `composer install` if needed.
3. Read the `## Execution progress` checklist in the plan. Start at the first unticked item. (This makes
   the loop resumable — if a prior run stopped, you continue where it left off.)

## The item list & order (follow the checklist exactly; dependencies are already baked into this order)
PR1 → PR2 → PR3 → PR4 → PR5a → PR5b(GATED) → PR6a → PR6b(GATED) → PR7 → PR8 → PR9 → PR10
- PR3 must precede PR4; PR4 must precede PR5; PR5 must precede PR6 (shared views); PR9 must precede PR10.
  Going in the order above satisfies all of these.
- PR5 and PR6 are dual-write-then-strip: **Phase 1 (a) = expand** (add columns + backfill + dual-write;
  backend-only, reversible → deploy normally). **Phase 2 (b) = strip + rewrite BOTH views + flip the
  wire contract → FRONTEND-GATED, HARD-PAUSE (see below).**

## Per-item loop — for each unticked item, in order:

### 1. Implement — spawn a Sonnet subagent (model: sonnet)
Give it: the item's full `## PRn` section (paste the section text), the repo root, and these standing
rules — implement EXACTLY what the section specifies (all code, the migration file(s) as raw SQL under
supabase/migrations/, the tests/Pest.php schema edits, the TDD tasks in order); follow CLAUDE.md house
rules; run the section's tests until green; `php artisan pint --dirty`; tick the section's task
checkboxes. For a migration, use a real timestamp LATER than the then-latest file in supabase/migrations/
(check `ls supabase/migrations/ | tail -1`), preserving the section's within-PR ordering. Do NOT push, do
NOT apply migrations to Supabase, do NOT commit — return a summary of files changed + test results.
- For **PR5a/PR6a**, scope the implementer to **Phase 1 only** (expand + dual-write). For **PR5b/PR6b**,
  Phase 2 only.
- Escalation: if the item is gnarly (PR3 write-path, PR5/PR6 views) and Sonnet stalls, you MAY re-spawn
  the implementer as Opus.

### 2. Independent review — spawn a SEPARATE Sonnet subagent (model: sonnet), NOT the implementer
Give it: the item's `## PRn` section + the diff (`git diff`) + the test output. It verifies: the change
actually implements the section, no regression/new bug, tests genuinely pass, house rules honored
(Policies not inline 403s, Resource classes, Supabase-not-Laravel migrations, `$backoff`), golden masters
intact, and the section's dev-Supabase verification steps are present/correct. It returns PASS or a
specific defect list. It must NOT have written the code.
- **PR1 (auth) additionally gets a SECURITY review**: the reviewer must confirm AAL2 behavior is
  byte-identical across all three delegating call sites (BasePolicy, MfaController, StaffUserController)
  and the `mfa_fresh_required` shape is unchanged.
- FAIL → hand the defects to a NEW Sonnet implementer (step 1), then re-review. After **2 failed review
  rounds**, escalate the implement+review pair to Opus for one round; if still failing, mark the item
  **BLOCKED**, STOP the whole loop, and report (do not proceed — later items may depend on this one).

### 3. Full-suite gate (non-negotiable)
Run the FULL `composer test`. A filtered subset is a false signal. Must be 100% green before any deploy.
If red, treat as a review FAIL (back to step 1). Never push on red.

### 4. Commit
`php artisan pint --dirty` (if anything changed), then commit the code + the plan's ticked checkboxes:
`git add -A && git commit -m "feat(wave2): <PRn FOUND-x> — <one-line>"`. Also tick this item in the
plan's `## Execution progress` checklist in the same commit.

### 5. Deploy — auto-push (this is the confirmed hands-off mode)
a. **Git → development:** `git fetch origin`; rebase your branch onto origin/development if it moved
   (`git rebase origin/development`; resolve or STOP+report on conflict); then advance development:
   `git checkout development && git pull --ff-only && git merge --no-ff audit-fix/wave2-exec -m "merge:
   wave2 <PRn>" && git push origin development`. If the push is rejected (concurrent dev), pull --rebase
   and retry up to 3×. Then `git checkout audit-fix/wave2-exec`. (Pushing development deploys BOTH api
   domains — this is expected under the confirmed auto-push mode.)
b. **Supabase (only if the item has a migration):** apply to dev ref `glncumufgaqcmqhzwrxm` via MCP:
   - Standard migrations → `apply_migration` (wraps in a txn).
   - `CREATE INDEX CONCURRENTLY` / any `-- no txn` migration (PR7, PR8's index file) → run each statement
     individually via `execute_sql` (autocommit); NEVER apply_migration (it would error "CONCURRENTLY
     cannot run inside a transaction block").
   - Then run that section's **DEV-SUPABASE VERIFICATION** steps via `execute_sql` (attempt the invalid
     insert, confirm the rejection; confirm the view/probe results). If a verification FAILS, STOP+report
     — the migration's constraint or view is wrong (SQLite couldn't catch it).
c. **Post-deploy smoke:** `cloud deployment:list development` (expect build.running → deployment.succeeded)
   and `cloud env:logs partna development --minutes 5` — confirm no new exceptions from this change. If
   the deploy failed or errors appear, STOP+report.

### 6. Advance
Tick the item in `## Execution progress` (commit that tick), then move to the next unticked item.

## HARD PAUSES — stop the loop and ask Josh (do NOT auto-proceed) when:
- **PR5b or PR6b (Phase-2 strip).** These change the public/dashboard wire contract and MUST NOT ship
  ahead of the frontend. When you reach PR5b/PR6b: implement + review + full-suite locally, then STOP and
  ask Josh to confirm `partna-pages` + `partna-frontend` have shipped their top-level reads. Only deploy
  Phase 2 after he confirms.
- An item is **BLOCKED** after the review escalation ladder (2 Sonnet rounds + 1 Opus round).
- **Tests stay red** and the implementer can't recover.
- A **dev-Supabase verification FAILS** (a CHECK/unique/FK didn't behave as the plan asserts).
- A **deploy fails** or post-deploy logs show new exceptions.
- A **git conflict** on rebase/merge you can't cleanly resolve.
- **PR9** requires `composer dump-autoload -o` after it deletes 21 connect-request files — run it before
  the full-suite gate; if the classmap misbehaves, STOP+report.

## Report
After each item deploys: one line (PRn — deployed, tests green, migration applied+verified). At the end
(all ticked) or on any hard-pause: report which items are done/deployed, which is paused/blocked and why,
the branch + development HEAD, and the next action needed from Josh. Do NOT touch the `production` branch
or the prod Supabase ref (`edplucmvkcnokyygxqsb`) — everything targets `development` + dev ref
`glncumufgaqcmqhzwrxm` only.
```

---

## Notes for Josh (not part of the prompt)

- **This is aggressive by design** — you chose full auto-push, so each passing item deploys to `development`
  (both api domains) and its migration lands on the live dev Supabase automatically. The only automatic
  brakes are: the full-suite gate, the independent review, the per-migration dev-Supabase verification, the
  post-deploy smoke check, and the hard-pauses list. If you'd rather it pause before *every* deploy, say so
  and I'll flip step 5 to "commit, then ask before push."
- **PR5/PR6 each deploy twice** (expand now, strip later). The loop auto-ships the safe expand phase and
  **hard-pauses before the wire-changing strip** until the frontend is ready — that's the SmartLinks-P2
  lesson encoded. You'll get a prompt at PR5b and PR6b.
- **Interactive `supabase link` is avoided** — the loop uses the Supabase MCP (`apply_migration` /
  `execute_sql`) so it can run unattended. If you prefer `supabase db push`, you'll need to `!supabase
  link` yourself first and tell the loop to use it.
- **Resumable:** it works the `## Execution progress` checklist in the plan, so if it stops (pause/block),
  re-pasting the same prompt continues from the first unticked item.
- **One knob you may want:** models. It runs Sonnet implement + Sonnet review (your instruction), escalating
  to Opus only after two failed review rounds. If you want PR3 / PR5 / PR6 on Opus from the start (they're
  the gnarliest — write-path rewire + view lockstep), tell me and I'll pin those items to Opus.
