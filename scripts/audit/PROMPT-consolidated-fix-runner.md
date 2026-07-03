# Consolidated Fix Runner — copy-paste prompt

Drives the **bundle-structured** consolidated fix plan (e.g.
`audits/codebase-full-sweep-2026-06-13/audit-2026-06-13-CONSOLIDATED.md`) from top to bottom,
one **bundle** at a time: risk-gate → verify premises → plan → implement → two reviews
(learning + technical) → test → check off → commit → push. It uses each bundle's own embedded
`*Plan:* / *Implementation:* / *Review (learning):* / *Review (technical):*` prompts and its
`Models: plan=·impl=·review=` line. It runs autonomously on safe bundles and **stops to ask you**
on anything risky (DB migrations, the Cloudflare Worker / KV, RLS, moderation/CSAM, auth) or
ambiguous.

**How to use:**
1. Open a **fresh Claude Code session in this repo** (recommended model: **Opus** — it's the orchestrator).
2. Edit the PARAMETERS block.
3. Paste everything from `=== PROMPT START ===` to the end as your first message. Walk away.

---

```
=== PROMPT START ===

You are the orchestrator for an autonomous consolidated-fix run. Work methodically, ONE BUNDLE at a
time, top to bottom. Default to ACTION: if a bundle is safe and its premise holds, just do it —
implement, review, test, check off, commit, push, move on. Only STOP to ask me when a risk gate
trips or you hit a genuine ambiguity/decision — and when you stop, give a recommendation. Follow
this runbook exactly; do not be clever or take shortcuts.

## PARAMETERS  (the only things that change between runs)

CONSOLIDATED_FILE:  audits/codebase-full-sweep-2026-06-13/audit-2026-06-13-CONSOLIDATED.md
INTEGRATION_BRANCH: development
WORK_BRANCH:        audit-fix/full-sweep-<today's date YYYY-MM-DD>

## MISSION

Read CONSOLIDATED_FILE. It is organised into `### Bundle BN:` blocks and a `## Standalone — do NOT
bundle` section of `### SN:` blocks. Each block contains: a `- [ ] **… complete**` checkbox, a
`Models: plan=·impl=·review=` line, a `- Findings:` list of `- [ ]` items (each ending `→
audit-2026-06-13-<lens>.md` — the source file with full Where/Technical/Evidence), a
Rationale/Approach/Dependencies, and four `**Session prompts:**` blockquotes (Plan, Implementation,
Review (learning), Review (technical)).

Work every **incompletely-checked bundle**, top to bottom (B1, B2, … then S1, S2, …). A bundle is
"done" when all its finding `- [ ]` are `- [x]` AND its `- [ ] **… complete**` box is `- [x]`. Skip
bundles already fully checked (idempotent — safe to resume). For each bundle run the PER-BUNDLE LOOP.
When the loop ends, print the FINAL SUMMARY.

This prompt authorises you to commit and push autonomously for SAFE bundles. RISKY bundles (Rule A)
require my explicit go-ahead each time.

## ONE-TIME SETUP

1. `git status`. The audit deliverables under `audits/codebase-full-sweep-2026-06-13/` are expected
   to be present (the plan you're executing). If OTHER unrelated files are dirty, STOP and ask.
2. `git fetch origin` then `git log --oneline -10 origin/INTEGRATION_BRANCH`.
3. Create the work branch off the *remote* integration branch (local dev lags; default branch is
   production): `git checkout -B WORK_BRANCH origin/INTEGRATION_BRANCH`.
4. Bring CONSOLIDATED_FILE (and the 20 lens files + executive summary in that dir) onto the branch as
   a baseline commit so check-offs commit cleanly:
   `git add audits/codebase-full-sweep-2026-06-13/ && git commit -m "docs(audit): full-sweep consolidated fix plan baseline"` then push it.
5. Parse CONSOLIDATED_FILE into an ordered list of not-yet-complete bundles. Create a TodoWrite list,
   one item per bundle, so progress is visible.

## PER-BUNDLE LOOP

For each not-complete bundle, in order:

### 1. Risk gate (Rule A) — decide BEFORE touching anything
STOP and ask me for permission + guidance if ANY is true:
  - **Standalone:** the block is in the `## Standalone — do NOT bundle` section (S1–S8).
  - **DB-touching:** any finding mentions a migration, raw SQL, `supabase/`, a schema change, RLS, an
    index/constraint, or a data backfill. (These need a `supabase/migrations/` file + a gated
    `supabase db push` — they cannot land via `git push`, and Laravel migration files are blocked by a
    composer guard.)
  - **Edge/Worker:** any finding touches `cloudflare-worker/` or the `SUBDOMAIN_KV` namespace.
    (Editing is fine, but it affects live edge routing and needs a separate `wrangler` deploy —
    confirm with me before changing prod edge behaviour.)
  - **Critical path:** the fix touches the single-writer KV job (`SyncSubdomainToKvJob`),
    moderation/CSAM enforcement, auth/JWT/MFA, or media-processing jobs.
  - **Heavy:** the bundle Effort is `L` or `XL`.
When you stop: name the bundle, which trigger fired, what the fix entails, your recommendation. Do
not implement. Continue only when I say so (or I tell you to skip).
  → For THIS plan, that means you autonomously clear the backend-code bundles and STOP at the
    edge-Worker bundles (B1/B2/B26) and every DB standalone (S3–S8). Do the safe ones; park the rest.

### 2. Verify the premises — adjudicated findings are sometimes wrong
For each finding in the bundle, open the source file (`→ audit-2026-06-13-<lens>.md`) and the cited
`Where:` paths. Confirm the problem still exists and every symbol/column/method/file the finding
names actually exists in the current code (the repo moved fast — account-types/Square/custom-domains
landed recently; some premises may be stale or already fixed).
  - If a finding's premise is false/stale: do NOT implement it, do NOT check it off, note it, and
    continue with the rest of the bundle. If the WHOLE bundle is stale, STOP and report.

### 3. Plan (only if the bundle's `Models: plan=` is a model, not `—`)
Dispatch ONE plan subagent (Agent tool, `model:` = the bundle's `plan=` value) with the bundle's
`*Plan:*` blockquote + your premise notes. It returns a short step list + risks. Use it to steer
implementation. If `plan=—`, skip straight to implement.

### 4. Implement (subagent)
Dispatch ONE implementation subagent (Agent tool, `subagent_type: general-purpose`, `model:` = the
bundle's `impl=` value) scoped to THIS bundle. Give it: the bundle's `*Implementation:*` blockquote,
the finding list with their source-file pointers, and the plan output. Instruct it: minimal blast
radius, follow existing patterns, obey CLAUDE.md — Resource classes for API responses, Policies via
`authorizeForUser` (never inline 403s), no Laravel migration files, every `ShouldQueue` job needs
`$backoff`. It returns a summary of exactly what changed.

### 5. Style
`vendor/bin/pint` on the CHANGED files only (`vendor/bin/pint <paths>`). NEVER a repo-wide pint fix —
the baseline shifts and you'll create unrelated churn (revert any stray baseline edits).

### 6. Two reviews (subagents) — both at the bundle's `review=` model
Dispatch the **technical** review subagent with the bundle's `*Review (technical):*` blockquote + the
diff (`git diff`). It is the GATE: does the change fully resolve every finding, is it correct, scoped,
side-effect-free, does it preserve the named invariants? If it reports real problems, go back to step
4 with the feedback. After **2** failed technical-review rounds, STOP and ask me.
Then dispatch the **learning** review subagent with the bundle's `*Review (learning):*` blockquote +
the diff. Capture its plain-language write-up — include it in the commit body / final summary (it's
the teaching record; it does not gate, but if it surfaces a real correctness concern, treat it like a
technical finding).
Do NOT run `composer test` while a review subagent is running (resource contention makes tests flaky).

### 7. Test — in THIS checkout, never a worktree
Run `composer test` (feature tests are unreliable in worktrees). On failure: debug the root cause
(read the failure, hypothesis, fix — no bandaids). Still red after **2** attempts → STOP and ask.
Never commit or push red.

### 8. Check off
In CONSOLIDATED_FILE: flip each implemented finding's `- [ ]` to `- [x]`, and the bundle's
`- [ ] **Bundle BN complete**` (or `**SN complete**`) box to `- [x]`. Leave any premise-rejected
finding unchecked with a one-line `<!-- premise stale: … -->` note.

### 9. Commit (one commit per bundle)
`git add` the implementation files + CONSOLIDATED_FILE. Run `git diff --cached --stat` and confirm it
contains ONLY this bundle's files + the check-off — nothing stray (the index can carry prior WIP).
Then:

    <type>(<area>): <bundle title> [<bundle id>]

    <1-3 lines: what was wrong, what changed, finding IDs>
    Review (learning): <one-line gist from the learning review>

    Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>

`<type>` = fix for correctness/security, refactor/chore for cleanup, test for coverage bundles.

### 10. Push the WORK_BRANCH (Josh merges — do NOT push to development directly)
  a. `git fetch origin`
  b. `git rebase origin/INTEGRATION_BRANCH` — keep the branch current. On conflict: STOP and ask.
  c. `git push origin WORK_BRANCH` (first push: `-u`). The commits accumulate on the work branch;
     I review and merge to development myself. Do NOT push to INTEGRATION_BRANCH.
  (If you want direct-to-development autonomous pushes instead, say so and change this step to
   `git push origin HEAD:INTEGRATION_BRANCH` with the same fetch→rebase→retry cycle.)

### 11. Log + next
Mark the TodoWrite item done, print `✅ <bundle id> implemented, reviewed (tech+learning), tested,
pushed → development`, move to the next bundle.

## WHEN YOU STOP (any guard)
Leave the tree clean and understandable:
  - Risk-gate / false-premise stops happen BEFORE edits → tree already clean.
  - Review / test stops → leave changes uncommitted, unpushed; describe them.
Post: bundle id, why you stopped, what you found, options, your recommendation. Wait. Push nothing
partial.

## NON-NEGOTIABLES (CLAUDE.md + project memory)
  - No Laravel migration files; DB changes go through Rule A (stop and ask) → raw SQL in
    `supabase/migrations/`, applied dev-first via gated `supabase db push`, never prod unattended.
  - Never change `cloudflare-worker/` / `SUBDOMAIN_KV` behaviour without my OK (live edge routing).
  - API responses use Resource classes; authz uses Policies (`authorizeForUser`), never inline 403s.
  - `composer test` runs in the main checkout, not a worktree; not concurrently with a review subagent.
  - `vendor/bin/pint` scoped to changed files only.
  - One bundle per implement-subagent; keep your orchestrator context lean.
  - Verify premises before implementing. Base the branch off origin/development.
  - Never push to development without the fetch→rebase cycle; stop on conflict.

## FINAL SUMMARY (when the loop ends)
Print a table: each bundle id → status (pushed ✅ / parked-for-you ⏸ with the trigger / premise-rejected
findings ❌). State current `composer test` status, how many commits landed on development, and the
list of parked bundles (the edge-Worker + DB standalones) so I can run those manually.

=== PROMPT END ===
```
