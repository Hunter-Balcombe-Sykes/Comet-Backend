# P0 + P1 execution prompt — AUTONOMOUS

Paste the block below to work **all 36 P0/P1 findings** from the 2026-07-28 full-sweep, in two phases:
the 11 pilot-blocking items first, then the remaining 25. Same runbook (`scripts/audit/fix-flow.md`),
**blocker gates waived by owner directive** — it runs start to finish without pausing.

The 223 P2/P3 findings stay in `CONSOLIDATED.md` untouched — the folder will not auto-archive until
they are worked or explicitly deferred, which is the intended behaviour.

Rough size: Phase 1 ≈ 11 items (1×L, 5×M, 5×S). Phase 2 ≈ 25 items (10×L, 8×M, 7×S) — on the file's own
effort bands that is ~150h of work, so expect Phase 2 to span multiple sessions.

---

execute audit audits/sweeps/2026-07-28-content-platform-rebuild/CONSOLIDATED.md

Work ALL 36 P0/P1 findings, in the two phases below. Phase 1 completes entirely before Phase 2 starts.

PHASE 1 — pilot gate (11):
  #PRIV-1 (P0), #PRIV-2, DINT-1, DINT-2, DINT-16, #EDGE-1, #SEC-1, #JOB-1, LIFE-1, #SEM-1, #TEST-8

PHASE 2 — remaining P1 (25):
  SCALE-1..9, #TEST-1..7, #TEST-9..17

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

   ── PHASE 2 begins here. Do not start it until every Phase 1 unit is committed or marked blocked. ──

   Unit 9 — Lander read/write shape: SCALE-4, SCALE-5
     RE-VERIFY FIRST. Unit 2 rewrites the exact four statements SCALE-4 is about ("4 DB round-trips per
     landed record"). If Unit 2's rewrite already collapsed them, close SCALE-4 as resolved-by-Unit-2
     with the evidence rather than changing the file again. SCALE-5 (`foldAbsence()` loading every
     non-tombstoned record for a stream) is independent and still stands either way.

   Unit 10 — ProjectionWriter: SCALE-6, SCALE-7, SCALE-8
     All three are the same file and the same root cause — unbounded per-stream/per-user loads plus a
     ~19-query-per-item cache refresh. One pass, or they collide.

   Unit 11 — IngestProjectCommand: SCALE-1, SCALE-2
     Same command: unbounded source load + an N+1 on `streams`. Chunk the outer read and eager-load.

   Unit 12 — Document build reads: SCALE-3, SCALE-9
     `SiteBuildDocumentsCommand`'s unbounded `pluck()` and `DocumentBuilder`'s per-item query. Same path.

   Unit 13 — Ingest concurrency + money + PII proofs: #TEST-7, #TEST-6, #TEST-5, #TEST-9, #TEST-10
     The highest-value tests in Phase 2 — charge-once under concurrent dispatch, `claimDue()` mutual
     exclusion under real concurrent claimers, `RunSourceJob`'s claim-release safety net, the 40%
     mass-deletion circuit breaker (test BOTH directions: trips, and does not trip), and
     `RunExecutor::isClaimed()`, the PII-redaction gate for unclaimed accounts.
     Concurrency claims must be proven CONCURRENTLY — a sequential test that passes proves nothing here.
     These need real Postgres: put them in `tests/Postgres/`.

   Unit 14 — Routing security proofs: #TEST-13, #TEST-12, #TEST-2
     `PublicSuffixList` unit tests (the registrable-domain algorithm under all routing security), then
     negative tests locking the host-spoofing TLD allowlists closed (Eventbrite, OpenTable, Google
     Business), then `RoutingController::store()`'s 5+ outcome branches. Do PSL first — the others
     depend on it behaving.

   Unit 15 — Content identity + document build proofs: #TEST-14, #TEST-16, #TEST-11
     `Resolver` + `DisjointSet` unit tests (a documented historical bug, still no coverage), the
     `BuildState` CAS protocol + `DocumentBuilder` hash-idempotency and rule-operator coverage, and the
     `field_bindings_manual_priority` CHECK — that constraint is the single point of failure for
     "manual always wins", so its test belongs in `tests/Postgres/`, not SQLite.

   Unit 16 — Platform connection proofs: #TEST-3, #TEST-4, #TEST-17
     `SourceProvisioner::sync()` + the 22-platform `identifierFor()` dispatch, the deferred-connect
     self-deadlock prevention in both controllers, and `ConnectionPayload::forWrite()` — whose absent
     contract already caused a real production bug once, so this is a regression lock, not new coverage.

   Unit 17 — Capability + policy proofs: #TEST-1, #TEST-15
     `AccountCapabilities` gate-REJECTION paths (page creation, section rules, lifestyle pages), and
     policy ability coverage — only 1 of 14 Policy classes has a dedicated test file. Check
     `PolicyCoverageTest`'s `POLICY_EXEMPT` allowlist before writing: some may be justified exemptions.

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

7. DO NOT run `archive-done.sh` — 223 P2/P3 findings remain unworked by design, so the folder stays put.

8. PHASE BOUNDARY. When Phase 1's eight units are all committed or blocked, run `composer test` for the
   whole branch and post an interim report (units done, blocked, pending migration, test status) BEFORE
   starting Unit 9. Do not stop for approval — just make the boundary visible in the log so the pilot
   gate can be read off independently of the longer Phase 2 tail.

9. A TEST THAT DOCUMENTS A BUG IS NOT A PASS. Phase 2 is mostly coverage work, which has a specific
   failure mode: writing a test that asserts whatever the code currently does, then ticking the box. For
   every `#TEST-*` item, first establish what the behaviour SHOULD be from the finding text and the
   surrounding contract, and if the code disagrees, fix the code — do not encode the defect. `#TEST-8` in
   Phase 1 is the worked example; treat the rest with the same suspicion.

10. RUN `composer test` ONCE MORE at the very end for the whole branch. Then report per phase: units
    complete, units blocked and why, any premise that no longer held, the pending migration file(s),
    test status, and the branch name.

---

## Not in this run

- **All 125 P2 and 98 P3 findings.** Still in `CONSOLIDATED.md`, untouched.
- Of those, the ones worth an early look are in `test-coverage` (56 findings total) — that lens is
  cheap to raise findings in and its P2/P3 tail is better treated as backlog than as a queue.
- `--bundle scale-health` is the right instrument for re-grading the `SCALE-*` family against a real
  10k-user target once there is traffic; Phase 2 fixes the specific unbounded reads, not the shape.
