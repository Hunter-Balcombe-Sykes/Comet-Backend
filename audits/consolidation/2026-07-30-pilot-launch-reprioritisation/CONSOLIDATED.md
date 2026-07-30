# Pilot / Launch re-prioritisation — CONSOLIDATED — 2026-07-30

**302 open audit findings, re-graded against the pilot and launch gates and verified against live code.**
Execution prompts are directly below; the reasoning and evidence follow them.

---

## Execution policy  (how `execute audit` runs this file)

Per `scripts/audit/fix-flow.md`, these three values govern every unit worked out of this file:

| Step | Model |
|---|---|
| **Plan** | Opus |
| **Implement** | Sonnet — escalate to Opus for any unit touching the public wire, a lock, or a DB write path |
| **Review** | Sonnet — always a **separate** instance from the implementer |

**Blocker gate** (pause for Josh's sign-off before implementing): any P0-PILOT or P0-LAUNCH unit, or any
unit touching auth/authorization, money, a DB migration, or graded L/XL.

**One rule specific to this file, and it is non-negotiable:**

> **Verify the premise before writing a line of code.** This file was built by verifying 48 findings
> against live code, and **12 of them (25%) were already done or simply wrong.** The source audits are
> between 2 and 19 days old. Every unit therefore starts by re-confirming the finding still reproduces.
> `VERIFICATION-LOG.md` in this folder records what was true on 2026-07-30 — start from it, don't
> re-derive it, but don't trust it blindly either. **If a finding no longer reproduces, tick it as DEAD
> with a one-line note and move on. Do not invent work to justify the ticket.**

## Execution prompts

Four prompts, one per bucket. Paste the indented block into a **fresh Claude Code session** at the repo
root. Default order — **P0-PILOT → P1-PILOT → P0-LAUNCH → P1-LAUNCH**.

**What can run concurrently** (each in its own worktree; the constraint is file overlap, not the
`audit.sh` one-at-a-time rule — that governs *scans*, not fix-flow execution):

| Pair | Safe? | Why |
|---|---|---|
| P0-LAUNCH ∥ **P1-LAUNCH** | ✅ **yes, minus one item** | 🔴 **Exclude `#TEST-41`** — it edits `tests/Postgres/*` while `#TEST-1`/`#TEST-2` re-wire the schema-drift gate over those same files, from opposite ends. Everything else is disjoint. Paste-ready prompt: [`EXECUTE-P1-LAUNCH-CONCURRENT.md`](./EXECUTE-P1-LAUNCH-CONCURRENT.md). |
| ~~P1-PILOT ∥ P0-LAUNCH~~ | *historical* | Ran concurrently. P1-PILOT merged at `91f8064c`. |
| ~~P1-PILOT ∥ P1-LAUNCH~~ | *historical* | Was ❌ on three hard collisions; **moot now that P1-PILOT has merged**. Two of the three still matter as *verification* items — see below. |

⚠️ **Any session branching after 2026-07-30 must base off `91f8064c` or later.** `e4b9f573` predates
both the audit-triage merge (`c0088c9f`) and the P1-PILOT merge (`91f8064c`); on that base you will
re-do work that already exists, and conflict on `tests/Pest.php` and `.github/workflows/ci.yml`.

**P1-LAUNCH ↔ P1-PILOT hard collisions — do not run these together:**

| P1-PILOT | P1-LAUNCH | Collision |
|---|---|---|
| `JOB-4` — `RunExecutor.php:168-186` | `CACHE-3` — `RunExecutor.php:168-186` | **same method**, same lines |
| `PRIV-3` — DSAR erasure coverage | `#9` — evidence snapshot export | ~~**`#9` is a subset of `PRIV-3`**~~ — **this prediction was WRONG; `#9` is still open.** The P1-PILOT run (2026-07-30) deliberately did NOT add a `streamEvidence()` export section: `moderation.evidence`'s PII lives in a `payload` JSON column and an export section over it would have leaked third-party moderation context to the reported party. `PRIV-3` closed the *erasure*-coverage half only. `#9` — surfacing the subject's **own** captured handle/display_name in their DSAR — remains unstarted P1-LAUNCH work. |
| `#43` + `#EDGE-2` + `#10` | `#10` — `src/index.js` | `#10` is listed in **both** buckets (Prompt 2 authorises it opportunistically). It belongs to whichever session reaches `cloudflare-worker/` first. |

⚠️ Never run `LC-K6` (P1-LAUNCH unit 6) in the same window as `LC-NIGHTWATCH` (P1-PILOT unit 6) — both
target dev, and the load run will drown the alert signal you're trying to confirm.

Worktrees isolate the filesystem, so concurrency cannot corrupt a run. The risk is the **semantic merge
conflict**: two branches edit the same function, both suites go green in isolation, and the breakage
only appears once merged. Run the suite on the *merged* result, not just on each branch.

This file carries **summaries, not full findings**. Every prompt names the source audit file each ID
lives in; the `Where:` / `Technical:` / `Evidence:` blocks there are the real spec.

---

### Prompt 1 — P0-PILOT (7 findings, 6 units)

> Work the **P0-PILOT** bucket of
> `audits/consolidation/2026-07-30-pilot-launch-reprioritisation/CONSOLIDATED.md`.
>
> Follow `scripts/audit/fix-flow.md` exactly — plan → implement → **independent** review per unit, models
> from this file's `## Execution policy` header. Do not invent a different flow.
>
> **Scope — these 6 units ONLY.** Everything else in the file is deliberately out of scope for this run.
>
> | Unit | ID | Kind | Source of the full finding |
> |---|---|---|---|
> | 1 | `271-PRIV-2` | **product/legal decision, then code** | `audits/sweeps/2026-07-24-pr270-pr271-actions-and-slugs/CONSOLIDATED.md` |
> | 2 | `#INH-7-DRIFT` | code — one validation rule | `audits/consolidation/2026-07-25-backend-inheritance/CONSOLIDATED.md` + `VERIFICATION-LOG.md` §V3 |
> | 3 | `LC-PROD-ENV` | ops — Josh only | `audits/launch-check/2026-07-26/REPORT.md` |
> | 4 | `LC-BACKUP` | ops + decision — Josh only | same |
> | 5 | `LC-RUNBOOKS-2` | docs — two runbooks | same |
> | 6 | `LC-EDGE-HARDENING` | dashboard config — Josh only | same — **covers two report rows**: Cloudflare dashboard + Supabase dashboard |
>
> **Read this before planning:** only units 2 and 5 are things you can do unaided. Units 3, 4 and 6 are
> Josh's to execute (stopped Cloud env, Supabase plan tier, Cloudflare/Supabase dashboard toggles) — for
> those, produce a precise checklist with the exact commands/settings and **stop**. Unit 1 needs his
> decision before any code exists to write. Do not attempt to action 3, 4 or 6 yourself, and **never**
> start, stop, deploy or promote an environment.
>
> ### Step 0 — isolated worktree (REQUIRED)
> ```bash
> git fetch origin
> git worktree add -b audit-fix/p0-pilot-2026-07-30 \
>   "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p0-pilot-2026-07-30" \
>   origin/development
> cd "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p0-pilot-2026-07-30"
> composer install
> cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env
>
> # Sanity gate — all must exist, or you are on the wrong base. STOP if any fail.
> ls audits/consolidation/2026-07-30-pilot-launch-reprioritisation/CONSOLIDATED.md
> ls app/Services/Platforms/Payloads/GoogleBusinessPayload.php
> ls app/Services/Platforms/DisplaySettingsFilter.php
> ls docs/runbooks/
> ```
> Own `composer install` and a real copied `.env` — **never symlink either**; symlinked `vendor/`/`.env`
> is the known cause of phantom feature-test failures. All commits land on the branch above.
> **Never run `git stash`** — another session may pop or drop it.
>
> ### Step 1 — verify the premise (do this first, per unit)
> Re-confirm each finding still reproduces. `VERIFICATION-LOG.md` §V3 and §V6 already carry evidence
> dated 2026-07-30 — read it rather than re-deriving, then spot-check the cited lines still say what it
> claims. If a finding no longer reproduces, tick it DEAD with a one-line note and move on.
>
> ### Blocker gate — every unit here is a P0. Plan first, wait for sign-off, then implement.
>
> **Unit 1 — `271-PRIV-2` is a decision, not a task.** Current verified behaviour: `stripThirdPartyPii`
> runs only while `user.status === 'unclaimed'`; on claim the strip stops applying and the next refresh
> restores full Google reviewer names, photos and review text into the payload, which then ships to
> unauthenticated visitors because the `google-business` allowlist includes `reviews`/`reviewSummary`
> behind a `DisplaySettingsFilter` toggle that defaults **ON**. Present Josh the options — strip reviewer
> identity entirely / redact to first name or initial / keep and record the lawful basis — with the blast
> radius of each. **Do not pick for him.** Note this data is CDN-cached, so whatever is chosen, ask
> whether a purge is needed for anything already served.
>
> **Unit 2 — `#INH-7-DRIFT` is the one clean code fix in this bucket.** A shared `WithBotProtection`
> trait already exists and is used by three of the four public form request classes, all requiring
> `form_started_at_ms`. `PublicEarlyAccessSignupRequest` skipped the trait and declares that field
> `nullable`; because the controller gates on `is_int($startedMs)`, omitting the field bypasses the
> timing/anti-automation check entirely on that endpoint. Fix by adopting the trait (preferred) or making
> the field required — **and add a regression test asserting a submission with the field absent is
> rejected on all four endpoints.** Do not refactor the other three controllers in this unit; that is
> `#INH-7`'s consistency half and it is BACKLOG.
>
> **Unit 5 — `LC-RUNBOOKS-2`.** Write `docs/runbooks/` entries for **DB pool exhausted** and **queue
> backed up**, matching the shape of the existing `vendor-outage` and `redis-down` runbooks. Ground the
> pool one in the real constraint: Supavisor session mode pins a connection slot per *process*, and six
> Horizon daemons consume six of fifteen — so symptoms, how to confirm via `pg_stat_activity`, and what
> to shed first.
>
> ### Record + report
> Tick each finding in **both** this file and its source audit file, bump the source file's `## Progress`
> counts, and commit code + ticked files together as `fix(audit): p0-pilot — <ids>`. Then report: units
> done, units awaiting Josh, test status, branch name. **Do not push to `development` or `production`.**

---

### Prompt 2 — P1-PILOT (12 findings, 6 units)

> Work the **P1-PILOT** bucket of
> `audits/consolidation/2026-07-30-pilot-launch-reprioritisation/CONSOLIDATED.md`.
>
> Follow `scripts/audit/fix-flow.md` exactly — plan → implement → **independent** review per unit, models
> from this file's `## Execution policy` header.
>
> **Scope — these 12 findings ONLY**, grouped into 6 units. Work units sequentially.
>
> | Unit | IDs | Eff | Source file |
> |---|---|---|---|
> | 1 | `PRIV-1` + `PRIV-2` + `PRIV-4` | S+S+S | `audits/sweeps/2026-07-24-...pr270-pr271.../CONSOLIDATED.md` |
> | 2 | `PRIV-3` | M | same |
> | 3 | `#CCH-4` + `#LIFE-11` | S+S | `audits/sweeps/2026-07-28-content-platform-rebuild/CONSOLIDATED.md` |
> | 4 | `#LIFE-12` + `#JOB-4` | S+S | same |
> | 5 | `271-SEM-1` | M | `audits/sweeps/2026-07-24-...pr270-pr271.../CONSOLIDATED.md` |
> | 6 | `#43` + `#EDGE-2` + `LC-NIGHTWATCH` | M+S+S | `#43` → 07-11 sweep; `#EDGE-2` → 07-28 sweep; `LC-*` → launch-check report |
>
> ### Step 0 — isolated worktree (REQUIRED)
> ```bash
> git fetch origin
> git worktree add -b audit-fix/p1-pilot-2026-07-30 \
>   "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p1-pilot-2026-07-30" \
>   origin/development
> cd "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p1-pilot-2026-07-30"
> composer install && cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env
>
> # Sanity gate — STOP if any fail.
> ls app/Services/Analytics/AnalyticsEventSanitizer.php
> ls app/Observers/Core/IntegrationConnectionObserver.php
> ls app/Services/Site/ItemSlugAllocator.php
> ls cloudflare-worker/src/index.js
> ```
> Own `composer install`, real copied `.env`, never symlinked. **Never run `git stash`.**
>
> ### Step 1 — verify the premise per unit
> `VERIFICATION-LOG.md` §V5 and §V6 carry 2026-07-30 evidence for units 1–5. Start there; spot-check the
> cited lines. Tick DEAD and move on if a finding no longer reproduces.
>
> ### Blocker gate
> - **Unit 2 (`PRIV-3`)** touches the GDPR erasure path → plan, present blast radius, **wait for sign-off**.
> - **Unit 5 (`271-SEM-1`)** changes public URL slugs and may need a migration → same, wait for sign-off.
> - Units 1, 3, 4, 6 proceed without asking.
>
> ### Per-unit notes
> **Unit 1** — three small analytics-privacy changes in one pass: round visitor lat/long to ~2dp in
> `PostgresEventWriter::visitRow()`; extend `AnalyticsEventSanitizer::userAgent()` to reduce to browser
> family + major version rather than only length-capping at 256; add
> `analytics.content_popularity_scores` to `PurgeRawAnalyticsEvents::TABLES` with an explicit retention
> window in `config/partna.php`. Document the lat/long truncation in a migration comment.
>
> **Unit 3** — `#CCH-4`: add `$connection->user?->site?->touch()` inside the same
> `hasCompletenessPredicate()` gate in `deleted()` and `restored()` that `saved()` already uses.
> `#LIFE-11`: wrap `cleanupMirroredMedia`'s body in try/catch matching every sibling best-effort method
> in that class. Same file — one unit, one review.
>
> **Unit 4** — `#LIFE-12`: the sibling `connectionRefreshFailing` **already implements the fix** and
> carries a comment documenting the earlier discovery; copy that episode-boundary pattern into
> `menuScrapeFailed` rather than designing a new one. `#JOB-4`: in `RunExecutor`'s projection catch block,
> downgrade `$worstOutcome` to `'degraded'` — that rank already exists in `worse()` and is currently unused.
>
> **Unit 6** — `#43` is the largest item here: `cloudflare-worker/` has **zero** tests. Add a minimal
> Vitest/Miniflare suite covering the security-relevant branches (KV `individual` vs `alias` routing,
> the 301 path, cache-key construction, security headers). Do not attempt full coverage — cover what
> would silently break. `#EDGE-2` is the paired hour: rewrite `cloudflare-worker/README.md` to match the
> actual `individual`/`alias` KV contract. While in this file, also fix `#10` (`unclaimedHtml()`
> hardcodes `https://partna.au`) if it is a one-liner — otherwise leave it, it is P1-LAUNCH.
> ⚠️ `SyncSubdomainToKvJob` is the **only** permitted KV writer — do not add another.
>
> ### Record + report
> Tick in **both** this file and each source audit file, bump their `## Progress` counts, commit as
> `fix(audit): p1-pilot — <ids>`. Run `composer test` green before reporting. Do not push to
> `development`/`production`.

---

### Prompt 3 — P0-LAUNCH (6 findings, 4 units)

> 📎 **Running this alongside a live P1-PILOT session? Use
> [`EXECUTE-P0-LAUNCH-CONCURRENT.md`](./EXECUTE-P0-LAUNCH-CONCURRENT.md) instead of the prompt below.**
> Same six findings, plus a file-ownership table, a `git diff`-based conflict pre-flight, and the two
> known flashpoints (`DataExportCoverageTest.php`, the `site_visits_lat_lon` migration).

> Work the **P0-LAUNCH** bucket of
> `audits/consolidation/2026-07-30-pilot-launch-reprioritisation/CONSOLIDATED.md`.
>
> Follow `scripts/audit/fix-flow.md` exactly. **Every unit here is a P0 → plan first, wait for sign-off,
> then implement.**
>
> **Scope — these 6 findings ONLY**, in 4 units.
>
> | Unit | IDs | Source file |
> |---|---|---|
> | 1 | `#TEST-2` + `#TEST-1` + `271-PARITY-1` | `audits/sweeps/2026-07-24-...pr270-pr271.../CONSOLIDATED.md` |
> | 2 | `LC-ROLLBACK` | `audits/launch-check/2026-07-26/REPORT.md` |
> | 3 | `#API-1` | `audits/sweeps/2026-07-24-...pr270-pr271.../CONSOLIDATED.md` |
> | 4 | `LC-DAST` | `audits/launch-check/2026-07-26/REPORT.md` |
>
> ### Step 0 — isolated worktree
> ```bash
> git fetch origin
> git worktree add -b audit-fix/p0-launch-2026-07-30 \
>   "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p0-launch-2026-07-30" \
>   origin/development
> cd "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p0-launch-2026-07-30"
> composer install && cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env
>
> # Sanity gate — STOP if any fail.
> ls .github/workflows/ci.yml
> ls scripts/launch-check/schema-drift-baseline.json
> ls tests/Schema/ tests/Postgres/
> ```
> **Never run `git stash`.**
>
> ### Step 1 — verify the premise
> `VERIFICATION-LOG.md` §V1 carries 2026-07-30 evidence for all of unit 1 and records each as **PARTIAL** —
> real progress already landed, so scope this to the *remainder*, not a rewrite. Confirm before planning.
>
> ### Per-unit notes
> **Unit 1 is one coherent piece of work — treat it as such.** All three findings are the same root
> problem: guards that exist but never execute.
> - `#TEST-2` remainder: `CheckConstraintsTest` already runs in the new `schema-tests` CI job.
>   `IndexCoverageTest`, `ArchitectureSystemConstraintsTest` and `UpdatedAtTriggerCoverageTest` do not —
>   wire them into a Postgres-backed lane so their `pgsql` driver guard actually passes.
> - `#TEST-1` remainder: `NoLocalCanonicalTableDdlTest` narrowed the hole to 13 canonical tables
>   (grandfathered offenders 15 → 6), but **77 test files still carry inline `CREATE TABLE`** invisible
>   to the schema-drift gate. Widen the gate's scope or the allowlist — do not hand-migrate 77 files.
> - `271-PARITY-1`: `site.menus.user_id` is already `NOT NULL`; **`site.menu_items.menu_id` and
>   `site.menu_items.name` remain nullable** in `tests/Pest.php` and grandfathered in
>   `scripts/launch-check/schema-drift-baseline.json`. Tighten both and remove the grandfather entries.
>
> ⚠️ **Tests run SQLite, prod runs Postgres.** That mismatch is the entire subject of this unit — verify
> every change against the DDL in `supabase/migrations/`, not against a green suite.
>
> **Unit 2 — `LC-ROLLBACK`.** 49 migrations landed since prod's last deploy; only 12 of 51 files carry any
> revert note. Add a one-line `-- to revert:` comment to each migration lacking one, and add the
> convention to `supabase/migrations/CONVENTIONS.md` so new files inherit it. **Do not write or run
> reverse migrations against any database** — this unit produces documentation only. Note the Free plan
> has no PITR, which is why this matters.
>
> **Unit 3 — `#API-1`.** Add a `SHOP_PRODUCT_ALLOWLIST` and map each brand's products through
> `array_intersect_key` before it reaches `PublicIntegrationConnectionResource`. Touches the public wire →
> escalate implement to Opus. Add a test asserting an unlisted key never reaches the response.
>
> **Unit 4 — `LC-DAST`.** The tooling already shipped to `development`; what is missing is baseline
> triage. Produce the triaged findings list with a recommended disposition per item and **present it to
> Josh** — do not silently accept or suppress anything.
>
> ### Record + report
> Tick in both files, commit as `fix(audit): p0-launch — <ids>`, `composer test` green. No pushes to
> shared branches.

---

### Prompt 4 — P1-LAUNCH (34 findings, 8 units)

> 📎 **Running this alongside a live P0-LAUNCH session? Use
> [`EXECUTE-P1-LAUNCH-CONCURRENT.md`](./EXECUTE-P1-LAUNCH-CONCURRENT.md) instead of the prompt below.**
> It folds in the 7 promoted findings as two new units, excludes `#TEST-41`, carries the P0-LAUNCH file
> ownership table and conflict pre-flight, and flags the four findings whose premise changed when
> P1-PILOT merged (`#9`, `#10`, `CFG-16`, `INH-6`).

> Work the **P1-LAUNCH** bucket of
> `audits/consolidation/2026-07-30-pilot-launch-reprioritisation/CONSOLIDATED.md`.
>
> Follow `scripts/audit/fix-flow.md` exactly. This is the largest bucket and the least urgent — **it is
> also the one most likely to contain findings that are already done.** Verify every premise before
> planning; expect casualties.
>
> **Scope — these 27 findings ONLY**, in 6 units.
>
> | Unit | IDs | Theme | Source |
> |---|---|---|---|
> | 1 | `#SCALE-13/14/17/19/20` + `#CACHE-1/2/3` | unbounded `whereIn` + per-row INSERT loops | 07-28 sweep |
> | 2 | `DINT-1` + `271-PRIV-1` + `#3` | missing indexes + unbounded retention | 07-24 sweep; `#3` → 07-11 |
> | 3 | `#SCALE-11` | `SiteMedia` force-delete storage I/O | 07-28 sweep |
> | 4 | `#TEST-9`/`271-TEST-1` + `#TEST-41` + `#TEST-49` + `#TEST-50` + `#38` | migration invariant guards | 07-24 + 07-28 sweeps |
> | 5 | `#9` + `#10` + `#SEC-4` + `#INH-6` | residuals | 07-11, 07-28, inheritance |
> | 6 | `LC-DRILL-worker-kill` + `LC-DRILL-vendor-outage` + `LC-DRILL-redis-down` + `LC-K6` + `LC-RERUN` | operational drills | `audits/launch-check/2026-07-26/REPORT.md` |
>
> ### Step 0 — isolated worktree
> ```bash
> git fetch origin
> git worktree add -b audit-fix/p1-launch-2026-07-30 \
>   "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p1-launch-2026-07-30" \
>   origin/development
> cd "/Users/joshuahunter/Herd/Side Street/backend-wt/audit-fix-p1-launch-2026-07-30"
> composer install && cp "/Users/joshuahunter/Herd/Side Street/backend/.env" .env
> ```
> **Never run `git stash`.**
>
> ### Step 1 — verify the premise, and expect to delete work
> This bucket draws partly on the 2026-07-11 sweep, where sampling found **6 of 14 findings already
> fixed**. Before planning any unit, re-confirm each finding reproduces. **Tick DEAD and move on** for any
> that don't — that is a successful outcome, not a failed one.
>
> ### Blocker gate
> - **Unit 2** adds DB indexes → migration → plan, wait for sign-off.
> - **Unit 3 (`#SCALE-11`)** is listed **standalone** in the 07-28 sweep because it touches the GDPR
>   account-deletion data path → plan, wait for sign-off, isolate its review.
> - **Unit 4** changes `supabase/migrations` invariants → wait for sign-off.
> - Units 1, 5, 6 proceed without asking.
>
> ### Per-unit notes
> **Unit 1** — pure scale work; none of it bites at pilot volume. Chunk unbounded `whereIn` arrays
> (~500/batch) and collapse per-row INSERT loops into multi-row inserts. Measure before and after on one
> representative path rather than fanning out on assumption.
>
> **Unit 2** — `DINT-1` needs `CREATE INDEX CONCURRENTLY` on `analytics.action_events(user_id)` and
> `analytics.item_views(user_id)`. ⚠️ **One `CONCURRENTLY` statement per migration file, alone** — the
> Supabase CLI pipelines multi-statement files and `CONCURRENTLY` cannot run in a pipeline (`SQLSTATE
> 25001`). `271-PRIV-1` adds `retired_at` + a scheduled prune command in `routes/console.php`.
>
> **Unit 4** — these are grep-based invariant tests, cheap and high-value. Note `#TEST-9`/`271-TEST-1`
> is worse than the source audits record: `ArchitectureSystemConstraintsTest` exists but does **not**
> assert `site.themes` stays dropped, **and** it doesn't run in CI. `CLAUDE.md` currently claims this
> rule is "pinned" by that test — **correct that claim as part of this unit.** `#TEST-41`:
> `ItemTombstoneBackfillTest` already reads real migration SQL off disk; copy that pattern into
> `BrandAssetPipelineTest` and `CatalogSyncIdempotenceTest`.
>
> **Unit 5** — `#INH-6` is verified **NOT drifted**: the three `normalizeName`/`norm` copies are still
> byte-identical, so this is consolidation into the `NormalizesMenuData` trait, not a bug fix. Prove
> byte-identity again before touching it; if they *have* drifted since 2026-07-30, stop and escalate —
> that would be a live menu-matching bug, not a refactor.
>
> **Unit 6 is not code.** Run the three drills from `docs/runbooks/drills/` on the **LOCAL** stack only
> and log to `docs/runbooks/drills/logs/`. Run the k6 baseline (10 VU/5 min) + public-handle spike
> (50–100 VU) **against dev only**, watching edge cache-hit ratio, Supavisor headroom and p95.
> ⚠️ The k6 harness hard-codes three real invariants (gallery capped at 6/site, gallery items needing a
> matching `site.media_variants` row, analytics writes needing a matching `Origin`/`Referer`) — if a seed
> fails, check those before debugging the harness. `LC-RERUN` is a process change: document re-running
> launch-check after every migration push and before every promote.
>
> ### Record + report
> Tick in both files, commit as `fix(audit): p1-launch — <ids>`, `composer test` green. Report DEAD
> findings separately from fixed ones so the count is honest. No pushes to shared branches.

---

## Scope

- **Input:** every open (`- [ ]`) item across all six non-archived audit folders + the launch-check report
- **Method:** mechanical extraction (Haiku ×7) → verification against current code (Sonnet ×6) → re-grading (Opus)
- **Branch at time of run:** `guard/postgres-lane-walker`
- **Sources read:**
    - `audits/sweeps/2026-07-28-content-platform-rebuild/CONSOLIDATED.md` — 170 open
    - `audits/sweeps/2026-07-11-full-work-sweep/CONSOLIDATED.md` — 59 open (all P3)
    - `audits/sweeps/2026-07-24-pr270-pr271-actions-and-slugs/CONSOLIDATED.md` — 43 open
    - `audits/consolidation/2026-07-25-backend-inheritance/CONSOLIDATED.md` — 17 open
    - `audits/sweeps/2026-07-27-dead-code/BACKEND-INHERITANCE-CONSOLIDATION-VERIFIED.md` — 16 open (same 17 findings as above, re-numbered; **not** double-counted)
    - `audits/launch-check/2026-07-26/REPORT.md` — 13 open action items
- **Universe:** **302 unique open items.**

## Why this file exists

The existing audit folders grade findings on *technical severity at scan time*. They do not answer
"what must be true before a real customer touches this." That is a different question, and the answer
re-orders the list substantially: several P2/P3 items are pilot blockers, and a large majority of the
P2 tranche is safely post-launch.

Two structural problems in the source corpus had to be solved first:

1. **A tick records intent, not state.** The 07-28 sweep says so in its own header — a large share of
   its P1s were closed by *re-grading*, not by writing what was asked.
2. **The inverse is also true, and nobody was tracking it.** Work shipped between 07-11 and today
   closed findings that were never ticked back. There is no mechanism in the audit pipeline that
   would notice.

So nothing here carries a priority tag until it was checked against the code as it exists now.

## New priority vocabulary

| Tag | Means |
|---|---|
| **P0-PILOT** | Blocks the first real customer. Do not run a pilot with these open. |
| **P1-PILOT** | The pilot itself will expose this. Land it before or in the first week. |
| **P0-LAUNCH** | Blocks public launch. Pilot's small N hides it; GA will not. |
| **P1-LAUNCH** | Should land before GA. |
| **BACKLOG** | Post-launch. Correct to defer. |
| **DEAD** | Verified already done, or the finding was wrong. Tick it and stop carrying it. |

## Findings at a glance

| Bucket | Findings | Units | Original grades folded in |
|---|---|---|---|
| **P0-PILOT** | 7 | 6 | 2× P2, 5× ungraded launch-check |
| **P1-PILOT** | 12 | 6 | 8× P2, 1× P3, 3× ungraded |
| **P0-LAUNCH** | 7 | 4 | 2× P1, 2× P2, 2× ungraded, +1 promoted |
| **P1-LAUNCH** | 34 | 6 | 1× P1, 9× P2, 12× P3, 5× ungraded, +7 promoted |
| **BACKLOG** | 231 | — | **triaged 2026-07-30** → 96 opportunistic, 135 wontfix |
| **DEAD** | 11 | — | verified already done or phantom |
| **Total** | **302** | 22 | |

*Findings* counts individual audit IDs; *units* counts the work packages the execution prompts group
them into. They differ where several findings share a root cause and one review covers them all.

**Backlog is now dispositioned, not just deferred** — see
[`BACKLOG-TRIAGE.md`](./BACKLOG-TRIAGE.md). That pass promoted 8 findings (reflected above), closed 135
as WONTFIX with stated reasons, and marked 96 OPPORTUNISTIC under the standing rule now recorded in
`CLAUDE.md`. Nothing in the backlog is scheduled work.

**Verification coverage: 48 of 302 items were checked against live code.** That is deliberate, not a
shortcut — every item that lands in a P0/P1 bucket was verified, plus a 14-item staleness sample of
the oldest sweep. The 239 BACKLOG items were **not** individually verified, because staleness there
does not change the decision: a stale P3 and a live P3 are both deferred. Treat backlog counts as
an upper bound.

---

## Progress

Added 2026-07-30 during the P0-PILOT run. The file shipped with **zero** checkboxes, so
`scripts/audit/archive-done.sh` — which treats a file as done at "≥1 checkbox and zero `- [ ]`" —
could neither track it nor safely archive it. Boxes cover all **63 bucketed findings**; the 239
BACKLOG items are deliberately excluded because this file does not re-document them (they stay
tracked in their source folders).

| Bucket | Done |
|---|---|
| P0-PILOT | 6 / 7 |
| P1-PILOT | 11 / 12 |
| P0-LAUNCH | 7 / 7 |
| P1-LAUNCH | 0 / 27 |
| DEAD bookkeeping | 0 / 11 |

**P0-PILOT** — worked 2026-07-30 on `audit-fix/p0-pilot-2026-07-30`.
- [ ] `271-PRIV-2` — **OPEN: awaiting Josh's product/legal decision.** Decision brief prepared; no code written by design.
- [x] `#INH-7-DRIFT` — `PublicEarlyAccessSignupRequest` adopts `WithBotProtection`; 4-endpoint regression test added. Independent review PASS; 428 passed / 0 failed across `tests/Feature/Security` + `tests/Feature/PublicSite`.
- [x] `LC-PROD-ENV` — **delegated to Josh**, checklist issued. Ticked on handoff, *not* on verified restart: prod was still `status=stopped` at tick time.
- [x] `LC-BACKUP` — **delegated to Josh**, decision + checklist issued. Ticked on handoff; org plan still `free` at tick time.
- [x] `LC-RUNBOOKS-2` — `docs/runbooks/db-pool-exhausted.md` + `queue-backed-up.md` written. Independent review PASS, zero citation drift; the reviewer re-ran the `pg_stat_activity` query live and reproduced the documented 15-idle-connection signature.
- [x] `LC-EDGE-HARDENING` — **delegated to Josh**, checklist issued (covers both report rows: Cloudflare + Supabase). Ticked on handoff; no dashboard setting verified changed.

> **The three delegated units are written up in `JOSH-OPS-CHECKLIST.md` in this folder** — exact
> commands, dashboard paths, and every `UNVERIFIED` gap named with the check that would close it.
>
> **Read the three `delegated to Josh` ticks as intent, not as state.** Per this repo's convention an
> infra box ticks on the decision/handoff, and the live system must be confirmed separately. Nothing in
> `LC-PROD-ENV`, `LC-BACKUP` or `LC-EDGE-HARDENING` was actioned by an agent — starting, stopping,
> deploying or promoting an environment was explicitly out of bounds for this run.

**P1-PILOT** — worked 2026-07-30 on `audit-fix/p1-pilot-2026-07-30` (isolated worktree, 7 commits).
- [x] `#CCH-4` · [x] `#LIFE-11` · [x] `#LIFE-12` · [x] `271-SEM-1` · [x] `#JOB-4` · [x] `PRIV-1`
- [x] `PRIV-2` · [x] `PRIV-4` · [x] `PRIV-3` · [x] `#43` · [x] `#EDGE-2` · [ ] `LC-NIGHTWATCH`

> Every unit got plan → implement → **independent** review. Two units failed first review and
> were re-implemented; one of those needed a second review round. Reviewers were given the two
> highest-risk lines by name and asked for *positive* evidence rather than absence of concern —
> which is how the `PRIV-3` UPDATE predicate got checked by dumping its generated SQL
> (`where ("reporter_user_id" = ? or lower(trim(reporter_email)) = ?)`, correctly parenthesised,
> OR leg omitted entirely on a null email) rather than by reading it.
>
> **`LC-NIGHTWATCH` is the one left open, deliberately.** Capture is proven — a `QueryException`
> landed on dev unprompted as Nightwatch issue **#370** on 2026-07-30. The finding is about
> *delivery*, and nothing an agent can read confirms an email or Slack message arrived.
> **Trap for whoever runs it:** Nightwatch fingerprints by exception class + location, not
> message, so a second probe of the same class folds into the same issue and will NOT re-fire a
> new-issue alert — each attempt needs a distinct class. Also, a `report()` from `tinker` lands
> as a **command**-source exception; if the alert rule is request-scoped, nothing fires even
> though capture works, and *that* is the finding.
>
> **Three things the findings did not describe, all surfaced by verifying the premise first:**
>
> 1. **`PRIV-3` sat on a live erasure no-op.** `purgeCaseSignalPii()` filtered on
>    `reporter_user_id`, but `ContentReportService::submit()` — the only `CaseSignal` writer —
>    never populates it, and that route is unauthenticated. The column is always NULL in
>    production, so the reporter-PII erasure had **never fired**. The existing test was green
>    only because its fixture hand-inserts a row shape no production path produces. Fixed. The
>    new guard now asserts every `PURGED_PII_TABLES` entry names a method that is actually
>    *invoked inside* `purge()` — the gap was never a missing list, it was that nothing checked
>    the list against live code.
> 2. **`#LIFE-12`'s prescribed fix was net-harmful.** "Copy `connectionRefreshFailing`'s episode
>    pattern" works only because `last_refreshed_at` has ONE writer.
>    `site.menus.last_fetched_at` has three — `MenuFetchJob` (including its soft-unavailable
>    branch), `MenuContentController::resolveMenu()` (every manual dish edit) and
>    `MenuScanApplier::resolveMenu()` (every photo-scan apply) — so keying on it re-notifies on
>    every failure that follows any manual edit. Needed a new single-writer column:
>    `20260730120000_menus_last_successful_fetch_at.sql`, **applied to dev and the
>    `schema_migrations` ledger realigned.** Prod untouched.
> 3. **`#JOB-4` woke a dormant consumer.** `'degraded'` sat in `worse()`'s rank map but was never
>    produced. Emitting it sent runs down `SourceScheduler::release()`'s failure branch →
>    `consecutive_failures` → `health='dead'` at 10 → permanently excluded by `scoreDue()`, with
>    reconnect deliberately not resetting scheduler health. A bug in our *own* projector could
>    have retired a healthy vendor source forever. `$qualifies` now admits `'degraded'` with a
>    per-field carve-out (EWMA applied, failures reset, measured interval — but `health` set to
>    `'degraded'`, not `'ok'`, since the derived content is not good).
>
> **`271-SEM-1` needed no migration.** The collision-vs-name-digits discriminator is derivable at
> runtime; a stored column would have been *worse*, because existing rows are ambiguous by
> construction so an honest backfill must run the same walk anyway — and
> `base_slug = base(current name)` would have cemented the bug permanently on already-broken
> rows. Its public-URL consequence has an external dependency worth recording: item-slug 301s are
> the **pages app's** job via the `aliases` array, and `partna-pages` does not exist yet (both
> local frontends are Next.js dashboards with no public item route). So `aliases` is currently an
> unfulfilled contract; the fix is still strictly better, since before it a stale slug was served
> forever with no alias row at all.
>
> **`#10` is PARTIAL and deliberately NOT ticked** (it is P1-LAUNCH regardless). The three
> hardcoded literals in `unclaimedHtml()` now read `PARTNA_DOMAIN`, which is behaviour-neutral,
> but the finding's actual complaint — "regardless of environment" — is unfixed, because
> `PARTNA_DOMAIN` is itself a deliberate flat literal (EDGE-3: the Worker has no `env()`).
>
> **`PRIV-1`'s premise was partly stale:** coordinates were never stored at full double precision
> — `DetectsClientInfo::parseCoordinate()` already rounded to 4dp at ingest. The 2dp cut at the
> persistence boundary was kept anyway, and review found it is *not* redundant:
> `DataExportPayloadBuilder::streamAnalyticsSiteVisits()` streams those columns into a GDPR
> export with no rounding of its own.
>
> **`#9` was predicted to close as a side effect of `PRIV-3`. It did not** — see the corrected
> collision table above. It remains unstarted P1-LAUNCH work.

**P0-LAUNCH** — worked 2026-07-30 on `audit-fix/p0-launch-2026-07-30`.
- [x] `#TEST-2` · [x] `#TEST-1` · [x] `271-PARITY-1` · [x] `LC-ROLLBACK` · [x] `#API-1` · [x] `#50` · [x] `LC-DAST`

Unit 1 (`#TEST-2` + `#TEST-1` + `271-PARITY-1`) — one root cause: `tests/TestCase.php:21-33`
unconditionally repoints the `pgsql` alias at in-memory SQLite, so every `getDriverName() === 'pgsql'`
gate in the Feature suite is unsatisfiable. Those tests reported green having asked the database nothing.
- `#TEST-2` — `IndexCoverageTest`, `ArchitectureSystemConstraintsTest`, `UpdatedAtTriggerCoverageTest`
  moved to `tests/Schema/` (the applied-schema lane, CI job `schema-tests`). **17 assertions that had
  never executed now run.** No CI-config change was needed — the lane already existed, so this was far
  cheaper than the L estimate, which predates `5ea53445`. Two things the move exposed: (a) a Pest
  `uses()->in(__DIR__)` ordering landmine in `CheckConstraintsTest.php:27` that would have thrown
  "facade root has not been set" / `TestCaseAlreadyInUse` — fixed to `->in(__FILE__)`; (b) **6 of
  `UpdatedAtTriggerCoverageTest`'s 13 assertions targeted tables that do not exist** (`brand.*` ×4,
  `commerce.affiliate_product_selections`, `core.gdpr_requests` — the `brand`/`commerce` schemas were
  removed in the standalone strip-down). Deleted with the reason recorded in-file. An unrun guard does
  not merely fail to catch regressions in the code; it fails to catch regressions in itself.
- `#TEST-1` — drift-gate scope inverted rather than widened by hand. Jurisdiction is now "any table a
  no-arg `setup*` global declared in `tests/Pest.php` builds" (new `Tests\Support\SchemaDrift\PestSetupHelpers`,
  shared with `SchemaDriftGuardTest` so the two gates cannot disagree about "canonical"). Measured
  6 → 31 files caught, zero new false positives; the alternative (broadening the hardcoded 13-name
  list) was rejected as heavily prose-false-positive. **Zero of the 78 files hand-migrated** — baselining
  is the established mechanism.
- `271-PARITY-1` — `site.menus.user_id` was already `NOT NULL`. Remaining: `site.menu_items.menu_id`
  and `.name` tightened in the test seed, plus **`.id`, which the finding did not name** — prod is
  `"id" uuid NOT NULL` and SQLite does not imply NOT NULL from `PRIMARY KEY` on a non-`INTEGER` column.
  Verified against `20260726000000_baseline_pilot.sql`, not against a green suite; no later migration
  touches the table. 3 grandfather entries removed from `schema-drift-baseline.json` by surgical delete,
  not regeneration. No migration needed — prod was already correct.

**Scope addition, approved by Josh mid-run: the same root cause spanned 30 files, not 3.** A grep for all
`getDriverName()`-vs-`'pgsql'` gate forms outside the two real Postgres lanes returns **30 test files**,
heaviest in `tests/Feature/Security` (7) and `tests/Feature/Moderation` (6). The 7 security files were
folded into this unit; the rest are logged below as a follow-up. Folded: `DesignKitsRlsTest`,
`AdminOnlyWritePoliciesTest`, `FunctionSearchPathTest`, `ModerationSchemaRlsTest`,
`AnalyticsSessionsRlsTest`, `AuditModerationEventsRlsTest`, `PlatformAndMenuRlsTest` → **80 RLS,
policy-shape and `search_path`-hardening assertions now have a lane that executes them.**

Unlike unit 1a's files these carry **zero schema rot** — every object they name still exists. What they
carried instead was a **Pest API misuse that only execution could surface.**
`vendor/pestphp/pest/src/Mixins/Expectation.php:184` declares `toContain(mixed ...$needles)` — variadic,
**no message parameter** — while `toBe`, `toBeTrue` and `toBeNull` all take `string $message = ''`. So
`toContain($needle, "[$policy] must allow staff access.")` asserted the catalog value contained *both*
strings, the second being an English sentence. **18 sites failed unconditionally; 3 negated ones passed
vacuously.** All 21 fixed to the single-needle form the executing lanes already use. Six of the seven
files made the identical mistake — and the one that didn't (`AnalyticsSessionsRlsTest`) simply has no
`toContain` calls. The error correlates with surface area, not author care: the wrong form is the one
that reads correctly by analogy, PHP's variadic swallows the extra argument silently, and review cannot
see it. **An unrun guard does not just fail to catch regressions in the code — it fails to catch them in
itself.** Corroborating evidence: zero multi-needle `toContain` calls exist in `tests/Postgres/` or
`tests/Schema/`, the lanes that actually run.

Also repointed `scripts/audit/lenses/schema-rls.md` (CI-enforced — `AuditPipelineIntegrityTest`
`file_exists()`-checks every backticked `tests/…` token, so the moves would have turned that guard red)
and `scripts/audit/lenses/test-coverage.md`.

Independent review PASS on both halves (one reviewer stalled and was replaced). Per-file `it()` counts
preserved exactly across all 7 moves; every `toContain` fix verified to drop the *message* with the
needle byte-identical — no assertion weakened. One defect caught pre-review and fixed: the implementer
added a `sort()` to `PestSetupHelpers::names()`, which silently reordered the 59 fixture builders
`SchemaDriftGuardTest` *calls*. Verified statically that declaration order ≠ alphabetical and that no
helper `ALTER`s another's table, so it was harmless today — removed anyway, since a green suite could
not have distinguished the two versions.

Unit 2 (`LC-ROLLBACK`) — **56 migrations, 43 given a `-- ROLLBACK:` note, 56/56 now compliant.**
Documentation only, proven mechanically: **zero non-comment lines added and zero lines removed** across
all 43 files, so not one byte of executable SQL changed. Convention recorded in `CONVENTIONS.md` §10
(+ cheat-sheet row), `TEMPLATE.sql.example` and `docs/deploy/routine-deploy.md`; enforced by two new
`it()` blocks in `MigrationTransactionBoundaryTest`.

The audit's own numbers were wrong in both directions and are corrected here: **53 files not 51**
(then 56 after this branch and the P1-PILOT merge each added migrations), and **9 real notes, not 12** —
its `grep -liE "revert|rollback"` counted three *prose mentions* as compliant, including one match
inside a `COMMENT ON COLUMN` string literal. The second new test uses those three as fixtures, pinning
the exact distinction the grep got wrong.

Convention is `-- ROLLBACK:` (all 9 pre-existing live files); `-- To revert:` is archive-era and the
matcher accepts both case-insensitively — which is why the two migrations merged from P1-PILOT, using
lowercase `-- to revert:`, already comply.

The guard test went into `MigrationTransactionBoundaryTest`, **not** `guard-no-unsafe-migrations.php`:
that script's `-- guard:no-unsafe-migrations:disable-file` marker `continue`s a whole file *before any
check runs*, and three files carry it including `baseline_pilot.sql`. A lock-safety opt-out must not
double as a documentation opt-out.

🔴 **The real content of this finding: 13 of the pending migrations have no usable reverse path.**
- **Irreversible without a restore (8)** — `baseline_pilot`; whole-schema `DROP … CASCADE` on `catalog`,
  `routing`, `ingest`, `content`; whole-table on `sections_and_documents`; hard `DELETE` in
  `purge_orphan_ingest_rows` and `purge_orphan_section_items`.
- **One-way data operations (5)** — `connections_surface_key_backfill`, `retire_pinterest`,
  `repair_record_versions_current`, `repair_sections_site_id`, `backfill_pconn_timestamps`.
- (A further 7 `VALIDATE`-only files say NONE, but that is a technicality — Postgres has no
  "un-validate" and the sibling `_not_valid` file's `DROP CONSTRAINT` removes the constraint entirely.
  Counted separately so it does not inflate the real risk.)

**Two warrant a decision beyond a comment:** `20260727130000_ingest_schema` creates `ingest.effects`,
the charge-once money ledger (`cost_tag`/`cost_units`/`claimed_at`/`settled_at`) — a bad apply forcing a
schema drop destroys the record of vendor spend already incurred, and no backup tier covers it.
`20260728100000_retire_pinterest` collapses `routing.source_intents.state`: `'proposed'` and `'blocked'`
both become `'superseded'`, with nothing recording which was which. **Recommendation for Josh, out of
this unit's scope: a manual `pg_dump` of prod immediately before the first `db push` of this pending
set, held until the pilot is stable.**

Independent review caught **one blocking defect**, which is the strongest argument for the convention it
was enforcing. The note on `ingest_schema` claimed CASCADE would drop `content.source_items`' `stream_id`
FK. **No such FK exists** — `content_schema.sql:82` is a plain nullable `uuid` with only an index; the
three real FKs to `ingest.streams` are all on `ingest.*` tables, inside the schema being dropped. The
claim originated in the plan and was transcribed faithfully, and **every mechanical check passed it** —
comment-only, guard green, suite green, note present and correctly placed. Only reading the claim against
the DDL could catch it. Corrected to state the truth and pre-empt the wrong inference ("Do not go looking
for a broken FK to rebuild; there never was one"); re-reviewed PASS. This is exactly the failure the §10
rule names: *a note claiming revertibility where none exists is worse than no note.*

⚠️ **Bookkeeping caveat:** `LC-ROLLBACK`'s source report (`audits/launch-check/2026-07-26/REPORT.md`) is
**gitignored** (`.gitignore:64`), so it does not exist in the repo and its box could not be ticked there
— only here. Also note the action item's exact wording is "every migration since last deploy has a
**tested** reverse path". This unit closes the *documented* half by mandate; **the tested half remains
open** and belongs with the PITR/backup item and `docs/runbooks/drills/04-backup-restore.md`.

Unit 3 (`#API-1` + `#50`) — **two findings graded identically that are structurally opposite.**

`#API-1` is a **real defect**: `ShopBrand::toBrandArray()` spreads `ShopProduct.data` verbatim into
`products` (`ShopBrand.php:129-138`), and `SHOP_BRAND_ALLOWLIST` allowlisted **brand**-level keys only —
so any key a fetcher adds reaches unauthenticated visitors with **no enforcement point**, unlike every
other platform in `::ALLOWLIST`, which fails closed. Fixed with a 15-key `SHOP_PRODUCT_ALLOWLIST` and an
`array_map` through `array_intersect_key`, at the **Resource** not the model — decisive because
`ShopCatalog::syncLatest()` reads `createdAt` from `toBrandArray()` to sort latest-mode products and
`ShopController::setProducts()` reads `products` from it for fetch dispatch, so filtering at the model
would silently degrade internal callers.

🔴 **The audit's own prescribed key list was WRONG** and would have shipped a user-visible regression: it
omitted `variantId`, `vendor`, `description` and `createdAt`, all live on the wire. Dropping `variantId`
breaks checkout deep links for every `linkMode: 'checkout'` store. The 15 keys here are the empirical
union of all five emitters (`Shopify`, `WooCommerce` incl. `productsFromClient`, `Squarespace`,
`BigCartel`, `GenericShop` JSON-LD + OpenGraph), re-derived independently by the reviewer. All three
writers were checked for a user-supplied-key path: none exists (`AddShopProductRequest` takes a `url`,
`SetShopProductsRequest` takes ids; product objects always come from a scraper).

`#50` — **DEAD as graded.** `PublicMenuController::show()` emits every key by hand-written literal: no
spread, no `Model::toArray()`, no payload pass-through, so the hand-built map **is** an enumerated
allowlist and a new column cannot reach the wire without a code edit. The promotion rationale ("grading
them differently was an inconsistency in the source audits, not a real distinction") is factually
backwards — **the distinction is spread-vs-enumerate and it is real.** Both original source audits said so
themselves (`#API-9`, `#API-3`: *"Every field shipped today is deliberately curated and non-sensitive —
this is not a live leak."*). Verified: **zero internal-looking columns are currently public**; all of
`scan_items`, `suppressed_items` (what the owner deliberately hid), `content_source`, `source_platform`,
`pickup_source`, `delivery_source`, `is_manual` are withheld, and `MenuPayloadComposer` emits exactly
those to the *dashboard* — proving the split is deliberate.

**Josh's decision: close `#50` WONTFIX-with-hardening** — an `INTENTIONAL EXCLUSIONS` docblock plus three
wire-contract tests pinning the exact key set at all four nesting levels. A Resource class and an
`array_intersect_key` were both **deliberately rejected**: `IndividualProfileResource` (the precedent the
original audit cited) is itself array-in/hand-written-keys-out, so a Resource would be a **file move, not
a guardrail**; `array_intersect_key` on a literal is a **tautology** — dead code that reads like a control,
which is worse than none. And 6 `phpstan-baseline.neon` entries are pinned to that controller with
`reportUnmatchedIgnoredErrors` unset (defaults true), so moving the mapping would have failed
`composer analyse`. Following the rule's letter would have made the code less safe than following its
intent, on a payload that is CDN-cached 15 minutes with no golden master and no frontend contract doc.

**Mutation-tested, because a guard that cannot fail is the thing this whole bucket is about:** deleting
`'variantId'` fails Test 2; deleting the `array_map` block fails Test 1; and — unprompted — adding
`isManual` to the menu payload fails two `#50` tests, proving that guardrail is load-bearing rather than
decorative. Tests: `PublicIntegrationAllowlistTest` 16 → 18, `PublicMenuControllerTest` 10 → 13. Byte-
identity canaries green (`ShopRelationalStorageTest` 18, `GoldenMaster` 31, `Registry`+`Unit/Platforms`
426). Suite 6867 passed; PHPStan 21 unchanged with all 6 pinned entries still matched. Independent
review PASS.

**Also shipped, per Josh's decision:** a required `pg_dump` pre-flight in `docs/deploy/routine-deploy.md`
for the current pending migration set — 53 migrations pending against prod, 13 with no usable reverse
path, on a Free plan with no PITR and ~7-day RPO. Every flag traced to the verified invocation in
`docs/runbooks/drills/04-backup-restore.md`; the pooler hostname is a marked placeholder, not fabricated.

Unit 4 (`LC-DAST`) — full triage in [`LC-DAST-TRIAGE.md`](./LC-DAST-TRIAGE.md). **Read the tick as
intent, not state.**

**The finding is worse than "untriaged baseline": the control has never run.** One workflow execution
ever (`30211809194`, 2026-07-26T17:04:24Z), **failed in 10 seconds** on `[dast] ERROR: no target`.
`gh api .../actions/secrets` → `total_count: 0` — none of the three secrets were ever set. The weekly
cron (Sun 16:00 UTC) is a guaranteed-red no-op and fires again every week. No `REPORT.md` has ever been
committed; the 07-26 run artifacts are gone from disk. The tooling itself is real and self-tested (ZAP
active lane, Nuclei + wcvs edge lane, 7/7 canary self-test) — it simply isn't wired to anything.

9 findings triaged, recovered from the implementation plan's Phase 10 prose since the raw JSON is gone:
**FIX NOW — none**, nothing exploitable. **FIX BEFORE GA** — P1, no HSTS on `/robots.txt` and
`/favicon.ico` because static files never enter Laravel so `SecureHeaders` never runs (Cloudflare zone
toggle, `preload` OFF). **ACCEPT** — P2, CORP absent, but CORS + `default-src 'none'` already close the
vectors and a blanket `same-origin` would **break the public QR-code SVG embed**; accepted with a
revisit trigger. **SUPPRESS** — A1, A2, P3, P4, P6, all local-runner artifacts or Cloudflare's own
`__cf_bm`/`_cfuvid` cookies, verified absent from the deployed host by header fetch. **HOLD** — P5, the
one disposition reached by inference. **Cannot triage** — P7, a 7th WARN the prose never named; not
guessed.

🔴 **Two blockers found while reviewing the recommendations, which changed them:**
1. **Baselining is impossible without a fresh run.** `--update-baseline` appends *that run's own*
   findings after the scanners execute, and baselines are keyed by scanner-specific stable keys
   (`template-id@matched-at`, `technique@url`, ZAP alert keys). Those keys do not exist for the six
   SUPPRESS items. Hand-writing entries would be **worse than an empty baseline** — a key matching no
   real finding is dead weight *and* reads as "triaged". So items 4 and 5 are **decided, not applied.**
2. **Partial secret configuration produces NO REPORT AT ALL.** `run.sh` runs the three edge scanners
   under `set -euo pipefail` *before* the `set +e` guarding `diff-baseline.sh`. Set only
   `DAST_EDGE_TARGET` and Nuclei does real work, then `wcvs.sh` dies on the missing sitepage target,
   `run.sh` aborts, and the report block never executes — a successful scan silently discarded. Both
   secrets or neither.

Also corrected: with `--fail-on high` and empty baselines **all nine findings are already below the
gate**, so baselining changes report noise, not gating; and P1's real-world value is smaller than it
first appears, since `SecureHeaders` already pins HSTS with `includeSubDomains` on every PHP response —
the gap only reaches a client whose *first ever* request is one of those two static files.

To unblock the run: dev Supabase has 12 published sites. ⚠️ **Use `subdomain`, not `handle`** — they
differ for three rows, so the handle would 404. Recommended `showcase-eats`.

Open on Josh: set both repo secrets and prove the workflow green via `workflow_dispatch`; enable
Cloudflare HSTS (`preload` OFF, after confirming no HTTP-only subdomain); then re-run both lanes to
obtain real keys and apply the SUPPRESS set. Two caveats stay open by design and are accepted for
pilot: the active lane runs as superuser against a local stack so a green result is **not** proof of
prod RLS, and Cloudflare's WAF can turn a sweep into challenge pages that read as clean.

**Follow-up logged, not fixed (new findings, out of this bucket's scope):**
- **23 more files carry the same unsatisfiable `pgsql` gate**, including 6 in `tests/Feature/Moderation`
  and `tests/Feature/Database/DataExportSchemaParityTest.php` (deliberately deferred — it reflects over
  `DataExportPayloadBuilder`, which P1-PILOT was rewriting for `PRIV-3`).
- **53 tables have an `updated_at` column and no DB trigger** (77 with the column, 24 with a trigger),
  including `site.menus`, `site.pages`, `content.items`, all 13 `content.f_*`. Non-Eloquent write paths
  won't stamp them. Inverting `UpdatedAtTriggerCoverageTest` to assert this was explicitly rejected as a
  product decision, not a test fix.
- **`audit.staff_audit_log` is hand-rolled in 30 test files with no shared helper** — a real prod table
  entirely outside drift-gate jurisdiction. One `setupStaffAuditLogTable()` would bring all 30 in.
- **`site.all_site_data` is a `VIEW` in prod but a `TABLE` in a test helper.** The drift machinery has no
  concept of view-vs-table mismatch.

Suite: `6824 passed`, skipped `145 → 122` (−23: the relocated cases, which only ever skipped). PHPStan
21 errors, unchanged. `tests/Schema` 24 → 41 cases.

⚠️ **The schema lane cannot block a merge.** `development` has **no branch protection and no required
status checks** (`gh api .../branches/development/protection` → 404). So these 17 newly-live assertions
will go red without stopping anything. Closing that is a repo setting, not code, and it is cheaper than
any unit in this bucket.

**P1-LAUNCH**
- [ ] `DINT-1` · [ ] `271-PRIV-1` · [ ] `#SCALE-11` · [x] `#SCALE-13` · [x] `#SCALE-14` · [x] `#SCALE-17`
- [x] `#SCALE-19` · [x] `#SCALE-20` · [x] `#CACHE-1` · [x] `#CACHE-2` · [ ] `#CACHE-3` · [ ] `#3`
- [ ] `#TEST-9` · [ ] `271-TEST-1` · [ ] `#TEST-41` · [ ] `#TEST-49` · [ ] `#TEST-50` · [ ] `#38`
- [ ] `#INH-6` · [ ] `#SEC-4` · [ ] `#9` · [ ] `LC-DRILL-worker-kill` · [ ] `LC-DRILL-vendor-outage`
- [ ] `LC-DRILL-redis-down` · [ ] `LC-K6` · [ ] `LC-RERUN` · [x] `#10`

> **P1-LAUNCH** — worked 2026-07-30 on `audit-fix/p1-launch-2026-07-30`, concurrently with P0-LAUNCH.
>
> **Unit 1 verification collapsed 8 findings into 4 tickets.** Three closed without code:
> - **`#CACHE-1` — DEAD.** Commit `790a0c11` ("perf(audit): batch ProjectionWriter's per-item queries —
>   SCALE-6/7/8") already replaced the per-facet `exists()` loop with exactly the prescribed `whereIn` +
>   in-memory-set batching (`ProjectionWriter::refreshItemCaches`, `BATCH_SIZE = 500`), and pinned it with
>   `ProjectionWriterBatchingTest.php:377`. The sweep captured its evidence before that commit landed.
> - **`#CACHE-2` — folded into `#SCALE-17`.** Same code site (`replaceCollections`), and `#SCALE-17`
>   strictly contains it. One fix, one commit, one test. Ticked when `#SCALE-17` lands.
> - **`#SCALE-19` — closed, no fix, by decision.** The premise ("grows unbounded") is false:
>   `idx_source_intents_live` (`20260727120000_routing_schema.sql:81`) is UNIQUE on
>   `(user_id, surface_key, identifier)` for the blocked states, so cardinality is single-digit per user,
>   and `idx_source_intents_inbox` already covers the query shape. The prescribed fix — push the
>   `legacyPlatform` filter into SQL — has a semantic trap: `LegacyPlatformMap::legacyFor()` is
>   `SPECIAL_TO_LEGACY[$k] ?? explode('.', $k, 2)[0]`, a **prefix rule, not a lookup**. No exact SQL
>   equivalent without `split_part` (Postgres-only, unrunnable in the SQLite lane) or materialising
>   `CompiledCatalog::surfaces()`, which silently changes behaviour for any deliberately-unconstrained
>   `surface_key` absent from the compiled artefact.
>
> **`#CACHE-3` deliberately left OPEN — escalated, not deferred.** Its prescribed `Bus::chain` fix
> regresses two things that shipped days earlier: (1) **`JOB-4`** (`a32e8cbe`) made
> `RunExecutor.php:176-186` downgrade a run to `degraded` on projection failure — chaining finalises
> `ingest.runs.outcome` as `ok` *before* projection runs, silently undoing it; (2) `RunSourceJob`'s
> `finally` calls `SourceScheduler::release()`, so chaining drops the source claim while projection is
> still running, letting `claimDue()` start a second land+project pass concurrently — and
> `ProjectionWriter`'s delete-then-insert is not concurrency-safe against itself on one stream.
> **The open question for Josh:** decouple projection from landing or not, and if so, what replaces
> JOB-4's degraded-outcome signal and the claim-held-during-projection invariant? Lifecycle redesign,
> not scale hygiene.
>
> **`#SCALE-13` — fixed, but NOT as the audit prescribed.** The audit's "chunk the ID list into batches
> of ~500" is **mathematically wrong here** and was rejected: `visitsAggregate`/`clicksAggregate` select
> `COUNT(DISTINCT ...)`, which does not sum across chunks — a visitor under two users in different
> chunks would be double-counted, silently corrupting the staff dashboard. The temp-table JOIN variant
> was also rejected (needs DDL; `CREATE TEMP TABLE` doesn't survive Supavisor transaction-mode pooling).
> Shipped instead: a 2000-user cap (`partna.analytics.staff_segment_max_users`, env-overridable) with a
> 422 above it, plus a non-throwing defence-in-depth warning in `scopedTable()`. The 422 is a deliberate,
> user-visible product decision signed off by Josh — nobody hits it today at 0 live users.

> ### P1-LAUNCH run STOPPED EARLY 2026-07-30 — deliberate, on Josh's call
>
> Branch `audit-fix/p1-launch-2026-07-30`, 9 commits, **9 of 34 findings dispositioned.** The run was
> stopped mid-bucket, not abandoned: the remaining findings are listed below with why each is still open.
>
> **Why it stopped.** `CLAUDE.md`'s own policy says it: *"Never run a 'clear the backlog' campaign …
> under `fix-flow.md` the verify→plan→implement→review overhead exceeds a sub-hour fix. **Disposition
> beats execution.**"* This bucket is 34 mostly-P2/P3 findings and this file's own prompt calls it *"the
> largest bucket and the least urgent."* It was being executed at P0 rigour — three agent rounds per unit
> plus a ~450s full suite per commit. The value did not justify the burn.
>
> **What the finished work was actually worth** — of 9 dispositioned, **4 changed anything real:**
> `#SCALE-17` (removed a genuine crash path: the old `insert()`-after-`SELECT` threw a unique violation
> on a lost race and killed the whole `projectStream`), `#SLOP-21` (found that `CatalogCompileCommand`
> never validated a regex compiles), `#SCALE-13` (value was *catching that the prescribed fix was
> mathematically invalid*, not shipping it), and the off-audit fix that greened `development`'s 12 red
> tests. The other five were disposition — already done, or premise false.
>
> 🔴 **The headline finding of this run is about the audit process, not the code.** Of 13 findings
> examined, **only 3 were implementable exactly as written.** Four were already fixed or duplicates
> (`#CACHE-1`, `#10`, `#JOB-6`, and `#TEST-9`'s CI half). One premise was false (`#SCALE-19`). And
> **five carried prescriptions that were wrong in ways that would have shipped bugs:**
> - `#SCALE-13` — "chunk the user-id list" is invalid: the aggregates are `COUNT(DISTINCT …)`, which does
>   not sum across chunks. Following it would have silently corrupted the staff dashboard.
> - `#SCALE-20` — "collect all projected pairs up front" is impossible: projection happens inside
>   `route()`, per link, after canonicalisation.
> - `#SLOP-21` — "remove the `@`" would have 500'd the live paste-preview API, **and the suite would have
>   stayed green** (PHPUnit installs its own error handler).
> - `#TEST-30` — the prescribed RFC 2606 domains would have broken all 8 tests in its own target file
>   (`assertSafe()` does a real DNS lookup `Http::fake()` cannot intercept; only `example.com` resolves).
> - `#JOB-6` — the prescribed `$e->getCode()` check is not portable (`23505` on Postgres vs a generic
>   `23000` on SQLite).
>
> **Verifying the premise catches the stale ones; only reading the proposed fix catches these.** The
> audit pipeline's `adjudicate` stage should be treated as a hypothesis, never a work order.
>
> **Still open, with reasons:**
>
> | Findings | Why still open |
> |---|---|
> | `DINT-1`, `271-PRIV-1`, `#3` (Unit 2) | Signed off, not started. Genuinely worth doing — two missing analytics indexes, cheap and real. **Highest-value remaining work.** |
> | `#SCALE-11` (Unit 3) | Standalone/GDPR deletion path. Not started. Needs its own isolated review. |
> | `#TEST-9`, `271-TEST-1`, `#TEST-49`, `#TEST-50`, `#38` (Unit 4) | Not started. ⚠️ `#TEST-9` is now **half-closed upstream**: P0-LAUNCH moved `ArchitectureSystemConstraintsTest` into the real applied-schema lane (`tests/Schema/`), so the "doesn't run in CI" half is done. The `site.themes` half is still open and is now **unblocked** (that file has no owner since P0-LAUNCH merged). `CLAUDE.md:228`'s claim that the rule is "pinned by `ArchitectureSystemConstraintsTest`" remains **false** and still needs correcting. |
> | `#9`, `#SEC-4`, `#INH-6` (Unit 5) | Not started. `#9` is a **real GDPR gap** (DSAR omits the subject's own frozen `handle`/`display_name`) and is the second-highest-value item left. `#SEC-4` guards a *hypothetical* future edit — the finding itself concedes today's insert is safe. `#INH-6` is a refactor of three byte-identical functions (re-verified byte-identical 2026-07-30, so a safe consolidation, **not** a bug). |
> | `CFG-16`, `CFG-8`, `CFG-9` (Unit 7) | Not started. Planned in detail; implementation was stopped before writing anything. ⚠️ `CFG-8` must ship a **1..3 clamp**: `fetchPlaceDetails()` claims a `PlacesBudget` slot *inside* the retry loop, so `max_attempts` is a direct multiplier on billed spend for the only paid API with no vendor cap. `CFG-9` should cover **all three** identical `->timeout(110)` Apify sites (Josh's ruling), not just the one the finding names. |
> | 3 × `LC-DRILL-*`, `LC-K6`, `LC-RERUN` (Unit 6) | Not started. Hours of operational work, no code. Lowest priority at zero live users. |
> | `#TEST-41` | Deferral condition ("once P0-LAUNCH merges") is now **met** and it is unblocked, but not done. |
> | `#CACHE-3` | **Open by decision, escalated to Josh.** Its `Bus::chain` fix regresses `JOB-4`'s degraded-outcome signal (`RunExecutor.php:176-186`) and drops the scheduler claim mid-projection, letting `claimDue()` start a concurrent second pass against a `ProjectionWriter` that is not concurrency-safe against itself. **The open question: decouple projection from landing or not, and if so what replaces those two invariants?** Lifecycle redesign, not scale hygiene. |
>
> **Follow-ups discovered during the work — none of these came from the audit:**
> 1. **`YoutubeFeed::mapEntry()` (`:79-81`) fatals on a feed with no `xmlns:media`.** `children($mediaNs)->group` returns a non-null *empty* element so the `!== null` guard passes, then `children($mediaNs)` returns null and line 81 throws. Unreachable today (real YouTube feeds always declare it) but it turns malformed third-party input into a 500 instead of `thumbnail => null`.
> 2. **`tests/Postgres/ProjectionWriterBatchingTest.php` hardcodes `art_url => null` on all four tests**, so the real-Postgres lane issues **zero** `item_media`/`media_assets` writes. Every Postgres-specific risk `#SCALE-17` introduced — `ON CONFLICT DO NOTHING` vs `INSERT OR IGNORE`, 4,000-bind multi-row inserts, lock behaviour of the widened DELETE inside a transaction — is exercised nowhere. One line in `pwbtDoc` closes it. Now unblocked.
> 3. **Worker CSP hardcoding** — `https://app.partna.au` literal at `cloudflare-worker/src/index.js:156` and `:311`; and `PARTNA_DOMAIN` is itself a compile-time `const` (`:46`), not read from `env`. Split out of `#10` deliberately.
> 4. **`#INH-6`'s other two-thirds** — `cleanString` spans **6** files (not 4), and its two menu copies have divergent *signatures and bodies*, so that half is a behaviour question, not a move. `nextPosition` spans 2.
>
> **Process notes worth keeping:**
> - **Two `php artisan test` runs in one worktree interfere.** `Storage::fake('media')` resolves to a fixed path and Redis session sets are process-global, so concurrent runs produce phantom failures in unrelated tests (`ReconcileTrackedSessionsCommandTest`, `VideoVariantServicePurgeTest`). Driver pinning in `phpunit.xml` protects across worktrees, **not within one.**
> - **The promoted findings tick only in their source audit.** `#SLOP-21`, `CFG-*`, `TEST-30/44`, `#JOB-6` have no checkbox in this file — their appearances here and in `BACKLOG-TRIAGE.md` are prose. So this file's `## Progress` P1-LAUNCH count tops out at 27, never 34.

**DEAD — bookkeeping owed to the source folders** (see `## Bookkeeping to apply to the source files`).
Left open on purpose: these are verified dead *here*, but the tick has to land in each source audit
before the finding stops being carried. Six of them block `audits/sweeps/2026-07-11-full-work-sweep/`
from auto-archiving.
- [ ] `#7` · [ ] `#40` · [ ] `#59` · [ ] `#37` · [ ] `#58` · [ ] `#11` · [ ] `#INH-1` · [ ] `#CCH-5`
- [ ] `#LIFE-10` · [ ] `#TEST-21` · [ ] `#TEST-27`

---

# DEAD — 11 items. Tick these and stop carrying them.

Your suspicion was correct, and the hit rate is high: **12 of the 48 verified items (25%) were dead.**
Six of the fourteen I sampled from the 07-11 sweep are already fixed.

| ID | Source | Original | Verified state |
|---|---|---|---|
| `#7` | 07-11 | P3 | **ALREADY-DONE** — `charlie@ai.com` placeholder no longer in `config/partna.php` |
| `#40` | 07-11 | P3 | **ALREADY-DONE** — `CloudflareCustomHostnameService::delete()` now checks the response |
| `#59` | 07-11 | P3 | **ALREADY-DONE** — Instagram reel mirror fd leak closed |
| `#37` | 07-11 | P3 | **ALREADY-DONE** — and note `AccountType::Individual` is a *deliberate* keep per `CLAUDE.md`, not debt |
| `#58` | 07-11 | P3 | **ALREADY-DONE** — dead `hasStoreKey`/`count` methods removed |
| `#11` | 07-11 | P3 | **ALREADY-DONE** — staging KV namespace no longer a placeholder |
| `#INH-1` | inheritance | **P1** | **ALREADY-DONE** — fixed in `eb44f8fa` (2026-07-25) via new `App\Services\Http\UrlAbsolutizer`, with `tests/Unit/Http/UrlAbsolutizerTest.php`. The fix also folded in a 4th copy (`WebsiteLogoCandidateExtractor`) the audit missed. **File B's tick is correct; file A is stale.** |
| `#CCH-5` | 07-28 | P2 | **ALREADY-DONE** — the cache-poisoning path is closed via `hasDegraded()`/`shortenDegraded()`, which rewrite both primary and stale keys under a short TTL. This was the highest-stakes correctness item in the corpus. |
| `#LIFE-10` | 07-28 | P2 | **PHANTOM** — the TOCTOU window is real but a pre-existing unique constraint on `(site_id, position)` prevents any duplicate; the loser fails cleanly into the existing catch |
| `#TEST-21` | 07-28 | P2 | **PHANTOM** — no dedicated resource test file exists, but all 5 `filterPayload()` branches are covered route-level by `PublicIntegrationAllowlistTest` + `PublicAllowlistCoverageTest` |
| `#TEST-27` | 07-28 | P2 | **PHANTOM** — `resolvePhotoUrls` isn't named in tests, but its budget-claim loop is proven via `fetchPlaceDetails()` in `PlacesBudgetGateTest` |

**Also resolved (a launch-check verdict row, not a numbered item):** Group G's two config FAILs are
gone — prod and dev both now report `QUEUE_CONNECTION=redis` and `SESSION_DRIVER=redis`.

`#TEST-21` and `#TEST-27` are the "phantom coverage gap" pattern the 07-28 sweep warned about in its
own header, confirmed twice more here. **The test-coverage lens cannot see route-level tests.** That is
worth fixing in `scripts/audit/lenses/` before the next sweep, or every future run re-reports these.

---

# P0-PILOT — 7 findings / 6 units. Do not run a pilot with these open.

### `271-PRIV-2` · was P2 → **P0-PILOT** · Google reviewer PII published by default
- **Where:** `PublicIntegrationConnectionResource.php`, `DisplaySettingsFilter.php`, `GoogleBusinessPayload.php`
- **Verified:** STILL-OPEN. Exact current behaviour — `stripThirdPartyPii` runs **only** on the
  pre-claim path (gated on `user.status === 'unclaimed'`). The moment an account is claimed, status
  flips to `active`, the strip stops applying, and the next refresh restores full reviewer data. That
  data then ships on the public wire because the `google-business` allowlist includes
  `reviews`/`reviewSummary`, behind a `DisplaySettingsFilter` toggle whose default is **ON**.
  **Reviewer PII is opt-out, not opt-in, for every claimed connection.**
- **Why P0-PILOT:** this is third-party PII — the *professional's customers*, who never entered any
  relationship with Partna. It is republished to unauthenticated visitors and CDN-cached. Today it is
  harmless because no claimed connections exist. The first pilot customer who connects Google Business
  makes it real, and CDN caching means it is not cleanly retractable.
- **Note:** you deferred this on 2026-07-24 with "revisit before the pilot." This is that moment. It is
  a product/legal call, not an engineering task — the engineering (flip the default, or document the
  second-subject processing basis) is small either way.

### `#INH-7-DRIFT` · was P2 → **P0-PILOT** · early-access endpoint bypasses anti-automation
- **Where:** `app/Http/Requests/.../PublicEarlyAccessSignupRequest.php` vs the `WithBotProtection` trait
- **Verified:** **PARTIAL — and verification found a live gap the audit did not describe.** The audit
  called this a consistency refactor across four public form controllers. It is more than that. A shared
  `WithBotProtection` trait already exists (shipped 2026-07-02) and is used by three of the four request
  classes, all requiring `form_started_at_ms`. `PublicEarlyAccessSignupRequest` was created *after* the
  trait existed, skipped it, and declares `form_started_at_ms` as **`nullable`** instead of required.
  The controller's timing check is gated on `is_int($startedMs)` — so a submission that simply **omits
  the field** bypasses the timing/anti-automation check entirely, on that one endpoint. The other three
  reject a missing field at validation before the controller runs.
- **Why P0-PILOT:** live, unauthenticated, public, and it is the weakest of four doors into the same
  system. Fix is one word in a validation rule.

### `LC-PROD-ENV` · was ungraded → **P0-PILOT** · production environment is stopped
- **Verified:** `cloud environment:get production` reports **`status=stopped`**. Every prod URL
  (`/api/health`, `/api/ping`, `/`) returns an empty-bodied 404 at the Cloudflare edge.
- **This is consistent with your 2026-07-26 decision to stop it deliberately** — flagging it not as a
  regression but as an explicit pre-pilot gate. Worth restating the trap you already recorded: a bare
  404 and a moved git ref *both* lie about a deploy, so confirm via `cloud deployment:list`, not by
  curling a URL.
- **Also:** `/api/health` is liveness-only and will go green on a fully broken prod. Probe a DB-touching
  route as the real readiness signal.

### `LC-BACKUP` · was ungraded → **P0-PILOT** · backup posture before real data lands
- **Verified:** the backup-restore drill *has* been run once (log dated 2026-07-26) — that part is done.
  The open part is the posture itself: the Supabase org is on the **Free** plan, so there is **no PITR
  and no managed backups**, projects can auto-pause, and the `partna-db-backup` R2 dump is the only copy.
- **Why P0-PILOT:** with `core.users = 0` this is a non-issue. The first pilot customer's data changes
  that completely — an untested single-copy backup is the difference between an incident and an
  extinction event. Either move off Free before onboarding, or accept it in writing and re-run drill-04
  against a database that actually contains rows.

### `LC-RUNBOOKS-2` · was ungraded → **P0-PILOT** · two missing runbooks, one of which you will need
- **Verified:** `docs/runbooks/` has `vendor-outage` and `redis-down`. There is **no runbook for "DB pool
  exhausted"** and **none for "queue backed up."**
- **Why P0-PILOT:** pool exhaustion is not hypothetical here. Supavisor session mode pins a slot per
  *process*, and six Horizon daemons consume six of fifteen. That is the failure you are most likely to
  actually hit during a pilot, and it is the one with no written procedure. Two documents, one sitting.

### `LC-EDGE-HARDENING` · was ungraded → **P0-PILOT** · Cloudflare + Supabase dashboard settings
- **Verified:** unverifiable by script (not API-readable) and never confirmed. Covers: Cache Deception
  Armor ON, edge rate-limiting rules (not only Laravel's), SSL mode Full (strict); and on Supabase — SSL
  enforcement ON, network restrictions, auth rate limits reviewed, custom SMTP.
- **Why P0-PILOT:** these guard *public unauthenticated traffic*, which is exactly what a pilot creates.
  All manual dashboard toggles, all cheap. Cache Deception Armor in particular matters because your
  public sitepages are aggressively CDN-cached.

---

# P1-PILOT — 12 findings / 6 units. The pilot will expose these.

| ID | Was | Item | Verified |
|---|---|---|---|
| `#CCH-4` | P2 | `IntegrationConnectionObserver::deleted()`/`restored()` never `touch()` the site, so disconnecting an integration leaves a **stale public page** | STILL-OPEN — neither method calls it, gated or otherwise |
| `#LIFE-11` | P2 | `cleanupMirroredMedia` is the only best-effort method in its class with no try/catch — Instagram disconnect can throw and skip the rest of cleanup | STILL-OPEN |
| `#LIFE-12` | P2 | `menuScrapeFailed` dedupe key is `userId`-only with no episode boundary — a user whose menu breaks, recovers, then breaks again inside 14 days is **never told the second time** | STILL-OPEN — sibling `connectionRefreshFailing` already does this correctly |
| `271-SEM-1` | P2 | `ItemSlugAllocator::ensureCurrent()` conflates a collision suffix with a name ending in digits — renaming a menu item can leave a **stale public URL** | STILL-OPEN |
| `#JOB-4` | P2 | Ingest run reports `outcome = 'ok'` even when **every** projection failed — you would be blind to it | STILL-OPEN — `'degraded'` sits unused in the rank map |
| `PRIV-1` | P2 | Visitor lat/long stored at full double precision, no truncation, 90-day retention | STILL-OPEN |
| `PRIV-2` | P2 | UA sanitizer length-caps at 256 chars only — full browser fingerprint across 5 tables for 90 days | STILL-OPEN |
| `PRIV-4` | P2 | `analytics.content_popularity_scores` has no time-bound retention at all | STILL-OPEN |
| `PRIV-3` | P2 | Moderation PII erasure runs on every deletion but is untracked by the export/erasure coverage guard | PARTIAL |
| `#43` | **P3** | `cloudflare-worker/` has **zero** automated test coverage | STILL-OPEN |
| `#EDGE-2` | P2 | Worker README describes an obsolete brand/affiliate architecture that no longer matches the KV contract | STILL-OPEN |
| `LC-NIGHTWATCH` | ungraded | Throw a deliberate exception on dev and confirm the alert **actually arrives** | never done |

**On the three privacy items (`PRIV-1`, `PRIV-2`, `PRIV-4`):** grouped deliberately. All three are small,
all three live in the analytics write path, and all three become real the moment a pilot site receives
its first outside visitor. One session closes the set.

**On `#43` (P3 → P1-PILOT), the biggest single upgrade in this file.** Read it together with `#EDGE-2`:
every `<handle>.partna.au` request routes through the Cloudflare Worker, and that Worker has **no tests,
no Nightwatch coverage, and a README that describes an architecture it no longer implements.** That is
three-deep blindness on the single most critical path in the product. Any one of those alone is a P3;
together they mean a Worker regression ships silently, pages nobody, and is debugged against wrong
documentation. Closing `#EDGE-2` is an hour and buys most of the incident-response value.

**On `#LIFE-12`:** worth noting the notifier's *sibling* method already implements the exact fix, with a
comment documenting the earlier discovery. The pattern was found and applied once, then not propagated —
the same class of miss as `#INH-7`.

---

# P0-LAUNCH — 7 findings / 4 units. Pilot's small N hides these; GA will not.

### `#TEST-2` · was P1 → **P0-LAUNCH** · constraint/index/trigger tests still don't run in CI
- **Verified: PARTIAL.** Genuine progress — `CheckConstraintsTest.php` moved to `tests/Schema/` and now
  runs for real in a new `schema-tests` CI job. But **`IndexCoverageTest.php`,
  `ArchitectureSystemConstraintsTest.php` and `UpdatedAtTriggerCoverageTest.php` are unchanged** and
  still execute against no Postgres lane. Every index, FK-cascade and trigger they assert is unguarded:
  a migration that drops one passes CI green.

### `#TEST-1` · was P1 → **P0-LAUNCH** · test-fixture schema drift
- **Verified: PARTIAL.** The schema-drift gate exists with its own CI job, and a new
  `NoLocalCanonicalTableDdlTest.php` narrowed the hole for 13 named canonical tables (grandfathered
  offenders cut 15 → 6). But **77 other test files still carry inline `CREATE TABLE` invisible to the
  gate.** This is the failure mode that has already bitten you in production once (`42703` on the GDPR
  export) — tests run SQLite, prod runs Postgres, and a phantom column is green in one and fatal in the other.

### `271-PARITY-1` · was P2 → **P0-LAUNCH** · menu columns nullable in tests, NOT NULL in prod
- **Verified: PARTIAL**, exactly as the 07-25 note predicted. `site.menus.user_id` is now `NOT NULL`,
  but **`site.menu_items.menu_id` and `site.menu_items.name` remain nullable** in the test seed and are
  still grandfathered in `scripts/launch-check/schema-drift-baseline.json`. Same shape as `#TEST-1`;
  fix them in the same sitting.

### `LC-ROLLBACK` · was ungraded → **P0-LAUNCH** · migrations without a reverse path
- **Verified: STILL-OPEN.** **49 migrations have landed since prod's last deploy**, and only **12 of 51
  files carry any revert/rollback/undo comment — 39 have none.** On a Free plan with no PITR, "roll
  forward and hope" is the entire recovery strategy for those 39.

### `#API-1` · was P2 → **P0-LAUNCH** · shop products reach the public wire with no allowlist
- **Verified: STILL-OPEN.** Every key on a `ShopBrand`'s products array is emitted verbatim to
  unauthenticated visitors, with no enforcement layer. Today the array holds only public catalog data —
  which is precisely why it is P0-*LAUNCH* and not P0-PILOT. It becomes a leak the first time anyone
  adds a field to that structure without thinking about who reads it.

### `LC-DAST` · was ungraded → **P0-LAUNCH** · dynamic security pass never triaged
- The DAST tooling shipped to `development` on 2026-07-26, but the **baseline triage still needs you**.
  A DAST run nobody has read is not a completed control.

### `#50` · was P3 → **P0-LAUNCH** · public menu endpoint has no field allowlist *(promoted 2026-07-30)*
- **Where:** `app/Http/Controllers/Api/PublicSite/PublicMenuController.php`
- `show()` hand-builds the public menu payload with no Resource class, so an unauthenticated public
  endpoint has **no allowlist guardrail** — a future internal column reaches the public wire silently.
- **Why promoted:** identical defect class to `#API-1`, which was already P0-LAUNCH. Grading them
  differently was an inconsistency in the source audits, not a real distinction. `#API-1` covers shop
  products; this covers the menu, a core product surface. **Work both in the same unit.**

---

# P1-LAUNCH — 34 findings / 6 units

**Scale and data-growth** — none of these bite at pilot volume; all of them bite at GA:
- `DINT-1` (P3) — no index on `analytics.action_events(user_id)` or `item_views(user_id)`; every GDPR purge sequential-scans a write-heavy table. **STILL-OPEN.**
- `271-PRIV-1` (P2) — retired `site.item_slugs` accumulate forever; no `retired_at`, no purge job. **STILL-OPEN.**
- `#SCALE-11` (P2) — `SiteMedia` force-delete serialises per-file storage I/O; a heavy user times out their own account deletion. **Touches the GDPR deletion path — isolate for sign-off.**
- `#SCALE-13/14/17/19/20` + `#CACHE-1/2/3` (all P2) — unbounded `whereIn` arrays and per-row INSERT loops across ingest, projection and staff analytics. Correct to defer; wrong to forget.
- `#3` (P3) — `analytics.item_views` has no DB-level dedup key, relying entirely on app-side Redis. **STILL-OPEN.**

**Correctness guards:**
- `#TEST-9` / `271-TEST-1` (P3/P2) — no invariant test that `site.themes` stays dropped. **STILL-OPEN, and worse than recorded:** `ArchitectureSystemConstraintsTest` exists but its three assertions don't cover themes-dropped — *and* it doesn't run in CI (see `#TEST-2`). `CLAUDE.md` currently claims this rule is "pinned" by that test. **It isn't.**
- `#TEST-41` — `BrandAssetPipelineTest` and `CatalogSyncIdempotenceTest` still hand-copy migration DDL. `ItemTombstoneBackfillTest` is the one file doing it correctly; copy that pattern. **STILL-OPEN.**
- `#TEST-49` / `#TEST-50` — no invariant test for the `detectors_surface_xor_signal` CHECK, and none asserting the deliberate *absence* of a unique index on `content.identity_keys(key_class, key_value)`. **STILL-OPEN.**
- `#38` (P3) — `site.menus.dining_modes` JSONB has no `jsonb_typeof = 'array'` CHECK. **STILL-OPEN.**
- `#INH-6` (P1 → P1-LAUNCH) — `normalizeName`/`norm` declared three times with "must stay identical" comments. **Verified NOT drifted today** — all three implementations are still byte-identical, so this is latent risk, not a live bug. That verification is why it drops from P1 rather than rising.
- `#SEC-4` (P2) — raw insert bypasses `$fillable`. **PARTIAL** — the row keys are a fixed literal set never derived from request input, so there is no live injection path; structural hardening only.

**Privacy / compliance:**
- `#9` (P3) — evidence-snapshot handle/display_name missing from the DSAR export (deletion side is already correct). **STILL-OPEN.**

**Operational:**
- `LC-DRILL-worker-kill`, `LC-DRILL-vendor-outage`, `LC-DRILL-redis-down` — **verified never run.** Only backup-restore has a log.
- `LC-K6` — baseline load pass (10 VU/5 min) + public-handle spike (50–100 VU), watching edge cache-hit ratio, Supavisor headroom, p95. Harness exists and is ready.
- `LC-RERUN` — make re-running launch-check a standing step after every migration push and before every promote. Process, not code.
- `#10` (P3) — Worker `unclaimedHtml()` hardcodes `https://partna.au` regardless of environment. **STILL-OPEN.** Trivial, and it pairs with `#43`/`#EDGE-2`.

**Promoted from backlog, 2026-07-30** — seven findings the source audits under-graded. Full reasoning in
[`BACKLOG-TRIAGE.md`](./BACKLOG-TRIAGE.md):
- `#JOB-6` (was P3) — `EffectLedger::once()` can mask a non-duplicate DB error as a silent `'refused'`, **skipping a billed effect** (actor runs, AI extraction) with no log and no exception.
- `#SLOP-21` (was P3) — `@`-suppressed `preg_match` on catalog regex in `LinkProjector` (3 sites): a typo'd detector pattern **fails closed silently** and links stop routing.
- `#TEST-30` (was P2) — `SafeUrlFetcher`, Partna's own **SSRF boundary**, is `Mockery::mock()`ed out of the Shop URL validation tests, stripping allowlist/DNS/redirect checks from all of them. Switch to `Http::fake()`.
- `#TEST-44` (was P2) — no XXE regression test on `YoutubeFeed::parse()`. Defence is correct today; the risk is a future "fix" reopening it.
- `#CFG-16` + `#CFG-8` + `#CFG-9` (were P3) — the only `CFG-*` items that are genuine **incident and paid-API knobs**: ingest deletion sensitivity, billed-effect abandonment, scheduler fairness, Places retry/backoff, Apify timeout. One ~1h unit. The other 15 `CFG-*` are WONTFIX.

**Supply chain** (a launch-check verdict row, not a numbered item): `cloudflare-worker` npm still carries
the same 3 high advisories — `sharp` pinned at 0.34.5 (<0.35.0) via miniflare/wrangler. **Verified
dev-only dependencies**, never shipped to the edge runtime, so the real risk is low. Bump it when
convenient; don't gate launch on it.

---

# BACKLOG — 231 items. Dispositioned 2026-07-30, not scheduled.

**Full decision record: [`BACKLOG-TRIAGE.md`](./BACKLOG-TRIAGE.md).** Summary:

| Disposition | Count | Meaning |
|---|---|---|
| **WONTFIX** | 135 | Closed permanently with a stated reason. ~34 were disarmed by the audit's own caveats ("no action needed", "already fully backstopped", "the prescribed fix does not fix anything"); ~9 are duplicates or superseded by `LC-ROLLBACK`; ~45 are cosmetic; ~47 are the stale 07-11 P3 tail. |
| **OPPORTUNISTIC** | 96 | Never scheduled. Fixed in-passing when the file is already open — the standing rule now lives in `CLAUDE.md`. |
| **PROMOTE** | 8 | Mis-graded. Moved into P0-LAUNCH (1) and P1-LAUNCH (7); counts above already reflect this. |

**Do not batch-execute this list, and do not re-derive it.** Three measured reasons: the 07-11 group has
a **43% already-fixed rate** (6 of 14 sampled), the test-coverage lens has **eight** confirmed phantoms,
and under `fix-flow.md` the verify→plan→implement→review overhead exceeds the fix for a sub-hour item.
Disposition was the cheaper and more honest close.

Composition of what was triaged:

| Group | Count | Character |
|---|---|---|
| `#SLOP-*` (07-28) | 21 | Banners and docblocks. 2 promoted/opportunistic (comments that actively mislead), rest WONTFIX. |
| `#CFG-*` (07-28) | 19 | Hardcoded constants. **3 promoted** (incident + paid-API tunables), 1 opportunistic, 15 WONTFIX. |
| `#TEST-*` remainder | ~55 | **2 promoted** (SSRF + XXE regression guards). Rest opportunistic, with a mandatory re-verify against the revised lens. |
| `#LIFE-*` P3s | ~12 | Races the audit marks "already fully backstopped", plus four on code with no production caller. Mostly WONTFIX. |
| `#SEM-*`, `#API-*`, `#MIG-*` | ~25 | `MIG-*` WONTFIX (superseded by `LC-ROLLBACK`); `SEM-2`/`SEM-3` opportunistic-high. |
| `#INH-*` remainder | 14 | Opportunistic — **except `INH-4` and `INH-8`**, which are standalone and must not be absorbed casually. |
| 07-11 P3 remainder | ~47 | Closed en bloc on the 43% dead rate. |
| Everything else | ~38 | |

---

## Recommended sequencing

1. **Before onboarding customer #1** — the 6 P0-PILOT items. Realistically one focused day, because four
   of them are decisions and dashboard toggles rather than code. `271-PRIV-2` needs your product/legal
   call before anything can be implemented, so start there.
2. **Same week** — `#INH-7-DRIFT` (one word), then the P1-PILOT twelve, taking the three `PRIV-*` items
   as one session and `#43`+`#EDGE-2`+`#10` as another.
3. **Before GA** — P0-LAUNCH. `#TEST-1`/`#TEST-2`/`271-PARITY-1` are one coherent piece of work: finish
   wiring the Postgres/schema-drift lanes so the guards you already wrote actually execute.
4. **Then** — P1-LAUNCH, scale cluster first.

## Bookkeeping to apply to the source files

- Tick the 11 DEAD items in their source folders. Six of them are in
  `audits/sweeps/2026-07-11-full-work-sweep/`, which cannot auto-archive while they stand.
- Tick `#INH-1` in `audits/consolidation/2026-07-25-backend-inheritance/CONSOLIDATED.md` — the 07-27
  verification file already has it correct.
- **Fix the audit pipeline itself:** the test-coverage lens cannot see `tests/Unit/Routing/`,
  `tests/Unit/Policies/`, `tests/Feature/Security/PolicyEnforcement/`, `TenantIsolation/`, or
  route-level feature tests. It produced 3 confirmed phantoms across this run and the 07-28 sweep's own
  header lists 6 more. Until `scripts/audit/lenses/` is widened, every future sweep re-reports them.
- **Correct `CLAUDE.md`:** it states the architecture rules are "Pinned by
  `ArchitectureSystemConstraintsTest`." Verified false on two counts — that test does not assert
  `site.themes` stays dropped, and it does not run in CI.
