# Audit Fix Runner (BUNDLED) — copy-paste prompt

Bundle-aware variant of `PROMPT-audit-fix-runner.md`, for **consolidated** audit files
(`audit-YYYY-MM-DD-CONSOLIDATED.md`) whose findings are grouped into `### Bundle Bn` and
`### Sn` (standalone) blocks, each carrying its own `Models:` line and `Session prompts:`
(Plan / Implementation / Review-learning / Review-technical).

**Use this file when** the audit groups findings into bundles. **Use the per-item
`PROMPT-audit-fix-runner.md` when** the audit is a flat P0→P3 finding list (a per-lens file).

**Why a separate runner:** the per-item runner works findings in *tier order across the whole
file*, which shatters a bundle (e.g. B2's seven Worker findings span P0/P2/P3 and would be
done in three different places, forcing three Worker deploys). This runner's unit of work is the
**bundle**: one coherent change, one review, one commit, one push — preserving the cohesion the
consolidated file was authored for. The findings here are one-liners; their full
**Technical / Plain-English / Evidence** lives behind each finding's `→ audit-YYYY-MM-DD-<lens>.md`
backref, and each bundle already ships paste-ready session prompts — this runner *uses* them.

**How to use:**
1. Open a **fresh Claude Code session in this repo** (recommended model: **Opus** — it's the orchestrator).
2. Edit the three values in the PARAMETERS block below.
3. Copy everything from `=== PROMPT START ===` to the end and paste it as your first message.
4. Walk away. It runs bundle-by-bundle and **stops to ask you** at every risk gate, false premise,
   or failure it can't resolve.

---

```
=== PROMPT START ===

You are the orchestrator for an autonomous, BUNDLE-AWARE audit-fix run over a consolidated audit
file. Your unit of work is the BUNDLE, not the individual finding. Work methodically, one bundle at
a time, and STOP to ask me whenever a guard trips. Do not be clever or take shortcuts — follow this
runbook exactly.

## PARAMETERS  (the only things that change between runs)

AUDIT_FILE:         <fill in, e.g. audits/codebase-full-sweep-2026-06-13/audit-2026-06-13-CONSOLIDATED.md>
INTEGRATION_BRANCH: development
WORK_BRANCH:        audit-fix/<short-lens-slug>-<today's date YYYY-MM-DD>

## MISSION

Read AUDIT_FILE. Work every unchecked BUNDLE under `## Suggested bundled fix sessions` in document
order — B1, B2, … Bn, top to bottom (NOT tier order; bundle cohesion beats strict P0-first). For
each bundle: risk-gate it, verify each finding's premise, (optionally plan), implement the whole
bundle as ONE change via a subagent, review it via a subagent, prove tests pass, tick every finding
box AND the `Bundle Bn complete` box in AUDIT_FILE, commit once, and push to INTEGRATION_BRANCH.
Then the next bundle. After the last bundle, walk the `## Standalone — do NOT bundle` section — every
`Sn` item is an automatic Rule-A stop (see below); surface each and wait. When everything is checked
off or parked for me, print a final summary.

This prompt is your authorization to commit and push autonomously for non-risky bundles. Risky
bundles (Rule A) require my explicit go-ahead each time.

## ONE-TIME SETUP

1. Confirm a clean working tree (`git status`). If dirty, STOP and ask — do not clobber WIP.
2. `git fetch origin` then `git log --oneline -10 origin/INTEGRATION_BRANCH` to see recent work.
3. Create the work branch off the *remote* integration branch:
   `git checkout -B WORK_BRANCH origin/INTEGRATION_BRANCH`
   (Base off origin/development, not local — local dev lags; the default branch is production.)
4. Parse AUDIT_FILE into an ordered list of BUNDLES. A bundle block looks like:
   `### Bundle B<n>: <name> (<k> items) — Effort: <S|M|L|XL>`
   followed by:
     - `- [ ] **Bundle B<n> complete**`              ← the bundle roll-up box (NOT a finding)
     - `- Models: plan=<x> · impl=<y> · review=<z>`   ← the named models for this bundle
     - `- Findings:` then indented `- [ ] **<ID>** · P<n> — <title> — <path:line> → audit-…-<lens>.md`
     - `- Rationale:` / `- Suggested approach:` / `- Dependencies:`
     - `**Session prompts:**` then `*Plan:* / *Implementation:* / *Review (learning):* / *Review (technical):*`
   Skip any bundle whose `Bundle B<n> complete` box is already `- [x]` (idempotent — safe to resume).
   If a bundle is PARTIALLY checked (some findings `- [x]` but the bundle box still `- [ ]`) a prior
   run was interrupted mid-bundle: STOP and ask me before touching it — re-implementing a subset of a
   change meant to land as one coherent commit can conflict with what already shipped. Do not continue silently.
   IGNORE the `## Cross-lens high-confidence findings` prose and the `## Deduplication notes` /
   `## Coverage report` sections — they are not work items.
5. Create a TodoWrite list with one item per remaining bundle (label = `B<n>: <name>`).

## PER-BUNDLE LOOP

For each bundle, in document order:

### 1. Risk gate (Rule A) — decide BEFORE touching anything
STOP and ask me for permission + guidance if ANY finding in the bundle, or the bundle as a whole,
trips ANY of these:
  - **DB-touching:** any finding mentions a migration, raw SQL, `supabase`, a schema/DDL change, RLS,
    or a data backfill. These need a `supabase/migrations/` file + a gated `supabase db push` — they
    cannot land via `git push`, and Laravel migration files are blocked by a composer guard. (Prod is
    still on the pre-standalone schema, so any DDL is a gated prod re-baseline concern.)
  - **Worker-touching:** a finding's OWN target `<path:line>` is under `cloudflare-worker/`. Judge by
    the finding's file, NOT by prose in `Suggested approach`/`Plan` that merely *mentions* the Worker —
    a bundle whose findings target PHP (`CloudflarePurgeService`, `SiteObserver`) is git-pushable and
    does NOT trip this, even if its plan says "read the Worker's cache-put paths". Worker fixes land via
    `wrangler deploy` to a PREVIEW + a real-page render check — NOT `git push origin development`.
  - **Heavy effort:** the bundle's `— Effort:` tag is `L` or `XL`.
  - **Standalone:** the block lives under `## Standalone — do NOT bundle` (every `Sn` — these are
    explicitly un-bundleable: KV contract, schema-wide RLS, raw DDL, large test authoring, or a human
    design call).
  - **Critical path:** the fix touches auth/JWT/MFA verification or media-processing jobs. (No
    payments/billing in this codebase.) Authorization-policy bundles (e.g. staff-controller policy
    refactors), transaction-boundary, and GDPR/PII bundles are NOT auto-stops — they land via
    `git push` and the file already assigns them `review=opus`; run them autonomously but give them
    extra scrutiny in step 6. The line: changing a Policy/transaction is routine and test-covered;
    touching JWT/MFA *verification* is not — stop for the latter.
When you stop, tell me: which bundle, which trigger fired, which finding(s) caused it, what the fix
entails (from Suggested approach), and your recommendation. Do not implement. Resume only when I say
so (or I tell you to skip the bundle).

### 2. Verify each finding's premise — adjudicated findings are sometimes wrong
For every finding in the bundle, open its `→ audit-…-<lens>.md` backref (it sits in the same directory
as AUDIT_FILE and carries the full Technical / Plain-English / Evidence) AND the exact `<path:line>` in
the finding. Confirm the problem
still exists and that every symbol, column, query, and method named actually exists in the current
code/schema. Then:
  - If a **P0 or P1** finding's premise is false/stale, OR **more than one-third** of the bundle's
    findings are stale: STOP, report exactly what you found, do NOT implement, do NOT check anything
    off. Ask whether to do the bundle minus the stale item(s) or skip it.
  - If a single **P2/P3** finding is stale (and the bundle is otherwise sound): drop just that
    finding from the bundle's scope, leave its box `- [ ]`, append ` — _premise stale, skipped: <reason>_`
    after its line in AUDIT_FILE, and proceed with the rest. Note it in your progress line.

### 3. Plan (subagent, only if `plan=` is set)
If the bundle's `Models:` line has `plan=` set to a model (not `—`), dispatch ONE plan subagent
(Agent tool, `model:` = that value) with the bundle's `*Plan:*` session prompt verbatim plus your
premise-verification notes. It returns a short step list. Feed that into step 4. If `plan=—`, skip.

### 4. Implement the WHOLE bundle (one subagent)
Dispatch ONE implementation subagent (Agent tool, `subagent_type: general-purpose`, `model:` = the
bundle's `impl=` value) scoped to THIS bundle's findings as a single coherent change. Give it:
  - the bundle's `*Implementation:*` session prompt verbatim,
  - the `Suggested approach`, each finding's resolved detail (from its backref), the plan output if any,
  - and your premise notes (incl. any P2/P3 finding you dropped — tell it NOT to touch that one).
Instruct it: minimal blast radius, follow existing patterns, obey CLAUDE.md (Resource classes for API
responses, Policies via `authorizeForUser`, no Laravel migration files, no raw 403 aborts in
controllers, no `site.themes`/`settings.design.*` reintroduction). It returns a summary of exactly
what it changed across the bundle.

### 5. Style
Run `vendor/bin/pint` on the changed files ONLY (`vendor/bin/pint <paths>`). Never a repo-wide pint
fix — the baseline isn't clean and you'll create unrelated churn.

### 6. Review the bundle (subagent)
Dispatch a review subagent (Agent tool, `model:` = the bundle's `review=` value; never haiku). Give
it the bundle's `*Review (technical):*` session prompt as the rubric, the full bundle block, and the
diff (`git diff`). It checks every finding in the bundle is fully + correctly resolved, no side
effects, matches codebase patterns, stays scoped. This technical review is the GATE: if it reports
problems, go back to step 4 with the feedback. After **2** failed review rounds, STOP and ask me.
(The bundle's `*Review (learning):*` prompt is for human readers — you may run it for the record, but
it does not gate.)

### 7. Test — in THIS checkout, never a worktree
Run `composer test`. Feature tests are unreliable in git worktrees; everything runs in the main
checkout. If tests fail: debug systematically (read the failure, form a hypothesis, fix the root
cause — not a bandaid). If still red after **2** attempts, STOP and ask me. **Never push red.**

### 8. Check off
In AUDIT_FILE: flip every fixed finding's `- [ ]` to `- [x]`, flip the `- [ ] **Bundle B<n> complete**`
box to `- [x]`, and update any `## Progress` / `## Coverage report` counters present.

### 9. Commit (ONE per bundle)
`git add` the implementation files + AUDIT_FILE. Run `git diff --cached --stat` and verify it contains
ONLY this bundle's files plus the audit checkoff — nothing stray. Then commit:

    <type>(<area>): <bundle name> [B<n>]

    <1-2 lines: the family of problems, the one coherent change>
    Findings: <ID>, <ID>, …

    Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>

`<type>` = fix for correctness bundles, refactor/chore for cleanup bundles.

### 10. Integrate to INTEGRATION_BRANCH (feature branch → development, no PR)
  a. `git fetch origin`
  b. `git rebase origin/INTEGRATION_BRANCH`  — absorbs concurrent work.
     On conflict: STOP and ask me (do not guess a resolution).
  c. `git push origin HEAD:INTEGRATION_BRANCH`  — lands this bundle on development, no PR.
  d. If the push is rejected (someone pushed in between), repeat a–c. After **3** rejected cycles,
     STOP and ask me.

### 11. Log + next
Mark the TodoWrite item completed, print one progress line
(`✅ B<n> <name> — <k> findings fixed, reviewed, tested, pushed → development`), move to the next bundle.

## AFTER THE LAST BUNDLE — the Standalone section
Walk `## Standalone — do NOT bundle` top to bottom. Every `Sn` is an automatic Rule-A stop. For each
not-yet-complete `Sn`: surface it (its findings, effort, why-standalone, the trigger it fires — DDL,
KV contract, L-effort, etc.) with your recommendation, and wait for me. Do not implement standalone
items autonomously.

## WHEN YOU STOP (any guard)
Leave the working tree clean and understandable:
  - Risk-gate / false-premise stops happen *before* any edit → tree already clean.
  - Review / test-failure stops → leave the changes uncommitted and unpushed; describe them.
Then post: bundle ID, why you stopped, what you found, options, recommendation. Wait. Push nothing partial.

## NON-NEGOTIABLES (from CLAUDE.md + project memory)
  - No Laravel migration files — DB changes go through Rule A (stop and ask).
  - Worker (`cloudflare-worker/`) changes deploy via `wrangler` preview, not `git push` — Rule A stop.
  - API responses use Resource classes; authorization uses Policies (`authorizeForUser`), never inline 403.
  - `composer test` runs in the main checkout, not a worktree.
  - `vendor/bin/pint` scoped to changed files only — never wholesale.
  - One bundle per implementation subagent; keep your own (orchestrator) context lean.
  - Verify every finding's premise before implementing.
  - Never push to development without the fetch→rebase cycle; stop on conflict.
  - Bundle order (document order), NOT tier order — preserve bundle cohesion.

## FINAL SUMMARY (when the loop ends)
Print a table: each bundle B<n> → status (pushed ✅ / parked-for-you ⏸ with reason / premise-rejected ❌),
plus the Standalone Sn items left for me. State the current test status and how many commits landed on
development.

=== PROMPT END ===
```
