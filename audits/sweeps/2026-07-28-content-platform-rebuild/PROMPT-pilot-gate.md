# Pilot-gate execution prompt — AUTONOMOUS

Paste the block below to work the 11 pilot-blocking findings from the 2026-07-28 full-sweep.
It is a scoped `execute audit` run: same runbook (`scripts/audit/fix-flow.md`), restricted work list,
**blocker gates waived by owner directive** — it runs start to finish without pausing.

The other 248 findings stay in `CONSOLIDATED.md` untouched — the folder will not auto-archive until
they are worked or explicitly deferred, which is the intended behaviour.

---

execute audit audits/sweeps/2026-07-28-content-platform-rebuild/CONSOLIDATED.md

Scope this run to the PILOT GATE only — these 11 findings, nothing else:

  #PRIV-1 (P0), #PRIV-2, DINT-1, DINT-2, DINT-16, #EDGE-1, #SEC-1, #JOB-1, LIFE-1, #SEM-1, #TEST-8

Follow `scripts/audit/fix-flow.md`, with these run-specific overrides:

0. RUN AUTONOMOUSLY — DO NOT ASK.
   The blocker gate in fix-flow.md §1a is WAIVED for this run by owner directive. Do not stop for
   sign-off on P0, auth, money, DB/migration, L/XL or standalone units. Plan → implement → independent
   review → commit → next unit, straight through, without returning to me between units.
   The independent review step is NOT waived: every unit still gets a fresh reviewer instance that did
   not write the code, and a unit only commits on PASS. Keep plan and implement as separate instances
   for every unit here (all are P0/P1) — the waiver removes the human pause, not the internal rigour.
   If a unit fails review twice, mark it blocked, LEAVE ITS CHECKBOX UNTICKED, move to the next unit,
   and report it at the end. Never force a fix through to keep the run moving.

1. WORK LIST — override the file's `## Suggested Bundled Sessions`, which was written per-lens and does
   not see cross-lens overlap. Use these units, in this order:

   Unit 1 — Ingest PII lifecycle: #PRIV-1 (P0), DINT-1, DINT-2, #PRIV-2
     One root cause across four findings: `ingest.record_versions` has no FK to its parent chain, so
     scraped third-party PII survives account deletion, and the same data is absent from GDPR export
     while third-party PII that IS exported goes out verbatim. Fix the FK/cascade, the erasure wiring,
     and the export wiring together — fixing any one alone leaves the others incoherent.

   Unit 2 — Lander write sequence: DINT-16 (+ DINT-9 if you judge it inseparable)
     `insertOrIgnore` returns 0 when a doc reverts to a previously-seen hash, so `changed` stays 0 and
     `RunExecutor.php:168` skips `projectStream()` — reverted content never re-projects. DINT-9 wraps
     the same four statements in a transaction; they conflict if done separately.
     Regression test required: land hash A → B → A, assert `changed === 1` on the third landing and
     that exactly one `record_versions` row for the key has `is_current = true`.

   Unit 3 — #EDGE-1 (cross-tenant edge cache surviving a handle reclaim)
   Unit 4 — #SEC-1 (secret-bearing query params persisted across three tables)
   Unit 5 — #JOB-1 (retry re-triggers a paid Instagram scrape)
   Unit 6 — #TEST-8 (ProbeBudget::tryClaim check-then-increment)
   Unit 7 — LIFE-1 (safeQuery swallows DB failures silently)
   Unit 8 — #SEM-1 (shop product-picker manual guard is inert)

2. #TEST-8 IS NOT A TEST-ONLY ITEM. It is filed under the test-coverage lens, but its own text says the
   check-then-increment is not atomic *despite a docblock asserting that it is*. Fix the code (make the
   claim atomic), correct or delete the false docblock, THEN add the concurrency test. Do not close it
   by adding a test that documents the broken behaviour.

3. VERIFY EACH PREMISE BEFORE FIXING. These findings were adjudicated against the tree at 57be57d1. For
   every unit, re-read the cited code first and confirm the defect still exists as described. If a
   premise is stale, skip it and record "premise no longer holds" with the evidence — do not invent a
   fix to have something to commit.

4. TESTS RUN SQLITE, PROD IS POSTGRES. Units 1 and 2 touch constraint-bound writes. Verify every schema
   assumption against the DDL in `supabase/migrations/`, not just a green suite. Where a constraint is
   load-bearing, put the test in `tests/Postgres/` so it runs against real Postgres.

5. SCHEMA CHANGES — WRITE, DO NOT APPLY. Raw SQL in `supabase/migrations/`, never Laravel migrations.
   One `CONCURRENTLY` statement per file. Commit the migration file; do NOT run `supabase db push` or
   apply it to any project. This is the one thing the autonomy waiver does NOT cover: applying schema
   to a live database is not a commit, and `ingest.record_versions` is an 8-way hash-partitioned table
   carrying a new cascade — I apply that myself.
   Call out clearly in the final report that a migration is pending application, and name the file.

6. COMMIT AND PUSH AS YOU GO.
   - Branch `audit-fix/pilot-gate-<today>` off an up-to-date `development` (`git fetch && git pull` first).
   - One commit per unit, code + the ticked audit file together: `fix(audit): <unit> — <ids>`.
   - Tick `- [ ]` → `- [x]` for each finding and bump the lens file's `## Progress` counts. A box goes
     to `[x]` ONLY after the independent review returns PASS and tests are green.
   - `git push -u origin audit-fix/pilot-gate-<today>` after each unit's commit, so the work is durable
     if the run is interrupted.
   - NEVER push or merge to `development` or `production`. On this repo `development:production` is the
     deploy — pushing there ships to live. Branch only. Do not open a PR unless I ask.

7. DO NOT run `archive-done.sh` — 248 findings remain unworked by design, so the folder stays put.

8. RUN `composer test` ONCE MORE at the end for the whole branch. Then report: units complete, units
   blocked and why, any premise that no longer held, the pending migration file(s), test status, and the
   branch name.

---

## Deferred, deliberately

- **All 9 `SCALE-*` P1s** — unbounded reads / N+1 in the ingest and document-build paths. A pilot is
  small-N by construction; these are graded properly by `--bundle scale-health` against a 10k-user
  target once there is traffic.
- **The other 16 `#TEST-*` P1s** — coverage gaps, not known-bad behaviour. Worth doing before beta. If a
  middle tranche is wanted, `#TEST-5`, `-6`, `-7`, `-9`, `-10` guard charge-once, claim mutual exclusion,
  the 40% mass-deletion breaker, and the PII-redaction gate.
- **All 125 P2 and 98 P3.**
