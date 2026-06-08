# Audit Fix — launcher prompt

Thin wrapper over `scripts/audit/PROMPT-audit-fix-runner.md`. Paste the block below into a fresh
Claude Code session (Opus recommended) and put the audit filename on the last line. It derives the
parameters and runs the full runbook — no manual editing.

```
=== PASTE FROM HERE ===

Run the audit-fix runbook on the audit file I name on the LAST LINE of this message.

Do this:

1. Resolve the audit file. The last line is either a repo-relative path or a bare filename.
   - If it's a path that exists, use it.
   - If it's a bare filename, locate it: `find audits -name '<that name>'`. If exactly one match,
     use it. If zero or more than one, STOP and ask me which.

2. Derive the three parameters:
   - AUDIT_FILE         = the resolved repo-relative path from step 1.
   - INTEGRATION_BRANCH = development
   - WORK_BRANCH        = audit-fix/<SLUG>-<TODAY>, where:
       • SLUG  = the audit filename with the `audit-` prefix, the `YYYY-MM-DD-` date, the `.md`
                 extension, and any trailing `-consolidated` / `-CONSOLIDATED` removed.
                 (e.g. `audit-2026-06-06-code-quality-consolidated.md` → `code-quality`)
       • TODAY = the output of `date +%F`.

3. Read `scripts/audit/PROMPT-audit-fix-runner.md` and follow that runbook EXACTLY, substituting the
   three parameters you just derived for its PARAMETERS block.

4. Echo the three resolved values back to me on one line (`AUDIT_FILE=… INTEGRATION_BRANCH=…
   WORK_BRANCH=…`), then begin the run immediately. Do not wait for further confirmation — the
   runbook itself will stop and ask me whenever one of its guards trips.

AUDIT_FILE_TO_RUN:
<put the audit filename or path here — this must be the last line>

=== PASTE TO HERE ===
```

**Example last line:**
```
audits/code-quality/audit-2026-06-06-code-quality-consolidated.md
```
or just:
```
audit-2026-06-06-code-quality-consolidated.md
```
