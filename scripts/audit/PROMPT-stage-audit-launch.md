# Stage Audit — launcher prompt

Thin wrapper over `scripts/audit/PROMPT-stage-audit-runner.md`. Paste the block below into a
**fresh Claude Code session** (Sonnet is enough — the session only orchestrates and summarises;
DeepSeek + the adjudicator do the heavy lifting) and put the stage name on the last line.

Valid stages: `pre-pilot` · `launch-readiness` · `scale-health` · `full-sweep`

When to run which:
- **pre-pilot** — before the first real users. 12 correctness lenses, ~21 chunk scans.
- **launch-readiness** — before public launch. 6 lenses incl. privacy-compliance + edge-worker.
- **scale-health** — once live, periodically / before marketing pushes. 6 lenses re-tiered at 10k users.
- **full-sweep** — all 20 lenses, nothing left unturned (~37 chunk scans; the maximal run).

Expect roughly $5–25 against the DeepSeek key + Claude plan and 1–4 hours unattended,
scaling with the stage size. The run is resumable per lens if anything fails.

```
=== PASTE FROM HERE ===

Run the stage-audit runbook for the stage I name on the LAST LINE of this message.

Do this:

1. Validate the stage name. It must be exactly one of: pre-pilot, launch-readiness,
   scale-health, full-sweep. Anything else → STOP and ask me.

2. Derive the parameters:
   - STAGE     = the validated stage name.
   - SCAN_JOBS = 4   (override only if I said otherwise above the last line)
   - PHASE     = codebase-<STAGE>-<today's date YYYY-MM-DD>

3. Read `scripts/audit/PROMPT-stage-audit-runner.md` and follow that runbook EXACTLY,
   substituting the parameters you just derived for its PARAMETERS block.

4. Echo the resolved values back to me on one line (`STAGE=… SCAN_JOBS=… PHASE=…`),
   then begin immediately. Do not wait for further confirmation — the runbook itself
   stops and asks me whenever one of its guards trips.

STAGE_TO_RUN:
<put the stage name here — this must be the last line>

=== PASTE TO HERE ===
```

**Example last line:**
```
pre-pilot
```
