# EXECUTE — Pre-launch hardening (2026-08-25) · SPLIT INTO THREE PARTS

> **This file is an index, not a runnable prompt.** The tranche was split on 2026-08-25 because 14
> units / ~47 defects is well past the ~100K-token point where `CLAUDE.md` says recall degrades.
> **Do not execute this file.** Run the parts below, in order.

## Run order — the whole overnight sequence

| # | File | Branch | Units |
|---|---|---|---|
| 1 | `../2026-08-25-pre-pilot-blockers/EXECUTE.md` | `audit-fix/pre-pilot-blockers-2026-08-25` | 9 units, 11 findings |
| 2 | `EXECUTE-PART-1.md` — quick wins | `audit-fix/pre-launch-hardening-2026-08-25` | 2, 3, 4, 5, 7, 11 |
| 3 | `EXECUTE-PART-2.md` — surfaces & wire | *same branch* | 6, 8, 9, 10, 12, 14 |
| 4 | `EXECUTE-PART-3.md` — bounded reads & scale | *same branch* | 13 (as 13a–13g) |

**Run strictly sequentially, one session at a time, in one checkout.** The three PARTs share one
branch; PART N checks it out and continues on it. Running two concurrently in the same checkout lets a
peer session switch the branch mid-task and silently destroy work.

Unit numbers are the **original** ones from the undivided tranche, kept so cross-references to
`audits/consolidation/2026-08-25-pre-pilot-p2-promotion/BACKLOG-TRIAGE.md` and the source sweeps still
resolve.

## Unit 1 is NOT in any part — it was deferred out

`ProjectionWriter`'s identity-resolution scope (`#CACHE-2`, `#CACHE-4`, `#SCALE-8`, `#SCALE-9`
(≡ `#API-7`), `#SCALE-12`, all overnight-run) is a re-architecture of a 2,456-line file whose correctness boundary is
a real design decision, not an implementation detail. Josh deferred it out of the overnight tranche on
2026-08-25. It gets its own **attended planning session**:

→ `docs/superpowers/plans/2026-08-25-projectionwriter-identity-scope-PLAN-PROMPT.md`

Those five findings stay unticked until that session produces a plan and the plan is executed.

## What changed when this was split

Each PART is self-contained and written for an **unattended** run: every open question is answered
inline as a `**DECIDED:**` line, every sign-off gate is discharged in each file's §1.1, and each file
carries a DEFER lane (§1.2) so a large or ambiguous finding exits with a written plan instead of
stopping the run. Each PART writes its own `RESULT-PART-<n>.md`, and PART 3's report summarises the
whole branch.

The original undivided prompt is preserved in git history if you need it:
`git show HEAD~1:audits/consolidation/2026-08-25-pre-launch-hardening/EXECUTE.md`
