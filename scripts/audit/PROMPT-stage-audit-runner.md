# Stage Audit Runner — copy-paste prompt

Generic, reusable prompt for running a whole-codebase stage audit (pre-pilot /
launch-readiness / scale-health / full-sweep) end-to-end: preflight → run the pipeline →
verify outputs → spot-check for hallucinations → executive summary → hand-off plan.

**How to use:**
1. Open a **fresh Claude Code session in this repo** (recommended model: **Sonnet** — the session
   only orchestrates; DeepSeek scans + the Claude adjudicator inside `audit.sh` do the analysis).
2. Either use `scripts/audit/PROMPT-stage-audit-launch.md` (preferred) or edit the PARAMETERS
   block below and paste from `=== PROMPT START ===`.
3. Walk away. It runs unattended for 1–4 hours and stops to ask you only when a guard trips.

---

```
=== PROMPT START ===

You are the orchestrator for a whole-codebase stage audit. Your job is to run the existing
audit pipeline, verify its output, and summarise — NOT to generate findings yourself. Do not
audit code manually; the pipeline does that. Follow this runbook exactly.

## PARAMETERS  (the only things that change between runs)

STAGE:      <pre-pilot | launch-readiness | scale-health | full-sweep>
SCAN_JOBS:  4
PHASE:      codebase-<STAGE>-<today YYYY-MM-DD>

## MISSION

Run `scripts/audit/audit.sh --codebase --bundle STAGE` to completion, recover any failed
lenses, verify every emitted audit file is well-formed and non-hallucinated, write an
executive summary, and report a prioritised fix plan. You never commit or push — the audit
files stay in the working tree for Josh to review and commit.

## PREFLIGHT  (all must pass before spending money)

1. **Pull first.** `git fetch && git pull`, then `git log --oneline -10`. A dirty working
   tree is fine (you won't commit), but list any modified files so the report can note that
   the audit ran against uncommitted work.
2. **Pipeline integrity.** Verify, and STOP if any check fails:
   - `grep -q "STAGE)" scripts/audit/audit.sh` — the bundle exists.
   - All 20 lens files present: `ls scripts/audit/lenses/*.md | wc -l` ≥ 20.
   - Every scope-map path exists:
     `awk '/^[a-z-]+\|/{print}' scripts/audit/audit.sh | cut -d'|' -f2 | tr ' ' '\n' | sort -u | while read -r p; do [ -e "$p" ] || echo "MISSING: $p"; done`
     Any MISSING line → the tree moved since the map was written → STOP and show me.
   - `bash -n scripts/audit/audit.sh` passes.
3. **Credentials.** `scripts/audit/.env` contains `DEEPSEEK_API_KEY` (or it's exported), and
   `command -v claude` succeeds. Missing → STOP.
4. **No concurrent runs.** `pgrep -f "audit-scan.sh|audit-adjudicate.sh"` must be empty, and
   ask yourself whether an audit-fix session is currently running (adjudications and fix
   sessions share the Claude plan's rate budget). If anything is running → STOP and ask me.
5. **Expectations line.** Before launching, print one line: number of lenses, expected chunk
   scans, SCAN_JOBS, output dir `audits/PHASE/`, and the rough cost/time envelope.

## RUN

Launch in the background and monitor (the script parallelises DeepSeek scans internally at
SCAN_JOBS-wide and runs adjudications sequentially on purpose — do not parallelise further,
do not start a second audit.sh):

    scripts/audit/audit.sh --codebase --bundle STAGE --scan-jobs SCAN_JOBS --keep-drafts

- `--keep-drafts` is mandatory: drafts + per-scan logs land in `audits/PHASE/.drafts/` and
  are the recovery material if anything fails.
- Tail progress periodically. Scans complete in waves; each lens then adjudicates one at a
  time. Total wall time: ~1h (scale-health) to ~4h (full-sweep). Do not kill the run because
  it looks idle — adjudications can take 5–10 minutes each.
- If the whole script exits non-zero early (preflight inside it failed), read the error,
  fix only environment/argument issues, and retry ONCE. Anything else → STOP and ask me.

## RECOVER FAILED LENSES

The completion banner lists any `✗ <lens> (…)` lines. For each failed lens, retry ONCE:

1. Read that lens's chunk map from `codebase_chunks()` in `scripts/audit/audit.sh`.
2. Re-scan each chunk:
   `scripts/audit/audit-scan.sh --lens-file scripts/audit/lenses/<lens>.md --scope <p1> [--scope <p2>…] --out audits/PHASE/.drafts/drafts-<lens>-<chunk>.md`
3. Concatenate the chunk drafts (prefix each with `<!-- ═══ LENS: <lens> | CHUNK: <chunk> ═══ -->`)
   into `audits/PHASE/.drafts/drafts-<lens>.md`, then adjudicate:
   `scripts/audit/audit-adjudicate.sh --drafts <that file> --lens-file scripts/audit/lenses/<lens>.md --scope <p1> [--scope …] --no-source --max-budget 3.00 --out audits/PHASE/audit-<today>-<lens>.md`
4. Still failing after the retry → record it as UNRECOVERED and move on. More than 25% of
   the stage's lenses unrecovered → STOP and ask me (something systemic is wrong).

## VERIFY OUTPUT  (per audit file in audits/PHASE/)

1. **Count check.** One `audit-<date>-<lens>.md` per lens in the stage (pre-pilot 12,
   launch-readiness 6, scale-health 6, full-sweep 20), minus unrecovered.
2. **Format check.** Each file starts with a `# … Audit — <date>` title and contains a
   `## Progress` block. Findings count = `grep -c '^- \[ \]' <file>`. Zero findings WITH a
   proper header is a valid clean lens — keep it. A malformed file (no header, truncated
   mid-finding, or budget-cutoff text) → re-run that lens's adjudication once (step above);
   still malformed → mark UNRECOVERED.
3. **Hallucination spot-check.** Across all files, pick every P0 plus 3 random P1s. For each,
   verify the `Evidence:` excerpt actually appears in the cited file (Read/Grep — exact or
   near-exact match). Any failure → add a `> ⚠ SPOT-CHECK FAILED — verify before fixing`
   line directly under that finding in the audit file and flag it in the summary. Do not
   delete findings.

## EXECUTIVE SUMMARY

Write `audits/PHASE/00-executive-summary.md` (companion file — the fix orchestrator ignores
non-item-list files):

- Header: stage, date, branch + HEAD sha, dirty-tree note, run stats (lenses run/failed,
  chunk scans, wall time).
- A table: lens → P0 / P1 / P2 / P3 counts → one-line theme of its top finding.
- Every P0 listed verbatim (ID + title + Where).
- Top P1 themes (grouped, not exhaustive).
- Spot-check results and any UNRECOVERED lenses.
- Suggested fix order (next section's plan).

## FINAL REPORT + HAND-OFF  (in chat — do not start fix sessions yourself)

Report: total findings by tier, the P0 list, clean lenses, failures, total cost if visible
from the logs. Then the fix plan:

- One audit-fix session per audit file, P0-bearing files first, via
  `scripts/audit/PROMPT-audit-fix-launch.md` (paste its block + the audit filename).
- Call out findings the fix runbook will park anyway (DB-touching, L/XL effort,
  `Standalone — do NOT bundle`) so Josh can schedule those himself.
- Remind: audit files + summary are uncommitted; Josh reviews and commits.

## NON-NEGOTIABLES

- Never generate or edit findings yourself (except the ⚠ spot-check annotation).
- Never commit or push.
- Never run two audit.sh invocations at once; never raise SCAN_JOBS above 8.
- Adjudications stay sequential — that's a deliberate property of the pipeline.
- If a guard trips, STOP with: what tripped, what you found, options, your recommendation.

=== PROMPT END ===
```
