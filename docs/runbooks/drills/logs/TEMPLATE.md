# Drill log — <drill name>

- **Date:** <YYYY-MM-DD>
- **Runbook:** <link, e.g. ../01-worker-kill.md> (at commit <sha> — pins which version of the code was drilled)
- **Operator(s):** <who>
- **Environment:** <local stack / scratch project ref>
- **Mode/variants run:** <e.g. real-KV + scenarios A,B / variant 1 only / etc.>

## Timeline

| Time | Phase | Action / observation |
|------|-------|----------------------|
| | ARRANGE | |
| | INJECT | |
| | OBSERVE | |
| | RECOVER | |

## Evidence

<!-- paste the raw stuff: curl code+timing tables, KV GET bodies, Horizon attempt counts,
     SQL count diffs, log excerpts. Raw evidence > prose. -->

## Verdict

| Criterion (from runbook) | Result | Notes |
|--------------------------|--------|-------|
| | PASS / FAIL / PARTIAL | |

**Overall: PASS / FAIL / PARTIAL**

## Findings

<!-- anything that needs a code/config/doc change. One line each + where the fix work is
     tracked (issue, audit finding, commit). Empty section = explicitly write "none". -->

## Runbook corrections

<!-- steps that were wrong/missing in the runbook itself — apply them to the runbook in the
     same commit as this log. -->

## Next run due

<!-- for cadenced drills (04: quarterly). Otherwise "on material change to <path>". -->
