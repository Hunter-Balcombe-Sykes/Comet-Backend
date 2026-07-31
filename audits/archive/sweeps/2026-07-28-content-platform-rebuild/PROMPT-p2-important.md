# Important-P2 execution prompt — AUTONOMOUS

Works **52 of the 125 P2 findings** from the 2026-07-28 full-sweep — the ones selected on consequence
rather than tier. Same runbook (`scripts/audit/fix-flow.md`), **blocker gates waived by owner
directive**, runs start to finish without pausing.

Run this AFTER `PROMPT-p0-p1.md`. Several units touch files that the P0/P1 phases rewrite
(`Lander`, `EffectLedger`, `SourceScheduler`, `HttpIo`), so running it first guarantees conflicts.

Nearly all 52 are S effort (~0.5–1h); a dozen are M. Ballpark 60–80h.

## Why these 52 and not the other 73

Selected on one of six grounds:
1. **Money** — the charge-once path, where a silent failure costs real spend.
2. **Security doctrine** — 403-instead-of-404 enumeration, scheme gates, SSRF, canonicaliser correctness.
3. **Silent failure** — a pilot you cannot debug is worse than a pilot with bugs.
4. **Test/prod parity** — constraints that exist in Postgres but not the SQLite test schema.
5. **Time-sensitive** — migration hazards that are free today on an empty prod and expensive later.
6. **Rider** — trivial to fix while already inside that file for a P0/P1 unit.

Excluded: the performance family (`#CACHE-*`, `SCALE-11..20`, `#CCH-*`, `#CCG-1`) — that belongs with
the `SCALE-*` P1s and is better re-graded by `--bundle scale-health` against real traffic. Also excluded:
cosmetic drift, stale docs (`#EDGE-2`), mass-assignment findings whose own text confirms no live
over-post path (`#SEC-2/-3/-4`), and `DINT-9` (already covered in P0/P1 Unit 2).

---

execute audit audits/sweeps/2026-07-28-content-platform-rebuild/CONSOLIDATED.md

Work these 52 P2 findings, in the units below. Follow `scripts/audit/fix-flow.md` with these overrides:

0. RUN AUTONOMOUSLY — DO NOT ASK.
   The blocker gate in fix-flow.md §1a is WAIVED by owner directive. Do not stop for sign-off on DB,
   auth, money or standalone units. Plan → implement → independent review → commit → next unit, straight
   through. The independent review step is NOT waived: a fresh reviewer instance that did not write the
   code, and a unit commits only on PASS. Two failed reviews → mark blocked, leave the checkbox
   UNTICKED, move on, report at the end.

1. PREREQUISITE. Confirm `PROMPT-p0-p1.md`'s work has landed on this branch (or is merged) before
   starting. If `Lander`, `EffectLedger`, `SourceScheduler` or `HttpIo` do not yet show the P0/P1
   changes, STOP and report — do not work these units against the pre-fix versions.

2. VERIFY EACH PREMISE BEFORE FIXING. Adjudicated against 57be57d1, and the P0/P1 phases have since
   rewritten several of these exact files. Expect a meaningful number of these to be already-resolved.
   Re-read the cited code first; if the defect is gone, record "premise no longer holds — resolved by
   <unit/commit>" with evidence and tick it. Do not manufacture a change to have something to commit.

   UNITS

   Unit A — Charge-once / money path: LIFE-18, LIFE-6, #WHK-3, #WHK-4
     LIFE-18 is the important one: nothing scheduled ever releases `ingest.effects` rows stuck
     `claimed` after a worker crash. `EffectLedger`'s docblock says resolving is "a deliberate act
     (`ingest:effects --resolve`)" — but no scheduler entry runs it, so a crashed worker blocks that
     effect permanently rather than for the 900s the class implies. The other three are the same
     path: a bare `catch (\Throwable)` on the claim insert masking real INSERT failures as duplicate
     refusals (LIFE-6/#WHK-3), and abandoned effects being breadcrumb-only `Log::warning` that
     Nightwatch never surfaces (#WHK-4).

   Unit B — Ingest concurrency: LIFE-3, LIFE-4, LIFE-5, #WHK-1, #WHK-2
     Unlocked read-modify-write on `absent_runs` and `consecutive_failures`, the post-insert re-read
     race in `land()`, `releaseStranded` overwriting a legitimately re-claimed source (TOCTOU), and
     non-atomic absence counter bumps. Same subsystem, same class of fix. Concurrency claims must be
     proven concurrently — put the tests in `tests/Postgres/`.

   Unit C — SourceReconciler atomicity: LIFE-15, LIFE-16
     Intent creation races despite an existing UNIQUE index, and intent + resulting connection are
     written in two non-atomic steps.

   Unit D — Security doctrine: LIFE-22, LIFE-23, #API-1, #CFG-1, #SEM-4, #SEM-5
     LIFE-22/-23: `DesignKitRestylePolicy::create()` and `SectionPolicy::create()` return a raw
     boolean → 403 on ownership mismatch instead of `denyAsNotFound()`. CLAUDE.md is explicit that
     not-yours is 404 and that 403 enables enumeration — these are doctrine violations, not style.
     #API-1: public sitepage resolver emits three user-controlled URLs with no scheme gate.
     #CFG-1: bot protection fail-open defaults `true`, so a future enforce-mode deploy silently
     bypasses CAPTCHA. #SEM-4: `IriCanonicalizer` mishandles a `www.`-prefixed suffix-override host.
     #SEM-5: the poisoned-key guard compares raw values while the merge index compares canonicalised
     ones — the guard can be walked past.

   Unit E — Outbound POST SSRF: #SEC-5, LIFE-7
     Two lenses reporting one defect: `HttpIo::post()` skips both the per-hop redirect re-validation
     and the byte cap that `get()`/`getMany()` enforce. Latent only because today's callers pass
     hardcoded URLs; live the moment a connector's POST target derives from a vendor response. Fix
     once, tick both.

   Unit F — Silent failure / observability: OBS-1..OBS-8, LIFE-13, LIFE-19, LIFE-20, SCALE-10
     Commands exiting 0 after total failure (OBS-2, OBS-8), unknown message types logged but never
     thrown so Nightwatch never fires (OBS-7), probe cascades masking a platform outage as a clean
     "no match" (OBS-5, LIFE-13), no alert on stuck `source_intents` or unresolved `critical`
     anomalies (LIFE-19, LIFE-20), and four Horizon lanes with no `waits` thresholds so their backlog
     is invisible (OBS-6, SCALE-10).
     House rule: Nightwatch alerts on exceptions and slow jobs ONLY. A `Log::warning` is a breadcrumb,
     not an alert — anything that needs attention must throw or `$this->fail($e)`.

   Unit G — Schema constraints and FKs: SCHEMA-1, SCHEMA-2, SCHEMA-3, DINT-3, DINT-4, DINT-5, DINT-6,
            DINT-7, DINT-8
     Missing CHECKs where a column comment already documents a closed set, missing FKs on
     `content.item_merges` and `site.section_items`, a unique constraint bypassable on NULL
     `connection_id`, `site.sections` holding `page_id` and `site_id` with nothing enforcing agreement,
     and nullable-without-NOT-NULL timestamps. One migration, written in one pass.

   Unit H — Migration operational safety: #MIG-1, #MIG-2, #MIG-3, #MIG-4, SCALE-21
     All `site.platform_connections`: non-idempotent full-table backfills, `SET NOT NULL` and
     `ADD CONSTRAINT` without `lock_timeout`, six index operations without `CONCURRENTLY`, and a
     `DROP COLUMN` + `ADD COLUMN ... GENERATED ... STORED` that rewrites the table under a plain
     ACCESS EXCLUSIVE lock.
     TIME-SENSITIVE: prod currently has zero customer rows, so these are free to correct now and
     become downtime risk the moment it does not.
     One `CONCURRENTLY` statement per migration file — the CLI pipelines multi-statement files and
     `CONCURRENTLY` cannot run in a pipeline (SQLSTATE 25001).

   Unit I — Test/prod parity: #PARITY-1
     CHECK constraints on `site.sections`, `site.section_items`, `content.items` and
     `routing.source_intents` exist in Postgres but not the SQLite test schema, so constraint-violating
     writes pass tests and fail in production. Fix the stand-in schema; where the constraint is
     load-bearing, add the test to `tests/Postgres/`.

   Unit J — PII retention: #PRIV-3, #PRIV-4, #PRIV-5, #PRIV-6
     Reviewer PII in `content.f_review` with no retention bound, unbounded PII-bearing page-version
     snapshots in `site.site_documents`, full URLs with query strings stored verbatim across the new
     routing/content schemas, and per-visit lat/lon stored as distinct rows rather than a city centroid.
     #PRIV-5 is #SEC-1 generalised — if the P0/P1 phase fixed #SEC-1 only in the routing tables, this
     closes the same class everywhere else. Check what that fix actually covered before designing this.

   Unit K — Integrity of derived signals: #SEM-6, #SLOP-2
     `detectDeviceType`'s bot check is a small subset of the sibling `isBotUserAgent`, so most bot
     traffic is counted as human in analytics. `firstString` is copy-pasted across four connectors and
     has already drifted — two copies lack the numeric fallback, so those connectors silently parse
     differently. Both are real behaviour differences, not tidiness.

3. SCHEMA CHANGES — WRITE, DO NOT APPLY. Raw SQL in `supabase/migrations/`, never Laravel migrations.
   Commit the migration files; do NOT run `supabase db push` or apply to any project. Name every pending
   migration file in the final report. Units G, H, I and J all produce migrations.

4. COMMIT AND PUSH AS YOU GO.
   - Branch `audit-fix/p2-important-<today>` off the P0/P1 branch (or `development` if that is merged).
   - One commit per unit, code + ticked audit file: `fix(audit): <unit> — <ids>`.
   - Tick `- [ ]` → `- [x]` and bump each lens file's `## Progress` counts. `[x]` only after the
     independent review PASSes and tests are green.
   - Push after each unit's commit.
   - NEVER push or merge to `development` or `production`. On this repo `development:production` is the
     deploy. Branch only. No PR unless asked.

5. DO NOT run `archive-done.sh` — 73 P2s and 98 P3s remain unworked by design.

6. RUN `composer test` at the end for the whole branch. Report: units complete, units blocked and why,
   premises that no longer held (with what resolved them), pending migration files, test status, branch name.
