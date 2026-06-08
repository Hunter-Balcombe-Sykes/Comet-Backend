# Audit Fix Runner — copy-paste prompt

Generic, reusable prompt for working an audit file (canonical Partna format) from P0→P3,
one finding at a time: verify → implement → review → test → check off → push to development.

**How to use:**
1. Open a **fresh Claude Code session in this repo** (recommended model: **Opus** — it's the orchestrator).
2. Edit the three values in the PARAMETERS block below.
3. Copy everything from the `=== PROMPT START ===` line to the end and paste it as your first message.
4. Walk away. It runs autonomously and **stops to ask you** whenever it hits a risky finding,
   a false premise, or a failure it can't resolve.

---

```
=== PROMPT START ===

You are the orchestrator for an autonomous audit-fix run. Work methodically, one finding at a
time, and STOP to ask me whenever a guard trips. Do not be clever or take shortcuts — follow this
runbook exactly.

## PARAMETERS  (the only things that change between runs)

AUDIT_FILE:         <fill in, e.g. audits/code-quality/audit-2026-06-06-code-quality-consolidated.md>
INTEGRATION_BRANCH: development
WORK_BRANCH:        audit-fix/<short-lens-slug>-<today's date YYYY-MM-DD>

## MISSION

Read AUDIT_FILE. Work every unchecked finding in tier order — P0, then P1, P2, P3 — and within a
tier, top to bottom. For each finding: verify its premise, implement the fix via a subagent, review
it via a subagent, prove tests pass, check it off in AUDIT_FILE, commit, and push to
INTEGRATION_BRANCH. Then move to the next. When all findings are checked off or parked for me,
print a final summary.

This prompt is your authorization to commit and push autonomously for the non-risky findings.
Risky findings (Rule A) require my explicit go-ahead each time.

## ONE-TIME SETUP

1. Confirm a clean working tree (`git status`). If it's dirty, STOP and ask — do not clobber WIP.
2. `git fetch origin` then `git log --oneline -10 origin/INTEGRATION_BRANCH` to see recent work.
3. Create the work branch off the *remote* integration branch:
   `git checkout -B WORK_BRANCH origin/INTEGRATION_BRANCH`
   (We base off origin/development, not local — local dev lags; the default branch is production.)
4. Parse AUDIT_FILE into an ordered list of findings. Each finding looks like:
   `- [ ] **#<ID>** · P<n> — <title>` followed by indented `**Where:** / **Affects:** / **Effort:** /
   **What to do:** / **Technical:** / **Evidence:**` bullets.
   Skip any finding already marked `- [x]` (idempotent — safe to resume a partial run).
5. Create a TodoWrite list with one item per remaining finding so progress is visible.

## PER-FINDING LOOP

For each finding, in order:

### 1. Risk gate (Rule A) — decide BEFORE touching anything
STOP and ask me for permission + guidance if ANY of these is true:
  - **DB-touching:** the fix mentions a migration, raw SQL, `supabase`, a schema change, or a data
    backfill. (These need a `supabase/migrations/` file + a gated `supabase db push` — they cannot
    land via `git push`, and Laravel migration files are blocked by a composer guard.)
  - **Do-not-bundle:** the finding's ID appears in the `### Standalone — do NOT bundle` section of
    AUDIT_FILE.
  - **Heavy effort:** the `**Effort:**` tag is `L` or `XL`.
  - **Critical path:** the fix touches media-processing jobs, auth/JWT, or payments/billing.
When you stop, tell me: which finding, which trigger fired, what the fix entails, and your
recommendation. Do not implement it. Move on only when I say so (or I tell you to skip it).

### 2. Verify the premise — adjudicated findings are sometimes wrong
Open the exact files/lines in `**Where:**`. Confirm the described problem still exists and that every
symbol, column, query, and method the finding names actually exists in the current code/schema.
  - If the premise is false or stale (already fixed, references a column/method that doesn't exist,
    etc.): **STOP, report what you found, do NOT implement, do NOT check it off.** Ask whether to
    skip or how to proceed.

### 3. Pick models (Rule B)
  | Finding shape                                   | Implement | Review |
  |-------------------------------------------------|-----------|--------|
  | Trivial SLOP (comment / dead-code / one-liner)  | haiku     | sonnet |
  | Any SEM / behavioral change / P0–P2             | sonnet    | opus   |
  | M-effort refactor (multi-file, no logic change) | sonnet    | sonnet |
  (L/XL never reach here — Rule A stopped them.)

### 4. Implement (subagent)
Dispatch ONE subagent (Agent tool, `subagent_type: general-purpose`, `model:` per Rule B) scoped
strictly to THIS finding. Give it the finding's `What to do`, `Technical`, and `Evidence`, plus your
premise-verification notes. Instruct it: minimal blast radius, follow existing patterns, obey
CLAUDE.md (Resource classes for API responses, Policies for authz via `authorizeForUser`, no Laravel
migration files, no raw 403 aborts in controllers). It returns a summary of exactly what it changed.

### 5. Style
Run `vendor/bin/pint` on the changed files ONLY (`vendor/bin/pint <paths>`). Never run a
repo-wide pint fix — the baseline isn't clean and you'll create unrelated churn.

### 6. Review (subagent)
Dispatch a review subagent (Agent tool, `model:` per Rule B). Give it the finding and the diff
(`git diff`). It checks: does the change fully resolve the finding, is it correct, are there side
effects, does it match the codebase's patterns, is it scoped. If it reports problems, go back to
step 4 with the feedback. After **2** failed review rounds, STOP and ask me.

### 7. Test — in THIS checkout, never a worktree
Run `composer test`. Feature tests are unreliable in git worktrees, so everything here runs in the
main checkout. If tests fail: debug systematically (read the failure, form a hypothesis, fix the
root cause — not a bandaid). If still red after **2** attempts, STOP and ask me. **Never push red.**

### 8. Check off
In AUDIT_FILE: flip this finding's `- [ ]` to `- [x]`, and update the `## Progress` counters
(e.g. `P2 Medium: 1 of 4 complete`).

### 9. Commit
`git add` the implementation files + AUDIT_FILE. Run `git diff --cached --stat` and verify it
contains ONLY this finding's files plus the audit checkoff — nothing stray. Then commit:

    <type>(<area>): <concise summary> [#<ID>]

    <1-2 lines: what was wrong, what changed>

    Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>

`<type>` = fix for SEM correctness, refactor/chore for SLOP cleanup.

### 10. Integrate to INTEGRATION_BRANCH (feature branch → development, no PR)
  a. `git fetch origin`
  b. `git rebase origin/INTEGRATION_BRANCH`  — absorbs concurrent work.
     On conflict: STOP and ask me (do not guess a resolution).
  c. `git push origin HEAD:INTEGRATION_BRANCH`  — lands this commit on development, no PR.
  d. If the push is rejected (someone pushed in between), repeat a–c. After **3** rejected cycles,
     STOP and ask me.

### 11. Log + next
Update the TodoWrite item to completed, print one progress line
(`✅ #<ID> fixed, reviewed, tested, pushed → development`), and move to the next finding.

## WHEN YOU STOP (any guard)
Leave the working tree in a clean, understandable state:
  - Risk-gate / false-premise stops happen *before* any edit → tree already clean.
  - Review / test-failure stops → leave the changes uncommitted and unpushed; describe them.
Then post: the finding ID, why you stopped, what you found, options, and your recommendation. Wait
for me. Do not push anything partial.

## NON-NEGOTIABLES (from CLAUDE.md + project memory)
  - No Laravel migration files — DB changes go through Rule A (stop and ask).
  - API responses use Resource classes; authorization uses Policies (`authorizeForUser`), never
    inline 403 aborts.
  - `composer test` runs in the main checkout, not a worktree.
  - `vendor/bin/pint` scoped to changed lines only — never wholesale.
  - One finding per subagent; keep your own (orchestrator) context lean.
  - Verify premise before implementing.
  - Never push to development without the fetch→rebase cycle; stop on conflict.

## FINAL SUMMARY (when the loop ends)
Print a table: each finding ID → status (pushed ✅ / parked-for-you ⏸ with reason / premise-rejected
❌). State the current test status and how many commits landed on development.

=== PROMPT END ===
```
