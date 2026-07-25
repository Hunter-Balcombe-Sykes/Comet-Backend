# PROMPT — Phase 0: land the async-connect design, surface the one open reading

> Paste this whole file as the opening message of a fresh session in the
> Comet-Backend repo (`/Users/joshuahunter/Herd/Side Street/backend`).
> **This session commits documents. It writes no code and touches no controller.**

---

## Where this sits

| Phase | Prompt | Ships |
|---|---|---|
| **0 — you are here** | this file | the design docs, committed |
| 1 | `2026-07-24-connect-phase1-fetchbudget-PROMPT.md` | W1 — `FetchBudget` ×6 |
| 2 | `2026-07-24-connect-phase2-worker-prereqs-PROMPT.md` | RV-4 + RV-8 |
| 3 | `2026-07-24-connect-phase3-implementation-PROMPT.md` | W2–W8 (dark) |
| 4 | `2026-07-24-connect-phase4-activation-PROMPT.md` | activation (ops) |
| W9 | `2026-07-24-connect-w9-shop-PROMPT.md` | Shop — independent, any time |

## Mission

The async-connect design ran on 2026-07-23 and Josh signed off all six decisions.
The deliverables exist in the working tree but are **uncommitted**. Get them onto a
branch, verified and internally consistent, and surface the single open reading
before any implementation phase treats them as authoritative.

Deliverables to commit (verify each is present and non-empty):

- `docs/superpowers/specs/2026-07-23-platform-connect-async-design.md` — B1 fork
  recommendation (route (c)), B3 unit table (§5), decisions (§6), sequence (§7).
- `docs/frontend-contracts/2026-07-23-platform-connect-async.md` — B2 contract.
- `docs/superpowers/plans/2026-07-20-platform-connect-async.md` — B0 status note
  edited in place (the prior plan, now marked SHIPPED-both-phases + scope note).
- `docs/superpowers/plans/2026-07-23-worker-async-launch-PROMPT.md` — the corrected
  launch prompt (blocker #2 fixed, Part B marked SUPERSEDED).
- The four phase prompts `2026-07-24-connect-phase{1,2,3,4-5}-*-PROMPT.md` and this
  one, if present in the tree.

## Steps

1. `git fetch && git pull`. Branch `docs/connect-async-design-2026-07-24` off
   `development`. Never commit to `development`/`production`.
2. **Consistency pass — read, do not edit unless you find a contradiction.**
   Confirm the three documents agree on: the recommendation (route (c)); the unit
   set (W1–W9); the merge order and the activation order (§7 — they differ
   deliberately); and the six decisions (§6). If any two documents contradict each
   other, fix the *document*, not the design, and note what you changed. Do not
   re-open a decision.
3. **Verify the two facts the design corrected still hold** (they gate every later
   phase; a stale correction is worse than none):
   - `grep -n "deferredConnect()" app/Providers/PlatformRegistryServiceProvider.php`
     → expect **8** call sites.
   - `grep -n "connectFetchError\b" app/Services/Platforms/Registry/PlatformDescriptor.php`
     → expect the accessor pair to exist; `deferredFailureMessage` must **not**.
   If either has drifted, stop and report — do not commit a design that no longer
   matches the tree.
4. `git add` the deliverables. `git diff --cached --stat` and **confirm the file
   list is exactly the deliverables above** — the index can hold prior-session
   work; do not sweep in unrelated changes (`CLAUDE.md`, audit folders, etc.).
5. Commit: `docs(connect): async-connect design, contract, and phase prompts`.

## The one open reading — surface it, do not resolve it

§6 decision 3 recorded Josh's "yes" to "Is Fresha in the first slice?" as
*include W6/W7*. The design doc flags inline that the opposite reading (defer them)
is a two-row edit to the §7 sequence and nothing else. **Restate this in your final
report as a yes/no for Josh to confirm before Phase 3 runs** — it is the only scope
question still soft. Do not edit the sequence on your own judgement.

## Completion

Report: the branch name, the committed file list (from `--stat`), the result of the
two fact-checks in step 3, and the Fresha reading restated for confirmation. Then
**stop** — do not start Phase 1. Josh merges this branch; you do not push to
`development`.
