# Batch staleness pass over the open audit backlog — 2026-09-01

Go through every OPEN audit folder OLDEST FIRST and decide, per finding,
whether it is still true. This is a READ-ONLY triage pass: **no code changes,
no fixes, no subagents.** The only files you edit are the audits' own
`CONSOLIDATED.md` files.

## Why this pass exists

405 findings are open across 9 folders. Two of those folders were scanned
against a codebase where **more than 40% of `app/` has since changed**:

| Folder (oldest first)                          | Open | Scan base   | Commits since | app/ churn |
|------------------------------------------------|------|-------------|---------------|------------|
| reviews/2026-08-05-dashboard-commits            | 1    | <none>      | —             | —          |
| consolidation/2026-08-17-programme-review       | 16   | ce890848b   | 1023          | 42%        |
| sweeps/2026-08-18-overnight-run                 | 47   | b9e90e8d3   | 989           | 41%        |
| sweeps/2026-08-24-claim-gate-security           | 5    | ef76cac04   | 533           | 25%        |
| sweeps/2026-08-24-unified-actions-delta         | 39   | 78cbe8f0b   | 663           | 28%        |
| sweeps/2026-08-24-unified-actions-remainder     | 14   | 78cbe8f0b   | 663           | 28%        |
| sweeps/2026-08-24-unified-actions-security      | 17   | 78cbe8f0b   | 663           | 28%        |
| correctness/2026-08-24-actions-ordering-math    | 1    | <none>      | —             | —          |
| sweeps/2026-08-28-full-campaign                 | 257  | 1151b0b6e   | 361           | 21%        |

Working a stale finding costs a full plan→implement→review cycle to discover
the premise evaporated. This pass is grep + `git log`. It is orders of
magnitude cheaper, and it is what makes the remaining backlog trustworthy.

**Process the table top to bottom.** STOP after
`sweeps/2026-08-24-actions-ordering-math` and report — do NOT touch
`2026-08-28-full-campaign` (freshest, already triaged into Tier A/B/C, and
257 findings is a separate job).

## Per-finding decision rule

For each open `- [ ]` finding, read its `**Where:**` line to get the cited
path(s), then:

1. **Cited file UNCHANGED since the folder's scan base**
   (`git diff --quiet <scanbase>..HEAD -- <path>`)
   → **PRESUMED STILL VALID.** Leave `- [ ]`. Do not read it. This is the
   cheap majority and the whole point of the pass.

2. **Cited file CHANGED since the scan base**
   → READ the current code at that location. Decide:
   - the defect is gone → **STALE-FIXED**
   - the code moved but the defect came with it → still valid, leave open,
     and CORRECT the `**Where:**` line to the new location
   - the finding described something that never held → **STALE-WRONG**

3. **Cited file NO LONGER EXISTS**
   → grep for the concern before concluding. A deleted file usually means
   stale, but the logic may have moved. Confirm, don't assume.

4. **⚠️ ABSENCE findings — churn proves NOTHING.**
   If the finding asserts something is *missing* (no test, no index, no
   alert path, no CHECK constraint, not applied to production), you cannot
   validate it from file diffs. Verify the absence DIRECTLY.
   Worked example, already checked — do not re-litigate, do not close it:
   `#DEPLOY-1` (reviews/2026-08-05) says the `routing` schema is absent from
   production. Confirmed still true on 2026-09-01: prod's live ledger holds
   **4** migrations against **155** files in the tree, and `origin/production`
   is still `cb2d565ca`, the exact commit the finding cited. LEAVE IT OPEN.

5. **Evidence block is a `//` comment rather than executable code**
   → automatically suspect. Comments narrate history; they are not state.
   Go read the current implementation. This exact pattern produced two false
   P1s in one run before.

## Recording the verdict

- **STALE-FIXED / STALE-WRONG** → tick the box `- [ ]` → `- [x]` AND append,
  indented under the finding:

      - Resolution (2026-09-01): STALE — <one line of evidence: the commit
        that fixed it, or what the current code actually does>.

  A ticked box means "resolved as an open question", not "the code changed".
  Closing a finding with a stated reason is a legitimate outcome; leaving it
  open forever is not — it blocks auto-archive and makes the audit system
  read permanently red.
- **Still valid** → leave `- [ ]` untouched. If you corrected a `**Where:**`
  path, note that in the commit body.
- **NEVER tick a box just because the finding is old.** Every tick needs a
  written reason a reader can check.
- Update the folder's `## Progress` counts if it has them.

## Per folder, in order

1. Verify the scan base resolves: `git cat-file -e <scanbase>^{commit}`.
   For the two folders with no recorded base, use the folder's date to pick
   the nearest commit and SAY SO in your report.
2. Walk its open findings under the rule above.
3. Commit that folder alone, pathspec-limited:

       git commit -m "docs(audit): staleness pass — <folder> (<n> stale, <m> still valid)" \
         -- audits/<path>/CONSOLIDATED.md

   NEVER `git add -A`, never `git add <directory>`.
4. Then try to archive it:

       scripts/audit/archive-done.sh --dry-run audits/<path>
       scripts/audit/archive-done.sh audits/<path>      # only if dry-run says it moves

   It moves the folder to `audits/archive/` only when ZERO `- [ ]` remain.
   If boxes remain it stays put and tells you why — that is correct, not a
   failure. Do not force it.
5. Move to the next folder.

## Rules

- **No code changes.** If you find a real bug while reading, note it in the
  report — do not fix it here.
- Work on a branch off `origin/development`:
  `git fetch origin development && git checkout -b audit/staleness-pass-2026-09-01 origin/development`
  Never commit to `development` or `production`. Never force-push.
- No `git stash` (the stash stack is shared across every worktree).
- Leave `.worktrees/` alone — other sessions own those branches.
- `composer test -- --filter=X` is broken here; if you need to run a test use
  `./vendor/bin/pest <path>`. You should rarely need to.

## Report at the end

Per folder: findings checked, STALE-FIXED, STALE-WRONG, still-valid,
`**Where:**` paths corrected, archived yes/no. Then a single headline: how
many of the 140 findings in scope survived.

Flag separately any finding you could not decide without running code — those
are the only ones worth a follow-up.
